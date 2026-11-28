<?php

namespace Tests\Feature\Koperasi;

use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OrderAndComplaintUiTest extends TestCase
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
            ->firstOrFail();
    }

    private function createPaidOrder(int $qty = 2): Order
    {
        $subtotal = (int) $this->productA->price * $qty;

        $order = new Order;
        $order->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-UI-'.rand(1000, 9999),
            'status' => 'paid',
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

    public function test_routes_enforce_role_access_for_orders_and_complaints(): void
    {
        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.orders.index', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('ordering::order-manager');

        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.complaints.index', ['koperasi' => $this->kopA->slug]))
            ->assertForbidden();

        $this->actingAs($this->adminA, 'web')
            ->get(route('koperasi.complaints.index', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('ordering::complaint-manager');
    }

    public function test_kasir_advances_order_status_via_ui_and_cannot_cancel(): void
    {
        Queue::fake();
        $order = $this->createPaidOrder();

        Livewire::actingAs($this->kasirA, 'web')
            ->test('ordering::order-manager', ['koperasiId' => $this->kopA->id])
            ->assertSee($order->order_number)
            ->call('advance', $order->id, 'processing')
            ->assertHasNoErrors()
            ->call('advance', $order->id, 'ready')
            ->assertHasNoErrors()
            ->call('advance', $order->id, 'completed')
            ->assertHasNoErrors();

        $this->assertSame('completed', $order->fresh()->status);

        // Kasir ditolak 403 saat mencoba memicu pembatalan pesanan
        $otherOrder = $this->createPaidOrder();
        Livewire::actingAs($this->kasirA, 'web')
            ->test('ordering::order-manager', ['koperasiId' => $this->kopA->id])
            ->call('startCancel', $otherOrder->id)
            ->assertForbidden();
    }

    public function test_admin_cancels_order_and_responds_to_complaint_via_ui(): void
    {
        Queue::fake();
        $initialStock = (int) $this->productA->stock;
        $order = $this->createPaidOrder(2);

        Livewire::actingAs($this->adminA, 'web')
            ->test('ordering::order-manager', ['koperasiId' => $this->kopA->id])
            ->call('startCancel', $order->id)
            ->set('cancellationReason', 'Barang rusak di rak')
            ->call('confirmCancel')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame($initialStock + 2, (int) $this->productA->fresh()->stock);

        $complaint = new Complaint;
        $complaint->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_id' => $order->id,
            'category' => 'service',
            'subject' => 'Antrean loket pengambilan',
            'description' => 'Mohon tambah petugas saat jam istirahat.',
            'status' => 'open',
        ])->save();

        Livewire::actingAs($this->adminA, 'web')
            ->test('ordering::complaint-manager', ['koperasiId' => $this->kopA->id])
            ->assertSee('Antrean loket pengambilan')
            ->set("targetStatuses.{$complaint->id}", 'resolved')
            ->set("responses.{$complaint->id}", 'Terima kasih, kami sudah menambah loket piket.')
            ->call('submitResponse', $complaint->id)
            ->assertHasNoErrors();

        $this->assertSame('resolved', $complaint->fresh()->status);
        $this->assertSame('Terima kasih, kami sudah menambah loket piket.', $complaint->fresh()->admin_response);
    }
}
