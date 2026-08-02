<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\Admin\VehicleReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('impersonate/{userId}', [ImpersonationController::class, 'store'])
        ->whereNumber('userId')
        ->name('impersonate.store');

    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('{type}/{id}', [CustomerController::class, 'show'])->where('type', 'b2c|b2b')->name('show');
        Route::patch('{type}/{id}/status', [CustomerController::class, 'updateStatus'])->where('type', 'b2c|b2b')->name('status');
    });

    Route::prefix('vehicles')->name('vehicles.')->group(function () {
        Route::get('/', [VehicleController::class, 'index'])->name('index');
        Route::post('/', [VehicleController::class, 'store'])->name('store');
        Route::get('{vehicleId}', [VehicleController::class, 'show'])->whereUuid('vehicleId')->name('show');

        Route::post('{vehicleId}/reports', [VehicleReportController::class, 'upload'])->whereUuid('vehicleId')->name('reports.upload');
        Route::patch('reports/{documentId}/publish', [VehicleReportController::class, 'publish'])->whereUuid('documentId')->name('reports.publish');
        Route::delete('reports/{documentId}', [VehicleReportController::class, 'delete'])->whereUuid('documentId')->name('reports.delete');
    });

    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('{orderId}', [OrderController::class, 'show'])->whereUuid('orderId')->name('show');
        Route::post('{orderId}/approve', [OrderController::class, 'approve'])->whereUuid('orderId')->name('approve');
        Route::patch('{orderId}/status', [OrderController::class, 'updateStatus'])->whereUuid('orderId')->name('status');

        Route::post('{orderId}/offers', [OfferController::class, 'store'])->whereUuid('orderId')->name('offers.store');
        Route::patch('offers/{offerId}/publish', [OfferController::class, 'publish'])->whereUuid('offerId')->name('offers.publish');
        Route::patch('offers/{offerId}/select', [OfferController::class, 'select'])->whereUuid('offerId')->name('offers.select');
        Route::patch('offers/{offerId}/cancel', [OfferController::class, 'cancel'])->whereUuid('offerId')->name('offers.cancel');
    });
});
