<?php

use App\Enums\VehicleOwnerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforces valid vehicle_belongs values at the database level (Postgres
     * only), mirroring the users_user_type_check constraint. Built from
     * VehicleOwnerType::values() so the constraint can never silently drift
     * out of sync with the enum it mirrors. The Rust reference has this
     * constraint; the current Laravel migration didn't.
     *
     * Not applied on sqlite: SQLite has no ALTER TABLE ADD CONSTRAINT support
     * for an existing table without a full table rebuild.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $values = collect(VehicleOwnerType::values())
            ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');

        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_vehicle_belongs_check CHECK (vehicle_belongs IN ({$values}))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_vehicle_belongs_check');
    }
};
