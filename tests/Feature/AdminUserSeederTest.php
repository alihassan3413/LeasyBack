<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the credentials seeded by AdminUserSeeder — configured through
 * `auth.admin_seed` (ADMIN_SEED_* env) with a local-only password fallback —
 * and the privilege flags it sets via forceFill() (see UserModelTest for why
 * those attributes are deliberately not mass-assignable).
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the defaults so a developer's own ADMIN_SEED_* .env values
        // cannot influence the assertions below.
        config([
            'auth.admin_seed.name' => 'Leasyback Admin',
            'auth.admin_seed.email' => 'admin@leasyback.com',
            'auth.admin_seed.password' => null,
        ]);
    }

    public function test_it_falls_back_to_the_default_local_credentials(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@leasyback.com')->first();

        $this->assertNotNull($admin);
        $this->assertSame('Leasyback Admin', $admin->name);
        $this->assertTrue(Hash::check('12345678', $admin->password));
    }

    public function test_it_uses_the_configured_name_email_and_password(): void
    {
        config([
            'auth.admin_seed.name' => 'Ops Admin',
            'auth.admin_seed.email' => 'ops@example.test',
            'auth.admin_seed.password' => 'a-secret-from-the-environment',
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'ops@example.test')->sole();

        $this->assertSame('Ops Admin', $admin->name);
        $this->assertTrue(Hash::check('a-secret-from-the-environment', $admin->password));
        $this->assertFalse(Hash::check('12345678', $admin->password));
        $this->assertSame(0, User::where('email', 'admin@leasyback.com')->count());
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

    public function test_it_refuses_to_seed_a_default_password_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_SEED_PASSWORD is not set');

        try {
            $this->runSeederDirectly();
        } finally {
            $this->assertSame(0, User::count());
        }
    }

    public function test_it_seeds_in_production_with_a_configured_password(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'auth.admin_seed.email' => 'ops@example.test',
            'auth.admin_seed.password' => 'a-secret-from-the-environment',
        ]);

        $this->runSeederDirectly();

        $admin = User::where('email', 'ops@example.test')->sole();

        $this->assertTrue(Hash::check('a-secret-from-the-environment', $admin->password));
        $this->assertSame('Admin', $admin->user_type->value);
    }

    /**
     * Run the seeder without going through `db:seed`, which refuses to run
     * non-interactively in a production environment.
     */
    private function runSeederDirectly(): void
    {
        $seeder = $this->app->make(AdminUserSeeder::class);
        $seeder->setContainer($this->app);
        $seeder->run();
    }
}
