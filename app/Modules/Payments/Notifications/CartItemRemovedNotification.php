<?php

namespace App\Modules\Payments\Notifications;

use Illuminate\Notifications\Notification;

class CartItemRemovedNotification extends Notification
{
    public function __construct(
        public int $koperasiId,
        public int $productId,
        public string $productName,
        public string $reason,
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
            'type' => 'cart_item_removed',
            'koperasi_id' => $this->koperasiId,
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'reason' => $this->reason,
            'message' => "Item \"{$this->productName}\" dihapus dari keranjang Anda karena {$this->reason}.",
        ];
    }
}
