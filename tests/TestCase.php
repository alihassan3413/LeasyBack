<?php

namespace Tests;

use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
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

        /*
         * No test may reach a real third-party API (TÜV SÜD, DEKRA, Lexware,
         * TIM, partner webhooks). A request the test did not explicitly fake
         * with Http::fake() throws instead of leaving the machine — one test
         * booking with TÜV SÜD this way used the real endpoint and the token
         * from .env. Tests that exercise an integration fake its endpoint.
         */
        Http::preventStrayRequests();
    }
}
