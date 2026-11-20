<?php

use Illuminate\Support\Facades\Route;

Route::get('/home', fn () => redirect()->route('customer.landing'))->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth:web', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';