<?php

use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::post('vehicles/{vehicleId}/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::post('offers/{offerId}/select', [OfferController::class, 'select'])->name('offers.select');
});
