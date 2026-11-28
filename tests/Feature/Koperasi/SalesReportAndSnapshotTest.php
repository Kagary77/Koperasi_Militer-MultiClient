<?php

namespace Tests\Feature\Koperasi;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Modules\Pos\Services\CashierShiftService;
use App\Modules\Pos\Services\PosService;
use App\Modules\Pos\Services\PosVoidService;
use App\Modules\Reporting\Services\KoperasiSalesReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportAndSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Koperasi $kopA;

    private Koperasi $kopB;

    private User $adminA;

    private User $kasirA;

    private User $kasirB;

    private Customer $customer;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $this->kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $this->kasirA = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();
        $this->kasirB = User::query()->where('email', 'kasir.lantamal5@koperasi.test')->firstOrFail();
        $this->customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();

        $this->productA = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->productB = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopB->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function seedMixedTransactions(): array
    {
        // 1. POS Tunai sah di Koperasi A (2 unit)
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $posPrice = (int) $this->productA->price;
        $posCost = (int) $this->productA->cost_price;

        $validPos = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 2],
            paymentMethod: 'cash',
            amountPaid: $posPrice * 2,
        );

        // 2. POS Tunai yang di-void di Koperasi A (harus dikecualikan dari omzet)
        $voidedPos = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 1],
            paymentMethod: 'cash',
            amountPaid: $posPrice,
        );
        app(PosVoidService::class)->requestVoid($this->kopA, $voidedPos, $this->kasirA, 'Batal');
        app(PosVoidService::class)->approveVoid($this->kopA, $voidedPos->fresh(), $this->adminA, 'OK');

        // 3. Online Order sah di Koperasi A (3 unit)
        $onlineTotal = $posPrice * 3;
        $validOrder = new Order;
        $validOrder->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-RPT-VALID',
            'status' => 'completed',
            'tax_rate_snapshot' => 0,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_amount' => $onlineTotal,
        ])->save();

        $orderItem = new OrderItem;
        $orderItem->forceFill([
            'koperasi_id' => $this->kopA->id,
            'order_id' => $validOrder->id,
            'product_id' => $this->productA->id,
            'product_name' => $this->productA->name,
            'price_snapshot' => $posPrice,
            'quantity' => 3,
            'subtotal' => $onlineTotal,
        ])->save();

        // 4. Online Order cancelled di Koperasi A (harus dikecualikan)
        $cancelledOrder = new Order;
        $cancelledOrder->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-RPT-CANCEL',
            'status' => 'cancelled',
            'total_amount' => 999999,
        ])->save();

        // 5. POS Tunai sah di Koperasi B (tidak boleh bocor ke laporan Koperasi A)
        app(CashierShiftService::class)->open($this->kopB, $this->kasirB, 50000);
        app(PosService::class)->checkout(
            koperasi: $this->kopB,
            cashier: $this->kasirB,
            items: [$this->productB->id => 1],
            paymentMethod: 'cash',
            amountPaid: (int) $this->productB->price,
        );

        return [
            'pos_sales' => $posPrice * 2,
            'pos_cogs' => $posCost * 2,
            'online_sales' => $onlineTotal,
            'invoice' => $validPos->invoice_number,
        ];
    }

    public function test_sales_report_aggregates_valid_pos_and_online_isolated_per_koperasi(): void
    {
        $expected = $this->seedMixedTransactions();
        $today = now((string) config('app.display_timezone', 'Asia/Jakarta'))->format('Y-m-d');

        $report = app(KoperasiSalesReportService::class)->build($this->kopA, $today, $today, 'all');

        $this->assertSame($expected['pos_sales'] + $expected['online_sales'], $report['summary']['total_sales']);
        $this->assertSame(2, $report['summary']['total_transactions']);
        $this->assertSame($expected['pos_sales'], $report['summary']['pos_sales']);
        $this->assertSame(1, $report['summary']['pos_transactions']);
        $this->assertSame($expected['pos_cogs'], $report['summary']['pos_cogs']);
        $this->assertSame($expected['pos_sales'] - $expected['pos_cogs'], $report['summary']['pos_gross_profit']);
        $this->assertSame($expected['online_sales'], $report['summary']['online_sales']);
        $this->assertSame(1, $report['summary']['online_transactions']);
        $this->assertSame(5, $report['top_products'][0]['quantity']);
        $this->assertCount(2, $report['transactions']);
    }

    public function test_daily_snapshot_command_is_idempotent_and_records_accurate_metrics(): void
    {
        $expected = $this->seedMixedTransactions();
        $today = now((string) config('app.display_timezone', 'Asia/Jakarta'))->format('Y-m-d');

        // Jalankan 2 kali berturut-turut untuk memverifikasi idempotensi
        $this->artisan('reports:snapshot-daily', ['--date' => $today])->assertSuccessful();
        $this->artisan('reports:snapshot-daily', ['--date' => $today])->assertSuccessful();

        $this->assertSame(1, ReportSnapshot::query()->where('koperasi_id', $this->kopA->id)->whereDate('date', $today)->count());

        $snapshotA = ReportSnapshot::query()
            ->where('koperasi_id', $this->kopA->id)
            ->whereDate('date', $today)
            ->firstOrFail();

        $this->assertSame($expected['pos_sales'] + $expected['online_sales'], (int) $snapshotA->total_sales);
        $this->assertSame(2, (int) $snapshotA->total_transactions);
        $this->assertSame(1, (int) $snapshotA->total_pos_orders);
        $this->assertSame(1, (int) $snapshotA->total_online_orders);
        $this->assertSame(1, (int) $snapshotA->total_active_customers);
    }

    public function test_export_csv_and_pdf_are_restricted_to_admin_koperasi_and_logged(): void
    {
        $expected = $this->seedMixedTransactions();
        $today = now((string) config('app.display_timezone', 'Asia/Jakarta'))->format('Y-m-d');

        // Kasir ditolak 403
        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.reports.export.csv', ['koperasi' => $this->kopA->slug, 'start' => $today, 'end' => $today]))
            ->assertForbidden();

        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.reports.export.pdf', ['koperasi' => $this->kopA->slug, 'start' => $today, 'end' => $today]))
            ->assertForbidden();

        // Admin Koperasi dapat mengunduh CSV UTF-8 BOM
        $csvResponse = $this->actingAs($this->adminA, 'web')
            ->get(route('koperasi.reports.export.csv', ['koperasi' => $this->kopA->slug, 'start' => $today, 'end' => $today]));

        $csvResponse->assertOk();
        $content = $csvResponse->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString($expected['invoice'], $content);
        $this->assertStringContainsString('ORD-RPT-VALID', $content);
        $this->assertStringNotContainsString('ORD-RPT-CANCEL', $content);

        // Admin Koperasi dapat membuka halaman cetak/PDF A4
        $this->actingAs($this->adminA, 'web')
            ->get(route('koperasi.reports.export.pdf', ['koperasi' => $this->kopA->slug, 'start' => $today, 'end' => $today]))
            ->assertOk()
            ->assertSee($this->kopA->name)
            ->assertSee('ORD-RPT-VALID');

        $this->assertTrue(AuditLog::query()->where('action', 'report_exported_csv')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'report_exported_pdf')->exists());
    }
}
