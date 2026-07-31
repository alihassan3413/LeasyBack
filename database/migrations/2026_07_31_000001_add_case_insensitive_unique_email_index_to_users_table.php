<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforces case-insensitive email uniqueness at the database level.
     *
     * Postgres only: Laravel's plain unique() index on `email` (already present
     * from the base users migration) is case-sensitive under Postgres' default
     * collation, so "User@x.com" and "user@x.com" could both be inserted as
     * distinct rows via a direct DB write. This closes that gap as defense in
     * depth alongside the application-level case-insensitive check added to
     * RegisterRequest (which works on every database driver, including the
     * sqlite connection used for local/test).
     *
     * Not applied on sqlite/mysql: sqlite has no portable expression-index
     * syntax reachable from a shared migration, and MySQL's default collations
     * are already case-insensitive. The application-level check in
     * RegisterRequest is the cross-database source of truth; this index is
     * additional protection specifically for the Postgres production target.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (LOWER(email))');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
