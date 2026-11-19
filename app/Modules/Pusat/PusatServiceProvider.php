<?php

namespace App\Modules\Pusat;

use App\Modules\ModuleServiceProvider;

final class PusatServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Binding layanan lingkup Pusat (UC-17 s.d. UC-21).
    }

    protected function moduleAlias(): string
    {
        return 'pusat';
    }
}
