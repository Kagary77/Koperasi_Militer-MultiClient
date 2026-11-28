<?php

use App\Models\Koperasi;
use App\Modules\Reporting\Http\Controllers\KoperasiReportExportController;
use App\Support\Routing\PortalRoutes;
use Illuminate\Support\Facades\Route;

PortalRoutes::koperasi(function (): void {
    Route::middleware('role:admin_koperasi')->group(function (): void {
        Route::get('/reports', fn (Koperasi $koperasi) => view('reporting::koperasi.reports', ['koperasi' => $koperasi]))
            ->name('reports.index');

        Route::get('/reports/export/csv', [KoperasiReportExportController::class, 'csv'])
            ->name('reports.export.csv');

        Route::get('/reports/export/pdf', [KoperasiReportExportController::class, 'printPdf'])
            ->name('reports.export.pdf');
    });
});
