<?php

use App\Models\Koperasi;
use App\Support\Routing\PortalRoutes;
use Illuminate\Support\Facades\Route;

PortalRoutes::koperasi(function (): void {
    Route::get('/products', fn (Koperasi $koperasi) => view('catalog::koperasi.products', ['koperasi' => $koperasi]))
        ->name('products.index');
});
