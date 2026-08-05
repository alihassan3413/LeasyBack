<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order notes with an explicit audience (§16).
 *
 * A separate table rather than a `visibility` column on `order_messages`,
 * because the two are different entities: `order_messages` is a two-way
 * customer↔Admin thread with read state, unread counts and notifications,
 * while a note is a one-way Admin annotation on the order. Marking a message
 * internal would mean an invisible turn inside a conversation, silently
 * skewing every unread count and notification that thread already drives.
 *
 * `author_name` is a snapshot taken at write time, not a join onto users, so
 * a note stays attributable after its author's account is deleted — the same
 * reasoning `order_messages.sender_name` already uses.
 *
 * `visibility` defaults to 'internal' as a backstop: the service requires the
 * caller to state it explicitly, and the recoverable failure is a note the
 * customer cannot see, never an internal remark that leaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_order_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('auftragsnummer')->index();
            $table->string('visibility', 16)->default('internal');
            $table->text('body');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('leasyback_orders')->cascadeOnDelete();
            $table->index(['order_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_order_notes');
    }
};
