<?php

namespace App\Modules\Catalog\Services;

use App\Models\Koperasi;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

final class PublicCatalogQuery
{
    /**
     * Kueri katalog publik read-only untuk satu koperasi aktif.
     * Bypass global scope eksplisit diganti filter koperasi_id + status koperasi aktif.
     *
     * @return Collection<int, Product>
     */
    public function forKoperasi(
        Koperasi $koperasi,
        string $search = '',
        ?int $categoryId = null,
    ): Collection {
        if (! $koperasi->isActive()) {
            return new Collection;
        }

        return Product::query()
            ->withoutGlobalScope('koperasi')
            ->select([
                'id',
                'koperasi_id',
                'category_id',
                'unit_id',
                'name',
                'slug',
                'description',
                'price',
                'stock',
                'is_available',
                'image_path',
            ])
            ->where('koperasi_id', $koperasi->id)
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->with(['category:id,name', 'unit:id,name'])
            ->orderBy('name')
            ->get();
    }
}
