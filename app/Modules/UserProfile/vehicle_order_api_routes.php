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
    Route::get('tuvsud/confirm', [OrderController::class, 'confirm'])->middleware(['throttle:60,1', 'tuvsud.webhook']);
    Route::get('tuvsud/status', [OrderController::class, 'status'])->middleware(['throttle:60,1', 'tuvsud.webhook']);
});

// --- Authenticated routes ---
//
// The same authorization the session (web) routes apply, so a token cannot do
// what the portal refuses:
// - `active` rejects a deactivated account and revokes its token;
// - `b2b.can:*` requires the company permission of the web counterpart while
//   the caller acts as a company (Privatkunde, Werkstatt and Admin pass
//   through, as on the web — their access is decided by the policies);
// - which company and which of its vehicles a member reaches is decided by
//   VehicleScopeService inside the controllers and policies, exactly as on
//   the web.
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Vehicle CRUD
    Route::prefix('vehicle')->group(function () {
        Route::post('create', [VehicleController::class, 'store'])
            ->middleware('b2b.can:vehicles.create');
        Route::patch('{vehicleId}', [VehicleController::class, 'update'])->whereUuid('vehicleId')
            ->middleware('b2b.can:vehicles.update');
        Route::put('{vehicleId}', [VehicleController::class, 'assignProfile'])->whereUuid('vehicleId')
            ->middleware('b2b.can:vehicles.update');
        Route::get('find/{vehicleId}/{ownerId}', [VehicleController::class, 'findByOwner'])
            ->middleware('b2b.can:vehicles.view');
        Route::get('list/{ownerId}', [VehicleController::class, 'listByOwner'])
            ->middleware('b2b.can:vehicles.view');
        Route::get('list/report/status', [VehicleController::class, 'dashboard'])
            ->middleware('b2b.can:vehicles.view');

        // Vehicle documents
        Route::put('{vehicleId}/documents', [VehicleDocumentController::class, 'upload'])->whereUuid('vehicleId')
            ->middleware('b2b.can:vehicles.documents.upload');
        Route::get('{vehicleId}/documents', [VehicleDocumentController::class, 'index'])->whereUuid('vehicleId')
            ->middleware('b2b.can:vehicles.view');
        Route::get('{vehicleId}/documents/{documentId}', [VehicleDocumentController::class, 'show'])
            ->middleware('b2b.can:vehicles.view');
        Route::delete('{vehicleId}/documents/{documentId}', [VehicleDocumentController::class, 'destroy'])
            ->middleware('b2b.can:vehicles.documents.delete');

        // Customer offers (nested under /vehicle scope like Rust)
        Route::get('offers/customer/list/{auftragsnummer}', [OfferController::class, 'customerList'])
            ->middleware('b2b.can:vehicles.view');
        Route::post('offers/customer/select/{offerId}', [OfferController::class, 'customerSelect'])->whereUuid('offerId')
            ->middleware('b2b.can:offers.select');
    });

    // Orders
    Route::prefix('order')->group(function () {
        Route::post('tuvsud/create/{vehicleId}', [OrderController::class, 'createTuvsud'])->whereUuid('vehicleId')
            ->middleware('b2b.can:orders.create');
        Route::post('b2b/create/{vehicleId}', [OrderController::class, 'createB2bCollection'])->whereUuid('vehicleId')
            ->middleware('b2b.can:orders.create');
        Route::get('stations/{provider}', [OrderController::class, 'stationsByProvider']);
        Route::get('stations', [OrderController::class, 'allStations']);
        Route::post('stations/create', [OrderController::class, 'createStation']);
        Route::post('tuvsud/order/approve/{orderId}', [OrderController::class, 'approve'])->whereUuid('orderId');
        Route::post('others/create/{vehicleId}', [OrderController::class, 'createOther'])->whereUuid('vehicleId')
            ->middleware('b2b.can:orders.create');
        Route::post('others/confirm', [OrderController::class, 'confirmOther']);
    });

    // Admin offers
    Route::prefix('admin/offers')->group(function () {
        Route::post('create/{auftragsnummer}', [OfferController::class, 'create']);
        Route::post('publish/{offerId}', [OfferController::class, 'publish'])->whereUuid('offerId');
        Route::post('cancel/{offerId}', [OfferController::class, 'cancel'])->whereUuid('offerId');
        Route::get('list/{auftragsnummer}', [OfferController::class, 'adminList']);
    });
});
