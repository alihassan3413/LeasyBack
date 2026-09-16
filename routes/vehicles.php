<?php

use App\Http\Controllers\B2b\VehicleImportController;
use App\Http\Controllers\DashboardController;
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
    /*
     * The landing page every post-login redirect goes to: the service
     * catalogue, and the vehicle picker each service is booked through. The
     * fleet it books against is `vehicles.index` below.
     */
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * The fleet. Deliberately not behind `b2b.can:vehicles.view` like the
     * routes below it: the controller answers a member who lacks it with a
     * redirect to the page they *can* see, which is friendlier than the 403
     * the middleware would raise for the nav's own entry.
     */
    Route::get('fahrzeuge', [VehicleController::class, 'index'])->name('vehicles.index');

    Route::post('vehicles', [VehicleController::class, 'store'])
        ->middleware('b2b.can:vehicles.create')->name('vehicles.store');

    /*
     * Bulk import (§5). Same permission as creating one vehicle by hand —
     * importing is not a distinct capability, it is the same one applied to a
     * file. Both actions re-check the caller is a Firmenkunde in the
     * controller, because `b2b.can:*` waves other account types through.
     */
    Route::post('vehicles/import', [VehicleImportController::class, 'store'])
        ->middleware('b2b.can:vehicles.create')->name('vehicles.import');

    Route::get('vehicles/import/template', [VehicleImportController::class, 'template'])
        ->middleware('b2b.can:vehicles.create')->name('vehicles.import.template');

    Route::get('vehicles/{vehicleId}', [VehicleController::class, 'show'])
        ->middleware('b2b.can:vehicles.view')->name('vehicles.show');

    Route::patch('vehicles/{vehicleId}', [VehicleController::class, 'update'])
        ->middleware('b2b.can:vehicles.update')->name('vehicles.update');

    Route::post('vehicles/{vehicleId}/documents', [VehicleDocumentController::class, 'store'])
        ->middleware('b2b.can:vehicles.documents.upload')->name('vehicles.documents.store');

    Route::delete('vehicles/{vehicleId}/documents/{documentId}', [VehicleDocumentController::class, 'destroy'])
        ->middleware('b2b.can:vehicles.documents.delete')->name('vehicles.documents.destroy');
});
