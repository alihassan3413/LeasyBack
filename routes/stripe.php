<?php

use App\Modules\UserProfile\Payment\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Registered from bootstrap/app.php rather than routes/api.php, which is
 * loaded twice and would expose this at two URLs.
 */
Route::post('webhooks/stripe', StripeWebhookController::class)
    ->middleware(['stripe.webhook', 'throttle:120,1'])
    ->name('webhooks.stripe');
