<?php

namespace App\Modules\Payments;

use App\Modules\ModuleServiceProvider;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Binding kontrak PaymentGateway, webhook QRIS, dan notifikasi (UC-08, UC-09, UC-10).
    }

    protected function moduleAlias(): string
    {
        return 'payments';
    }
}
