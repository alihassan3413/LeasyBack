<?php

use App\Http\Controllers\B2b\CompanyContextController;
use App\Http\Controllers\B2b\InvitationAcceptController;
use App\Http\Controllers\B2b\InvitationController;
use App\Http\Controllers\B2b\MemberController;
use App\Http\Controllers\B2b\StatisticsController;
use Illuminate\Support\Facades\Route;

/*
 * Company team management. Every route here is scoped to the caller's own
 * active company — no company id is ever accepted from the URL, so there is
 * nothing to tamper with.
 */
Route::middleware(['auth', 'active'])->prefix('company')->name('b2b.')->group(function () {
    Route::post('switch', [CompanyContextController::class, 'update'])->name('switch');

    Route::get('members', [MemberController::class, 'index'])
        ->middleware('b2b.can:members.view')
        ->name('members.index');

    /*
     * Company statistics and their export (§17). Both sit behind the same
     * `analytics.view` permission that already governs every other
     * company-level figure, and neither takes a company id — the company comes
     * from the caller's active membership.
     */
    Route::middleware('b2b.can:analytics.view')->group(function () {
        Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('statistics/export', [StatisticsController::class, 'export'])->name('statistics.export');
    });

    Route::middleware('b2b.can:members.manage')->group(function () {
        Route::patch('members/{userId}', [MemberController::class, 'update'])
            ->whereNumber('userId')->name('members.update');
        Route::delete('members/{userId}', [MemberController::class, 'destroy'])
            ->whereNumber('userId')->name('members.destroy');

        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitationId}/resend', [InvitationController::class, 'resend'])
            ->whereUuid('invitationId')->name('invitations.resend');
        Route::delete('invitations/{invitationId}', [InvitationController::class, 'destroy'])
            ->whereUuid('invitationId')->name('invitations.revoke');
    });
});

/*
 * The invitee's side. `show` is deliberately open to guests — the person
 * being invited may not have an account yet, and bouncing them to a bare
 * login page with no context is a dead end. Accepting still requires being
 * signed in as the invited address (enforced in B2bInvitationService).
 * Throttled because the token is the only secret involved.
 */
Route::get('invitations/{token}', [InvitationAcceptController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('b2b.invitations.show');

Route::post('invitations/{token}', [InvitationAcceptController::class, 'accept'])
    ->middleware(['auth', 'active', 'throttle:10,1'])
    ->name('b2b.invitations.accept');
