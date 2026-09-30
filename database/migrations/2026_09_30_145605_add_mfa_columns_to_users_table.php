<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second-factor state on the account itself.
 *
 * Every column is nullable because an account without MFA is the normal case
 * during rollout, not an exception: null throughout means "never enrolled".
 * `mfa_confirmed_at` is the single source of truth for "enrolled" — a secret
 * alone means enrollment was started and abandoned, and must not be treated as
 * a working second factor.
 *
 * `mfa_secret` and `mfa_recovery_codes` hold material that grants access and
 * are encrypted at rest by the model's casts, never written in plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted TOTP shared secret. Longer than the raw secret needs:
            // Laravel's encryption expands it well past the Base32 length.
            $table->text('mfa_secret')->nullable()->after('is_active');

            // Encrypted JSON list of single-use recovery codes, each hashed
            // inside the payload, so a database read leaks neither the codes
            // nor their order of use.
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_secret');

            // Enrollment is only complete once the user has proved they can
            // produce a code. Until this is set there is no second factor.
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_recovery_codes');

            // 'totp' or 'email'. Which factor the account actually uses.
            $table->string('mfa_method', 16)->nullable()->after('mfa_confirmed_at');

            // The last accepted TOTP counter step. A code is valid for its
            // whole 30-second step, so without remembering the step a code
            // observed over someone's shoulder could be replayed within that
            // window; refusing a step at or below this one closes that.
            $table->unsignedBigInteger('mfa_last_used_step')->nullable()->after('mfa_method');

            // Set when a code was successfully delivered to and returned from
            // this address, which is what makes email a usable second factor
            // rather than just a column.
            $table->timestamp('mfa_email_confirmed_at')->nullable()->after('mfa_last_used_step');

            // Answering "who still has to enroll?" during rollout is a scan
            // over this column; without it that is a full table scan on every
            // admin page load.
            $table->index('mfa_confirmed_at', 'users_mfa_confirmed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_mfa_confirmed_at_index');

            $table->dropColumn([
                'mfa_secret',
                'mfa_recovery_codes',
                'mfa_confirmed_at',
                'mfa_method',
                'mfa_last_used_step',
                'mfa_email_confirmed_at',
            ]);
        });
    }
};
