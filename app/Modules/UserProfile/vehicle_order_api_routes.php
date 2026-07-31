<?php

use App\Modules\UserProfile\Offer\Http\Controllers\OfferController;
use App\Modules\UserProfile\Order\Http\Controllers\OrderController;
use App\Modules\UserProfile\Vehicle\Http\Controllers\VehicleController;
use App\Modules\UserProfile\Vehicle\Http\Controllers\VehicleDocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vehicle, Order, Image & Offers routes
|--------------------------------------------------------------------------
| Exact Rust URL contract preserved.
*/

// --- Public callback routes (API key auth, no Sanctum) ---
Route::prefix('order')->group(function () {
    Route::get('tuvsud/confirm', [OrderController::class, 'confirm'])->middleware('throttle:60,1');
    Route::get('tuvsud/status', [OrderController::class, 'status'])->middleware('throttle:60,1');
});

// --- Authenticated routes ---
Route::middleware('auth:sanctum')->group(function () {

    // Vehicle CRUD
    Route::prefix('vehicle')->group(function () {
        Route::post('create', [VehicleController::class, 'store']);
        Route::patch('{vehicleId}', [VehicleController::class, 'update'])->whereUuid('vehicleId');
        Route::put('{vehicleId}', [VehicleController::class, 'assignProfile'])->whereUuid('vehicleId');
        Route::get('find/{vehicleId}/{ownerId}', [VehicleController::class, 'findByOwner']);
        Route::get('list/{ownerId}', [VehicleController::class, 'listByOwner']);
        Route::get('list/report/status', [VehicleController::class, 'dashboard']);

        // Vehicle documents
        Route::put('{vehicleId}/documents', [VehicleDocumentController::class, 'upload'])->whereUuid('vehicleId');
        Route::get('{vehicleId}/documents', [VehicleDocumentController::class, 'index'])->whereUuid('vehicleId');
        Route::get('{vehicleId}/documents/{documentId}', [VehicleDocumentController::class, 'show']);
        Route::delete('{vehicleId}/documents/{documentId}', [VehicleDocumentController::class, 'destroy']);

        // Customer offers (nested under /vehicle scope like Rust)
        Route::get('offers/customer/list/{auftragsnummer}', [OfferController::class, 'customerList']);
        Route::post('offers/customer/select/{offerId}', [OfferController::class, 'customerSelect'])->whereUuid('offerId');
    });

    // Orders
    Route::prefix('order')->group(function () {
        Route::post('tuvsud/create/{vehicleId}', [OrderController::class, 'createTuvsud'])->whereUuid('vehicleId');
        Route::get('stations/{provider}', [OrderController::class, 'stationsByProvider']);
        Route::get('stations', [OrderController::class, 'allStations']);
        Route::post('stations/create', [OrderController::class, 'createStation']);
        Route::post('tuvsud/order/approve/{orderId}', [OrderController::class, 'approve'])->whereUuid('orderId');
        Route::post('others/create/{vehicleId}', [OrderController::class, 'createOther'])->whereUuid('vehicleId');
        Route::post('others/confirm', [OrderController::class, 'confirmOther']);
    });

    // Admin offers
    Route::prefix('admin/offers')->middleware('auth:sanctum')->group(function () {
        Route::post('create/{auftragsnummer}', [OfferController::class, 'create']);
        Route::post('publish/{offerId}', [OfferController::class, 'publish'])->whereUuid('offerId');
        Route::post('cancel/{offerId}', [OfferController::class, 'cancel'])->whereUuid('offerId');
        Route::get('list/{auftragsnummer}', [OfferController::class, 'adminList']);
    });
});
