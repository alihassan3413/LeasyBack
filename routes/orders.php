<?php

use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::post('vehicles/{vehicleId}/orders', [OrderController::class, 'store'])
        ->middleware('b2b.can:orders.create')->name('orders.store');

    Route::post('offers/{offerId}/select', [OfferController::class, 'select'])
        ->middleware('b2b.can:offers.select')->name('offers.select');
});
