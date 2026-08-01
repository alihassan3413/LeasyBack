<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('{type}/{id}', [CustomerController::class, 'show'])->where('type', 'b2c|b2b')->name('show');
        Route::patch('{type}/{id}/status', [CustomerController::class, 'updateStatus'])->where('type', 'b2c|b2b')->name('status');
    });
});
