<?php

namespace App\Modules\Ordering;

use App\Modules\ModuleServiceProvider;

final class OrderingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Binding layanan keranjang, checkout, pelacakan, pesanan online, karyawan, dan pengaduan.
    }

    protected function moduleAlias(): string
    {
        return 'ordering';
    }
}
