<?php

use App\Http\Controllers\Api\WorkshopController;
use App\Modules\UserProfile\B2B\Http\Controllers\B2BController;
use App\Modules\UserProfile\Profile\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('userprofile')->name('userprofile.')->group(function () {
        Route::post('address-contact', [ProfileController::class, 'storeAddressContact'])->name('address-contact.store');
        Route::put('address-contact', [ProfileController::class, 'updateAddressContact'])->name('address-contact.update');
        Route::post('user-preferences', [ProfileController::class, 'storePreferences'])->name('preferences.store');
        Route::put('user-preferences', [ProfileController::class, 'updatePreferences'])->name('preferences.update');
        Route::get('user-profile', [ProfileController::class, 'show'])->name('show');
    });

    Route::prefix('b2b')->name('b2b.')->group(function () {
        Route::post('create', [B2BController::class, 'store'])->name('create');
        Route::get('user_id/{id}', [B2BController::class, 'showByUser'])->whereNumber('id')->name('show');
        Route::patch('{id}', [B2BController::class, 'update'])->whereUuid('id')->name('update');
    });

    Route::prefix('workshop')->name('workshop.')->group(function () {
        Route::post('create', [WorkshopController::class, 'store'])->name('create');
        Route::get('user_id/{userId}', [WorkshopController::class, 'showByUser'])->whereNumber('userId')->name('show');
        Route::patch('{workshopId}', [WorkshopController::class, 'update'])->whereUuid('workshopId')->name('update');
        Route::post('{workshopId}/logo', [WorkshopController::class, 'uploadLogo'])->whereUuid('workshopId')->name('logo.upload');
        Route::delete('{workshopId}/logo', [WorkshopController::class, 'deleteLogo'])->whereUuid('workshopId')->name('logo.delete');
    });
});
