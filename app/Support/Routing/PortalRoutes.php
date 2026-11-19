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
     * Portal Pelanggan (landing page, katalog /toko/{koperasi:slug}, keranjang, pesanan saya).
     * Aksi keranjang, checkout, pesanan saya, dan pengaduan wajib memakai middleware 'auth:customer'.
     */
    public static function customer(Closure|string $routes): void
    {
        Route::middleware('web')
            ->name('customer.')
            ->group($routes);
    }

    /**
     * Portal Internal Koperasi (Admin Koperasi & Kasir).
     * Wajib menggunakan guard 'auth:web' agar terisolasi penuh dari guard 'customer'.
     */
    public static function koperasi(Closure|string $routes): void
    {
        Route::middleware(['web', 'auth:web', 'koperasi'])
            ->prefix('koperasi/{koperasi:slug}')
            ->name('koperasi.')
            ->scopeBindings()
            ->group($routes);
    }

    /**
     * Portal Super Admin Pusat.
     * Wajib menggunakan guard 'auth:web' agar terisolasi penuh dari guard 'customer'.
     */
    public static function pusat(Closure|string $routes): void
    {
        Route::middleware(['web', 'auth:web', 'role:super_admin'])
            ->prefix('pusat')
            ->name('pusat.')
            ->group($routes);
    }
}
