<?php

use App\Modules\Ordering\Http\Controllers\CustomerAuthController;
use App\Modules\Ordering\Http\Controllers\CustomerPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CustomerPortalController::class, 'landing'])->name('landing');

Route::middleware('guest:customer')->group(function (): void {
    Route::get('/anggota/masuk', [CustomerAuthController::class, 'showLogin'])->name('login');
    Route::post('/anggota/masuk', [CustomerAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login.store');

    Route::get('/anggota/daftar', [CustomerAuthController::class, 'showRegister'])->name('register');
    Route::post('/anggota/daftar', [CustomerAuthController::class, 'register'])
        ->middleware('throttle:10,1')
        ->name('register.store');
});

Route::middleware('auth:customer')->group(function (): void {
    Route::post('/anggota/keluar', [CustomerAuthController::class, 'logout'])->name('logout');

    Route::get('/anggota/notifikasi', [CustomerPortalController::class, 'notifications'])
        ->name('notifications');
    Route::post('/anggota/notifikasi/baca-semua', [CustomerPortalController::class, 'markAllNotificationsRead'])
        ->name('notifications.read-all');
});
