<?php

namespace App\Modules\Catalog;

use App\Modules\ModuleServiceProvider;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Binding layanan katalog, produk, kategori, dan stok (UC-01, UC-03, UC-12, UC-13).
    }

    protected function moduleAlias(): string
    {
        return 'catalog';
    }
}
