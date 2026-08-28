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

    Route::post('offers/{offerId}/reject', [OfferController::class, 'reject'])
        ->middleware('b2b.can:offers.select')->name('offers.reject');

    /*
     * One order in full, by its own id. Every reorder is a separate record
     * with its own Auftragsnummer, so a vehicle's past is a list of addresses
     * rather than a single "the order" — this is how the customer opens any
     * one of them. Gated on `vehicles.view` rather than a permission of its
     * own: an order is a vehicle's process, and which company members may see
     * which vehicles is already settled by VehicleScopeService, which
     * OrderPolicy::view() and the controller's scope lookup both consult.
     */
    Route::get('orders/{orderId}', [OrderController::class, 'show'])
        ->whereUuid('orderId')
        ->middleware('b2b.can:vehicles.view')->name('orders.show');

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
