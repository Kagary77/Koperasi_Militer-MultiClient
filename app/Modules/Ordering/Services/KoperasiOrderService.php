<?php

namespace App\Modules\Ordering\Services;

use App\Models\Customer;
use App\Models\Koperasi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMutation;
use App\Models\User;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Ordering\Exceptions\OrderingException;
use App\Modules\Ordering\Notifications\OrderStatusChangedNotification;
use App\Modules\Payments\Jobs\SendWhatsAppNotificationJob;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;

final class KoperasiOrderService
{
    /** @var array<string, string> */
    public const ALLOWED_NEXT_STATUS = [
        'paid' => 'processing',
        'processing' => 'ready',
        'ready' => 'completed',
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * Memajukan status pesanan online satu tahap sesuai state machine (UC-15).
     *
     * @throws OrderingException
     */
    public function advanceStatus(Koperasi $koperasi, Order $order, User $actor, string $nextStatus): Order
    {
        if (
            ! $actor->isActive()
            || ! in_array($actor->role, ['admin_koperasi', 'kasir'], true)
            || (int) $actor->koperasi_id !== (int) $koperasi->id
        ) {
            throw OrderingException::unauthorizedAction('Anda tidak berhak memproses pesanan pada koperasi ini.');
        }

        return DB::transaction(function () use ($koperasi, $order, $actor, $nextStatus): Order {
            $locked = Order::query()
                ->withoutGlobalScope('koperasi')
                ->with('customer')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            $current = (string) $locked->status;
            $expectedNext = self::ALLOWED_NEXT_STATUS[$current] ?? null;

            if ($expectedNext === null || $expectedNext !== $nextStatus) {
                throw OrderingException::invalidOrderTransition($current, $nextStatus);
            }

            $locked->forceFill(['status' => $nextStatus])->save();

            $this->audit->record(
                action: 'order_status_updated',
                modelType: Order::class,
                modelId: $locked->id,
                oldValues: ['status' => $current],
                newValues: ['status' => $nextStatus, 'order_number' => $locked->order_number],
                koperasiId: (int) $koperasi->id,
                userId: (int) $actor->id,
            );

            $this->notifyCustomer($locked, $koperasi, $this->buildStatusMessage($locked, $koperasi));

            return $locked;
        });
    }

    /**
     * Pembatalan pesanan lunas/diproses oleh Admin Koperasi (UC-15).
     * Mengembalikan stok secara atomik dan menginformasikan SOP Refund Luar Sistem (SLA 1x24 jam).
     *
     * @throws OrderingException
     */
    public function cancelByAdmin(Koperasi $koperasi, Order $order, User $admin, string $reason): Order
    {
        if (! $admin->isAdminKoperasi() || (int) $admin->koperasi_id !== (int) $koperasi->id) {
            throw OrderingException::unauthorizedAction('Hanya Admin Koperasi yang dapat membatalkan pesanan.');
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw OrderingException::cancellationReasonRequired();
        }

        return DB::transaction(function () use ($koperasi, $order, $admin, $cleanReason): Order {
            $locked = Order::query()
                ->withoutGlobalScope('koperasi')
                ->with('customer')
                ->where('koperasi_id', $koperasi->id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! in_array($locked->status, ['paid', 'processing'], true)) {
                throw OrderingException::orderCannotBeCancelled((string) $locked->status);
            }

            $oldStatus = (string) $locked->status;

            $items = OrderItem::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $koperasi->id)
                ->where('order_id', $locked->id)
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
                    'type' => 'return_online',
                    'quantity' => $qty,
                    'reference_type' => 'order',
                    'reference_id' => $locked->id,
                    'notes' => "Pembatalan pesanan {$locked->order_number}: {$cleanReason}",
                    'created_at' => now(),
                ])->save();

                event(new CatalogChanged((int) $koperasi->id, (int) $product->id));
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'cancellation_reason' => $cleanReason,
            ])->save();

            $this->audit->record(
                action: 'order_cancelled_by_admin',
                modelType: Order::class,
                modelId: $locked->id,
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => 'cancelled',
                    'cancellation_reason' => $cleanReason,
                    'order_number' => $locked->order_number,
                ],
                koperasiId: (int) $koperasi->id,
                userId: (int) $admin->id,
            );

            $message = "Pesanan {$locked->order_number} di {$koperasi->name} dibatalkan oleh Admin Koperasi. "
                ."Alasan: {$cleanReason}. Pengembalian dana diproses melalui SOP Refund Luar Sistem (SLA peninjauan 1×24 jam).";

            $this->notifyCustomer($locked, $koperasi, $message);

            return $locked;
        }, attempts: 3);
    }

    private function buildStatusMessage(Order $order, Koperasi $koperasi): string
    {
        return match ($order->status) {
            'processing' => "Pesanan {$order->order_number} di {$koperasi->name} sedang disiapkan oleh petugas koperasi.",
            'ready' => "Pesanan {$order->order_number} di {$koperasi->name} sudah SIAP DIAMBIL di loket koperasi.",
            'completed' => "Pesanan {$order->order_number} di {$koperasi->name} telah selesai diambil. Terima kasih.",
            default => "Status pesanan {$order->order_number} diperbarui menjadi {$order->status}.",
        };
    }

    private function notifyCustomer(Order $order, Koperasi $koperasi, string $message): void
    {
        $customer = $order->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        $customer->notify(new OrderStatusChangedNotification(
            koperasiId: (int) $koperasi->id,
            orderId: (int) $order->id,
            orderNumber: (string) $order->order_number,
            status: (string) $order->status,
            message: $message,
        ));

        if (is_string($customer->phone) && $customer->phone !== '') {
            SendWhatsAppNotificationJob::dispatch($customer->phone, $message, 'order_status');
        }
    }
}
