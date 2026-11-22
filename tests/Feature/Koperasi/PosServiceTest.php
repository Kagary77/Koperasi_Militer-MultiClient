<?php

namespace Tests\Feature\Koperasi;

use App\Models\AuditLog;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Product;
use App\Models\StockMutation;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Payments\Jobs\SendWhatsAppNotificationJob;
use App\Modules\Pos\Exceptions\PosException;
use App\Modules\Pos\Services\CashierShiftService;
use App\Modules\Pos\Services\PosService;
use App\Modules\Pos\Services\PosVoidService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PosServiceTest extends TestCase
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
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_pos_checkout_requires_open_shift(): void
    {
        $this->expectException(PosException::class);

        app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 1],
            paymentMethod: 'cash',
            amountPaid: 100000,
        );
    }

    public function test_cash_sale_deducts_stock_records_snapshots_and_closes_shift_with_recap(): void
    {
        Event::fake([CatalogChanged::class]);

        $shiftService = app(CashierShiftService::class);
        $shift = $shiftService->open($this->kopA, $this->kasirA, 200000);

        // Tidak boleh membuka shift kedua sebelum shift pertama ditutup
        try {
            $shiftService->open($this->kopA, $this->kasirA, 100000);
            $this->fail('Shift kedua seharusnya ditolak.');
        } catch (PosException) {
            // diharapkan
        }

        $initialStock = (int) $this->productA->stock;
        $totalExpected = (int) $this->productA->price * 2;

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 2],
            paymentMethod: 'cash',
            amountPaid: $totalExpected + 10000,
        );

        $this->assertSame('paid', $sale->status);
        $this->assertSame($totalExpected, (int) $sale->total_amount);
        $this->assertSame(10000, (int) $sale->change_amount);
        $this->assertSame($initialStock - 2, (int) $this->productA->fresh()->stock);

        $item = $sale->items->firstOrFail();
        $this->assertSame((int) $this->productA->price, (int) $item->price_snapshot);
        $this->assertSame((int) $this->productA->cost_price, (int) $item->cost_snapshot);

        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $this->productA->id,
            'type' => 'out_pos',
            'quantity' => -2,
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
        ]);

        Event::assertDispatched(CatalogChanged::class);

        // Tutup shift: expected = 200000 + totalExpected; actual lebih 5000 -> difference +5000
        $closed = $shiftService->close($this->kopA, $shift, $this->kasirA, 200000 + $totalExpected + 5000);
        $this->assertSame('closed', $closed->status);
        $this->assertSame(200000 + $totalExpected, (int) $closed->expected_cash);
        $this->assertSame(5000, (int) $closed->difference);
    }

    public function test_cash_sale_reaching_zero_stock_invalidates_customer_cart(): void
    {
        Queue::fake();
        Event::fake([CatalogChanged::class]);

        $customer = Customer::query()->where('email', 'budi@anggota.test')->firstOrFail();
        $cartItem = new CartItem;
        $cartItem->forceFill([
            'koperasi_id' => $this->kopA->id,
            'customer_id' => $customer->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ])->save();

        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $qtyAll = (int) $this->productA->stock;
        $total = (int) $this->productA->price * $qtyAll;

        app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => $qtyAll],
            paymentMethod: 'cash',
            amountPaid: $total,
        );

        $this->assertSame(0, (int) $this->productA->fresh()->stock);
        $this->assertSame(0, CartItem::query()->withoutGlobalScope('koperasi')->count());
        $this->assertSame(1, $customer->notifications()->count());
        Queue::assertPushed(SendWhatsAppNotificationJob::class);
    }

    public function test_qris_sale_opens_without_deducting_stock_before_settlement(): void
    {
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $initialStock = (int) $this->productA->stock;

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 3],
            paymentMethod: 'qris',
        );

        $this->assertSame('open', $sale->status);
        $this->assertSame('qris', $sale->payment_method);
        $this->assertSame($initialStock, (int) $this->productA->fresh()->stock, 'Stok POS QRIS baru dipotong saat webhook settlement.');
        $this->assertSame(0, StockMutation::query()->withoutGlobalScope('koperasi')->count());
    }

    public function test_void_approval_restores_stock_once_and_mark_refunded_does_not_alter_stock(): void
    {
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $initialStock = (int) $this->productA->stock;
        $total = (int) $this->productA->price * 2;

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 2],
            paymentMethod: 'cash',
            amountPaid: $total,
        );

        $this->assertSame($initialStock - 2, (int) $this->productA->fresh()->stock);

        $voidService = app(PosVoidService::class);

        // 1. Kasir ajukan void -> void_requested (stok belum kembali)
        $voidService->requestVoid($this->kopA, $sale, $this->kasirA, 'Salah input jumlah barang');
        $this->assertSame('void_requested', $sale->fresh()->status);
        $this->assertSame($initialStock - 2, (int) $this->productA->fresh()->stock);

        // Kasir tidak boleh menyetujui void sendiri
        try {
            $voidService->approveVoid($this->kopA, $sale->fresh(), $this->kasirA);
            $this->fail('Kasir tidak boleh menyetujui void.');
        } catch (PosException) {
            // diharapkan
        }

        // 2. Admin Koperasi menyetujui -> voided & stok kembali tepat satu kali
        $voidService->approveVoid($this->kopA, $sale->fresh(), $this->adminA, 'Disetujui sesuai struk');
        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame($initialStock, (int) $this->productA->fresh()->stock);

        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $this->productA->id,
            'type' => 'return_pos',
            'quantity' => 2,
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
        ]);

        // 3. Admin Koperasi menandai refund manual selesai -> refunded (stok tidak berubah lagi)
        $voidService->markRefunded($this->kopA, $sale->fresh(), $this->adminA, 'Tunai dikembalikan ke pembeli');
        $this->assertSame('refunded', $sale->fresh()->status);
        $this->assertSame($initialStock, (int) $this->productA->fresh()->stock);

        $this->assertTrue(AuditLog::query()->where('action', 'pos_void_approved')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'pos_sale_marked_refunded')->exists());
    }

    public function test_void_rejection_returns_sale_to_paid_without_changing_stock(): void
    {
        app(CashierShiftService::class)->open($this->kopA, $this->kasirA, 100000);
        $initialStock = (int) $this->productA->stock;
        $total = (int) $this->productA->price * 2;

        $sale = app(PosService::class)->checkout(
            koperasi: $this->kopA,
            cashier: $this->kasirA,
            items: [$this->productA->id => 2],
            paymentMethod: 'cash',
            amountPaid: $total,
        );

        $voidService = app(PosVoidService::class);
        $voidService->requestVoid($this->kopA, $sale, $this->kasirA, 'Batal beli');
        $voidService->rejectVoid($this->kopA, $sale->fresh(), $this->adminA, 'Barang sudah dibawa keluar');

        $this->assertSame('paid', $sale->fresh()->status);
        $this->assertSame($initialStock - 2, (int) $this->productA->fresh()->stock);
        $this->assertSame(0, StockMutation::query()->withoutGlobalScope('koperasi')->where('type', 'return_pos')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'pos_void_rejected')->exists());
    }
}
