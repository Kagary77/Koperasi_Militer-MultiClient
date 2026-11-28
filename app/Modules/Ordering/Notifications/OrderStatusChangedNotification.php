<?php

namespace App\Modules\Ordering\Notifications;

use Illuminate\Notifications\Notification;

class OrderStatusChangedNotification extends Notification
{
    public function __construct(
        public int $koperasiId,
        public int $orderId,
        public string $orderNumber,
        public string $status,
        public string $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_status_changed',
            'koperasi_id' => $this->koperasiId,
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
