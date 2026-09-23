<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->timestampTz('last_reminder_sent_at')->nullable()->after('presented_at');
            $table->unsignedSmallInteger('reminder_count')->default(0)->after('last_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_offer_presentations', function (Blueprint $table) {
            $table->dropColumn(['last_reminder_sent_at', 'reminder_count']);
        });
    }
};
