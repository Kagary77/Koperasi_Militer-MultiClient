<?php

namespace App\Support\Routing;

use Closure;
use Illuminate\Support\Facades\Route;

final class PortalRoutes
{
    /**
     * Route publik tanpa prefix portal (mis. webhook payment gateway).
     */
    public static function web(Closure|string $routes): void
    {
        Route::middleware('web')->group($routes);
    }

    /**
     * Portal Pelanggan (landing page, katalog /toko/{koperasi}, keranjang, pesanan saya).
     * Guest boleh baca katalog; aksi keranjang & checkout dilindungi middleware auth:customer.
     */
    public static function customer(Closure|string $routes): void
    {
        Route::middleware('web')
            ->name('customer.')
            ->group($routes);
    }

    /**
     * Portal Internal Koperasi (Admin Koperasi & Kasir).
     * Middleware resolver 'koperasi' (SetKoperasiContext) & scopeBindings() diaktifkan di Tahap 0.3.
     */
    public static function koperasi(Closure|string $routes): void
    {
        Route::middleware(['web', 'auth'])
            ->prefix('koperasi/{koperasi}')
            ->name('koperasi.')
            ->group($routes);
    }

    /**
     * Portal Super Admin Pusat.
     * Middleware 'role:super_admin' & MFA diaktifkan di Tahap 0.3.
     */
    public static function pusat(Closure|string $routes): void
    {
        Route::middleware(['web', 'auth'])
            ->prefix('pusat')
            ->name('pusat.')
            ->group($routes);
    }
}
