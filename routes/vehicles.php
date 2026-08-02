<?php

use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleDocumentController;
use Illuminate\Support\Facades\Route;

/*
 * `b2b.can:*` only applies to Firmenkunde accounts — Privatkunde and Admin
 * pass straight through it (see EnsureB2bPermission), so these routes keep
 * behaving exactly as before for them. For a company member it is the
 * route-level half of the check; which *rows* they may touch is still decided
 * by VehiclePolicy/VehicleScopeService.
 */
Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('dashboard', [VehicleController::class, 'index'])->name('dashboard');

    Route::post('vehicles', [VehicleController::class, 'store'])
        ->middleware('b2b.can:vehicles.create')->name('vehicles.store');

    Route::get('vehicles/{vehicleId}', [VehicleController::class, 'show'])
        ->middleware('b2b.can:vehicles.view')->name('vehicles.show');

    Route::patch('vehicles/{vehicleId}', [VehicleController::class, 'update'])
        ->middleware('b2b.can:vehicles.update')->name('vehicles.update');

    Route::post('vehicles/{vehicleId}/documents', [VehicleDocumentController::class, 'store'])
        ->middleware('b2b.can:vehicles.documents.upload')->name('vehicles.documents.store');

    Route::delete('vehicles/{vehicleId}/documents/{documentId}', [VehicleDocumentController::class, 'destroy'])
        ->middleware('b2b.can:vehicles.documents.delete')->name('vehicles.documents.destroy');
});
