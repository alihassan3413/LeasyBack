<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages exchanged between a customer and Admin about one order.
 *
 * Every message belongs to exactly one leasyback_order — there is no
 * standalone conversation entity, since the order already is the thread.
 *
 * sender_name/sender_is_admin are snapshots taken at send time rather than
 * joins onto users: a message stays readable after its author's account is
 * deleted (sender_id then nulls out), and the side a message was sent from
 * must not change retroactively if that user's type ever does.
 *
 * Read state is one row per participant per order (last_read_at), not a
 * per-message pivot: unread is "created after my last_read_at and not sent
 * by me", which answers both the count and the marker with a single row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_name');
            $table->boolean('sender_is_admin')->default(false);
            $table->text('body');
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_message_reads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('last_read_at');
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->unique(['order_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_message_reads');
        Schema::dropIfExists('order_messages');
    }
};
