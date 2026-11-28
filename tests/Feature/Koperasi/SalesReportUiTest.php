<?php

namespace Tests\Feature\Koperasi;

use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Modules\Pos\Services\CashierShiftService;
use App\Modules\Pos\Services\PosService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesReportUiTest extends TestCase
{
    use RefreshDatabase;

    private Koperasi $kopA;

    private Koperasi $kopB;

    private User $adminA;

    private User $kasirA;

    private Customer $customer;

    private Product $productA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $this->kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $this->kasirA = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();
        $this->customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();
        $this->productA = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_reports_page_is_accessible_by_admin_koperasi_and_forbidden_for_kasir(): void
    {
        $this->actingAs($this->adminA, 'web')
            ->get(route('koperasi.reports.index', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('reporting::sales-report')
            ->assertSee('Ekspor CSV (Excel)')
            ->assertSee('Cetak / PDF');

        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.reports.index', ['koperasi' => $this->kopA->slug]))
            ->assertForbidden();
    }

    public function test_admin_filters_sales_report_by_channel_and_presets(): void
    {
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $price = (int) $this->productA->price;

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 1],
            paymentMethod: 'cash',
            amountPaid: $price,
        );

        $order = new Order;
        $order->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-UI-RPT-01',
            'status' => 'completed',
            'total_amount' => $price * 2,
        ])->save();

        $item = new OrderItem;
        $item->forceFill([
            'koperasi_id' => $this->kopA->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'product_name' => $this->productA->name,
            'price_snapshot' => $price,
            'quantity' => 2,
            'subtotal' => $price * 2,
        ])->save();

        Livewire::actingAs($this->adminA, 'web')
            ->test('reporting::sales-report', ['koperasiId' => $this->kopA->id, 'koperasiSlug' => $this->kopA->slug])
            ->call('applyPreset', 'today')
            ->assertSee($sale->invoice_number)
            ->assertSee('ORD-UI-RPT-01')
            ->set('channel', 'pos')
            ->assertSee($sale->invoice_number)
            ->assertDontSee('ORD-UI-RPT-01')
            ->set('channel', 'online')
            ->assertSee('ORD-UI-RPT-01')
            ->assertDontSee($sale->invoice_number);
    }

    public function test_admin_cannot_access_other_koperasi_sales_report_component(): void
    {
        Livewire::actingAs($this->adminA, 'web')
            ->test('reporting::sales-report', ['koperasiId' => $this->kopB->id, 'koperasiSlug' => $this->kopB->slug])
            ->assertForbidden();
    }
}
