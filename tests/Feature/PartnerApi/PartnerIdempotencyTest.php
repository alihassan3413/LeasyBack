<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Models\PartnerIdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * Idempotency-Key handling.
 *
 * Phase 1 exposes no unsafe endpoint, so the middleware is exercised against
 * routes defined here — the same stack a phase 2 create endpoint will declare.
 * The counter in the test route is what makes the assertions meaningful: a
 * replay that returns the right body but ran the handler twice would have
 * created two orders in production, and only the counter catches that.
 */
class PartnerIdempotencyTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    private int $handlerRuns = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handlerRuns = 0;

        $stack = ['api', 'partner.request-id', 'partner.auth', 'partner.throttle'];

        Route::middleware([...$stack, 'partner.idempotent'])
            ->post('api/v1/partner/create-thing', function () {
                $this->handlerRuns++;

                return response()->json(['data' => ['id' => 'thing-'.$this->handlerRuns]], 201);
            });

        Route::middleware([...$stack, 'partner.idempotent:required'])
            ->post('api/v1/partner/create-required', function () {
                $this->handlerRuns++;

                return response()->json(['data' => ['id' => 'required-thing']], 201);
            });

        Route::middleware([...$stack, 'partner.idempotent'])
            ->post('api/v1/partner/failing-thing', function () {
                $this->handlerRuns++;

                return response()->json(['error' => ['code' => 'nope']], 422);
            });

        Route::middleware([...$stack, 'partner.idempotent'])
            ->get('api/v1/partner/safe-thing', function () {
                $this->handlerRuns++;

                return response()->json(['data' => ['runs' => $this->handlerRuns]]);
            });
    }

    public function test_a_retry_with_the_same_key_and_payload_replays_without_running_the_handler_again(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        $headers = [...$this->bearer($token), 'Idempotency-Key' => 'key-1'];

        $first = $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], $headers);
        $first->assertStatus(201)->assertJsonPath('data.id', 'thing-1');

        $second = $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], $headers);

        $second->assertStatus(201)
            ->assertJsonPath('data.id', 'thing-1')
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, $this->handlerRuns);
    }

    public function test_the_same_key_with_a_different_payload_is_a_conflict(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        $headers = [...$this->bearer($token), 'Idempotency-Key' => 'key-1'];

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], $headers)->assertStatus(201);

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-CD 2'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_conflict')
            ->assertJsonPath('error.type', 'conflict');

        $this->assertSame(1, $this->handlerRuns);
    }

    public function test_the_same_key_on_a_different_endpoint_is_a_conflict(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        $headers = [...$this->bearer($token), 'Idempotency-Key' => 'key-1'];

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], $headers)->assertStatus(201);

        $this->postJson('/api/v1/partner/create-required', ['plate' => 'M-AB 1'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_conflict');
    }

    public function test_key_ordering_inside_the_payload_is_not_a_different_request(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        $headers = [...$this->bearer($token), 'Idempotency-Key' => 'key-1'];

        $this->postJson('/api/v1/partner/create-thing', ['a' => 1, 'b' => 2], $headers)->assertStatus(201);

        $this->postJson('/api/v1/partner/create-thing', ['b' => 2, 'a' => 1], $headers)
            ->assertStatus(201)
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, $this->handlerRuns);
    }

    public function test_a_different_key_runs_the_handler_again(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], [
            ...$this->bearer($token), 'Idempotency-Key' => 'key-1',
        ])->assertStatus(201);

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], [
            ...$this->bearer($token), 'Idempotency-Key' => 'key-2',
        ])->assertStatus(201)->assertJsonPath('data.id', 'thing-2');

        $this->assertSame(2, $this->handlerRuns);
    }

    public function test_two_partners_may_use_the_same_key_without_colliding(): void
    {
        [, $alphaToken] = $this->makeAuthenticatedPartner(slug: 'alpha-partner');
        [, $betaToken] = $this->makeAuthenticatedPartner(slug: 'beta-partner');

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], [
            ...$this->bearer($alphaToken), 'Idempotency-Key' => 'shared-key',
        ])->assertStatus(201);

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], [
            ...$this->bearer($betaToken), 'Idempotency-Key' => 'shared-key',
        ])->assertStatus(201)->assertJsonPath('data.id', 'thing-2');

        $this->assertSame(2, $this->handlerRuns);
    }

    public function test_a_retry_while_the_original_is_still_running_is_refused(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        // Stand in for a request that is mid-flight: the row exists, is
        // unfinished, and its lock has not aged out.
        $record = new PartnerIdempotencyKey([
            'idempotency_key' => 'key-1',
            'endpoint' => 'POST /api/v1/partner/create-thing',
            'request_hash' => hash('sha256', json_encode(['plate' => 'M-AB 1'])),
            'status' => PartnerIdempotencyKey::STATUS_IN_PROGRESS,
            'locked_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $record->partner_integration_client_id = $client->id;
        $record->save();

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], [
            ...$this->bearer($token), 'Idempotency-Key' => 'key-1',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_in_progress')
            ->assertHeader('Retry-After', '1');

        $this->assertSame(0, $this->handlerRuns);
    }

    public function test_a_stale_lock_from_a_dead_request_is_taken_over(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        $record = new PartnerIdempotencyKey([
            'idempotency_key' => 'key-1',
            'endpoint' => 'POST /api/v1/partner/create-thing',
            'request_hash' => hash('sha256', json_encode(['plate' => 'M-AB 1'])),
            'status' => PartnerIdempotencyKey::STATUS_IN_PROGRESS,
            'locked_at' => now()->subHour(),
            'expires_at' => now()->addHours(24),
        ]);
        $record->partner_integration_client_id = $client->id;
        $record->save();

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], [
            ...$this->bearer($token), 'Idempotency-Key' => 'key-1',
        ])->assertStatus(201);

        $this->assertSame(1, $this->handlerRuns);
    }

    public function test_a_failed_request_leaves_the_key_reusable(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        $headers = [...$this->bearer($token), 'Idempotency-Key' => 'key-1'];

        $this->postJson('/api/v1/partner/failing-thing', ['plate' => 'M-AB 1'], $headers)->assertStatus(422);

        $this->assertSame(0, PartnerIdempotencyKey::count());

        $this->postJson('/api/v1/partner/failing-thing', ['plate' => 'M-AB 1'], $headers)->assertStatus(422);

        $this->assertSame(2, $this->handlerRuns);
    }

    public function test_an_expired_record_no_longer_replays(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        $headers = [...$this->bearer($token), 'Idempotency-Key' => 'key-1'];

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], $headers)->assertStatus(201);

        PartnerIdempotencyKey::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.id', 'thing-2');

        $this->assertSame(2, $this->handlerRuns);
    }

    public function test_an_endpoint_marked_required_refuses_a_request_without_a_key(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->postJson('/api/v1/partner/create-required', ['plate' => 'M-AB 1'], $this->bearer($token))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_required');

        $this->assertSame(0, $this->handlerRuns);
    }

    public function test_an_optional_endpoint_runs_normally_without_a_key(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->postJson('/api/v1/partner/create-thing', ['plate' => 'M-AB 1'], $this->bearer($token))
            ->assertStatus(201);

        $this->assertSame(0, PartnerIdempotencyKey::count());
    }

    public function test_an_over_long_key_is_refused(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->postJson('/api/v1/partner/create-thing', [], [
            ...$this->bearer($token), 'Idempotency-Key' => str_repeat('k', 300),
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_invalid');
    }

    public function test_safe_methods_are_never_recorded(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        $headers = [...$this->bearer($token), 'Idempotency-Key' => 'key-1'];

        $this->getJson('/api/v1/partner/safe-thing', $headers)->assertOk();
        $this->getJson('/api/v1/partner/safe-thing', $headers)->assertOk();

        $this->assertSame(0, PartnerIdempotencyKey::count());
        $this->assertSame(2, $this->handlerRuns);
    }
}
