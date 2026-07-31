<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 2: proves the mass-assignment hardening on User::$fillable.
 * `user_type` and `is_active` must never be settable via bulk-assigned
 * request input (fill()/create()) — only through explicit, reviewed code
 * (AuthController::register, AdminUserSeeder), which use direct attribute
 * assignment / forceFill() specifically because they are trusted call sites.
 */
class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_type_is_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill([
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'user_type' => 'Admin',
        ]);

        $this->assertNull($user->user_type);
        $this->assertSame('Attacker', $user->name);
    }

    public function test_is_active_is_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill([
            'name' => 'Attacker',
            'is_active' => false,
        ]);

        $this->assertNull($user->is_active);
    }

    public function test_create_with_user_type_in_the_attributes_array_silently_ignores_it(): void
    {
        // Documents the exact footgun this hardening closes: a naive
        // User::create($request->all())-style call cannot smuggle 'Admin'
        // through. The column falls back to its DB default (Privatkunde) —
        // not the attacker-supplied value, and never Admin.
        $user = User::create([
            'name' => 'Someone',
            'email' => 'someone-else@example.com',
            'password' => 'irrelevant-for-this-test',
            'user_type' => 'Admin',
        ]);

        $this->assertSame('Privatkunde', $user->fresh()->user_type->value);
    }

    public function test_explicit_attribute_assignment_still_works_for_trusted_code(): void
    {
        $user = User::create([
            'name' => 'Someone',
            'email' => 'trusted-path@example.com',
            'password' => 'irrelevant-for-this-test',
        ]);

        $user->user_type = 'Firmenkunde';
        $user->save();

        $this->assertSame('Firmenkunde', $user->fresh()->user_type->value);
    }
}
