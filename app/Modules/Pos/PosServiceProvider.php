<?php

namespace App\Modules\Pos;

use App\Modules\ModuleServiceProvider;

final class PosServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Binding layanan POS, shift kasir, dan persetujuan void (UC-14).
    }

    protected function moduleAlias(): string
    {
        return 'pos';
    }
}
