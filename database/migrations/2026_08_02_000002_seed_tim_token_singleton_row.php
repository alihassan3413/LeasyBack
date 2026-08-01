<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `tim_token` is a singleton row (always id=1) holding the one active TIM
 * SOAP session. TimToken::upsertToken() used a bare `updateOrCreate()` with
 * no row lock — two concurrent logins could both find no row and both
 * attempt an insert, racing on the primary key. Seeding the row once here
 * means upsertToken() only ever needs to lock-and-update an existing row
 * (see TimToken::upsertToken()), removing the insert race entirely rather
 * than just narrowing its window.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tim_token')->updateOrInsert(
            ['id' => 1],
            [
                'client_id' => '',
                'session' => '',
                'username' => '',
                'updated_by_user_id' => null,
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // Intentionally a no-op: the row is load-bearing infrastructure for
        // TimToken's singleton pattern, not sample/seed data to roll back.
    }
};
