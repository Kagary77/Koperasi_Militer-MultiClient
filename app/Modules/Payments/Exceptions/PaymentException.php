<?php

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

final class PaymentException extends RuntimeException
{
    public static function orderNotPayable(): self
    {
        return new self('Pesanan tidak dalam status menunggu pembayaran.');
    }

    public static function saleNotPayable(): self
    {
        return new self('Transaksi POS tidak dalam status terbuka untuk pembayaran QRIS.');
    }

    public static function gatewayNotConfigured(): self
    {
        return new self('Kredensial Payment Gateway koperasi belum dikonfigurasi.');
    }

    public static function gatewayUnavailable(): self
    {
        return new self('Layanan pembayaran sedang mengalami gangguan. Silakan coba beberapa saat lagi.');
    }
}
