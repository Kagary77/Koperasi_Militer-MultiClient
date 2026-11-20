<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Exceptions\NotificationFailedException;

interface NotificationGateway
{
    /**
     * Kirim pesan WhatsApp ke nomor pelanggan. Mengembalikan ID pesan provider.
     *
     * @throws NotificationFailedException
     */
    public function send(string $phone, string $message): string;

    public function channel(): string;
}
