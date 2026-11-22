<?php

namespace App\Modules\Pos\Services;

use App\Models\Koperasi;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMutation;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Pos\Exceptions\PosException;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;

final class PosVoidService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Kasir mengajukan void atas transaksi POS berstatus paid (UC-14 alur 5a).
     *
     * @throws PosException
     */
    public function requestVoid(Koperasi $koperasi, Sale $sale, User $actor, string $reason): Sale
    {
        if (! in_array($actor->role, ['kasir', 'admin_koperasi'], true) || (int) $actor->koperasi_id !== (int) $koperasi->id) {
            throw PosException::unauthorizedAction('Anda tidak berhak mengajukan void pada koperasi ini.');
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw PosException::invalidVoidTransition('Alasan pengajuan void wajib diisi.');
        }

        return DB::transaction(function () use ($koperasi, $sale, $actor, $cleanReason): Sale {
            $locked = Sale::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($locked->status !== 'paid') {
                throw PosException::invalidVoidTransition('Void hanya dapat diajukan untuk transaksi berstatus lunas (paid).');
            }

            $locked->forceFill(['status' => 'void_requested'])->save();

            $this->audit->record(
                action: 'pos_void_requested',
                modelType: Sale::class,
                modelId: $locked->id,
                oldValues: ['status' => 'paid'],
                newValues: ['status' => 'void_requested', 'reason' => $cleanReason],
                koperasiId: (int) $koperasi->id,
                userId: (int) $actor->id,
            );

            return $locked;
        });
    }

    /**
     * Admin Koperasi menyetujui void: status -> voided dan stok dikembalikan tepat satu kali (UC-14 alur 5a).
     *
     * @throws PosException
     */
    public function approveVoid(Koperasi $koperasi, Sale $sale, User $admin, ?string $notes = null): Sale
    {
        if (! $admin->isAdminKoperasi() || (int) $admin->koperasi_id !== (int) $koperasi->id) {
            throw PosException::unauthorizedAction('Hanya Admin Koperasi yang dapat menyetujui void.');
        }

        return DB::transaction(function () use ($koperasi, $sale, $admin, $notes): Sale {
            $locked = Sale::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($locked->status !== 'void_requested') {
                throw PosException::invalidVoidTransition('Hanya transaksi berstatus void_requested yang dapat disetujui.');
            }

            $items = SaleItem::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->where('sale_id', $locked->id)
                ->orderBy('product_id')
                ->get();

            $productIds = $items->pluck('product_id')->unique()->values()->all();
            $products = Product::withTrashed()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                /** @var Product|null $product */
                $product = $products->get($item->product_id);
                if ($product === null) {
                    continue;
                }

                $qty = (int) $item->quantity;
                $product->forceFill(['stock' => (int) $product->stock + $qty])->save();

                $mutation = new StockMutation;
                $mutation->forceFill([
                    'koperasi_id' => $koperasi->id,
                    'product_id' => $product->id,
                    'type' => 'return_pos',
                    'quantity' => $qty,
                    'reference_type' => 'sale',
                    'reference_id' => $locked->id,
                    'notes' => trim("Void disetujui {$locked->invoice_number} ".(string) $notes),
                    'created_at' => now(),
                ])->save();

                event(new CatalogChanged((int) $koperasi->id, (int) $product->id));
            }

            $locked->forceFill(['status' => 'voided'])->save();

            $this->audit->record(
                action: 'pos_void_approved',
                modelType: Sale::class,
                modelId: $locked->id,
                oldValues: ['status' => 'void_requested'],
                newValues: ['status' => 'voided', 'notes' => trim((string) $notes)],
                koperasiId: (int) $koperasi->id,
                userId: (int) $admin->id,
            );

            return $locked;
        }, attempts: 3);
    }

    /**
     * Admin Koperasi menolak void: status kembali ke paid dan stok tidak berubah (UC-14 alur 5a).
     *
     * @throws PosException
     */
    public function rejectVoid(Koperasi $koperasi, Sale $sale, User $admin, string $reason): Sale
    {
        if (! $admin->isAdminKoperasi() || (int) $admin->koperasi_id !== (int) $koperasi->id) {
            throw PosException::unauthorizedAction('Hanya Admin Koperasi yang dapat menolak void.');
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw PosException::invalidVoidTransition('Alasan penolakan void wajib diisi.');
        }

        return DB::transaction(function () use ($koperasi, $sale, $admin, $cleanReason): Sale {
            $locked = Sale::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($locked->status !== 'void_requested') {
                throw PosException::invalidVoidTransition('Hanya transaksi berstatus void_requested yang dapat ditolak.');
            }

            $locked->forceFill(['status' => 'paid'])->save();

            $this->audit->record(
                action: 'pos_void_rejected',
                modelType: Sale::class,
                modelId: $locked->id,
                oldValues: ['status' => 'void_requested'],
                newValues: ['status' => 'paid', 'reason' => $cleanReason],
                koperasiId: (int) $koperasi->id,
                userId: (int) $admin->id,
            );

            return $locked;
        });
    }

    /**
     * Penandaan administratif bahwa refund manual di luar sistem telah selesai (voided -> refunded).
     * Stok tidak diubah lagi karena sudah dikembalikan saat approveVoid().
     *
     * @throws PosException
     */
    public function markRefunded(Koperasi $koperasi, Sale $sale, User $admin, string $notes): Sale
    {
        if (! $admin->isAdminKoperasi() || (int) $admin->koperasi_id !== (int) $koperasi->id) {
            throw PosException::unauthorizedAction('Hanya Admin Koperasi yang dapat menandai status refunded.');
        }

        $cleanNotes = trim($notes);
        if ($cleanNotes === '') {
            throw PosException::invalidVoidTransition('Catatan bukti refund manual wajib diisi.');
        }

        return DB::transaction(function () use ($koperasi, $sale, $admin, $cleanNotes): Sale {
            $locked = Sale::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($locked->status !== 'voided') {
                throw PosException::invalidVoidTransition('Hanya transaksi berstatus voided yang dapat ditandai refunded.');
            }

            $locked->forceFill(['status' => 'refunded'])->save();

            $this->audit->record(
                action: 'pos_sale_marked_refunded',
                modelType: Sale::class,
                modelId: $locked->id,
                oldValues: ['status' => 'voided'],
                newValues: ['status' => 'refunded', 'notes' => $cleanNotes],
                koperasiId: (int) $koperasi->id,
                userId: (int) $admin->id,
            );

            return $locked;
        });
    }
}
