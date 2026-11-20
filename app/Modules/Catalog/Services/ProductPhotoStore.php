<?php

namespace App\Modules\Catalog\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ProductPhotoStore
{
    public const MAX_SIDE = 800;

    public function store(UploadedFile $file, int $koperasiId): string
    {
        $source = imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new RuntimeException('Berkas bukan gambar yang valid.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($width, $height));

        $target = imagescale(
            $source,
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        );

        if ($target === false) {
            throw new RuntimeException('Gambar gagal diperkecil.');
        }

        ob_start();
        imagewebp($target, null, 80);
        $webp = (string) ob_get_clean();

        $path = "products/{$koperasiId}/".Str::uuid().'.webp';
        Storage::disk('public')->put($path, $webp);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}
