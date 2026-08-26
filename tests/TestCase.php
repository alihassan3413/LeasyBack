<?php

namespace Tests;

use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeStripeGateway;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Bound for every test, not only the payment suite: the repair charge
         * fires from a status transition, so any test that drives an order to
         * `delivered` would otherwise construct the real client and attempt a
         * network call against whatever key happens to be in .env.
         *
         * Tests that need to assert on the requests resolve this same instance
         * out of the container.
         */
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);
    }
}
