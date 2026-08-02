<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company a multi-company user is currently acting as.
 *
 * Persisted on the user rather than kept in the session because the same
 * question has to be answerable from the Sanctum API (which has no session)
 * and from queued jobs, and because "one non-deterministic row out of N" —
 * what `DB::table('user_b2b')->where('user_id', ...)->value('b2b_id')` would
 * silently become once a user has two memberships — is not an acceptable
 * answer for an authorization boundary.
 *
 * Nullable: a Firmenkunde who has not registered or joined a company yet has
 * no active company, and B2bContext falls back to their earliest membership
 * (persisting the choice) whenever this is null or points at a company they
 * no longer belong to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('active_b2b_id')->nullable()->after('user_type');
            $table->foreign('active_b2b_id')->references('b2b_id')->on('b2b')->nullOnDelete();
            $table->index('active_b2b_id');
        });

        // Everyone with exactly one membership today keeps acting as it.
        DB::table('users')->update([
            'active_b2b_id' => DB::table('user_b2b')
                ->whereColumn('user_b2b.user_id', 'users.id')
                ->limit(1)
                ->select('b2b_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['active_b2b_id']);
            $table->dropIndex(['active_b2b_id']);
            $table->dropColumn('active_b2b_id');
        });
    }
};
