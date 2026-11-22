<?php

namespace Tests\Feature\Koperasi;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\PaymentChargeRequest;
use App\Modules\Payments\Data\QrisCharge;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\FakeQrisGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\QrisImage;
use App\Modules\Pos\Services\CashierShiftService;
use App\Modules\Pos\Services\PosService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Koperasi $kopA;

    private User $kasirA;

    private Customer $customer;

    private Product $productA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $this->kasirA = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();
        $this->customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();
        $this->productA = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->firstOrFail();
    }

    private function createPendingOrder(int $amount = 75000): Order
    {
        $order = new Order;
        $order->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-PAY-'.rand(1000, 9999),
            'status' => 'pending_payment',
            'tax_rate_snapshot' => 11.00,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_amount' => $amount,
        ])->save();

        return $order;
    }

    public function test_initiate_qris_for_order_is_idempotent_and_renders_valid_svg(): void
    {
        $order = $this->createPendingOrder(82500);
        $service = app(PaymentService::class);

        $first = $service->initiateForOrder($order);
        $second = $service->initiateForOrder($order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('pending', $first->status);
        $this->assertSame(82500, (int) $first->amount);
        $this->assertSame((int) $order->id, (int) $first->order_id);
        $this->assertNull($first->sale_id);
        $this->assertStringStartsWith('000201', (string) $first->qris_payload);
        $this->assertStringContainsString('5303360', (string) $first->qris_payload);
        $this->assertStringContainsString('540582500', (string) $first->qris_payload);
        $this->assertTrue($first->expired_at->lessThanOrEqualTo(now()->addMinutes(15)));

        $svg = app(QrisImage::class)->svg((string) $first->qris_payload);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_initiate_qris_for_pos_sale_respects_xor_constraint(): void
    {
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 2],
            paymentMethod: 'qris',
        );

        $payment = app(PaymentService::class)->initiateForSale($sale);

        $this->assertNull($payment->order_id);
        $this->assertSame((int) $sale->id, (int) $payment->sale_id);
        $this->assertSame((int) $sale->total_amount, (int) $payment->amount);
        $this->assertSame('pending', $payment->status);
    }

    public function test_expired_qris_marks_old_payment_expired_and_creates_new_payment(): void
    {
        $order = $this->createPendingOrder(50000);
        $service = app(PaymentService::class);

        $first = $service->initiateForOrder($order);
        $this->travel(16)->minutes();

        $second = $service->initiateForOrder($order);

        $this->assertSame('expired', $first->fresh()->status);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('pending', $second->status);
        $this->assertSame(2, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_gateway_unavailable_retries_three_times_and_records_audit_log(): void
    {
        Sleep::fake();

        $attempts = 0;
        $this->app->instance(PaymentGateway::class, new class($attempts) implements PaymentGateway
        {
            public function __construct(private int &$attempts) {}

            public function createQrisCharge(PaymentChargeRequest $request): QrisCharge
            {
                $this->attempts++;
                throw new GatewayUnavailableException('Timeout sandbox');
            }

            public function name(): string
            {
                return 'spy-unavailable';
            }

            public function isSandbox(): bool
            {
                return true;
            }
        });

        $order = $this->createPendingOrder(30000);

        try {
            app(PaymentService::class)->initiateForOrder($order);
            $this->fail('Seharusnya melempar PaymentException setelah 3 percobaan.');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('gangguan', $e->getMessage());
        }

        $this->assertSame(3, $attempts);
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'payment_gateway_unavailable')->exists());
    }

    public function test_non_payable_order_or_cash_sale_is_rejected(): void
    {
        $order = $this->createPendingOrder(40000);
        $order->update(['status' => 'cancelled']);

        try {
            app(PaymentService::class)->initiateForOrder($order);
            $this->fail('Order cancelled tidak boleh dibuatkan QRIS.');
        } catch (PaymentException) {
            // diharapkan
        }

        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $cashSale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 1],
            paymentMethod: 'cash',
            amountPaid: (int) $this->productA->price,
        );

        $this->expectException(PaymentException::class);
        app(PaymentService::class)->initiateForSale($cashSale);
    }

    public function test_default_container_binding_resolves_fake_qris_gateway(): void
    {
        $gateway = app(PaymentGateway::class);
        $this->assertInstanceOf(FakeQrisGateway::class, $gateway);
        $this->assertTrue($gateway->isSandbox());
    }
}
