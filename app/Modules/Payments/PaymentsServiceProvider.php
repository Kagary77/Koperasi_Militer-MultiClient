<?php

namespace App\Modules\Payments;

use App\Modules\ModuleServiceProvider;
use App\Modules\Payments\Contracts\NotificationGateway;
use App\Modules\Payments\Gateways\FakeWhatsAppGateway;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            NotificationGateway::class,
            fn (): FakeWhatsAppGateway => new FakeWhatsAppGateway(
                unavailable: (bool) config('services.whatsapp.fake_unavailable'),
            ),
        );
    }

    protected function moduleAlias(): string
    {
        return 'payments';
    }
}
