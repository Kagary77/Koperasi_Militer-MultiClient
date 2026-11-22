<?php

namespace Tests\Feature\Koperasi;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Payments\Events\PaymentSettled;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\WebhookSignatureVerifier;
use App\Modules\Pos\Services\CashierShiftService;
use App\Modules\Pos\Services\PosService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentWebhookAndExpiryTest extends TestCase
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
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedWebhook(array $payload, ?string $overrideSignature = null): TestResponse
    {
        $rawBody = (string) json_encode($payload);
        $secret = (string) $this->kopA->gateway_credentials['webhook_secret'];
        $signature = $overrideSignature ?? app(WebhookSignatureVerifier::class)->sign($rawBody, $secret);

        return $this->call(
            method: 'POST',
            uri: route('webhooks.qris'),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_QRIS_SIGNATURE' => $signature,
            ],
            content: $rawBody,
        );
    }

    private function createPendingOrderWithItem(int $qty = 2): Order
    {
        $subtotal = (int) $this->productA->price * $qty;

        $order = new Order;
        $order->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-WH-'.rand(1000, 9999),
            'status' => 'pending_payment',
            'tax_rate_snapshot' => 0,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_amount' => $subtotal,
        ])->save();

        $item = new OrderItem;
        $item->forceFill([
            'koperasi_id' => $this->kopA->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'product_name' => $this->productA->name,
            'price_snapshot' => (int) $this->productA->price,
            'quantity' => $qty,
            'subtotal' => $subtotal,
        ])->save();

        return $order;
    }

    public function test_valid_hmac_webhook_settles_online_order_and_is_idempotent(): void
    {
        Event::fake([PaymentSettled::class]);

        $order = $this->createPendingOrderWithItem(2);
        $payment = app(PaymentService::class)->initiateForOrder($order);

        $payload = [
            'event_id' => 'EVT-ORD-001',
            'payment_reference' => $payment->payment_reference,
            'status' => 'settled',
            'amount' => (int) $payment->amount,
        ];

        $this->postSignedWebhook($payload)
            ->assertOk()
            ->assertJson(['code' => 'settled']);

        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, PaymentEvent::query()->where('provider_event_id', 'EVT-ORD-001')->count());
        Event::assertDispatchedTimes(PaymentSettled::class, 1);

        // Pengiriman ulang event yang sama -> idempoten (HTTP 200 tanpa proses ulang)
        $this->postSignedWebhook($payload)
            ->assertOk()
            ->assertJson(['code' => 'duplicate_ignored']);

        $this->assertSame(1, PaymentEvent::query()->where('provider_event_id', 'EVT-ORD-001')->count());
        Event::assertDispatchedTimes(PaymentSettled::class, 1);
    }

    public function test_invalid_hmac_signature_is_rejected_401_and_logged(): void
    {
        $order = $this->createPendingOrderWithItem(1);
        $payment = app(PaymentService::class)->initiateForOrder($order);

        $this->postSignedWebhook([
            'event_id' => 'EVT-BAD-SIG',
            'payment_reference' => $payment->payment_reference,
            'status' => 'settled',
            'amount' => (int) $payment->amount,
        ], overrideSignature: 'tanda-tangan-palsu')
            ->assertStatus(401)
            ->assertJson(['code' => 'invalid_signature']);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'EVT-BAD-SIG',
            'signature_valid' => false,
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'webhook_invalid_signature')->exists());
    }

    public function test_amount_mismatch_is_rejected_422_without_settling(): void
    {
        $order = $this->createPendingOrderWithItem(1);
        $payment = app(PaymentService::class)->initiateForOrder($order);

        $this->postSignedWebhook([
            'event_id' => 'EVT-BAD-AMT',
            'payment_reference' => $payment->payment_reference,
            'status' => 'settled',
            'amount' => (int) $payment->amount - 1000,
        ])->assertStatus(422)
            ->assertJson(['code' => 'amount_mismatch']);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'payment_amount_mismatch')->exists());
    }

    public function test_webhook_settles_pos_qris_and_deducts_stock_atomically(): void
    {
        Event::fake([CatalogChanged::class, PaymentSettled::class]);

        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $initialStock = (int) $this->productA->stock;

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 3],
            paymentMethod: 'qris',
        );

        $payment = app(PaymentService::class)->initiateForSale($sale);
        $this->assertSame($initialStock, (int) $this->productA->fresh()->stock);

        $this->postSignedWebhook([
            'event_id' => 'EVT-POS-001',
            'payment_reference' => $payment->payment_reference,
            'status' => 'settled',
            'amount' => (int) $payment->amount,
        ])->assertOk()
            ->assertJson(['code' => 'settled']);

        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertSame('paid', $sale->fresh()->status);
        $this->assertSame($initialStock - 3, (int) $this->productA->fresh()->stock);
        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $this->productA->id,
            'type' => 'out_pos',
            'quantity' => -3,
            'reference_id' => $sale->id,
        ]);

        Event::assertDispatched(CatalogChanged::class);
        Event::assertDispatched(PaymentSettled::class);
    }

    public function test_pos_qris_settlement_with_depleted_stock_marks_voided_without_negative_stock(): void
    {
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 5],
            paymentMethod: 'qris',
        );

        $payment = app(PaymentService::class)->initiateForSale($sale);

        // Simulasikan stok habis dibeli transaksi lain sebelum webhook tiba (UC-09 edge case 4c)
        $this->productA->forceFill(['stock' => 2])->save();

        $this->postSignedWebhook([
            'event_id' => 'EVT-POS-4C',
            'payment_reference' => $payment->payment_reference,
            'status' => 'settled',
            'amount' => (int) $payment->amount,
        ])->assertOk()
            ->assertJson(['code' => 'insufficient_stock_voided']);

        $this->assertSame('settled', $payment->fresh()->status);
        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame(2, (int) $this->productA->fresh()->stock);
        $this->assertTrue(AuditLog::query()->where('action', 'pos_qris_settled_insufficient_stock')->exists());
    }

    public function test_scheduler_command_auto_cancels_expired_order_restores_stock_and_cancels_pos_sale(): void
    {
        Event::fake([CatalogChanged::class]);

        // 1. Online order pending_payment
        $initialStock = (int) $this->productA->stock;
        $order = $this->createPendingOrderWithItem(4);
        $orderPayment = app(PaymentService::class)->initiateForOrder($order);

        // 2. POS QRIS sale open
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 2],
            paymentMethod: 'qris',
        );
        $salePayment = app(PaymentService::class)->initiateForSale($sale);

        // Lewati 15 menit lalu jalankan command scheduler
        $this->travel(16)->minutes();

        $this->artisan('payments:expire-pending')->assertSuccessful();

        $this->assertSame('expired', $orderPayment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame($initialStock + 4, (int) $this->productA->fresh()->stock);
        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $this->productA->id,
            'type' => 'return_online',
            'quantity' => 4,
            'reference_id' => $order->id,
        ]);

        $this->assertSame('expired', $salePayment->fresh()->status);
        $this->assertSame('cancelled', $sale->fresh()->status);
    }

    public function test_pos_terminal_displays_qris_svg_and_completes_on_sandbox_simulation(): void
    {
        $initialStock = (int) $this->productA->stock;

        Livewire::actingAs($this->kasirA, 'web')
            ->test('pos::terminal', ['koperasiId' => $this->kopA->id, 'koperasiSlug' => $this->kopA->slug])
            ->set('openingBalance', 100000)
            ->call('openShift')
            ->call('addToCart', $this->productA->id)
            ->set('paymentMethod', 'qris')
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertSee('Pembayaran QRIS Dinamis')
            ->assertSeeHtml('<svg')
            ->call('simulateSandboxQrisSettlement')
            ->assertHasNoErrors();

        $sale = Sale::query()->withoutGlobalScope('koperasi')->latest('id')->firstOrFail();
        $this->assertSame('paid', $sale->status);
        $this->assertSame($initialStock - 1, (int) $this->productA->fresh()->stock);
    }
}
