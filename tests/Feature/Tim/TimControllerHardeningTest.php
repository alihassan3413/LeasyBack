<?php

namespace Tests\Feature\Tim;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Tim\Models\TimToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Checkpoint 12's TIM hardening: connection failures and malformed/
 * incomplete SOAP responses must return a clean, recoverable error instead
 * of an uncaught exception ("a panic" per
 * docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md's Checkpoint 12 bullet) or a
 * silently-persisted garbage row.
 */
class TimControllerHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_login_refresh_returns_a_clean_error_when_tim_is_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->withHeaders($this->bearer($this->admin()))
            ->postJson('/tim/appraisal/login/refresh')
            ->assertStatus(502)
            ->assertJson(['error' => 'tim_unreachable']);
    }

    public function test_sync_returns_a_clean_error_when_tim_is_unreachable(): void
    {
        TimToken::query()->update(['client_id' => 'C1', 'session' => 'S1', 'username' => 'U1']);
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->withHeaders($this->bearer($this->admin()))
            ->postJson('/tim/appraisal/xml/sync/123')
            ->assertStatus(502)
            ->assertJson(['error' => 'tim_unreachable']);

        $this->assertDatabaseMissing('tim_bewertung', ['bewertung_id' => 123]);
    }

    /**
     * extractTags() is regex-based, not a real XML parser — it never
     * throws on malformed input, it just finds nothing. Without an
     * explicit check, a truncated/non-XML response (e.g. a proxy error
     * page) would silently persist a near-empty TimBewertung row instead
     * of surfacing as the malformed response it actually is.
     */
    public function test_sync_rejects_a_malformed_response_instead_of_persisting_a_garbage_row(): void
    {
        TimToken::query()->update(['client_id' => 'C1', 'session' => 'S1', 'username' => 'U1']);
        Http::fake(['*' => Http::response('<html>not a soap response</html>', 200)]);

        $this->withHeaders($this->bearer($this->admin()))
            ->postJson('/tim/appraisal/xml/sync/456')
            ->assertStatus(502)
            ->assertJson(['error' => 'malformed_tim_response']);

        $this->assertDatabaseMissing('tim_bewertung', ['bewertung_id' => 456]);
    }

    public function test_sync_succeeds_for_a_well_formed_response(): void
    {
        TimToken::query()->update(['client_id' => 'C1', 'session' => 'S1', 'username' => 'U1']);
        Http::fake(['*' => Http::response('<Uid>U-1</Uid><Auftragsnummer>AUF-1</Auftragsnummer>', 200)]);

        $this->withHeaders($this->bearer($this->admin()))
            ->postJson('/tim/appraisal/xml/sync/789')
            ->assertOk();

        $this->assertDatabaseHas('tim_bewertung', ['bewertung_id' => 789, 'uid' => 'U-1', 'auftragsnummer' => 'AUF-1']);
    }

    /**
     * Regression test for the singleton-session-token race: upsertToken()
     * used to be a bare updateOrCreate() with no row lock. This doesn't
     * exercise true concurrency (PHPUnit is single-threaded), but proves
     * the row-seeded-then-locked-update path behaves correctly and never
     * produces a second row.
     */
    public function test_upserting_the_token_twice_updates_the_same_singleton_row(): void
    {
        TimToken::upsertToken('C1', 'S1', 'U1');
        TimToken::upsertToken('C2', 'S2', 'U2');

        $this->assertDatabaseCount('tim_token', 1);
        $this->assertDatabaseHas('tim_token', ['id' => 1, 'client_id' => 'C2', 'session' => 'S2', 'username' => 'U2']);
    }
}
