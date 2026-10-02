<?php

use App\Http\Controllers\Mfa\MfaChallengeController;
use App\Http\Controllers\Mfa\MfaEnrollmentController;
use Illuminate\Support\Facades\Route;

/*
 * Multi-factor authentication.
 *
 * Two groups, because the caller is in a different state in each:
 *
 *   challenge  — guests holding only a ticket. These must stay open to
 *                unauthenticated callers by definition; the ticket is the
 *                credential, and it is throttled accordingly.
 *   enrollment — already authenticated users turning a factor on.
 *
 * The browser and token flows share the controllers and therefore the rules;
 * only the transport differs.
 */

// ---------------------------------------------------------------- browser

Route::middleware('web')->group(function () {
    Route::get('mfa/verify', [MfaChallengeController::class, 'show'])
        ->middleware('throttle:mfa-verify')
        ->name('mfa.verify');

    Route::post('mfa/verify', [MfaChallengeController::class, 'verify'])
        ->middleware('throttle:mfa-verify')
        ->name('mfa.verify.store');

    Route::post('mfa/send-email', [MfaChallengeController::class, 'sendEmail'])
        ->middleware('throttle:mfa-send')
        ->name('mfa.send-email');

    // Enrollment screen. Authenticated, because a user reaches it either from
    // their settings or because EnsureMfaSatisfied pinned them here after a
    // normal login.
    Route::middleware(['auth', 'active'])->group(function () {
        Route::get('mfa/setup', [MfaEnrollmentController::class, 'show'])->name('mfa.setup');

        Route::post('mfa/setup/send-email', [MfaEnrollmentController::class, 'sendEmailWeb'])
            ->middleware('throttle:mfa-send')
            ->name('mfa.setup.send-email');

        Route::post('mfa/setup/confirm', [MfaEnrollmentController::class, 'confirmWeb'])
            ->middleware('throttle:mfa-verify')
            ->name('mfa.setup.confirm');

        // "Weiter" after the recovery codes: back to wherever the user was
        // going before EnsureMfaSatisfied pinned them to setup — for a fresh
        // registration that is the onboarding funnel, not the dashboard.
        Route::get('mfa/setup/continue', [MfaEnrollmentController::class, 'proceed'])
            ->name('mfa.setup.continue');
    });
});

// -------------------------------------------------------------------- api

Route::middleware('api')->prefix('api/mfa')->name('api.mfa.')->group(function () {
    // Guest: the ticket is the only thing the caller holds.
    Route::post('verify', [MfaChallengeController::class, 'verifyApi'])
        ->middleware('throttle:mfa-verify')
        ->name('verify');

    Route::post('send-email', [MfaChallengeController::class, 'sendEmailApi'])
        ->middleware('throttle:mfa-send')
        ->name('send-email');

    // Authenticated: enrollment.
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('setup', [MfaEnrollmentController::class, 'setup'])
            ->middleware('throttle:mfa-send')
            ->name('setup');

        Route::post('enroll/send-email', [MfaEnrollmentController::class, 'sendEmail'])
            ->middleware('throttle:mfa-send')
            ->name('enroll.send-email');

        Route::post('confirm', [MfaEnrollmentController::class, 'confirm'])
            ->middleware('throttle:mfa-verify')
            ->name('confirm');

        // Admin only: removes another account's second factor.
        Route::post('reset/{user}', [MfaEnrollmentController::class, 'destroy'])
            ->middleware('admin')
            ->name('reset');
    });
});
