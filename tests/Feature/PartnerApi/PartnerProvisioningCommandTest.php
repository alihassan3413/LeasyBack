<?php

namespace Tests\Feature\PartnerApi;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Models\PartnerApiToken;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use App\Modules\PartnerApi\Services\PartnerClientProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * The credential lifecycle, driven the way an operator drives it.
 *
 * Each command is asserted twice: on the database state it produced, and on
 * whether the resulting token actually authenticates. A provisioning run that
 * writes plausible rows but yields a token the API refuses is a failure that
 * only an end-to-end assertion catches.
 */
class PartnerProvisioningCommandTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    public function test_provisioning_creates_the_client_user_membership_and_a_working_token(): void
    {
        $company = $this->makePartnerCompany('Kunden GmbH');

        $this->artisan('partner:provision', [
            'slug' => 'shiftmove',
            '--name' => 'Shiftmove',
            '--company' => $company->b2b_id,
            '--environment' => 'sandbox',
            '--issued-by' => 'ops@leasyback.de',
        ])->assertSuccessful();

        $client = PartnerIntegrationClient::where('slug', 'shiftmove')->firstOrFail();

        $this->assertSame($company->b2b_id, $client->b2b_id);
        $this->assertSame(PartnerEnvironment::Sandbox, $client->environment);
        $this->assertTrue($client->is_active);

        $user = User::findOrFail($client->user_id);
        $this->assertSame(UserType::Firmenkunde, $user->user_type);
        $this->assertTrue($user->is_active);
        $this->assertSame($company->b2b_id, $user->active_b2b_id);

        $membership = DB::table('user_b2b')
            ->where('user_id', $user->id)
            ->where('b2b_id', $company->b2b_id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('active', $membership->status);
        $this->assertSame('member', $membership->role);
        $this->assertSame(
            PartnerClientProvisioner::INTEGRATION_USER_PERMISSIONS,
            json_decode($membership->permissions, true),
        );

        $token = $client->tokens()->firstOrFail();
        $this->assertSame(PartnerAbility::values(), $token->abilityValues());
        $this->assertSame('ops@leasyback.de', $token->issued_by);
        $this->assertNull($token->expires_at);
    }

    public function test_the_provisioned_token_is_displayed_once_and_authenticates(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $company->b2b_id,
        ])->assertSuccessful();

        $plainText = $this->capturedToken('partner:provision', [
            'slug' => 'second-partner',
            '--company' => $company->b2b_id,
        ]);

        $this->assertStringStartsWith('lbp_sbx_', $plainText);

        $this->getJson('/api/v1/partner/me', $this->bearer($plainText))
            ->assertOk()
            ->assertJsonPath('data.client.slug', 'second-partner')
            ->assertJsonPath('data.company.id', $company->b2b_id);

        // Only the hash is stored — the plaintext is unrecoverable.
        $this->assertDatabaseMissing('partner_api_tokens', ['token_hash' => $plainText]);
    }

    public function test_provisioning_refuses_a_slug_that_already_exists_in_that_environment(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', ['slug' => 'shiftmove', '--company' => $company->b2b_id])
            ->assertSuccessful();

        $this->artisan('partner:provision', ['slug' => 'shiftmove', '--company' => $company->b2b_id])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->assertSame(1, PartnerIntegrationClient::where('slug', 'shiftmove')->count());
    }

    public function test_the_same_slug_may_be_provisioned_once_per_environment(): void
    {
        $sandboxCompany = $this->makePartnerCompany('Sandkasten GmbH');
        $productionCompany = $this->makePartnerCompany('Produktion GmbH');

        $this->artisan('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $sandboxCompany->b2b_id,
            '--environment' => 'sandbox',
        ])->assertSuccessful();

        $this->artisan('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $productionCompany->b2b_id,
            '--environment' => 'production',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, PartnerIntegrationClient::where('slug', 'shiftmove')->count());
        $this->assertNotSame(
            PartnerIntegrationClient::where('environment', 'sandbox')->value('user_id'),
            PartnerIntegrationClient::where('environment', 'production')->value('user_id'),
        );
    }

    public function test_provisioning_rejects_an_unknown_company_and_an_unknown_ability(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', ['slug' => 'a-partner', '--company' => 'no-such-company'])
            ->expectsOutputToContain('No B2B company')
            ->assertFailed();

        $this->artisan('partner:provision', [
            'slug' => 'a-partner',
            '--company' => $company->b2b_id,
            '--abilities' => 'vehicles.read,vehicles.reed',
        ])
            ->expectsOutputToContain('Unknown abilities')
            ->assertFailed();

        $this->assertSame(0, PartnerIntegrationClient::count());
    }

    public function test_provisioning_refuses_a_deactivated_company(): void
    {
        $company = $this->makePartnerCompany();
        $company->forceFill(['is_active' => false])->save();

        $this->artisan('partner:provision', ['slug' => 'a-partner', '--company' => $company->b2b_id])
            ->expectsOutputToContain('deactivated')
            ->assertFailed();
    }

    public function test_provisioning_honours_an_explicit_scope_set_and_expiry(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', [
            'slug' => 'scoped-partner',
            '--company' => $company->b2b_id,
            '--abilities' => 'vehicles.read, orders.read',
            '--expires-in-days' => 30,
        ])->assertSuccessful();

        $token = PartnerApiToken::firstOrFail();

        $this->assertSame(['vehicles.read', 'orders.read'], $token->abilityValues());
        $this->assertTrue($token->expires_at->isBetween(now()->addDays(29), now()->addDays(31)));
    }

    public function test_an_account_already_backing_another_client_cannot_be_reused(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', [
            'slug' => 'first-partner',
            '--company' => $company->b2b_id,
            '--user-email' => 'shared@example.com',
        ])->assertSuccessful();

        $this->artisan('partner:provision', [
            'slug' => 'second-partner',
            '--company' => $company->b2b_id,
            '--user-email' => 'shared@example.com',
        ])
            ->expectsOutputToContain('already backs another integration client')
            ->assertFailed();
    }

    public function test_rotation_issues_a_working_replacement_and_kills_the_previous_token(): void
    {
        $company = $this->makePartnerCompany();
        $client = $this->makePartnerClient($company, slug: 'shiftmove');
        $old = $this->issueToken($client, ['vehicles.read'])->plainTextToken;

        $this->getJson('/api/v1/partner/health', $this->bearer($old))->assertOk();

        $new = $this->capturedToken('partner:token:rotate', ['slug' => 'shiftmove']);

        $this->getJson('/api/v1/partner/health', $this->bearer($new))->assertOk();
        $this->getJson('/api/v1/partner/health', $this->bearer($old))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'token_revoked');

        // The scope set carries over when none is given. Looked up by name
        // rather than by created_at: the column has second precision, so
        // "the newest row" is ambiguous between two tokens issued in the same
        // second, which is exactly what a rotation does.
        $this->assertSame(['vehicles.read'], PartnerApiToken::where('name', 'rotated')->firstOrFail()->abilityValues());
    }

    public function test_rotation_with_a_grace_window_keeps_the_previous_token_alive_for_now(): void
    {
        $client = $this->makePartnerClient(slug: 'shiftmove');
        $old = $this->issueToken($client)->plainTextToken;

        $this->artisan('partner:token:rotate', ['slug' => 'shiftmove', '--grace-minutes' => 10])
            ->assertSuccessful();

        $this->getJson('/api/v1/partner/health', $this->bearer($old))->assertOk();

        $this->travelTo(now()->addMinutes(11));

        $this->getJson('/api/v1/partner/health', $this->bearer($old))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'token_revoked');
    }

    public function test_rotation_can_narrow_the_scope_set(): void
    {
        $client = $this->makePartnerClient(slug: 'shiftmove');
        $this->issueToken($client);

        $this->artisan('partner:token:rotate', [
            'slug' => 'shiftmove',
            '--abilities' => 'vehicles.read',
        ])->assertSuccessful();

        $this->assertSame(
            ['vehicles.read'],
            PartnerApiToken::where('name', 'rotated')->firstOrFail()->abilityValues(),
        );
    }

    public function test_revocation_locks_the_partner_out_immediately_and_leaves_the_client_intact(): void
    {
        $client = $this->makePartnerClient(slug: 'shiftmove');
        $token = $this->issueToken($client)->plainTextToken;

        $this->artisan('partner:token:revoke', ['slug' => 'shiftmove'])->assertSuccessful();

        $this->getJson('/api/v1/partner/health', $this->bearer($token))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'token_revoked');

        $this->assertTrue($client->fresh()->is_active);
        $this->assertNotNull(PartnerApiToken::firstOrFail()->revoked_at);
    }

    public function test_revocation_of_a_client_with_no_live_tokens_is_a_no_op(): void
    {
        $this->makePartnerClient(slug: 'shiftmove');

        $this->artisan('partner:token:revoke', ['slug' => 'shiftmove'])
            ->expectsOutputToContain('nothing to revoke')
            ->assertSuccessful();
    }

    public function test_a_revoked_partner_is_restored_by_rotation_without_reprovisioning(): void
    {
        $client = $this->makePartnerClient(slug: 'shiftmove');
        $this->issueToken($client);

        $this->artisan('partner:token:revoke', ['slug' => 'shiftmove'])->assertSuccessful();

        $restored = $this->capturedToken('partner:token:rotate', ['slug' => 'shiftmove']);

        $this->getJson('/api/v1/partner/health', $this->bearer($restored))->assertOk();
    }

    public function test_deactivation_suspends_the_client_without_touching_its_tokens(): void
    {
        $client = $this->makePartnerClient(slug: 'shiftmove');
        $token = $this->issueToken($client)->plainTextToken;

        $this->artisan('partner:deactivate', ['slug' => 'shiftmove'])->assertSuccessful();

        $this->getJson('/api/v1/partner/health', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'client_inactive');

        $this->assertFalse($client->fresh()->is_active);
        $this->assertNull(PartnerApiToken::firstOrFail()->revoked_at);
    }

    public function test_activation_restores_the_same_token(): void
    {
        $client = $this->makePartnerClient(slug: 'shiftmove', active: false);
        $token = $this->issueToken($client)->plainTextToken;

        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertStatus(403);

        $this->artisan('partner:activate', ['slug' => 'shiftmove'])->assertSuccessful();

        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
    }

    public function test_activating_an_already_active_client_is_a_no_op(): void
    {
        $this->makePartnerClient(slug: 'shiftmove');

        $this->artisan('partner:activate', ['slug' => 'shiftmove'])
            ->expectsOutputToContain('already active')
            ->assertSuccessful();
    }

    public function test_the_commands_refuse_an_unknown_client_or_environment(): void
    {
        $this->makePartnerClient(slug: 'shiftmove');

        $this->artisan('partner:token:rotate', ['slug' => 'nope'])
            ->expectsOutputToContain("No integration client 'nope'")
            ->assertFailed();

        // A sandbox client is not reachable by asking for production.
        $this->artisan('partner:token:revoke', ['slug' => 'shiftmove', '--environment' => 'production', '--force' => true])
            ->expectsOutputToContain('No integration client')
            ->assertFailed();

        $this->artisan('partner:activate', ['slug' => 'shiftmove', '--environment' => 'staging'])
            ->expectsOutputToContain('Unknown environment')
            ->assertFailed();
    }

    public function test_a_sandbox_token_is_issued_with_no_expiry(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $company->b2b_id,
            '--environment' => 'sandbox',
        ])->assertSuccessful();

        $token = PartnerIntegrationClient::where('slug', 'shiftmove')->firstOrFail()
            ->tokens()->firstOrFail();

        $this->assertNull($token->expires_at);
        $this->assertFalse($token->isExpired());
        $this->assertTrue($token->isUsable());
    }

    public function test_a_production_token_is_issued_with_no_expiry(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $company->b2b_id,
            '--environment' => 'production',
            '--force' => true,
        ])->assertSuccessful();

        $token = PartnerIntegrationClient::where('slug', 'shiftmove')
            ->where('environment', PartnerEnvironment::Production->value)
            ->firstOrFail()
            ->tokens()->firstOrFail();

        $this->assertNull($token->expires_at);
        $this->assertFalse($token->isExpired());
    }

    public function test_a_token_with_no_expiry_authenticates(): void
    {
        $company = $this->makePartnerCompany();

        $plainText = $this->capturedToken('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $company->b2b_id,
        ]);

        $this->assertNull(PartnerApiToken::latest('id')->firstOrFail()->expires_at);

        $this->getJson('/api/v1/partner/me', $this->bearer($plainText))
            ->assertOk()
            ->assertJsonPath('data.client.slug', 'shiftmove');
    }

    /**
     * The production regression this fix exists for.
     *
     * `.env` shipping `PARTNER_API_TOKEN_EXPIRY_DAYS=` makes `env()` return the
     * string `''`. It is not null, so `??` does not fall back to "never"; it
     * casts to 0, so `addDays()` produced *now*. Every token issued on such a
     * server was expired on arrival and the API answered `token_expired` on the
     * partner's first request. Each of these values must mean never.
     *
     * @return list<array{mixed}>
     */
    public static function blankExpiryProvider(): array
    {
        return [
            'blank env value' => [''],
            'zero' => [0],
            'zero as text' => ['0'],
            'whitespace' => ['   '],
            'non-numeric' => ['never'],
            'negative' => [-1],
        ];
    }

    #[DataProvider('blankExpiryProvider')]
    public function test_a_non_positive_expiry_setting_issues_a_token_that_never_expires(mixed $configured): void
    {
        config(['partner_api.token.default_expiry_days' => $configured]);

        $company = $this->makePartnerCompany();

        $plainText = $this->capturedToken('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $company->b2b_id,
        ]);

        $token = PartnerApiToken::latest('id')->firstOrFail();

        $this->assertNull($token->expires_at);

        // The assertion that would have caught this in production: the token
        // the operator just handed over actually works.
        $this->getJson('/api/v1/partner/me', $this->bearer($plainText))->assertOk();
    }

    public function test_the_config_normalises_a_blank_expiry_env_value_to_null(): void
    {
        $normalise = static fn (mixed $raw): ?int => is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;

        $this->assertNull($normalise(''), 'A blank .env value must mean never, not now.');
        $this->assertNull($normalise(null));
        $this->assertNull($normalise('0'));
        $this->assertNull($normalise('not-a-number'));
        $this->assertSame(30, $normalise('30'));

        // The shipped default is unset, so the resolved config is null.
        $this->assertNull(config('partner_api.token.default_expiry_days'));
    }

    public function test_an_explicit_expiry_is_still_honoured(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $company->b2b_id,
            '--expires-in-days' => 30,
        ])->assertSuccessful();

        $token = PartnerApiToken::latest('id')->firstOrFail();

        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(30, now()->diffInDays($token->expires_at, false), 1);
    }

    public function test_provisioning_reports_that_the_token_never_expires(): void
    {
        $company = $this->makePartnerCompany();

        // Artisan::call rather than $this->artisan(): this asserts on the whole
        // block the operator actually reads, in one piece.
        $this->assertSame(0, Artisan::call('partner:provision', [
            'slug' => 'shiftmove',
            '--company' => $company->b2b_id,
        ]));

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/Expires\s[\s.]*\sNever/', $output);
        $this->assertStringNotContainsString('token_expired', $output);
    }

    public function test_rotation_also_issues_a_token_with_no_expiry(): void
    {
        config(['partner_api.token.default_expiry_days' => '']);

        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', ['slug' => 'shiftmove', '--company' => $company->b2b_id])
            ->assertSuccessful();

        $rotated = $this->capturedToken('partner:token:rotate', ['slug' => 'shiftmove']);

        $this->assertNull(PartnerApiToken::latest('id')->firstOrFail()->expires_at);

        $this->getJson('/api/v1/partner/me', $this->bearer($rotated))->assertOk();
    }

    public function test_issuing_a_token_does_not_alter_an_existing_ones_expiry(): void
    {
        $company = $this->makePartnerCompany();

        $this->artisan('partner:provision', ['slug' => 'first-partner', '--company' => $company->b2b_id])
            ->assertSuccessful();

        // An already-expiring token, as a server that ran the old code would hold.
        $existing = PartnerApiToken::latest('id')->firstOrFail();
        $existing->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('partner:provision', ['slug' => 'second-partner', '--company' => $company->b2b_id])
            ->assertSuccessful();

        // Untouched: the fix changes what is issued from now on, and nothing
        // reaches back to rewrite credentials a partner may already be using.
        $this->assertTrue($existing->fresh()->expires_at->isYesterday());
        $this->assertNull(PartnerApiToken::latest('id')->firstOrFail()->expires_at);
    }

    /**
     * Run a credential command and pull the plaintext token out of its output.
     *
     * The token is printed exactly once and never returned by an API, so the
     * operator's terminal is the only place it exists — which is precisely
     * what this parses.
     */
    private function capturedToken(string $command, array $arguments): string
    {
        $before = PartnerApiToken::pluck('id')->all();

        // Artisan::call rather than $this->artisan(): the plaintext only ever
        // reaches the operator's terminal, and this is the call that hands
        // back what was actually written there.
        $this->assertSame(0, Artisan::call($command, $arguments), "{$command} failed.");

        $this->assertSame(
            1,
            PartnerApiToken::whereNotIn('id', $before)->count(),
            "{$command} should have issued exactly one token.",
        );

        preg_match('/lbp_(?:sbx|live)_[0-9a-f]{64}/', Artisan::output(), $matches);

        $this->assertNotEmpty($matches, "{$command} did not print a token.");

        return $matches[0];
    }
}
