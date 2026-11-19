<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MasterCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Beras & Biji-bijian',
            'Minyak Goreng',
            'Gula & Garam',
            'Telur & Protein',
            'Mie & Makanan Instan',
            'Minuman',
            'Kebutuhan Rumah Tangga',
            'Bumbu Dapur',
        ];

        foreach ($categories as $name) {
            $slug = Str::slug($name);

            $category = Category::query()->firstOrNew([
                'koperasi_id' => null,
                'slug' => $slug,
            ]);

            $category->forceFill([
                'koperasi_id' => null,
                'name' => $name,
                'slug' => $slug,
                'is_active' => true,
            ])->save();
        }
    }
}
