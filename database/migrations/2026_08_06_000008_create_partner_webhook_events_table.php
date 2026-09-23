<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox. Every business change that partners may hear about is written
 * here, in the same transaction as the change itself.
 *
 * That co-location is the whole design. A rolled-back transaction takes its
 * event row with it, so a change that did not happen cannot be announced; a
 * committed transaction leaves the row behind, so a crash between commit and
 * queue dispatch loses nothing — `partner:webhooks:dispatch-pending` picks up
 * anything still holding `dispatched_at IS NULL`.
 *
 * The row is per *event*, not per subscriber. `event_id` is therefore stable
 * across every subscription, every attempt and every manual replay, which is
 * what makes `X-LeasyBack-Event-ID` usable as a deduplication key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The public identifier: evt_ + 32 lowercase hex. Unique so a
            // duplicate emit is a database error rather than two events.
            $table->string('event_id', 64)->unique();

            $table->string('type', 64);
            $table->string('api_version', 16);

            // Which company's data this is about. Fan-out reads this and only
            // this — a subscription in another company is never a candidate.
            $table->uuid('b2b_id');

            // Correlation for support, never used for scoping.
            $table->uuid('order_id')->nullable();
            $table->uuid('vehicle_id')->nullable();

            $table->json('payload');

            $table->timestamp('occurred_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['b2b_id', 'type']);
            $table->index(['dispatched_at', 'occurred_at'], 'partner_webhook_events_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_events');
    }
};
