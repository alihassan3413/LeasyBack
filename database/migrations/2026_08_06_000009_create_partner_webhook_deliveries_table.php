<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One event, one subscription, one delivery — and every HTTP call that delivery
 * took, in its own table.
 *
 * Split in two because the two answer different questions. `deliveries` answers
 * "did this partner get event X", which is the one a support request actually
 * asks, and stays one row per pair forever. `delivery_attempts` answers "what
 * did their endpoint say the third time we tried", which is where the response
 * excerpts and error strings live and where the volume is.
 *
 * `unique(event, subscription)` is what makes a redelivery a retry rather than
 * a second event, including when fan-out itself is replayed after a crash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('partner_webhook_event_id');
            $table->uuid('partner_webhook_subscription_id');

            // pending | delivering | succeeded | failed | exhausted
            $table->string('status', 16)->default('pending');

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->unsignedSmallInteger('last_status_code')->nullable();

            // A short, sanitised excerpt of what the endpoint returned. Bounded
            // on write, never the whole body: a partner's error page can be
            // megabytes and is not ours to store.
            $table->text('last_response_excerpt')->nullable();
            $table->string('last_error')->nullable();

            // Set when a human asked for this delivery to be tried again, so a
            // replayed delivery is distinguishable from one that simply retried.
            $table->timestamp('replayed_at')->nullable();

            $table->timestamps();

            $table->foreign('partner_webhook_event_id')
                ->references('id')->on('partner_webhook_events')
                ->cascadeOnDelete();

            $table->foreign('partner_webhook_subscription_id')
                ->references('id')->on('partner_webhook_subscriptions')
                ->cascadeOnDelete();

            $table->unique(
                ['partner_webhook_event_id', 'partner_webhook_subscription_id'],
                'partner_webhook_deliveries_event_subscription_unique',
            );

            $table->index(
                ['partner_webhook_subscription_id', 'created_at'],
                'partner_webhook_deliveries_subscription_recent_index',
            );

            $table->index(['status', 'next_attempt_at'], 'partner_webhook_deliveries_due_index');
        });

        Schema::create('partner_webhook_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('partner_webhook_delivery_id');

            $table->unsignedInteger('attempt');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->string('error')->nullable();

            // True when the attempt never left the process — an SSRF refusal or
            // a DNS failure. Distinguished from a 5xx because the fix is on our
            // side of the wire in one case and theirs in the other.
            $table->boolean('blocked')->default(false);

            $table->timestamp('attempted_at');

            $table->foreign('partner_webhook_delivery_id')
                ->references('id')->on('partner_webhook_deliveries')
                ->cascadeOnDelete();

            $table->index(
                ['partner_webhook_delivery_id', 'attempt'],
                'partner_webhook_attempts_delivery_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_delivery_attempts');
        Schema::dropIfExists('partner_webhook_deliveries');
    }
};
