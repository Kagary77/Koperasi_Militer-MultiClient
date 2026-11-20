<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Contracts\NotificationGateway;
use App\Modules\Payments\Exceptions\NotificationFailedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $phone,
        public string $message,
        public string $context = 'general',
    ) {
        $this->afterCommit();
    }

    /**
     * Jeda percobaan ulang (detik): 5 → 25 (exponential backoff).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 25];
    }

    public function handle(NotificationGateway $gateway): void
    {
        try {
            $gateway->send($this->phone, $this->message);
        } catch (NotificationFailedException $e) {
            $attempt = $this->attempts();

            if ($attempt < $this->tries) {
                $this->release($this->backoff()[$attempt - 1] ?? 25);

                return;
            }

            $this->failed($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Pengiriman notifikasi WhatsApp gagal setelah percobaan ulang.', [
            'context' => $this->context,
            'to' => '…'.substr($this->phone, -4),
            'error' => $exception?->getMessage(),
        ]);
    }
}
