<?php

use Illuminate\Support\Facades\Route;

/**
 * Route inti Portal Pusat (Rekan 1).
 * Route fitur pusat ditambahkan di app/Modules/Pusat/routes/pusat.php.
 */
Route::get('/dashboard', fn () => view('pusat.dashboard'))
    ->name('dashboard');
