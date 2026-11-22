<?php

namespace App\Modules\Pos\Exceptions;

use RuntimeException;

final class PosException extends RuntimeException
{
    public static function shiftAlreadyOpen(): self
    {
        return new self('Anda masih memiliki shift kasir yang terbuka. Tutup shift sebelumnya terlebih dahulu.');
    }

    public static function shiftNotOpen(): self
    {
        return new self('Shift kasir belum dibuka. Buka shift terlebih dahulu sebelum bertransaksi.');
    }

    public static function invalidOpeningBalance(): self
    {
        return new self('Modal awal shift tidak boleh negatif.');
    }

    public static function invalidActualCash(): self
    {
        return new self('Kas fisik saat tutup shift tidak boleh negatif.');
    }

    public static function emptyCart(): self
    {
        return new self('Keranjang POS masih kosong.');
    }

    public static function invalidPaymentMethod(): self
    {
        return new self('Metode pembayaran harus Tunai (cash) atau QRIS (qris).');
    }

    public static function insufficientCashPayment(int $totalAmount): self
    {
        return new self('Nominal uang tunai kurang dari total belanja (Rp '.number_format($totalAmount, 0, ',', '.').').');
    }

    public static function productNotAvailable(string $name): self
    {
        return new self("Produk \"{$name}\" sedang tidak tersedia.");
    }

    public static function insufficientStock(string $name, int $available): self
    {
        return new self("Stok produk \"{$name}\" tidak mencukupi (tersedia: {$available}).");
    }

    public static function invalidVoidTransition(string $message): self
    {
        return new self($message);
    }

    public static function unauthorizedAction(string $message): self
    {
        return new self($message);
    }
}
