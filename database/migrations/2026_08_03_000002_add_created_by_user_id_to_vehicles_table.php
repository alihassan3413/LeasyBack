<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which user actually created a vehicle. B2B vehicles belong to the
 * company (`b2b_id`), which is what authorization keys off — but a company
 * member can be restricted to seeing only the vehicles they registered
 * themselves (B2bVehicleScope::Own), and the owner's dashboard filters and
 * per-member analytics need the same attribution. `vehicle_audit_log` already
 * records the creator, but only as an append-only log row that every listing
 * query would have to join and de-duplicate; a column on the row itself is
 * both correct and indexable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('b2c_user_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['b2b_id', 'created_by_user_id']);
        });

        // Backfill from the audit log so pre-existing vehicles are attributed
        // rather than silently invisible to Own-scoped members.
        $creators = DB::table('vehicle_audit_log')
            ->where('action', 'INSERT')
            ->whereNotNull('changed_by_user_id')
            ->orderBy('changed_at')
            ->get(['vehicle_id', 'changed_by_user_id'])
            ->unique('vehicle_id');

        foreach ($creators as $creator) {
            DB::table('vehicles')
                ->where('vehicle_id', $creator->vehicle_id)
                ->update(['created_by_user_id' => $creator->changed_by_user_id]);
        }

        // B2C vehicles are always created by their own owner.
        DB::table('vehicles')
            ->where('vehicle_belongs', 'B2C')
            ->whereNull('created_by_user_id')
            ->whereNotNull('b2c_user_id')
            ->update(['created_by_user_id' => DB::raw('b2c_user_id')]);
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['b2b_id', 'created_by_user_id']);
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
