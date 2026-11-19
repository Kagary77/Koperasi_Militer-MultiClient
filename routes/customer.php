<?php

use Illuminate\Support\Facades\Route;

/**
 * Route inti Portal Pelanggan (Rekan 2).
 * Route fitur pelanggan ditambahkan di app/Modules/{Catalog,Ordering}/routes/customer.php.
 */
Route::get('/', fn () => view('customer.landing'))->name('landing');
