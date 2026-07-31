<?php

use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('dashboard', [VehicleController::class, 'index'])->name('dashboard');
    Route::post('vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
    Route::patch('vehicles/{vehicleId}', [VehicleController::class, 'update'])->name('vehicles.update');

    Route::post('vehicles/{vehicleId}/documents', [VehicleDocumentController::class, 'store'])->name('vehicles.documents.store');
    Route::delete('vehicles/{vehicleId}/documents/{documentId}', [VehicleDocumentController::class, 'destroy'])->name('vehicles.documents.destroy');
});
