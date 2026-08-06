<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner's standing request to be told when something happens.
 *
 * One subscription belongs to exactly one integration client, which is what
 * makes cross-company isolation structural rather than a filter someone has to
 * remember: a delivery is only ever fanned out to subscriptions of clients in
 * the company the event belongs to.
 *
 * Two secrets, not one. `secret` signs everything from now on; `previous_secret`
 * keeps signing until `previous_secret_expires_at`, so a partner can deploy a
 * rotated secret without a hard cutover — the same grace-window shape
 * `partner:token:rotate` already uses for credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('partner_integration_client_id');

            $table->string('url', 2048);
            $table->string('description')->nullable();

            // Explicit list. There is no "all events" wildcard: a partner that
            // opted into four event types must not silently start receiving a
            // fifth we add later, and an integration that cannot parse an
            // unknown type would break on the deploy that introduced it.
            $table->json('event_types');

            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();

            $table->boolean('is_active')->default(true);

            // Why it is off, when a partner did not turn it off themselves.
            $table->string('disabled_reason')->nullable();
            $table->timestamp('disabled_at')->nullable();

            // Reset by any success. Reaching the configured ceiling suspends
            // the subscription rather than retrying a dead endpoint forever.
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamp('last_success_at')->nullable();

            $table->timestamps();

            $table->foreign('partner_integration_client_id')
                ->references('id')->on('partner_integration_clients')
                ->cascadeOnDelete();

            $table->index(['partner_integration_client_id', 'is_active'], 'partner_webhook_subs_client_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_subscriptions');
    }
};
