<?php

use App\Http\Controllers\Admin\AppraisalPositionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\OrderBillingController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderNoteController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\Admin\VehicleReportController;
use App\Http\Controllers\Admin\WorkshopQuotationController;
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
        Route::patch('b2b/{id}/service-fee', [CustomerController::class, 'updateServiceFee'])->whereUuid('id')->name('service-fee');
    });

    Route::prefix('vehicles')->name('vehicles.')->group(function () {
        Route::get('/', [VehicleController::class, 'index'])->name('index');
        Route::post('/', [VehicleController::class, 'store'])->name('store');
        Route::get('{vehicleId}', [VehicleController::class, 'show'])->whereUuid('vehicleId')->name('show');

        // Order creation deliberately has no admin-specific route: the
        // session route orders.store already serves it. VehicleScopeService's
        // Admin branch is unfiltered by design, so an admin may book for any
        // vehicle, and OrderService records created_by_user_id as the admin.
        // (Note the resulting order goes straight to order_placed rather than
        // the order_requested staging a Firmenkunde gets — that staging
        // exists so an admin can approve, and here the admin *is* the actor.)
        Route::post('{vehicleId}/reports', [VehicleReportController::class, 'upload'])->whereUuid('vehicleId')->name('reports.upload');
        Route::post('{vehicleId}/reports/pull', [VehicleReportController::class, 'pull'])->whereUuid('vehicleId')->name('reports.pull');
        Route::patch('reports/{documentId}/publish', [VehicleReportController::class, 'publish'])->whereUuid('documentId')->name('reports.publish');
        Route::delete('reports/{documentId}', [VehicleReportController::class, 'delete'])->whereUuid('documentId')->name('reports.delete');
    });

    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('{orderId}', [OrderController::class, 'show'])->whereUuid('orderId')->name('show');
        Route::post('{orderId}/approve', [OrderController::class, 'approve'])->whereUuid('orderId')->name('approve');
        Route::patch('{orderId}/status', [OrderController::class, 'updateStatus'])->whereUuid('orderId')->name('status');
        Route::patch('{orderId}/collection', [OrderController::class, 'updateCollection'])->whereUuid('orderId')->name('collection');
        Route::patch('{orderId}/repair-appointment', [OrderController::class, 'updateRepairAppointment'])
            ->whereUuid('orderId')->name('repair-appointment');
        Route::patch('{orderId}/billing', [OrderBillingController::class, 'update'])
            ->whereUuid('orderId')->name('billing');
        Route::put('{orderId}/appraisal-positions', [AppraisalPositionController::class, 'update'])->whereUuid('orderId')->name('appraisal-positions');

        /*
         * Order notes (§16). Admin-authored only: §16 gives company users the
         * right to see customer-visible notes, not to write them, and the
         * customer's own writing surface is the order_messages thread.
         */
        Route::post('{orderId}/notes', [OrderNoteController::class, 'store'])
            ->whereUuid('orderId')->name('notes.store');
        Route::delete('{orderId}/notes/{noteId}', [OrderNoteController::class, 'destroy'])
            ->whereUuid('orderId')->whereUuid('noteId')->name('notes.destroy');

        Route::post('{orderId}/workshop-quotations', [WorkshopQuotationController::class, 'store'])
            ->whereUuid('orderId')->name('workshop-quotations.store');
        Route::delete('workshop-quotations/{quotationId}', [WorkshopQuotationController::class, 'destroy'])
            ->whereUuid('quotationId')->name('workshop-quotations.revoke');
        Route::post('{orderId}/b2b-offer', [WorkshopQuotationController::class, 'createOffer'])
            ->whereUuid('orderId')->name('b2b-offer.store');

        Route::post('{orderId}/offers', [OfferController::class, 'store'])->whereUuid('orderId')->name('offers.store');
        Route::patch('offers/{offerId}/publish', [OfferController::class, 'publish'])->whereUuid('offerId')->name('offers.publish');
        Route::patch('offers/{offerId}/select', [OfferController::class, 'select'])->whereUuid('offerId')->name('offers.select');
        Route::patch('offers/{offerId}/cancel', [OfferController::class, 'cancel'])->whereUuid('offerId')->name('offers.cancel');
    });
});
