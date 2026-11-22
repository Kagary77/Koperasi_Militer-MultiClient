<?php

use App\Models\Koperasi;
use App\Models\Sale;
use App\Support\Routing\PortalRoutes;
use Illuminate\Support\Facades\Route;

PortalRoutes::koperasi(function (): void {
    Route::get('/pos', fn (Koperasi $koperasi) => view('pos::koperasi.terminal', ['koperasi' => $koperasi]))
        ->name('pos.index');

    Route::get('/pos/receipt/{sale}', function (Koperasi $koperasi, Sale $sale) {
        $sale->load(['items', 'user:id,name']);

        return view('pos::koperasi.receipt', [
            'koperasi' => $koperasi,
            'sale' => $sale,
        ]);
    })->name('pos.receipt');

    Route::middleware('role:admin_koperasi')->group(function (): void {
        Route::get('/pos/voids', fn (Koperasi $koperasi) => view('pos::koperasi.void-approvals', ['koperasi' => $koperasi]))
            ->name('pos.voids');
    });
});
