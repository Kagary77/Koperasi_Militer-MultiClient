<?php

namespace App\Providers;

use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: satu instance per request/job, otomatis di-reset pada lifecycle berikutnya.
        $this->app->scoped(
            KoperasiContext::class,
            fn (): KoperasiContext => new KoperasiContext,
        );
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
