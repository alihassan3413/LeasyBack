<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Checkpoint 2: verifies the Postgres-specific DB-level constraints added in
 * database/migrations/2026_07_31_000001_*.php and 2026_07_31_000002_*.php.
 *
 * These constraints are deliberately Postgres-only (see the migrations'
 * doc comments for why sqlite/mysql are out of scope). The default test
 * connection in phpunit.xml is sqlite, so the assertions that actually hit
 * Postgres are skipped there and only run when the suite is pointed at a
 * real pgsql connection (e.g. CI/staging) — this file still runs on sqlite
 * to confirm the migrations themselves are no-ops there, not silently
 * broken skips.
 */
class UserSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    public function test_migrations_run_cleanly_regardless_of_driver(): void
    {
        // If this test class can even boot (RefreshDatabase already ran every
        // migration, including the two added in this checkpoint), both
        // migrations executed without error on whatever driver is active.
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_case_insensitive_email_index_exists_on_postgres(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('users_email_lower_unique is Postgres-only; current connection is '.Schema::getConnection()->getDriverName());
        }

        $indexExists = ! empty(DB::select(
            "SELECT 1 FROM pg_indexes WHERE tablename = 'users' AND indexname = 'users_email_lower_unique'"
        ));

        $this->assertTrue($indexExists);
    }

    public function test_case_insensitive_duplicate_email_insert_violates_constraint_on_postgres(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Enforced at the DB level on Postgres only; application-level enforcement (cross-database) is covered in AuthControllerTest.');
        }

        User::factory()->create(['email' => 'Duplicate@Example.com']);

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Someone Else',
            'email' => 'duplicate@example.com',
            'password' => 'irrelevant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_user_type_check_constraint_exists_on_postgres(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('users_user_type_check is Postgres-only; current connection is '.Schema::getConnection()->getDriverName());
        }

        $constraintExists = ! empty(DB::select(
            "SELECT 1 FROM pg_constraint WHERE conname = 'users_user_type_check'"
        ));

        $this->assertTrue($constraintExists);
    }

    public function test_invalid_user_type_direct_insert_violates_constraint_on_postgres(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Enforced at the DB level on Postgres only; application-level enforcement (enum cast + Rule::in) is cross-database.');
        }

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Bad Type',
            'email' => 'bad-type@example.com',
            'user_type' => 'NotARealType',
            'password' => 'irrelevant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
