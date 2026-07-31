<?php

use App\Enums\UserType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforces valid user_type values at the database level (Postgres only),
     * as defense in depth alongside the application-level enum cast on the
     * User model and the Rule::in() validation in RegisterRequest. Built from
     * UserType::values() so the constraint can never silently drift out of
     * sync with the enum it mirrors.
     *
     * Not applied on sqlite: SQLite has no ALTER TABLE ADD CONSTRAINT support
     * (adding a CHECK constraint to an existing table requires a full table
     * rebuild), so this is Postgres-specific. The Eloquent enum cast on
     * User::user_type already throws on an invalid stored value on any
     * driver, which is the cross-database safety net.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $values = collect(UserType::values())
            ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_user_type_check CHECK (user_type IN ({$values}))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_user_type_check');
    }
};
