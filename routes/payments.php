<?php

use App\Modules\UserProfile\Payment\Http\Controllers\PaymentMethodController;
use Illuminate\Support\Facades\Route;

/*
 * B2C payments. Same middleware stack as orders.php — a payment method is
 * attached to an order, so it inherits the order flow's requirement that the
 * account be active and its email verified.
 *
 * Deliberately outside `b2b.can:*`: these routes are B2C-only by construction
 * (PaymentAuthorizer refuses a B2B order), so a company permission would be
 * gating something no company member can reach anyway.
 *
 * Authorization is not expressed here as middleware but in PaymentAuthorizer,
 * because it is three questions rather than one — owner, channel, and whether
 * the order is still open — and the webhook path needs two of the three
 * without an authenticated user to hang them on.
 */
Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('orders/{orderId}/payment-method', [PaymentMethodController::class, 'show'])
        ->whereUuid('orderId')
        ->name('payments.method.show');

    Route::post('orders/{orderId}/payment-method/setup-intent', [PaymentMethodController::class, 'createIntent'])
        ->whereUuid('orderId')
        ->name('payments.method.intent');

    Route::post('orders/{orderId}/payment-method/confirm', [PaymentMethodController::class, 'confirm'])
        ->whereUuid('orderId')
        ->name('payments.method.confirm');
});
