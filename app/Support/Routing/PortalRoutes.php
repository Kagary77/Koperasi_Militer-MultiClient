<?php

namespace App\Support\Routing;

use Closure;
use Illuminate\Support\Facades\Route;

final class PortalRoutes
{
    public static function web(Closure|string $routes): void
    {
        Route::middleware('web')->group($routes);
    }

    public static function customer(Closure|string $routes): void
    {
        Route::middleware('web')
            ->name('customer.')
            ->group($routes);
    }

    public static function koperasi(Closure|string $routes): void
    {
        Route::middleware(['web', 'auth:web', 'mfa', 'koperasi'])
            ->prefix('koperasi/{koperasi:slug}')
            ->name('koperasi.')
            ->scopeBindings()
            ->group($routes);
    }

    public static function pusat(Closure|string $routes): void
    {
        Route::middleware(['web', 'auth:web', 'mfa', 'role:super_admin'])
            ->prefix('pusat')
            ->name('pusat.')
            ->group($routes);
    }
}
