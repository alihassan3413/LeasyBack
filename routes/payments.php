<?php

use App\Modules\UserProfile\Payment\Http\Controllers\OrderCancellationController;
use App\Modules\UserProfile\Payment\Http\Controllers\OrderPaymentController;
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

    /*
     * Settling one obligation by hand, after the automatic off-session attempt
     * came back needing the customer — an authentication challenge, or a card
     * that was declined.
     *
     * The purpose is a route *default*, not a URL or payload parameter: one
     * controller serves both, but which obligation a given URL settles is fixed
     * by the route table, so no request can point the repair endpoint at a
     * cancellation fee or the reverse.
     *
     * None of the six takes a PaymentIntent id either — the intent is resolved
     * from the order's own obligation, so there is no parameter through which
     * another customer's intent could be named.
     */
    foreach (['repair' => 'repair', 'cancellation-fee' => 'cancellation_fee'] as $slug => $purpose) {
        Route::get("orders/{orderId}/payments/{$slug}", [OrderPaymentController::class, 'show'])
            ->whereUuid('orderId')
            ->defaults('purpose', $purpose)
            ->name("payments.{$slug}.show");

        Route::post("orders/{orderId}/payments/{$slug}/intent", [OrderPaymentController::class, 'intent'])
            ->whereUuid('orderId')
            ->defaults('purpose', $purpose)
            ->name("payments.{$slug}.intent");

        Route::post("orders/{orderId}/payments/{$slug}/sync", [OrderPaymentController::class, 'sync'])
            ->whereUuid('orderId')
            ->defaults('purpose', $purpose)
            ->name("payments.{$slug}.sync");
    }

    /*
     * The customer's own cancellation, and the only path that levies the €200
     * fee. Admin cancels through `admin.orders.status`, which never reaches
     * this controller.
     */
    Route::post('orders/{orderId}/cancel', [OrderCancellationController::class, 'store'])
        ->whereUuid('orderId')
        ->name('orders.cancel');
});
