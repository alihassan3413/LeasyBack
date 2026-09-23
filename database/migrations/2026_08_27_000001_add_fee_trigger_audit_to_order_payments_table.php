<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->string('trigger_reason', 40)->nullable()->index();
            $table->timestampTz('triggered_at')->nullable();

            /**
             * The source fact the trigger was derived from — the appointment
             * time, the offer's sent timestamp, the workshop start date — so a
             * charge can be justified later without re-deriving it.
             */
            $table->json('trigger_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropColumn(['trigger_reason', 'triggered_at', 'trigger_context']);
        });
    }
};
