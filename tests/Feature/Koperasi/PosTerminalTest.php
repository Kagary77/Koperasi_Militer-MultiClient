<?php

namespace Tests\Feature\Koperasi;

use App\Models\Koperasi;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Modules\Pos\Services\CashierShiftService;
use App\Modules\Pos\Services\PosService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PosTerminalTest extends TestCase
{
    use RefreshDatabase;

    private Koperasi $kopA;

    private User $adminA;

    private User $kasirA;

    private Product $productA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->kopA = Koperasi::query()->where('slug', 'primkopad-yonif-501')->firstOrFail();
        $this->adminA = User::query()->where('email', 'admin.yonif501@koperasi.test')->firstOrFail();
        $this->kasirA = User::query()->where('email', 'kasir.yonif501@koperasi.test')->firstOrFail();
        $this->productA = Product::query()->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $this->kopA->id)
            ->whereNotNull('barcode')
            ->firstOrFail();
    }

    public function test_pos_page_and_void_approvals_enforce_role_access(): void
    {
        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.pos.index', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('pos::terminal');

        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.pos.voids', ['koperasi' => $this->kopA->slug]))
            ->assertForbidden();

        $this->actingAs($this->adminA, 'web')
            ->get(route('koperasi.pos.voids', ['koperasi' => $this->kopA->slug]))
            ->assertOk()
            ->assertSeeLivewire('pos::void-approvals');
    }

    public function test_kasir_opens_shift_scans_barcode_completes_cash_sale_prints_receipt_and_closes_shift(): void
    {
        $initialStock = (int) $this->productA->stock;

        Livewire::actingAs($this->kasirA, 'web')
            ->test('pos::terminal', ['koperasiId' => $this->kopA->id, 'koperasiSlug' => $this->kopA->slug])
            ->set('openingBalance', 150000)
            ->call('openShift')
            ->assertHasNoErrors()
            ->set('barcodeInput', (string) $this->productA->barcode)
            ->call('scanBarcode')
            ->assertHasNoErrors()
            ->call('checkout')
            ->assertHasNoErrors();

        $sale = Sale::query()->withoutGlobalScope('koperasi')->latest('id')->firstOrFail();
        $this->assertSame('paid', $sale->status);
        $this->assertSame($initialStock - 1, (int) $this->productA->fresh()->stock);

        // Struk termal dapat dibuka oleh kasir koperasi terkait
        $this->actingAs($this->kasirA, 'web')
            ->get(route('koperasi.pos.receipt', ['koperasi' => $this->kopA->slug, 'sale' => $sale->id]))
            ->assertOk()
            ->assertSee($sale->invoice_number)
            ->assertSee($this->productA->name);

        // Tutup shift & verifikasi rekap kas
        $expectedCash = 150000 + (int) $sale->total_amount;
        Livewire::actingAs($this->kasirA, 'web')
            ->test('pos::terminal', ['koperasiId' => $this->kopA->id, 'koperasiSlug' => $this->kopA->slug])
            ->set('actualCash', $expectedCash)
            ->call('closeShift')
            ->assertHasNoErrors()
            ->assertSee('Rekap Penutupan Shift');
    }

    public function test_kasir_requests_void_and_admin_approves_then_marks_refunded_via_ui(): void
    {
        $initialStock = (int) $this->productA->stock;
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 2],
            paymentMethod: 'cash',
            amountPaid: (int) $this->productA->price * 2,
        );

        // Kasir ajukan void melalui terminal POS
        Livewire::actingAs($this->kasirA, 'web')
            ->test('pos::terminal', ['koperasiId' => $this->kopA->id, 'koperasiSlug' => $this->kopA->slug])
            ->call('startVoid', $sale->id)
            ->set('voidReason', 'Salah ketik qty')
            ->call('submitVoidRequest')
            ->assertHasNoErrors();

        $this->assertSame('void_requested', $sale->fresh()->status);

        // Admin menyetujui void -> stok kembali, lalu menandai refunded
        Livewire::actingAs($this->adminA, 'web')
            ->test('pos::void-approvals', ['koperasiId' => $this->kopA->id])
            ->assertSee($sale->invoice_number)
            ->set("notes.{$sale->id}", 'Disetujui admin')
            ->call('approve', $sale->id)
            ->assertHasNoErrors();

        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame($initialStock, (int) $this->productA->fresh()->stock);

        Livewire::actingAs($this->adminA, 'web')
            ->test('pos::void-approvals', ['koperasiId' => $this->kopA->id])
            ->set("notes.{$sale->id}", 'Tunai dikembalikan di kasir')
            ->call('markRefunded', $sale->id)
            ->assertHasNoErrors();

        $this->assertSame('refunded', $sale->fresh()->status);
        $this->assertSame($initialStock, (int) $this->productA->fresh()->stock);
    }

    public function test_cross_koperasi_receipt_access_is_blocked_by_scoped_binding(): void
    {
        $kopB = Koperasi::query()->where('slug', 'primkopal-lantamal-v')->firstOrFail();
        $kasirB = User::query()->where('email', 'kasir.lantamal5@koperasi.test')->firstOrFail();

        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $saleA = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 1],
            paymentMethod: 'cash',
            amountPaid: (int) $this->productA->price,
        );

        // Kasir Koperasi B mencoba membuka struk milik Koperasi A lewat URL Koperasi B -> 404
        $this->actingAs($kasirB, 'web')
            ->get(route('koperasi.pos.receipt', ['koperasi' => $kopB->slug, 'sale' => $saleA->id]))
            ->assertNotFound();
    }
}
