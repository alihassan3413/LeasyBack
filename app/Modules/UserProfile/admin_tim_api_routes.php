<?php

use App\Modules\UserProfile\Admin\Http\Controllers\AdminController;
use App\Modules\UserProfile\Admin\Http\Controllers\VehicleReportController;
use App\Modules\UserProfile\Tim\Http\Controllers\TimController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('tim/appraisal')->name('tim.appraisal.')->group(function () {
        Route::post('login/refresh', [TimController::class, 'refreshLogin'])->name('login.refresh');
        Route::post('xml/sync/{bewertungId}', [TimController::class, 'sync'])->whereNumber('bewertungId')->name('xml.sync');
        Route::get('xml/{bewertungId}', [TimController::class, 'xml'])->whereNumber('bewertungId')->name('xml.show');
        Route::get('docs/{auftragsnummer}', [TimController::class, 'documents'])->name('documents.index');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::patch('b2c/{userId}/status', [AdminController::class, 'updateB2cStatus'])->name('b2c.status');
        Route::patch('b2b/{b2bId}/status', [AdminController::class, 'updateB2bStatus'])->name('b2b.status');
        Route::get('dashboard/summary', [AdminController::class, 'summary'])->name('dashboard.summary');
        Route::get('users/b2c', [AdminController::class, 'b2c'])->name('users.b2c');
        Route::get('users/b2b', [AdminController::class, 'b2b'])->name('users.b2b');
        Route::get('list/orders', [AdminController::class, 'orders'])->name('orders.index');
        Route::get('list/orders/by-user-type', [AdminController::class, 'ordersByUserType'])->name('orders.by-user-type');
        Route::get('list/orders/user/{userId}', [AdminController::class, 'ordersByUser'])->name('orders.by-user');
        Route::get('list/vehicles', [AdminController::class, 'vehicles'])->name('vehicles.index');
        Route::get('list/vehicles/by-user-type', [AdminController::class, 'vehiclesByUserType'])->name('vehicles.by-user-type');
        Route::get('list/vehicles/user/{userId}', [AdminController::class, 'vehiclesByUser'])->name('vehicles.by-user');
        Route::post('vehicle/report/transfer', [VehicleReportController::class, 'transfer'])->name('vehicle-report.transfer');
        Route::post('vehicle/report/upload', [VehicleReportController::class, 'upload'])->name('vehicle-report.upload');
        Route::patch('vehicle/report/publish/{documentId}', [VehicleReportController::class, 'publish'])->whereUuid('documentId')->name('vehicle-report.publish');
        Route::delete('vehicle/report/delete/{documentId}', [VehicleReportController::class, 'delete'])->whereUuid('documentId')->name('vehicle-report.delete');
    });
});
