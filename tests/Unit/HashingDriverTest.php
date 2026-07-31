<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Checkpoint 1: verifies the production hashing driver decision
 * (config/hashing.php -> argon2id) on its own merits. No Rust
 * involvement — greenfield per docs/AUTH_PRODUCTION_IMPLEMENTATION_PLAN.md
 * Decision 0.
 */
class HashingDriverTest extends TestCase
{
    public function test_default_driver_is_argon2id(): void
    {
        $this->assertSame('argon2id', config('hashing.driver'));
    }

    public function test_hash_make_produces_an_argon2id_phc_string(): void
    {
        $hash = Hash::make('a-strong-password');

        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function test_hash_check_verifies_the_correct_password(): void
    {
        $hash = Hash::make('a-strong-password');

        $this->assertTrue(Hash::check('a-strong-password', $hash));
    }

    public function test_hash_check_rejects_the_wrong_password(): void
    {
        $hash = Hash::make('a-strong-password');

        $this->assertFalse(Hash::check('totally-different-password', $hash));
    }

    public function test_hash_check_rejects_a_hash_from_a_different_algorithm(): void
    {
        // 'verify' => true (config/hashing.php) makes the Argon2id hasher refuse
        // to check a value that isn't actually an Argon2id hash, rather than
        // silently returning false. This is a deliberate safety net against a
        // hash ever being verified under the wrong algorithm — not a bug.
        $bcryptHash = password_hash('a-strong-password', PASSWORD_BCRYPT);

        $this->expectException(\RuntimeException::class);

        Hash::check('a-strong-password', $bcryptHash);
    }
}
