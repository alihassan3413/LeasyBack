<?php

namespace Tests\Feature\Api;

use App\Mail\RegistrationWelcome;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Checkpoint 1 baseline: locks in the CURRENT behavior of Api\AuthController
 * (register/login/changepassword/logout/me) before any production-hardening
 * changes land in later checkpoints. Some assertions here document behavior
 * that is intentionally going to change (see docs/AUTH_PRODUCTION_IMPLEMENTATION_PLAN.md
 * Checkpoint 4) — they exist to make those changes visible as intentional
 * diffs, not silent regressions.
 */
class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_returns_expected_contract(): void
    {
        Mail::fake();

        $response = $this->postJson('/auth/register', [
            'user_email' => 'new.user@example.com',
            'user_type' => 'Privatkunde',
            'password' => 'a-strong-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'ok', 'data' => ['user_id', 'user_email', 'user_type', 'created_at'], 'message',
            ])
            ->assertJson([
                'ok' => true,
                'message' => 'User registered',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'new.user@example.com',
            'user_type' => 'Privatkunde',
        ]);

        $user = User::where('email', 'new.user@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
    }

    public function test_registration_welcome_email_is_queued_not_sent_synchronously(): void
    {
        Mail::fake();

        $this->postJson('/auth/register', [
            'user_email' => 'queued-mail@example.com',
            'user_type' => 'Privatkunde',
            'password' => 'a-strong-password',
        ]);

        Mail::assertQueued(RegistrationWelcome::class, function (RegistrationWelcome $mail) {
            return $mail->hasTo('queued-mail@example.com');
        });
        Mail::assertNotSent(RegistrationWelcome::class);
    }

    public function test_registration_succeeds_even_when_mail_delivery_fails(): void
    {
        // Forces the exact failure mode AuthController::register's try/catch
        // exists to survive: the mail system itself throwing (e.g. SendGrid
        // unreachable), not just a normal Mail::fake() no-op.
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SendGrid unreachable'));
        Log::shouldReceive('error')->once()->with('Registration email failed', \Mockery::on(
            fn (array $context) => $context['email'] === 'mail-outage@example.com'
        ));

        $response = $this->postJson('/auth/register', [
            'user_email' => 'mail-outage@example.com',
            'user_type' => 'Privatkunde',
            'password' => 'a-strong-password',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'message' => 'User registered']);
        $this->assertDatabaseHas('users', ['email' => 'mail-outage@example.com']);
    }

    public function test_register_derives_name_from_email_local_part_when_absent(): void
    {
        Mail::fake();

        $this->postJson('/auth/register', [
            'user_email' => 'jane.doe@example.com',
            'user_type' => 'Firmenkunde',
            'password' => 'a-strong-password',
        ])->assertOk();

        $user = User::where('email', 'jane.doe@example.com')->firstOrFail();
        $this->assertSame('jane.doe', $user->name);
    }

    public function test_register_rejects_invalid_user_type(): void
    {
        Mail::fake();

        $response = $this->postJson('/auth/register', [
            'user_email' => 'someone@example.com',
            'user_type' => 'NotARealType',
            'password' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }

    public function test_register_rejects_admin_self_registration(): void
    {
        Mail::fake();

        $response = $this->postJson('/auth/register', [
            'user_email' => 'wannabe-admin@example.com',
            'user_type' => 'Admin',
            'password' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'wannabe-admin@example.com']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->postJson('/auth/register', [
            'user_email' => 'duplicate@example.com',
            'user_type' => 'Privatkunde',
            'password' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_rejects_case_insensitive_duplicate_email(): void
    {
        // Cross-database enforcement (RegisterRequest's whereRaw(LOWER(...)) check) —
        // works on every driver, unlike the Postgres-only functional unique index.
        Mail::fake();

        User::factory()->create(['email' => 'CaseSensitive@Example.com']);

        $response = $this->postJson('/auth/register', [
            'user_email' => 'casesensitive@example.com',
            'user_type' => 'Privatkunde',
            'password' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['casesensitive@example.com'])->count());
    }

    public function test_register_rejects_user_type_not_in_registrable_values_even_though_admin_exists_in_the_enum(): void
    {
        // user_type is mass-assignment-guarded on the model (User::$fillable) and
        // set explicitly in AuthController::register — this proves the full path
        // (validation + controller) still rejects Admin end-to-end after that change.
        Mail::fake();

        $response = $this->postJson('/auth/register', [
            'user_email' => 'still-not-admin@example.com',
            'user_type' => 'Admin',
            'password' => 'a-strong-password',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'still-not-admin@example.com']);
    }

    public function test_login_with_correct_credentials_returns_token(): void
    {
        $user = User::factory()->create([
            'email' => 'login-test@example.com',
            'password' => Hash::make('correct-password'),
            'user_type' => 'Privatkunde',
        ]);

        $response = $this->postJson('/auth/login', [
            'user_email' => 'login-test@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['ok', 'data' => ['token', 'user_id', 'user_type'], 'message'])
            ->assertJson([
                'ok' => true,
                'data' => ['user_id' => $user->id, 'user_type' => 'Privatkunde'],
            ]);
    }

    public function test_login_is_case_insensitive_on_email(): void
    {
        User::factory()->create([
            'email' => 'CaseSensitive@Example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/auth/login', [
            'user_email' => 'casesensitive@example.com',
            'password' => 'correct-password',
        ])->assertOk();
    }

    public function test_login_with_wrong_password_returns_generic_401(): void
    {
        User::factory()->create([
            'email' => 'login-test@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/auth/login', [
            'user_email' => 'login-test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['ok' => false, 'data' => null, 'message' => 'Invalid credentials.']);
    }

    public function test_login_with_nonexistent_email_returns_generic_401(): void
    {
        $response = $this->postJson('/auth/login', [
            'user_email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401)
            ->assertJson(['ok' => false, 'data' => null, 'message' => 'Invalid credentials.']);
    }

    public function test_changepassword_requires_authentication(): void
    {
        $response = $this->postJson('/auth/changepassword', [
            'current_password' => 'whatever',
            'new_password' => 'a-new-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_changepassword_with_wrong_current_password_fails(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/auth/changepassword', [
                'current_password' => 'wrong-password',
                'new_password' => 'a-new-password',
            ]);

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Current password is incorrect.']);
    }

    public function test_changepassword_rejects_new_password_same_as_current(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/auth/changepassword', [
                'current_password' => 'correct-password',
                'new_password' => 'correct-password',
            ]);

        $response->assertStatus(422);
    }

    public function test_changepassword_rejects_weak_new_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/auth/changepassword', [
                'current_password' => 'correct-password',
                'new_password' => 'short',
            ]);

        $response->assertStatus(422);
    }

    public function test_changepassword_succeeds_and_updates_hash(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/auth/changepassword', [
                'current_password' => 'correct-password',
                'new_password' => 'a-brand-new-password',
            ]);

        $response->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Password updated successfully.']);

        $this->assertTrue(Hash::check('a-brand-new-password', $user->refresh()->password));
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/auth/logout')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Logged out successfully.']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_me_returns_current_user(): void
    {
        $user = User::factory()->create(['user_type' => 'Firmenkunde']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/auth/me');

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'user_type' => 'Firmenkunde',
                ],
            ]);
    }

    public function test_login_rejects_a_deactivated_account_with_the_same_generic_message(): void
    {
        // Checkpoint 4: is_active was never checked at login before this.
        // Must be indistinguishable from wrong-password/nonexistent-email.
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/auth/login', [
            'user_email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['ok' => false, 'data' => null, 'message' => 'Invalid credentials.']);
    }

    public function test_deactivated_user_token_is_rejected_on_protected_routes(): void
    {
        // Checkpoint 4: a token issued before deactivation must stop working
        // immediately, not just block new logins.
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('test')->plainTextToken;

        // is_active is intentionally not mass-assignable (Checkpoint 2) —
        // explicit attribute assignment, same as trusted code elsewhere.
        $user->is_active = false;
        $user->save();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/auth/me');

        $response->assertStatus(403)
            ->assertJson(['ok' => false, 'data' => null, 'message' => 'This account has been deactivated.']);

        // The token used to discover this must itself be revoked as a side effect.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unauthenticated_request_to_protected_route_uses_the_standard_envelope(): void
    {
        // Checkpoint 4: Sanctum's AuthenticationException previously rendered
        // Laravel's raw default ({"message":"Unauthenticated."}), not our
        // {ok,data,message} contract.
        $response = $this->getJson('/auth/me');

        $response->assertStatus(401)
            ->assertJson(['ok' => false, 'data' => null, 'message' => 'Unauthenticated.']);
    }

    public function test_uncaught_exception_never_leaks_internal_details(): void
    {
        // Checkpoint 4: forces the exact scenario found in Checkpoint 1 —
        // Hash::check() throws RuntimeException("This password does not use
        // the Argon2id algorithm.") when the stored value isn't a real
        // Argon2id hash. Before the global exception handler, this would
        // leak that message (or a full stack trace under APP_DEBUG=true).
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)
            ->update(['password' => 'not-a-real-hash']);

        $response = $this->postJson('/auth/login', [
            'user_email' => $user->email,
            'password' => 'anything',
        ]);

        $response->assertStatus(500)
            ->assertJson(['ok' => false, 'data' => null, 'message' => 'Something went wrong. Please try again later.']);

        $response->assertDontSee('Argon2id', false);
        $response->assertDontSee('RuntimeException', false);
    }

    public function test_register_endpoint_is_rate_limited(): void
    {
        Mail::fake();

        // routes/api.php: throttle:5,1 — 5 requests/minute.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/register', [
                'user_email' => "rate-limit-{$i}@example.com",
                'user_type' => 'Privatkunde',
                'password' => 'a-strong-password',
            ]);
        }

        $response = $this->postJson('/auth/register', [
            'user_email' => 'rate-limit-6@example.com',
            'user_type' => 'Privatkunde',
            'password' => 'a-strong-password',
        ]);

        $response->assertStatus(429);
        $this->assertDatabaseMissing('users', ['email' => 'rate-limit-6@example.com']);
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        // routes/api.php: throttle:10,1 — 10 requests/minute.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/auth/login', [
                'user_email' => 'nobody@example.com',
                'password' => 'whatever',
            ]);
        }

        $response = $this->postJson('/auth/login', [
            'user_email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(429);
    }

    public function test_changepassword_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $token = $user->createToken('test')->plainTextToken;

        // routes/api.php: throttle:5,1 — 5 requests/minute.
        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/auth/changepassword', [
                    'current_password' => 'wrong-password',
                    'new_password' => 'a-new-password',
                ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/auth/changepassword', [
                'current_password' => 'correct-password',
                'new_password' => 'a-new-password',
            ]);

        $response->assertStatus(429);
    }
}
