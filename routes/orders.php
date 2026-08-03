<?php

use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderMessageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::post('vehicles/{vehicleId}/orders', [OrderController::class, 'store'])
        ->middleware('b2b.can:orders.create')->name('orders.store');

    Route::post('offers/{offerId}/select', [OfferController::class, 'select'])
        ->middleware('b2b.can:offers.select')->name('offers.select');

    /*
     * Shared by the customer's vehicle page and Admin's order page — no
     * admin/ counterpart, since OrderPolicy already answers "may this user
     * see this order's thread" for both. Deliberately outside `b2b.can:*`:
     * which company members reach a thread follows from the vehicle access
     * VehicleScopeService grants them, not a separate messaging permission.
     */
    Route::get('orders/{orderId}/messages', [OrderMessageController::class, 'index'])
        ->whereUuid('orderId')->name('orders.messages.index');

    Route::post('orders/{orderId}/messages', [OrderMessageController::class, 'store'])
        ->whereUuid('orderId')->name('orders.messages.store');

    Route::post('orders/{orderId}/messages/read', [OrderMessageController::class, 'read'])
        ->whereUuid('orderId')->name('orders.messages.read');
});
