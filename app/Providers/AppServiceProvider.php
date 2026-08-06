<?php

namespace App\Providers;

use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not a plain binding: which company a user is acting as and
        // what they may do in it is asked by middleware, controllers,
        // policies and the Inertia share on the same request. Resolving it
        // once per request keeps that to a single pair of queries, and keeps
        // every consumer looking at the same answer.
        $this->app->scoped(B2bContext::class);

        // Same reasoning, for the Partner API: the middleware establishes the
        // calling integration's identity once, and every controller, service
        // and error response on that request reads the same instance. A
        // non-scoped binding would let a controller resolve a fresh, empty
        // context and silently run unscoped.
        $this->app->scoped(PartnerContext::class);
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
