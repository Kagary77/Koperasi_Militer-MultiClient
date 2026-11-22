<?php

namespace App\Modules\Payments\Services;

use App\Models\Koperasi;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Sale;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\PaymentChargeRequest;
use App\Modules\Payments\Data\QrisCharge;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Services\Shared\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class PaymentService
{
    public const MAX_GATEWAY_TRIES = 3;

    public const MAX_EXPIRY_SECONDS = 900; // Maksimal 15 menit (SRS v3.2 FR-PAY-01)

    public function __construct(
        private PaymentGateway $gateway,
        private AuditLogger $audit,
    ) {}

    /**
     * Membangkitkan atau mengembalikan QRIS dinamis aktif untuk Order Online (UC-08).
     *
     * @throws PaymentException
     */
    public function initiateForOrder(Order $order): Payment
    {
        if (! $order->isPendingPayment()) {
            throw PaymentException::orderNotPayable();
        }

        $order->loadMissing('koperasi');
        $koperasi = $order->koperasi;
        $this->assertKoperasiGatewayConfigured($koperasi);

        return Cache::lock('payment-init:order:'.$order->id, 15)->block(10, function () use ($order, $koperasi): Payment {
            $existing = Payment::query()
                ->where('order_id', $order->id)
                ->latest('id')
                ->first();

            if ($existing !== null && $existing->status === 'pending' && ! $existing->isExpired()) {
                return $existing;
            }

            if ($existing !== null && $existing->status === 'pending' && $existing->isExpired()) {
                $existing->forceFill(['status' => 'expired'])->save();
            }

            return $this->createDynamicQris(
                koperasi: $koperasi,
                orderId: (int) $order->id,
                saleId: null,
                transactionNumber: (string) $order->order_number,
                amount: (int) $order->total_amount,
            );
        });
    }

    /**
     * Membangkitkan atau mengembalikan QRIS dinamis aktif untuk Transaksi POS QRIS (UC-08 / UC-14).
     *
     * @throws PaymentException
     */
    public function initiateForSale(Sale $sale): Payment
    {
        if ($sale->status !== 'open' || $sale->payment_method !== 'qris') {
            throw PaymentException::saleNotPayable();
        }

        $sale->loadMissing('koperasi');
        $koperasi = $sale->koperasi;
        $this->assertKoperasiGatewayConfigured($koperasi);

        return Cache::lock('payment-init:sale:'.$sale->id, 15)->block(10, function () use ($sale, $koperasi): Payment {
            $existing = Payment::query()
                ->where('sale_id', $sale->id)
                ->latest('id')
                ->first();

            if ($existing !== null && $existing->status === 'pending' && ! $existing->isExpired()) {
                return $existing;
            }

            if ($existing !== null && $existing->status === 'pending' && $existing->isExpired()) {
                $existing->forceFill(['status' => 'expired'])->save();
            }

            return $this->createDynamicQris(
                koperasi: $koperasi,
                orderId: null,
                saleId: (int) $sale->id,
                transactionNumber: (string) $sale->invoice_number,
                amount: (int) $sale->total_amount,
            );
        });
    }

    /**
     * @throws PaymentException
     */
    private function createDynamicQris(
        Koperasi $koperasi,
        ?int $orderId,
        ?int $saleId,
        string $transactionNumber,
        int $amount,
    ): Payment {
        $paymentReference = 'QR-'.now()->format('ymd').'-'.strtoupper(Str::random(10));
        $expirySeconds = min(
            self::MAX_EXPIRY_SECONDS,
            max(60, (int) config('services.qris.expiry_seconds', self::MAX_EXPIRY_SECONDS)),
        );

        $request = new PaymentChargeRequest(
            paymentReference: $paymentReference,
            transactionNumber: $transactionNumber,
            amount: $amount,
            koperasiName: (string) $koperasi->name,
            merchantId: (string) $koperasi->gateway_merchant_id,
            credentials: is_array($koperasi->gateway_credentials) ? $koperasi->gateway_credentials : [],
            expiresInSeconds: $expirySeconds,
        );

        // Pemanggilan jaringan ke Payment Gateway dilakukan DI LUAR transaksi DB (maks 3 percobaan).
        try {
            /** @var QrisCharge $charge */
            $charge = retry(
                times: self::MAX_GATEWAY_TRIES,
                callback: fn (): QrisCharge => $this->gateway->createQrisCharge($request),
                sleepMilliseconds: 200,
                when: fn (Throwable $e): bool => $e instanceof GatewayUnavailableException,
            );
        } catch (GatewayUnavailableException) {
            $this->audit->record(
                action: 'payment_gateway_unavailable',
                modelType: Payment::class,
                modelId: null,
                oldValues: null,
                newValues: [
                    'transaction_number' => $transactionNumber,
                    'tries' => self::MAX_GATEWAY_TRIES,
                    'provider' => $this->gateway->name(),
                ],
                koperasiId: (int) $koperasi->id,
                actorType: 'system',
            );

            throw PaymentException::gatewayUnavailable();
        }

        $payment = new Payment;
        $payment->forceFill([
            'koperasi_id' => $koperasi->id,
            'order_id' => $orderId,
            'sale_id' => $saleId,
            'payment_reference' => $paymentReference,
            'qris_payload' => $charge->qrisPayload,
            'amount' => $amount,
            'status' => 'pending',
            'expired_at' => $charge->expiresAt,
            'paid_at' => null,
        ])->save();

        $this->audit->record(
            action: 'payment_qris_created',
            modelType: Payment::class,
            modelId: $payment->id,
            oldValues: null,
            newValues: [
                'payment_reference' => $paymentReference,
                'transaction_number' => $transactionNumber,
                'amount' => $amount,
                'provider' => $this->gateway->name(),
            ],
            koperasiId: (int) $koperasi->id,
            actorType: 'system',
        );

        return $payment;
    }

    private function assertKoperasiGatewayConfigured(?Koperasi $koperasi): void
    {
        if (
            ! $koperasi instanceof Koperasi
            || trim((string) $koperasi->gateway_merchant_id) === ''
            || ! is_array($koperasi->gateway_credentials)
            || $koperasi->gateway_credentials === []
        ) {
            throw PaymentException::gatewayNotConfigured();
        }
    }
}
