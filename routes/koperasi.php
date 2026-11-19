<?php

use Illuminate\Support\Facades\Route;

/**
 * Route inti Portal Koperasi (Anda — Lead).
 * Route fitur koperasi ditambahkan di app/Modules/{Catalog,Pos,Ordering,Reporting}/routes/koperasi.php.
 */
Route::get('/dashboard', fn (string $koperasi) => view('koperasi.dashboard', ['koperasi' => $koperasi]))
    ->name('dashboard');
