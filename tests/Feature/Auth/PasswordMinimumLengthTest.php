<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordMinimumLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_web_registration_rejects_an_11_character_password(): void
    {
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'short@example.com',
            'password' => 'Abcdefgh123', // 11 characters
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'short@example.com']);
    }

    public function test_web_registration_accepts_a_12_character_password(): void
    {
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'long@example.com',
            'password' => 'Abcdefgh1234', // 12 characters
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'long@example.com']);
    }

    public function test_api_registration_rejects_an_11_character_password(): void
    {
        $this->postJson('/api/auth/register', [
            'user_type' => 'Privatkunde',
            'user_email' => 'api-short@example.com',
            'password' => 'Abcdefgh123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'api-short@example.com']);
    }

    public function test_api_registration_accepts_a_12_character_password(): void
    {
        $this->postJson('/api/auth/register', [
            'user_type' => 'Privatkunde',
            'user_email' => 'api-long@example.com',
            'password' => 'Abcdefgh1234',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('users', ['email' => 'api-long@example.com']);
    }

    public function test_an_existing_user_with_an_old_8_character_password_can_still_log_in(): void
    {
        User::factory()->create([
            'email' => 'old-user@example.com',
            'password' => Hash::make('oldpass8'),
            'is_active' => true,
        ]);

        // ok => true either way: a token, or an MFA ticket if MFA applies.
        // What matters is that the short password is NOT rejected.
        $this->postJson('/api/auth/login', [
            'user_email' => 'old-user@example.com',
            'password' => 'oldpass8',
        ])->assertOk()->assertJson(['ok' => true]);
    }
}