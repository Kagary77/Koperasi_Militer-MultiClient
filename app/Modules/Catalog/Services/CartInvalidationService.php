<?php

namespace App\Modules\Catalog\Services;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Modules\Payments\Jobs\SendWhatsAppNotificationJob;
use App\Modules\Payments\Notifications\CartItemRemovedNotification;

final class CartInvalidationService
{
    /**
     * Menghapus item keranjang pelanggan yang terdampak produk dihapus/nonaktif/habis,
     * membuat notifikasi in-app persisten, dan mengantrikan pemberitahuan WhatsApp.
     */
    public function invalidateForProduct(Product $product, string $reason): int
    {
        $items = CartItem::query()
            ->withoutGlobalScope('koperasi')
            ->with('customer')
            ->where('koperasi_id', $product->koperasi_id)
            ->where('product_id', $product->id)
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $customers = $items->pluck('customer')
            ->filter(fn ($customer): bool => $customer instanceof Customer)
            ->unique('id');

        CartItem::query()
            ->withoutGlobalScope('koperasi')
            ->whereIn('id', $items->pluck('id')->all())
            ->delete();

        $message = "Pemberitahuan Koperasi: Item \"{$product->name}\" dihapus dari keranjang belanja Anda karena {$reason}.";

        foreach ($customers as $customer) {
            $customer->notify(new CartItemRemovedNotification(
                koperasiId: (int) $product->koperasi_id,
                productId: (int) $product->id,
                productName: (string) $product->name,
                reason: $reason,
            ));

            if (is_string($customer->phone) && $customer->phone !== '') {
                SendWhatsAppNotificationJob::dispatch($customer->phone, $message, 'cart_invalidation');
            }
        }

        return $items->count();
    }
}
