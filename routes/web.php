<?php

use App\Http\Controllers\DashboardRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/home', fn () => redirect()->route('customer.landing'))->name('home');

Route::middleware(['auth:web'])->group(function (): void {
    Route::get('dashboard', DashboardRedirectController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
