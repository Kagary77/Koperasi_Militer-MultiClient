<?php

namespace App\Modules\Payments\Data;

final readonly class PaymentChargeRequest
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public string $paymentReference,
        public string $transactionNumber,
        public int $amount,
        public string $koperasiName,
        public string $merchantId,
        public array $credentials,
        public int $expiresInSeconds = 900,
    ) {}
}
