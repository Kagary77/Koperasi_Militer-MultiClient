<?php

namespace Tests\Feature\Koperasi;

use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Ordering\Exceptions\OrderingException;
use App\Modules\Ordering\Services\ComplaintService;
use App\Modules\Ordering\Services\KoperasiOrderService;
use App\Modules\Payments\Jobs\SendWhatsAppNotificationJob;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderAndComplaintServiceTest extends TestCase
{
    use RefreshDatabase;

    private Koperasi $kopA;

    private User $adminA;

    private User $kasirA;

    private Customer $customer;

    private Product $productA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $this->kasirA = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();
        $this->customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();
        $this->productA = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function createOrderWithItem(string $status = 'paid', int $qty = 2): Order
    {
        $subtotal = (int) $this->productA->price * $qty;

        $order = new Order;
        $order->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-KOP-'.rand(1000, 9999),
            'status' => $status,
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

    public function test_kasir_and_admin_can_advance_paid_order_through_full_lifecycle(): void
    {
        Queue::fake();

        $order = $this->createOrderWithItem('paid');
        $service = app(KoperasiOrderService::class);

        // Tidak boleh melompati tahap langsung dari paid ke completed
        try {
            $service->advanceStatus($this->kopA, $order, $this->kasirA, 'completed');
            $this->fail('Transisi melompat seharusnya ditolak.');
        } catch (OrderingException) {
            // diharapkan
        }

        $service->advanceStatus($this->kopA, $order, $this->kasirA, 'processing');
        $this->assertSame('processing', $order->fresh()->status);

        $service->advanceStatus($this->kopA, $order->fresh(), $this->kasirA, 'ready');
        $this->assertSame('ready', $order->fresh()->status);

        $service->advanceStatus($this->kopA, $order->fresh(), $this->adminA, 'completed');
        $this->assertSame('completed', $order->fresh()->status);

        $this->assertSame(3, $this->customer->notifications()->count());
        Queue::assertPushed(SendWhatsAppNotificationJob::class, 3);
        $this->assertSame(3, AuditLog::query()->where('action', 'order_status_updated')->count());
    }

    public function test_admin_cancels_paid_order_restores_stock_and_notifies_refund_sop(): void
    {
        Queue::fake();
        Event::fake([CatalogChanged::class]);

        $initialStock = (int) $this->productA->stock;
        $order = $this->createOrderWithItem('paid', 3);
        $service = app(KoperasiOrderService::class);

        // Kasir tidak berhak membatalkan pesanan lunas
        try {
            $service->cancelByAdmin($this->kopA, $order, $this->kasirA, 'Stok rusak');
            $this->fail('Kasir tidak boleh membatalkan pesanan.');
        } catch (OrderingException) {
            // diharapkan
        }

        $service->cancelByAdmin($this->kopA, $order, $this->adminA, 'Kemasan rusak saat pengepakan');

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('Kemasan rusak saat pengepakan', $fresh->cancellation_reason);
        $this->assertSame($initialStock + 3, (int) $this->productA->fresh()->stock);

        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $this->productA->id,
            'type' => 'return_online',
            'quantity' => 3,
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ]);

        $this->assertSame(1, $this->customer->notifications()->count());
        $this->assertStringContainsString('1×24 jam', (string) $this->customer->notifications()->first()->data['message']);

        Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
        Event::assertDispatched(CatalogChanged::class);
        $this->assertTrue(AuditLog::query()->where('action', 'order_cancelled_by_admin')->exists());
    }

    public function test_admin_cannot_cancel_ready_or_completed_order(): void
    {
        $readyOrder = $this->createOrderWithItem('ready', 1);

        $this->expectException(OrderingException::class);

        app(KoperasiOrderService::class)->cancelByAdmin($this->kopA, $readyOrder, $this->adminA, 'Terlambat');
    }

    public function test_admin_responds_to_complaint_and_closes_it_while_kasir_is_denied(): void
    {
        Queue::fake();

        $complaint = new Complaint;
        $complaint->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_id' => null,
            'category' => 'product_quality',
            'subject' => 'Kemasan beras sobek',
            'description' => 'Plastik bagian bawah robek saat diambil.',
            'status' => 'open',
        ])->save();

        $service = app(ComplaintService::class);

        // Kasir tidak berhak menanggapi pengaduan
        try {
            $service->respond($this->kopA, $complaint, $this->kasirA, 'processing', 'Sedang dicek');
            $this->fail('Kasir tidak boleh menanggapi pengaduan.');
        } catch (OrderingException) {
            // diharapkan
        }

        // Admin memperbarui ke processing lalu resolved
        $service->respond($this->kopA, $complaint, $this->adminA, 'processing', 'Sedang kami verifikasi dengan gudang.');
        $this->assertSame('processing', $complaint->fresh()->status);

        $service->respond($this->kopA, $complaint->fresh(), $this->adminA, 'resolved', 'Beras sudah diganti dengan kemasan baru.');
        $this->assertSame('resolved', $complaint->fresh()->status);
        $this->assertSame('Beras sudah diganti dengan kemasan baru.', $complaint->fresh()->admin_response);

        $this->assertSame(2, $this->customer->notifications()->count());
        Queue::assertPushed(SendWhatsAppNotificationJob::class, 2);
        $this->assertSame(2, AuditLog::query()->where('action', 'complaint_responded')->count());

        // Pengaduan yang sudah resolved tidak dapat diubah lagi
        $this->expectException(OrderingException::class);
        $service->respond($this->kopA, $complaint->fresh(), $this->adminA, 'rejected', 'Ubah ulang');
    }
}
