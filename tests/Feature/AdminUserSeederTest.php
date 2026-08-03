<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pins the default local Admin credentials seeded by AdminUserSeeder, and the
 * privilege flags it sets via forceFill() (see UserModelTest for why those
 * attributes are deliberately not mass-assignable).
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_admin_with_the_default_credentials(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@leasyback.com')->first();

        $this->assertNotNull($admin);
        $this->assertSame('Leasyback Admin', $admin->name);
        $this->assertTrue(Hash::check('12345678', $admin->password));
    }

    public function test_it_grants_the_admin_type_and_activates_the_account(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@leasyback.com')->sole();

        $this->assertSame('Admin', $admin->user_type->value);
        $this->assertTrue($admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_it_is_idempotent_and_resets_the_password_on_reseed(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@leasyback.com')->sole();
        $admin->forceFill(['password' => Hash::make('something-else')])->save();

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@leasyback.com')->count());
        $this->assertTrue(Hash::check('12345678', $admin->fresh()->password));
    }
}
