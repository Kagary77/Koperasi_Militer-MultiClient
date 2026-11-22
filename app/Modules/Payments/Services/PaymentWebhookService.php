<?php

namespace App\Modules\Payments\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMutation;
use App\Modules\Catalog\Events\CatalogChanged;
use App\Modules\Catalog\Services\CartInvalidationService;
use App\Modules\Payments\Events\PaymentSettled;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\DB;

final class PaymentWebhookService
{
    public function __construct(
        private WebhookSignatureVerifier $verifier,
        private CartInvalidationService $cartInvalidation,
        private AuditLogger $audit,
    ) {}

    /**
     * Memproses payload webhook QRIS secara idempoten dengan verifikasi HMAC-SHA256.
     *
     * @param  array<string, mixed>  $payload
     * @return array{http_status: int, code: string, message: string}
     */
    public function handleWebhook(string $rawBody, ?string $signature, array $payload): array
    {
        $providerEventId = trim((string) ($payload['event_id'] ?? ''));
        $paymentReference = trim((string) ($payload['payment_reference'] ?? ''));
        $incomingStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        $incomingAmount = isset($payload['amount']) ? (int) $payload['amount'] : null;

        if ($providerEventId === '' || $paymentReference === '' || ! in_array($incomingStatus, ['settled', 'paid', 'expired', 'denied'], true)) {
            return ['http_status' => 422, 'code' => 'invalid_payload', 'message' => 'Payload webhook tidak lengkap atau status tidak dikenal.'];
        }

        $payment = Payment::query()
            ->with('koperasi')
            ->where('payment_reference', $paymentReference)
            ->first();

        if (! $payment instanceof Payment || $payment->koperasi === null) {
            return ['http_status' => 404, 'code' => 'payment_not_found', 'message' => 'Referensi pembayaran tidak ditemukan.'];
        }

        // 1. Verifikasi HMAC-SHA256 atas raw request body menggunakan webhook_secret koperasi terkait.
        $signatureValid = $this->verifier->verify($rawBody, $signature, $payment->koperasi);

        if (! $signatureValid) {
            $this->recordEventIfNotExists($payment, $providerEventId, $payload, false);

            $this->audit->record(
                action: 'webhook_invalid_signature',
                modelType: Payment::class,
                modelId: $payment->id,
                oldValues: null,
                newValues: ['provider_event_id' => $providerEventId, 'payment_reference' => $paymentReference],
                koperasiId: (int) $payment->koperasi_id,
                actorType: 'webhook',
            );

            return ['http_status' => 401, 'code' => 'invalid_signature', 'message' => 'Tanda tangan webhook tidak valid.'];
        }

        // 2. Idempotensi ketat berbasis provider_event_id (UC-09).
        if (PaymentEvent::query()->where('provider_event_id', $providerEventId)->exists()) {
            return ['http_status' => 200, 'code' => 'duplicate_ignored', 'message' => 'Event sudah pernah diproses.'];
        }

        // 3. Validasi kesesuaian nominal transaksi.
        if ($incomingAmount === null || $incomingAmount !== (int) $payment->amount) {
            $this->recordEventIfNotExists($payment, $providerEventId, $payload, true);

            $this->audit->record(
                action: 'payment_amount_mismatch',
                modelType: Payment::class,
                modelId: $payment->id,
                oldValues: ['expected_amount' => (int) $payment->amount],
                newValues: ['incoming_amount' => $incomingAmount, 'provider_event_id' => $providerEventId],
                koperasiId: (int) $payment->koperasi_id,
                actorType: 'webhook',
            );

            return ['http_status' => 422, 'code' => 'amount_mismatch', 'message' => 'Nominal webhook tidak sesuai dengan tagihan.'];
        }

        $normalizedStatus = $incomingStatus === 'paid' ? 'settled' : $incomingStatus;

        return DB::transaction(function () use ($payment, $providerEventId, $payload, $normalizedStatus): array {
            $lockedPayment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            // Re-check idempotensi di dalam transaksi untuk mencegah race condition webhook paralel.
            if (PaymentEvent::query()->where('provider_event_id', $providerEventId)->exists()) {
                return ['http_status' => 200, 'code' => 'duplicate_ignored', 'message' => 'Event sudah pernah diproses.'];
            }

            $this->recordEventIfNotExists($lockedPayment, $providerEventId, $payload, true);

            if ($normalizedStatus === 'settled') {
                return $this->settleLockedPayment($lockedPayment, 'webhook');
            }

            $this->failOrExpireLockedPayment(
                payment: $lockedPayment,
                targetPaymentStatus: $normalizedStatus,
                reason: $normalizedStatus === 'denied' ? 'Pembayaran ditolak oleh penyedia pembayaran.' : 'Batas waktu pembayaran QRIS telah berakhir.',
                actorType: 'webhook',
            );

            return ['http_status' => 200, 'code' => 'processed_'.$normalizedStatus, 'message' => 'Status pembayaran diperbarui.'];
        }, attempts: 3);
    }

    /**
     * Membatalkan satu tagihan pending yang sudah melewati batas waktu 15 menit (dipanggil Scheduler).
     */
    public function expirePendingPayment(int $paymentId): bool
    {
        return DB::transaction(function () use ($paymentId): bool {
            $locked = Payment::query()->lockForUpdate()->find($paymentId);

            if (! $locked instanceof Payment || $locked->status !== 'pending' || ! $locked->isExpired()) {
                return false;
            }

            $this->failOrExpireLockedPayment(
                payment: $locked,
                targetPaymentStatus: 'expired',
                reason: 'Pembayaran QRIS kedaluwarsa otomatis setelah 15 menit.',
                actorType: 'system',
            );

            return true;
        }, attempts: 3);
    }

    /**
     * @return array{http_status: int, code: string, message: string}
     */
    private function settleLockedPayment(Payment $payment, string $actorType): array
    {
        if ($payment->status === 'settled') {
            return ['http_status' => 200, 'code' => 'already_settled', 'message' => 'Pembayaran sudah lunas sebelumnya.'];
        }

        if ($payment->order_id !== null) {
            return $this->settleOnlineOrder($payment, $actorType);
        }

        if ($payment->sale_id !== null) {
            return $this->settlePosSale($payment, $actorType);
        }

        return ['http_status' => 422, 'code' => 'invalid_payment_target', 'message' => 'Target transaksi pembayaran tidak valid.'];
    }

    /**
     * @return array{http_status: int, code: string, message: string}
     */
    private function settleOnlineOrder(Payment $payment, string $actorType): array
    {
        $order = Order::query()
            ->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $payment->koperasi_id)
            ->lockForUpdate()
            ->findOrFail($payment->order_id);

        $payment->forceFill([
            'status' => 'settled',
            'paid_at' => now(),
        ])->save();

        if ($order->status === 'pending_payment') {
            $order->forceFill(['status' => 'paid'])->save();

            $this->audit->record(
                action: 'order_payment_settled',
                modelType: Order::class,
                modelId: $order->id,
                oldValues: ['status' => 'pending_payment'],
                newValues: ['status' => 'paid', 'payment_reference' => $payment->payment_reference],
                koperasiId: (int) $payment->koperasi_id,
                actorType: $actorType,
            );

            event(new PaymentSettled($payment));

            return ['http_status' => 200, 'code' => 'settled', 'message' => 'Pembayaran pesanan online berhasil dikonfirmasi.'];
        }

        // Late settlement setelah order sudah terlanjur dibatalkan -> catat untuk SOP Refund Luar Sistem 1x24 jam.
        $this->audit->record(
            action: 'order_late_settlement_requires_refund',
            modelType: Order::class,
            modelId: $order->id,
            oldValues: ['status' => $order->status],
            newValues: ['payment_status' => 'settled', 'payment_reference' => $payment->payment_reference],
            koperasiId: (int) $payment->koperasi_id,
            actorType: $actorType,
        );

        return ['http_status' => 200, 'code' => 'late_settlement_logged', 'message' => 'Pembayaran diterima pada pesanan yang sudah dibatalkan; dicatat untuk peninjauan refund.'];
    }

    /**
     * @return array{http_status: int, code: string, message: string}
     */
    private function settlePosSale(Payment $payment, string $actorType): array
    {
        $sale = Sale::query()
            ->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $payment->koperasi_id)
            ->lockForUpdate()
            ->findOrFail($payment->sale_id);

        $payment->forceFill([
            'status' => 'settled',
            'paid_at' => now(),
        ])->save();

        if ($sale->status !== 'open') {
            $this->audit->record(
                action: 'pos_qris_late_settlement_requires_refund',
                modelType: Sale::class,
                modelId: $sale->id,
                oldValues: ['status' => $sale->status],
                newValues: ['payment_status' => 'settled', 'payment_reference' => $payment->payment_reference],
                koperasiId: (int) $payment->koperasi_id,
                actorType: $actorType,
            );

            return ['http_status' => 200, 'code' => 'late_settlement_logged', 'message' => 'Pembayaran POS diterima pada transaksi yang tidak lagi terbuka; dicatat untuk refund manual.'];
        }

        $items = SaleItem::query()
            ->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $payment->koperasi_id)
            ->where('sale_id', $sale->id)
            ->orderBy('product_id')
            ->get();

        $productIds = $items->pluck('product_id')->unique()->values()->all();
        $products = Product::query()
            ->withoutGlobalScope('koperasi')
            ->where('koperasi_id', $payment->koperasi_id)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Cek ketersediaan stok seluruh item terlebih dahulu (UC-09 edge case 4c).
        $shortages = [];
        foreach ($items as $item) {
            /** @var Product|null $product */
            $product = $products->get($item->product_id);
            if ($product === null || (int) $product->stock < (int) $item->quantity) {
                $shortages[] = [
                    'product_id' => $item->product_id,
                    'needed' => (int) $item->quantity,
                    'available' => $product ? (int) $product->stock : 0,
                ];
            }
        }

        if ($shortages !== []) {
            // Edge case 4c: stok tidak cukup saat settlement -> jangan kurangi stok menjadi negatif; tandai voided untuk refund manual 1x24 jam.
            $sale->forceFill(['status' => 'voided'])->save();

            $this->audit->record(
                action: 'pos_qris_settled_insufficient_stock',
                modelType: Sale::class,
                modelId: $sale->id,
                oldValues: ['status' => 'open'],
                newValues: [
                    'status' => 'voided',
                    'payment_reference' => $payment->payment_reference,
                    'shortages' => $shortages,
                ],
                koperasiId: (int) $payment->koperasi_id,
                actorType: $actorType,
            );

            return ['http_status' => 200, 'code' => 'insufficient_stock_voided', 'message' => 'Stok tidak mencukupi saat settlement; transaksi ditandai voided untuk refund manual.'];
        }

        foreach ($items as $item) {
            /** @var Product $product */
            $product = $products->get($item->product_id);
            $qty = (int) $item->quantity;
            $newStock = (int) $product->stock - $qty;

            $product->forceFill(['stock' => $newStock])->save();

            $mutation = new StockMutation;
            $mutation->forceFill([
                'koperasi_id' => $payment->koperasi_id,
                'product_id' => $product->id,
                'type' => 'out_pos',
                'quantity' => -$qty,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'notes' => "POS QRIS {$sale->invoice_number}",
                'created_at' => now(),
            ])->save();

            if ($newStock === 0) {
                $this->cartInvalidation->invalidateForProduct($product, 'stok produk habis');
            }

            event(new CatalogChanged((int) $payment->koperasi_id, (int) $product->id));
        }

        $sale->forceFill([
            'status' => 'paid',
            'amount_paid' => (int) $payment->amount,
            'change_amount' => 0,
        ])->save();

        $this->audit->record(
            action: 'pos_qris_settled',
            modelType: Sale::class,
            modelId: $sale->id,
            oldValues: ['status' => 'open'],
            newValues: ['status' => 'paid', 'payment_reference' => $payment->payment_reference],
            koperasiId: (int) $payment->koperasi_id,
            actorType: $actorType,
        );

        event(new PaymentSettled($payment));

        return ['http_status' => 200, 'code' => 'settled', 'message' => 'Pembayaran POS QRIS berhasil dikonfirmasi dan stok telah dipotong.'];
    }

    private function failOrExpireLockedPayment(
        Payment $payment,
        string $targetPaymentStatus,
        string $reason,
        string $actorType,
    ): void {
        if (in_array($payment->status, ['settled', 'expired', 'denied'], true)) {
            return;
        }

        $payment->forceFill(['status' => $targetPaymentStatus])->save();

        if ($payment->order_id !== null) {
            $order = Order::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $payment->koperasi_id)
                ->lockForUpdate()
                ->find($payment->order_id);

            if ($order instanceof Order && $order->status === 'pending_payment') {
                $orderItems = OrderItem::query()
                    ->withoutGlobalScope('koperasi')
                    ->where('koperasi_id', $payment->koperasi_id)
                    ->where('order_id', $order->id)
                    ->orderBy('product_id')
                    ->get();

                $productIds = $orderItems->pluck('product_id')->unique()->values()->all();
                $products = Product::withTrashed()
                    ->withoutGlobalScope('koperasi')
                    ->where('koperasi_id', $payment->koperasi_id)
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($orderItems as $item) {
                    /** @var Product|null $product */
                    $product = $products->get($item->product_id);
                    if ($product === null) {
                        continue;
                    }

                    $qty = (int) $item->quantity;
                    $product->forceFill(['stock' => (int) $product->stock + $qty])->save();

                    $mutation = new StockMutation;
                    $mutation->forceFill([
                        'koperasi_id' => $payment->koperasi_id,
                        'product_id' => $product->id,
                        'type' => 'return_online',
                        'quantity' => $qty,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'notes' => "Pengembalian stok {$order->order_number} ({$targetPaymentStatus})",
                        'created_at' => now(),
                    ])->save();

                    event(new CatalogChanged((int) $payment->koperasi_id, (int) $product->id));
                }

                $order->forceFill([
                    'status' => 'cancelled',
                    'cancellation_reason' => $reason,
                ])->save();

                $this->audit->record(
                    action: 'order_cancelled_payment_'.$targetPaymentStatus,
                    modelType: Order::class,
                    modelId: $order->id,
                    oldValues: ['status' => 'pending_payment'],
                    newValues: ['status' => 'cancelled', 'reason' => $reason],
                    koperasiId: (int) $payment->koperasi_id,
                    actorType: $actorType,
                );
            }

            return;
        }

        if ($payment->sale_id !== null) {
            $sale = Sale::query()
                ->withoutGlobalScope('koperasi')
                ->where('koperasi_id', $payment->koperasi_id)
                ->lockForUpdate()
                ->find($payment->sale_id);

            if ($sale instanceof Sale && $sale->status === 'open') {
                $sale->forceFill(['status' => 'cancelled'])->save();

                $this->audit->record(
                    action: 'pos_sale_cancelled_payment_'.$targetPaymentStatus,
                    modelType: Sale::class,
                    modelId: $sale->id,
                    oldValues: ['status' => 'open'],
                    newValues: ['status' => 'cancelled', 'reason' => $reason],
                    koperasiId: (int) $payment->koperasi_id,
                    actorType: $actorType,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEventIfNotExists(
        Payment $payment,
        string $providerEventId,
        array $payload,
        bool $signatureValid,
    ): void {
        if (PaymentEvent::query()->where('provider_event_id', $providerEventId)->exists()) {
            return;
        }

        $event = new PaymentEvent;
        $event->forceFill([
            'payment_id' => $payment->id,
            'provider_event_id' => $providerEventId,
            'payload' => $payload,
            'signature_valid' => $signatureValid,
            'created_at' => now(),
        ])->save();
    }
}
