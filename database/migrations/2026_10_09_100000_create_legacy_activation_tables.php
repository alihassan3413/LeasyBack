<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activation of users imported from Base44, whose old passwords could not be
 * migrated (UserStep gives them an unusable one).
 *
 * - legacy_activation_tokens: the tokens of the `legacy_activation` password
 *   broker. Same shape as password_reset_tokens, but its own table, so an
 *   activation link and a normal "Passwort vergessen" link never overwrite or
 *   expire each other.
 * - legacy_activation_mails: one row per user the activation mail concerns —
 *   whether it was queued, sent, failed or skipped, and when the user actually
 *   set a password. It is what makes `legacy:send-activation-emails` safe to
 *   rerun and resume, and it outlives legacy_import_map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_activation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('legacy_activation_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('status', 20);
            $table->string('skip_reason', 60)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_activation_mails');
        Schema::dropIfExists('legacy_activation_tokens');
    }
};
