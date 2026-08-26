<?php

use App\Modules\UserProfile\Payment\Http\Controllers\PaymentMethodController;
use App\Modules\UserProfile\Payment\Http\Controllers\RepairPaymentController;
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

    /*
     * Settling a repair charge by hand, after the automatic off-session
     * attempt came back needing the customer — a 3DS challenge, or a card
     * that was declined.
     *
     * None of the three takes a PaymentIntent id: the intent is resolved from
     * the order's own repair payment, so there is no parameter through which
     * another customer's intent could be named.
     */
    Route::get('orders/{orderId}/payments/repair', [RepairPaymentController::class, 'show'])
        ->whereUuid('orderId')
        ->name('payments.repair.show');

    Route::post('orders/{orderId}/payments/repair/intent', [RepairPaymentController::class, 'intent'])
        ->whereUuid('orderId')
        ->name('payments.repair.intent');

    Route::post('orders/{orderId}/payments/repair/sync', [RepairPaymentController::class, 'sync'])
        ->whereUuid('orderId')
        ->name('payments.repair.sync');
});
