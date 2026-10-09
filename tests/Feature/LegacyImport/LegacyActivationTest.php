<?php

namespace Tests\Feature\LegacyImport;

use App\Enums\B2bPermission;
use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Jobs\SendLegacyActivationMail;
use App\Mail\LegacyAccountActivation;
use App\Models\LegacyActivationMail;
use App\Models\LegacyImportMap;
use App\Models\User;
use App\Support\LegacyImport\LegacyActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The go-live activation of users imported from Base44 (whose passwords could
 * not be migrated): who is mailed, that nobody is mailed twice or by accident,
 * and that the link sets a password after which login works as for anyone.
 */
class LegacyActivationTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const PORTAL = 'https://portal.leasyback.de';

    private const NEW_PASSWORD = 'Neu-Passwort-2026!x';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::PORTAL, 'queue.default' => 'sync']);
        URL::forceRootUrl(self::PORTAL);
        URL::forceScheme('https');
    }

    /** A user UserStep imported: a V2 account with an unusable password, recorded in the map. */
    private function imported(string $email, array $attributes = [], ?User $user = null): User
    {
        $user ??= User::factory()->create(['email' => $email, 'user_type' => UserType::Firmenkunde, 'password' => Hash::make(Str::random(64))]);
        $user->forceFill($attributes)->save();
        $this->map($email, 'imported', $user);

        return $user;
    }

    private function map(string $email, string $status, ?User $user = null, array $payload = []): void
    {
        LegacyImportMap::create([
            'entity' => 'user',
            'legacy_id' => mb_strtolower($email),
            'status' => $status,
            'target_table' => $user ? 'users' : null,
            'target_id' => $user ? (string) $user->id : null,
            'payload' => $payload ?: null,
            'batch_id' => (string) Str::uuid(),
        ]);
    }

    /** The population of a real import: two to mail, and everyone who must not be. */
    private function population(): void
    {
        $this->imported('anna@firma.test');
        $this->imported('bernd@firma.test');
        $this->imported('inaktiv@firma.test', ['is_active' => false]);
        $this->map('linked@firma.test', 'linked', User::factory()->create(['email' => 'linked@firma.test']));
        $this->map('admin@base44.test', 'skipped', payload: ['reason' => 'staff_admin_manual_review']);
        $this->map('ohnefirma@firma.test', 'skipped', payload: ['reason' => 'active_user_without_company_manual_review']);
        $this->map('kaputt', 'skipped', payload: ['reason' => 'invalid_email']);
    }

    // ------------------------------------------------------------- targeting

    public function test_a_dry_run_reports_who_would_be_mailed_and_changes_nothing(): void
    {
        Queue::fake();
        $this->population();

        $this->artisan('legacy:send-activation-emails', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsTable(['', 'Users'], [
                ['Imported Base44 users considered', 3],
                ['Would be mailed now', 2],
                ['Queued now', 0],
                ['Skipped: inactive_user', 1],
            ])
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('legacy_activation_mails', 0);
    }

    /** --all mails imported, active users only: never linked accounts, manual-review or invalid ones. */
    public function test_all_queues_only_successfully_imported_active_users(): void
    {
        Queue::fake();
        $this->population();

        $this->artisan('legacy:send-activation-emails', ['--all' => true])->assertSuccessful();

        Queue::assertPushed(SendLegacyActivationMail::class, 2);
        $this->assertEqualsCanonicalizing(
            ['anna@firma.test', 'bernd@firma.test'],
            LegacyActivationMail::where('status', 'queued')->pluck('email')->all(),
        );
        $this->assertDatabaseHas('legacy_activation_mails', ['email' => 'inaktiv@firma.test', 'status' => 'skipped', 'skip_reason' => 'inactive_user']);
        $this->assertDatabaseMissing('legacy_activation_mails', ['email' => 'linked@firma.test']);
    }

    public function test_an_imported_user_with_an_invalid_address_is_skipped(): void
    {
        Queue::fake();
        $user = $this->imported('placeholder@firma.test');
        $user->forceFill(['email' => 'kein-gueltiger-empfaenger'])->save();

        $this->artisan('legacy:send-activation-emails', ['--all' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('legacy_activation_mails', ['user_id' => $user->id, 'status' => 'skipped', 'skip_reason' => 'invalid_email']);
    }

    /** Nothing is sent without saying who: no flag is a refusal, not "everyone". */
    public function test_a_real_run_needs_email_or_all(): void
    {
        Queue::fake();
        $this->population();

        $this->artisan('legacy:send-activation-emails')->assertFailed();
        $this->artisan('legacy:send-activation-emails', ['--all' => true, '--email' => 'anna@firma.test'])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_one_test_account_can_be_mailed_alone_and_others_say_why_not(): void
    {
        Queue::fake();
        $this->population();

        $this->artisan('legacy:send-activation-emails', ['--email' => 'Anna@Firma.test'])
            ->expectsOutputToContain('anna@firma.test: activation mail queued.')
            ->assertSuccessful();
        Queue::assertPushed(SendLegacyActivationMail::class, 1);

        $this->artisan('legacy:send-activation-emails', ['--email' => 'linked@firma.test'])
            ->expectsOutputToContain('existing_v2_account_has_a_password')->assertSuccessful();
        $this->artisan('legacy:send-activation-emails', ['--email' => 'admin@base44.test'])
            ->expectsOutputToContain('staff_admin_manual_review')->assertSuccessful();
        $this->artisan('legacy:send-activation-emails', ['--email' => 'fremd@nirgendwo.test'])
            ->expectsOutputToContain('not_imported_from_base44')->assertSuccessful();

        Queue::assertPushed(SendLegacyActivationMail::class, 1);
    }

    // ------------------------------------------------- duplicates and resuming

    public function test_rerunning_never_mails_anyone_twice(): void
    {
        Queue::fake();
        $this->population();

        $this->artisan('legacy:send-activation-emails', ['--all' => true])->assertSuccessful();
        $this->artisan('legacy:send-activation-emails', ['--all' => true])
            ->expectsOutputToContain('Skipped: already_queued')
            ->assertSuccessful();

        Queue::assertPushed(SendLegacyActivationMail::class, 2);
    }

    /** A partial run resumes where it stopped; a failed mail is retried only on request. */
    public function test_a_partial_run_resumes_and_failures_are_retried_only_on_request(): void
    {
        Queue::fake();
        $this->population();

        $this->artisan('legacy:send-activation-emails', ['--all' => true, '--limit' => 1])->assertSuccessful();
        Queue::assertPushed(SendLegacyActivationMail::class, 1);

        $this->artisan('legacy:send-activation-emails', ['--all' => true])->assertSuccessful();
        Queue::assertPushed(SendLegacyActivationMail::class, 2);

        LegacyActivationMail::where('email', 'anna@firma.test')->update(['status' => 'failed', 'last_error' => 'SMTP down']);

        $this->artisan('legacy:send-activation-emails', ['--all' => true])
            ->expectsOutputToContain('failed_before_use_retry_failed')->assertSuccessful();
        Queue::assertPushed(SendLegacyActivationMail::class, 2);

        $this->artisan('legacy:send-activation-emails', ['--all' => true, '--retry-failed' => true])->assertSuccessful();
        Queue::assertPushed(SendLegacyActivationMail::class, 3);
    }

    // --------------------------------------------------------------- guards

    public function test_it_refuses_a_link_without_https_or_a_queue_that_discards_mail(): void
    {
        Queue::fake();
        $this->population();

        config(['app.url' => 'http://63.182.187.19']);
        $this->artisan('legacy:send-activation-emails', ['--all' => true])->expectsOutputToContain('final HTTPS domain')->assertFailed();
        Queue::assertNothingPushed();

        config(['app.url' => self::PORTAL, 'queue.default' => 'null']);
        $this->artisan('legacy:send-activation-emails', ['--all' => true])->expectsOutputToContain('QUEUE_CONNECTION is null')->assertFailed();
        Queue::assertNothingPushed();

        // A dry run needs neither.
        config(['app.url' => 'http://63.182.187.19']);
        $this->artisan('legacy:send-activation-emails', ['--dry-run' => true])->assertSuccessful();
    }

    public function test_production_needs_its_confirmation_except_for_a_dry_run(): void
    {
        Queue::fake();
        $this->population();
        $this->app['env'] = 'production';

        $this->artisan('legacy:send-activation-emails', ['--all' => true])->assertFailed();
        $this->artisan('legacy:send-activation-emails', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('legacy:send-activation-emails', ['--all' => true, '--confirm-production' => true])->assertSuccessful();

        Queue::assertPushed(SendLegacyActivationMail::class, 2);
    }

    // ------------------------------------------------------------- the mail

    /** Queued, then sent once, with a one-time HTTPS link on APP_URL and no password in it. */
    public function test_the_queued_job_sends_one_mail_with_a_secure_link(): void
    {
        Mail::fake();
        $user = $this->imported('anna@firma.test');

        $this->artisan('legacy:send-activation-emails', ['--email' => 'anna@firma.test'])->assertSuccessful();
        // The sync queue ran the job; running it again is a no-op.
        (new SendLegacyActivationMail(LegacyActivationMail::sole()->id))->handle(app(LegacyActivation::class));

        Mail::assertSent(LegacyAccountActivation::class, 1);
        Mail::assertSent(LegacyAccountActivation::class, function (LegacyAccountActivation $mail) use ($user) {
            return $mail->hasTo('anna@firma.test')
                && str_starts_with($mail->activationUrl, self::PORTAL.'/konto-aktivieren/')
                && str_contains($mail->activationUrl, 'email='.urlencode('anna@firma.test'))
                && $mail->user->is($user);
        });

        $tracked = LegacyActivationMail::sole();
        $this->assertSame('sent', $tracked->status);
        $this->assertNotNull($tracked->sent_at);
        $this->assertSame(1, $tracked->attempts);
        $this->assertDatabaseHas('legacy_activation_tokens', ['email' => 'anna@firma.test']);

        $html = (new LegacyAccountActivation($user, self::PORTAL.'/konto-aktivieren/abc?email=anna%40firma.test', 14))->render();
        $this->assertStringContainsString('Konto aktivieren', $html);
        $this->assertStringContainsString('14 Tage', $html);
        $this->assertStringNotContainsStringIgnoringCase('Ihr Passwort lautet', $html);
    }

    public function test_a_mail_that_cannot_be_sent_is_marked_failed_with_the_reason(): void
    {
        $user = $this->imported('anna@firma.test');
        app(LegacyActivation::class)->claim($user, false);
        $job = new SendLegacyActivationMail(LegacyActivationMail::sole()->id);

        $job->failed(new RuntimeException('Connection to SMTP refused'));

        $this->assertDatabaseHas('legacy_activation_mails', ['user_id' => $user->id, 'status' => 'failed', 'last_error' => 'Connection to SMTP refused']);
    }

    // ------------------------------------------------------------ the link

    private function mailedLink(string $email): string
    {
        Mail::fake();
        $this->artisan('legacy:send-activation-emails', ['--email' => $email])->assertSuccessful();

        $link = null;
        Mail::assertSent(LegacyAccountActivation::class, function (LegacyAccountActivation $mail) use (&$link) {
            $link = $mail->activationUrl;

            return true;
        });

        return (string) $link;
    }

    /** activation link → new password → normal login, with the MFA rules every account has. */
    public function test_the_link_sets_a_password_and_login_follows_the_normal_mfa_rules(): void
    {
        config(['mfa.enabled' => true, 'mfa.required' => false, 'mfa.required_for' => ['admin', 'b2b_owner'], 'mfa.exempt_emails' => []]);
        Mail::fake();

        $company = $this->makeCompany();
        $owner = $this->imported('chefin@firma.test', user: $this->makeOwner($company)->forceFill(['email' => 'chefin@firma.test']));
        $member = $this->imported('team@firma.test', user: $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray())->forceFill(['email' => 'team@firma.test']));

        foreach ([[$owner, route('mfa.setup')], [$member, route('dashboard')]] as [$user, $landing]) {
            $link = $this->mailedLink($user->email);
            $path = parse_url($link, PHP_URL_PATH);
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
            $token = basename((string) $path);

            $this->get($path.'?'.http_build_query($query))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('auth/ResetPassword')
                    ->where('submitRoute', 'legacy-activation.store')
                    ->where('email', $user->email));

            $this->post(route('legacy-activation.store'), [
                'token' => $token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

            $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
            $this->assertNotNull(LegacyActivationMail::where('user_id', $user->id)->value('activated_at'));

            // One time only.
            $this->post(route('legacy-activation.store'), [
                'token' => $token, 'email' => $user->email, 'password' => 'Anders-2026!xyz', 'password_confirmation' => 'Anders-2026!xyz',
            ])->assertSessionHasErrors('email');

            // A normal login — and MFA exactly where the existing policy requires it (owners), not for members.
            $this->post('/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])->assertRedirect(route('dashboard'));
            $landing === route('dashboard')
                ? $this->get(route('dashboard'))->assertOk()
                : $this->get(route('dashboard'))->assertRedirect($landing);
            $this->post(route('logout'));
        }
    }

    /**
     * Activation sets the password and the activation state — nothing else.
     * Role, permissions, vehicle scope, membership, owner status and company
     * ids stay exactly as the Base44 import created them, through the whole
     * flow: command, queued mail, link, new password, first login.
     */
    public function test_activation_changes_only_the_password_never_role_permissions_or_company(): void
    {
        Mail::fake();
        $company = $this->makeCompany('Import GmbH');
        $otherCompany = $this->makeCompany('Zweitfirma AG');
        $owner = $this->imported('chefin@firma.test', user: $this->makeOwner($company)->forceFill(['email' => 'chefin@firma.test']));
        $member = $this->imported('team@firma.test', user: $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::CreateOrders->value], 'own')->forceFill(['email' => 'team@firma.test']));
        DB::table('user_b2b')->insert([
            'user_id' => $member->id, 'b2b_id' => $otherCompany->b2b_id, 'role' => 'member',
            'permissions' => json_encode([B2bPermission::ViewVehicles->value]), 'vehicle_scope' => 'all', 'status' => 'active',
            'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $snapshot = fn () => [
            'users' => DB::table('users')->whereIn('id', [$owner->id, $member->id])->orderBy('id')->get()
                ->map(fn ($row) => collect((array) $row)->except(['password', 'remember_token', 'updated_at'])->all())->all(),
            'memberships' => DB::table('user_b2b')->whereIn('user_id', [$owner->id, $member->id])->orderBy('user_id')->orderBy('b2b_id')->get()
                ->map(fn ($row) => (array) $row)->all(),
            'companies' => DB::table('b2b')->whereIn('b2b_id', [$company->b2b_id, $otherCompany->b2b_id])->orderBy('b2b_id')->get()
                ->map(fn ($row) => (array) $row)->all(),
        ];
        $before = $snapshot();

        foreach ([$owner, $member] as $user) {
            $link = $this->mailedLink($user->email);

            $this->post(route('legacy-activation.store'), [
                'token' => basename((string) parse_url($link, PHP_URL_PATH)),
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        }

        // Mail sent, link used, password set: everything else is exactly as imported.
        $this->assertEquals($before, $snapshot(), 'activation must not touch role, permissions, membership, owner status or company ids');

        foreach ([$owner, $member] as $user) {
            $this->post('/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD]);
            $this->get(route('dashboard'));
            $this->post(route('logout'));
        }

        // The first login changes nothing either — except what it does for every
        // user: B2bContext remembers which of their *own* companies is active.
        $after = $snapshot();
        $this->assertEquals($before['memberships'], $after['memberships']);
        $this->assertEquals($before['companies'], $after['companies']);

        foreach ($after['users'] as $index => $row) {
            $this->assertEquals(collect($before['users'][$index])->except('active_b2b_id')->all(), collect($row)->except('active_b2b_id')->all());
            $this->assertContains($row['active_b2b_id'], DB::table('user_b2b')->where('user_id', $row['id'])->pluck('b2b_id')->all(), 'only a company the user already belongs to');
        }

        // And it did do its one job.
        foreach ([$owner, $member] as $user) {
            $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
            $this->assertNotNull(LegacyActivationMail::where('user_id', $user->id)->value('activated_at'));
        }
        $this->assertSame('owner', DB::table('user_b2b')->where('user_id', $owner->id)->value('role'));
        $this->assertSame('own', DB::table('user_b2b')->where('user_id', $member->id)->where('b2b_id', $company->b2b_id)->value('vehicle_scope'));
    }

    public function test_an_expired_link_is_refused(): void
    {
        $this->imported('anna@firma.test');
        $link = $this->mailedLink('anna@firma.test');

        $this->travel(15)->days();

        $this->post(route('legacy-activation.store'), [
            'token' => basename((string) parse_url($link, PHP_URL_PATH)),
            'email' => 'anna@firma.test',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');
    }

    /** Activation and "Passwort vergessen" never interfere; a normal reset also counts as activation. */
    public function test_activation_and_the_normal_reset_stay_separate(): void
    {
        $user = $this->imported('anna@firma.test');
        $activationToken = basename((string) parse_url($this->mailedLink('anna@firma.test'), PHP_URL_PATH));
        $resetToken = Password::broker()->createToken($user);

        // Each token works only on its own route; creating one did not erase the other.
        $this->post(route('password.store'), ['token' => $activationToken, 'email' => 'anna@firma.test', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('email');
        $this->post(route('legacy-activation.store'), ['token' => $resetToken, 'email' => 'anna@firma.test', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'anna@firma.test']);
        $this->assertDatabaseHas('legacy_activation_tokens', ['email' => 'anna@firma.test']);

        // An imported user who resets normally before the campaign is activated — and not mailed later.
        $other = $this->imported('bernd@firma.test');
        $this->post(route('password.store'), ['token' => Password::broker()->createToken($other), 'email' => 'bernd@firma.test', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect(route('login'));
        $this->assertDatabaseHas('legacy_activation_mails', ['user_id' => $other->id, 'skip_reason' => 'activated_before_mailing']);

        Queue::fake();
        $this->artisan('legacy:send-activation-emails', ['--email' => 'bernd@firma.test'])->expectsOutputToContain('already_activated')->assertSuccessful();
        Queue::assertNothingPushed();

        // A user who never came from Base44 resets as before, untracked.
        $native = User::factory()->create(['email' => 'neu@firma.test']);
        $this->post(route('password.store'), ['token' => Password::broker()->createToken($native), 'email' => 'neu@firma.test', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertRedirect(route('login'));
        $this->assertDatabaseMissing('legacy_activation_mails', ['user_id' => $native->id]);
    }
}
