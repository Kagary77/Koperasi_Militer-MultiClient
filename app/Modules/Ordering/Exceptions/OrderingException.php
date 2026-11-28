<?php

namespace App\Modules\Ordering\Exceptions;

use RuntimeException;

final class OrderingException extends RuntimeException
{
    public static function unauthorizedAction(string $message): self
    {
        return new self($message);
    }

    public static function invalidOrderTransition(string $current, string $target): self
    {
        return new self("Perubahan status pesanan dari '{$current}' ke '{$target}' tidak diizinkan.");
    }

    public static function orderCannotBeCancelled(string $current): self
    {
        return new self("Pesanan berstatus '{$current}' tidak dapat dibatalkan oleh Admin Koperasi.");
    }

    public static function cancellationReasonRequired(): self
    {
        return new self('Alasan pembatalan pesanan wajib diisi.');
    }

    public static function invalidComplaintStatus(string $status): self
    {
        return new self("Status pengaduan '{$status}' tidak valid.");
    }

    public static function complaintAlreadyClosed(): self
    {
        return new self('Pengaduan yang sudah selesai atau ditolak tidak dapat diubah lagi.');
    }

    public static function adminResponseRequired(): self
    {
        return new self('Tanggapan Admin Koperasi wajib diisi.');
    }
}
