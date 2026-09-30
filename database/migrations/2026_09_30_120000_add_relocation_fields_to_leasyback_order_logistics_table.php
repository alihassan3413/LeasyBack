<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Überführung (vehicle relocation) on the existing appointment row:
 *
 * - the confirmed time slot ("08:00-12:00") Admin saves together with the
 *   confirmed date — together they are what schedules a relocation;
 * - the transfer protocol (Übergabeprotokoll), saved either as a link or as an
 *   uploaded PDF — saving it completes the relocation.
 *
 * All nullable: no existing row is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->string('confirmed_collection_time_slot', 20)->nullable();
            $table->text('transfer_protocol_url')->nullable();
            $table->string('transfer_protocol_path')->nullable();
            $table->string('transfer_protocol_original_name')->nullable();
            $table->timestamp('transfer_protocol_saved_at')->nullable();
            $table->unsignedBigInteger('transfer_protocol_saved_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leasyback_order_logistics', function (Blueprint $table) {
            $table->dropColumn([
                'confirmed_collection_time_slot',
                'transfer_protocol_url',
                'transfer_protocol_path',
                'transfer_protocol_original_name',
                'transfer_protocol_saved_at',
                'transfer_protocol_saved_by_user_id',
            ]);
        });
    }
};