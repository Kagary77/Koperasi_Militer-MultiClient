<?php

namespace App\Modules\Pos\Services;

use App\Models\CashierShift;
use App\Models\Koperasi;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMutation;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Catalog\Services\CartInvalidationService;
use App\Modules\Pos\Exceptions\PosException;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PosService
{
    public function __construct(
        private CashierShiftService $shifts,
        private CartInvalidationService $cartInvalidation,
        private AuditLogger $audit,
    ) {}

    /**
     * Memproses transaksi POS langsung (UC-14 langkah 4-5).
     *
     * @param  array<int, int>  $items  Map [product_id => quantity]
     *
     * @throws PosException
     */
    public function checkout(
        Koperasi $koperasi,
        User $cashier,
        array $items,
        string $paymentMethod,
        int $amountPaid = 0,
    ): Sale {
        if (! in_array($paymentMethod, ['cash', 'qris'], true)) {
            throw PosException::invalidPaymentMethod();
        }

        // Normalisasi & urutkan berdasarkan product_id untuk mencegah deadlock saat row locking.
        $normalized = [];
        foreach ($items as $productId => $qty) {
            $id = (int) $productId;
            $q = (int) $qty;
            if ($id > 0 && $q > 0) {
                $normalized[$id] = ($normalized[$id] ?? 0) + $q;
            }
        }

        if ($normalized === []) {
            throw PosException::emptyCart();
        }

        ksort($normalized);

        $shift = $this->shifts->activeShift($koperasi, $cashier);
        if (! $shift instanceof CashierShift) {
            throw PosException::shiftNotOpen();
        }

        return DB::transaction(function () use ($koperasi, $cashier, $shift, $normalized, $paymentMethod, $amountPaid): Sale {
            $productIds = array_keys($normalized);

            $query = Product::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->whereIn('id', $productIds)
                ->orderBy('id');

            // Jalur Tunai langsung mengunci baris stok; Jalur QRIS tidak menahan/memotong stok dahulu.
            if ($paymentMethod === 'cash') {
                $query->lockForUpdate();
            }

            $products = $query->get()->keyBy('id');

            $totalAmount = 0;
            $linePayloads = [];

            foreach ($normalized as $productId => $qty) {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if ($product === null || ! $product->is_available) {
                    throw PosException::productNotAvailable($product?->name ?? "#{$productId}");
                }

                if ((int) $product->stock < $qty) {
                    throw PosException::insufficientStock($product->name, (int) $product->stock);
                }

                $subtotal = (int) $product->price * $qty;
                $totalAmount += $subtotal;

                $linePayloads[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'subtotal' => $subtotal,
                ];
            }

            if ($paymentMethod === 'cash') {
                if ($amountPaid < $totalAmount) {
                    throw PosException::insufficientCashPayment($totalAmount);
                }
                $changeAmount = $amountPaid - $totalAmount;
                $status = 'paid';
                $recordedAmountPaid = $amountPaid;
            } else {
                // Jalur QRIS: status open, stok dipotong saat webhook settlement (UC-09).
                $changeAmount = 0;
                $status = 'open';
                $recordedAmountPaid = 0;
            }

            $sale = new Sale;
            $sale->forceFill([
                'koperasi_id' => $koperasi->id,
                'shift_id' => $shift->id,
                'user_id' => $cashier->id,
                'invoice_number' => $this->generateInvoiceNumber($koperasi->id),
                'total_amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'amount_paid' => $recordedAmountPaid,
                'change_amount' => $changeAmount,
                'status' => $status,
            ])->save();

            foreach ($linePayloads as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $qty = (int) $line['quantity'];

                $saleItem = new SaleItem;
                $saleItem->forceFill([
                    'koperasi_id' => $koperasi->id,
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price_snapshot' => (int) $product->price,
                    'cost_snapshot' => (int) $product->cost_price,
                    'quantity' => $qty,
                    'subtotal' => (int) $line['subtotal'],
                ])->save();

                if ($paymentMethod === 'cash') {
                    $newStock = (int) $product->stock - $qty;
                    $product->forceFill(['stock' => $newStock])->save();

                    $mutation = new StockMutation;
                    $mutation->forceFill([
                        'koperasi_id' => $koperasi->id,
                        'product_id' => $product->id,
                        'type' => 'out_pos',
                        'quantity' => -$qty,
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                        'notes' => "POS Tunai {$sale->invoice_number}",
                        'created_at' => now(),
                    ])->save();

                    if ($newStock === 0) {
                        $this->cartInvalidation->invalidateForProduct($product, 'stok produk habis');
                    }

                    event(new CatalogChanged((int) $koperasi->id, (int) $product->id));
                }
            }

            $this->audit->record(
                action: $paymentMethod === 'cash' ? 'pos_sale_paid_cash' : 'pos_sale_opened_qris',
                modelType: Sale::class,
                modelId: $sale->id,
                oldValues: null,
                newValues: [
                    'invoice_number' => $sale->invoice_number,
                    'payment_method' => $paymentMethod,
                    'total_amount' => $totalAmount,
                    'status' => $status,
                ],
                koperasiId: (int) $koperasi->id,
                userId: (int) $cashier->id,
            );

            return $sale->load('items');
        }, attempts: 3);
    }

    private function generateInvoiceNumber(int $koperasiId): string
    {
        return 'POS-'.$koperasiId.'-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }
}
