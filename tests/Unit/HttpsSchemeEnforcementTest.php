<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Checkpoint 6: password-reset/verification links must always be https in
 * production, even behind a TLS-terminating proxy that talks plain HTTP to
 * the app internally — see AppServiceProvider::boot().
 */
class HttpsSchemeEnforcementTest extends TestCase
{
    public function test_urls_are_forced_to_https_in_production(): void
    {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/'));
    }

    public function test_urls_are_not_forced_to_https_outside_production(): void
    {
        $this->app['env'] = 'local';
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', URL::to('/'));
    }
}
