<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevUserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Covers the seeders that run on a production deploy (`db:seed --force`).
 * They must stay factory-free — production installs with
 * `composer install --no-dev`, where fakerphp/faker is absent and `fake()`
 * is an undefined function — deterministic, and safe to rerun.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.admin_seed.name' => 'Leasyback Admin',
            'auth.admin_seed.email' => 'admin@leasyback.com',
            'auth.admin_seed.password' => null,
        ]);
    }

    public function test_it_seeds_the_admin_and_the_development_user_locally(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@leasyback.com')->sole();
        $developmentUser = User::where('email', 'test@example.com')->sole();

        $this->assertSame(UserType::Admin, $admin->user_type);
        $this->assertSame('Test User', $developmentUser->name);
        $this->assertSame(UserType::Privatkunde, $developmentUser->user_type);
        $this->assertTrue($developmentUser->is_active);
        $this->assertTrue(Hash::check('password', $developmentUser->password));
    }

    public function test_it_creates_no_duplicates_when_rerun(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, User::count());
        $this->assertSame(1, User::where('email', 'admin@leasyback.com')->count());
        $this->assertSame(1, User::where('email', 'test@example.com')->count());
    }

    public function test_it_skips_the_development_user_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['auth.admin_seed.password' => 'a-secret-from-the-environment']);

        $this->runSeederDirectly(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(1, User::where('email', 'admin@leasyback.com')->count());
        $this->assertSame(0, User::where('email', 'test@example.com')->count());
    }

    public function test_the_development_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->runSeederDirectly(DevUserSeeder::class);

        $this->assertSame(0, User::count());
    }

    /**
     * Regression guard for the production seeding failure this replaced:
     * `Call to undefined function Database\Factories\fake()`. Seeders reached
     * by `db:seed` in production may not touch factories or Faker.
     *
     * @return array<int, array{0: class-string<Seeder>}>
     */
    public static function productionSeeders(): array
    {
        return [
            [DatabaseSeeder::class],
            [AdminUserSeeder::class],
        ];
    }

    /**
     * @param  class-string<Seeder>  $seeder
     */
    #[DataProvider('productionSeeders')]
    public function test_production_seeders_use_neither_factories_nor_faker(string $seeder): void
    {
        $code = $this->sourceWithoutComments($seeder);

        $this->assertStringNotContainsString('factory(', $code);
        $this->assertStringNotContainsString('fake(', $code);
        $this->assertStringNotContainsString('Faker', $code);
    }

    /**
     * The seeder's source with comments stripped, so that prose mentioning
     * `fake()` does not trip the assertions above.
     *
     * @param  class-string<Seeder>  $seeder
     */
    private function sourceWithoutComments(string $seeder): string
    {
        $file = (new ReflectionClass($seeder))->getFileName();

        $tokens = token_get_all((string) file_get_contents((string) $file));

        return collect($tokens)
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');
    }

    /**
     * Run a seeder without going through `db:seed`, which refuses to run
     * non-interactively in a production environment.
     *
     * @param  class-string<Seeder>  $seeder
     */
    private function runSeederDirectly(string $seeder): void
    {
        $instance = $this->app->make($seeder);
        $instance->setContainer($this->app);
        $instance->run();
    }
}
