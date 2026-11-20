<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\NotificationGateway;
use App\Modules\Payments\Exceptions\NotificationFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class FakeWhatsAppGateway implements NotificationGateway
{
    public function __construct(private bool $unavailable = false) {}

    public function send(string $phone, string $message): string
    {
        if ($this->unavailable) {
            throw new NotificationFailedException('Gateway WhatsApp sandbox tidak merespons.');
        }

        $id = 'FAKE-WA-'.strtoupper(Str::random(12));

        Log::info('WhatsApp (sandbox) terkirim.', [
            'id' => $id,
            'to' => '…'.substr($phone, -4),
            'message' => $message,
        ]);

        return $id;
    }

    public function channel(): string
    {
        return 'whatsapp';
    }
}
