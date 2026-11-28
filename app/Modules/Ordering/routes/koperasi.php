<?php

use App\Models\Koperasi;
use App\Support\Routing\PortalRoutes;
use Illuminate\Support\Facades\Route;

PortalRoutes::koperasi(function (): void {
    Route::get('/orders', fn (Koperasi $koperasi) => view('ordering::koperasi.orders', ['koperasi' => $koperasi]))
        ->name('orders.index');

    Route::middleware('role:admin_koperasi')->group(function (): void {
        Route::get('/employees', fn (Koperasi $koperasi) => view('ordering::koperasi.employees', ['koperasi' => $koperasi]))
            ->name('employees.index');

        Route::get('/complaints', fn (Koperasi $koperasi) => view('ordering::koperasi.complaints', ['koperasi' => $koperasi]))
            ->name('complaints.index');
    });
});
