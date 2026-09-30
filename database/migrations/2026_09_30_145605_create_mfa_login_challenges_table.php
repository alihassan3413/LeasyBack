<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The short-lived state between a correct password and an authenticated
 * session.
 *
 * A row here is the only thing a half-authenticated caller holds. It is
 * deliberately not a session: the API flow has no session, and both login
 * paths need the same object, so it lives in the database with an expiry.
 *
 * Nothing in this table is usable if it leaks. The ticket is stored as a
 * sha256 of the value handed to the client, and an emailed code as a password
 * hash — neither can be read back out and replayed.
 *
 * `purpose` separates the two moments a code is demanded:
 *   verify — signing in, before any session or token exists
 *   enroll — an already-authenticated user turning MFA on
 * They are kept in one table because the lifecycle is identical; they are
 * distinguished because a ticket minted for one must never satisfy the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_login_challenges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // sha256 of the plaintext ticket. Unique because the ticket is how
            // a challenge is looked up, and a collision would hand one user
            // another's challenge.
            $table->string('ticket_hash', 64)->unique();

            $table->string('purpose', 16);

            // Hash of the emailed code. Null for a TOTP challenge, where the
            // code comes from the user's app and there is nothing to store.
            $table->string('code_hash')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);

            // How many codes have been mailed for this challenge, and when the
            // last one went out — the resend cooldown and the per-window send
            // ceiling are both answered from these two columns rather than
            // from a cache that could be cleared to reset them.
            $table->unsignedTinyInteger('sends')->default(0);
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamp('expires_at');

            $table->timestamps();

            // Sweeping expired challenges is a range scan on this column.
            $table->index('expires_at', 'mfa_challenges_expires_at_index');

            // "Does this user already have a live challenge of this purpose?"
            // is asked on every login and every resend.
            $table->index(['user_id', 'purpose'], 'mfa_challenges_user_purpose_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_login_challenges');
    }
};
