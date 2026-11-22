<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Data\PaymentChargeRequest;
use App\Modules\Payments\Data\QrisCharge;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;

interface PaymentGateway
{
    /**
     * Membangkitkan transaksi QRIS dinamis atas nama koperasi terkait.
     *
     * @throws GatewayUnavailableException
     */
    public function createQrisCharge(PaymentChargeRequest $request): QrisCharge;

    public function name(): string;

    public function isSandbox(): bool;
}
