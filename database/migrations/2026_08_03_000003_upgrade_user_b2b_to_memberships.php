<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `user_b2b` from a one-company-per-user link table into a real
 * membership record.
 *
 * The `unique('user_id')` constraint is dropped: a person can legitimately be
 * a member of more than one company (an external fleet manager working for
 * two clients, say). Which company they are acting as at any moment is
 * `users.active_b2b_id` — see the next migration. The composite primary key
 * (user_id, b2b_id) stays and is what still prevents joining the same company
 * twice.
 *
 * `permissions` is a JSON allow-list of B2bPermission values; it is only
 * consulted for members, since owners implicitly hold every permission.
 * `vehicle_scope` narrows *which* of the company's vehicles a member sees
 * (all of them, or only the ones they registered) — deliberately a separate
 * axis from permissions, because "may view vehicles" and "may view everyone's
 * vehicles" are different questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_b2b', function (Blueprint $table) {
            $table->dropUnique('user_b2b_user_id_unique');
        });

        Schema::table('user_b2b', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
            $table->string('vehicle_scope', 10)->default('all')->after('permissions');
            $table->string('status', 20)->default('active')->after('vehicle_scope');
            $table->foreignId('invited_by_user_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable()->after('invited_by_user_id');
            $table->index(['b2b_id', 'status']);
        });

        // Existing rows predate invitations — they are the founding owners.
        DB::table('user_b2b')->whereNull('joined_at')->update([
            'joined_at' => DB::raw('created_at'),
            'status' => 'active',
        ]);
    }

    public function down(): void
    {
        Schema::table('user_b2b', function (Blueprint $table) {
            $table->dropIndex(['b2b_id', 'status']);
            $table->dropConstrainedForeignId('invited_by_user_id');
            $table->dropColumn(['permissions', 'vehicle_scope', 'status', 'joined_at']);
        });

        Schema::table('user_b2b', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }
};
