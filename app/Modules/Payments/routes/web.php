<?php

use App\Modules\Payments\Http\Controllers\QrisWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/qris', QrisWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.qris');
