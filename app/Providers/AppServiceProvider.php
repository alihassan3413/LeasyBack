<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password-reset links, verification links, etc. must always be
        // https in production, even if the app sits behind a TLS-terminating
        // proxy/load balancer that talks plain HTTP to this app internally.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
