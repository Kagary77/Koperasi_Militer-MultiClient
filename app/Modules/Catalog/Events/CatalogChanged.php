<?php

namespace App\Modules\Catalog\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class CatalogChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $koperasiId,
        public int $productId,
    ) {}

    public static function channelName(int $koperasiId): string
    {
        return 'koperasi.'.$koperasiId.'.catalog';
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel(self::channelName($this->koperasiId))];
    }

    public function broadcastAs(): string
    {
        return 'CatalogChanged';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['product_id' => $this->productId];
    }
}
