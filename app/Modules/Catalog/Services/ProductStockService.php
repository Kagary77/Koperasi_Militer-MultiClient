<?php

namespace App\Modules\Catalog\Services;

use App\Models\Product;
use App\Models\StockMutation;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Services\Shared\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ProductStockService
{
    public const ADJUSTMENT_TYPES = [
        'in' => 'Stok Masuk',
        'out' => 'Stok Keluar',
        'damaged' => 'Barang Rusak',
    ];

    public function __construct(
        private AuditLogger $audit,
        private CartInvalidationService $cartInvalidation,
    ) {}

    /**
     * Stock Adjustment manual (masuk, keluar, rusak) dengan pessimistic locking (UC-12).
     *
     * @throws DomainException
     */
    public function adjustStock(Product $product, string $adjustmentType, int $quantity, ?string $notes = null): Product
    {
        if (! array_key_exists($adjustmentType, self::ADJUSTMENT_TYPES)) {
            throw new DomainException('Jenis penyesuaian stok tidak valid.');
        }

        if ($quantity < 1) {
            throw new DomainException('Jumlah penyesuaian stok harus lebih dari nol.');
        }

        $delta = $adjustmentType === 'in' ? $quantity : -$quantity;
        $mutationType = $adjustmentType === 'in' ? 'in' : 'adjustment';
        $cleanNotes = trim((string) $notes);

        return DB::transaction(function () use ($product, $adjustmentType, $delta, $mutationType, $cleanNotes): Product {
            $locked = Product::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $product->koperasi_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $oldStock = (int) $locked->stock;
            $newStock = $oldStock + $delta;

            // UC-12 alur 5a: stok tidak boleh negatif.
            if ($newStock < 0) {
                throw new DomainException("Penyesuaian keluar melebihi stok tersedia ({$oldStock}).");
            }

            $locked->forceFill(['stock' => $newStock])->save();

            $mutation = new StockMutation;
            $mutation->forceFill([
                'koperasi_id' => $locked->koperasi_id,
                'product_id' => $locked->id,
                'type' => $mutationType,
                'quantity' => $delta,
                'reference_type' => 'adjustment',
                'reference_id' => null,
                'notes' => $cleanNotes !== '' ? "[{$adjustmentType}] {$cleanNotes}" : "[{$adjustmentType}]",
                'created_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'stock_adjusted',
                modelType: Product::class,
                modelId: $locked->id,
                oldValues: ['stock' => $oldStock],
                newValues: [
                    'stock' => $newStock,
                    'delta' => $delta,
                    'adjustment_type' => $adjustmentType,
                    'notes' => $cleanNotes,
                ],
                koperasiId: (int) $locked->koperasi_id,
            );

            // UC-12 alur 6a: stok menjadi nol → hapus dari cart_items & beri tahu pelanggan.
            if ($newStock === 0) {
                $this->cartInvalidation->invalidateForProduct($locked, 'stok produk habis');
            }

            event(new CatalogChanged((int) $locked->koperasi_id, (int) $locked->id));

            return $locked;
        });
    }

    /**
     * Sakelar satu ketukan untuk mengubah ketersediaan produk (UC-13).
     */
    public function toggleAvailability(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $locked = Product::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $product->koperasi_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $before = (bool) $locked->is_available;
            $after = ! $before;

            $locked->forceFill(['is_available' => $after])->save();

            $this->audit->record(
                action: 'product_availability_toggled',
                modelType: Product::class,
                modelId: $locked->id,
                oldValues: ['is_available' => $before],
                newValues: ['is_available' => $after],
                koperasiId: (int) $locked->koperasi_id,
            );

            // UC-13 alur 4a: produk dinonaktifkan → bersihkan keranjang pelanggan & kirim notifikasi.
            if (! $after) {
                $this->cartInvalidation->invalidateForProduct($locked, 'produk sedang tidak tersedia');
            }

            event(new CatalogChanged((int) $locked->koperasi_id, (int) $locked->id));

            return $locked;
        });
    }

    /**
     * Soft delete produk (UC-12 alur 3a) + invalidasi keranjang pelanggan terdampak.
     */
    public function softDelete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $product->koperasi_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $this->cartInvalidation->invalidateForProduct($locked, 'produk dihapus dari katalog');

            $locked->delete();

            $this->audit->record(
                action: 'product_deleted',
                modelType: Product::class,
                modelId: $locked->id,
                oldValues: ['name' => $locked->name, 'stock' => $locked->stock],
                newValues: null,
                koperasiId: (int) $locked->koperasi_id,
            );

            event(new CatalogChanged((int) $locked->koperasi_id, (int) $locked->id));
        });
    }
}
