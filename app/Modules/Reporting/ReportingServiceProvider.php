<?php

namespace App\Modules\Reporting;

use App\Modules\ModuleServiceProvider;

final class ReportingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Binding laporan koperasi, ekspor async, dan scheduler snapshot harian (UC-15, UC-16).
    }

    protected function moduleAlias(): string
    {
        return 'reporting';
    }
}
