<?php

use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('/', [OnboardingController::class, 'show'])->name('show');
    Route::post('/profile', [OnboardingController::class, 'storeProfile'])->name('profile.store');
});

// Vehicle and appointment creation require a verified email, matching
// vehicles.php/orders.php elsewhere in the app — the wizard doesn't weaken
// that invariant just because it's reached right after registration.
Route::middleware(['auth', 'active', 'verified'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::post('/vehicle', [OnboardingController::class, 'storeVehicle'])->name('vehicle.store');
    Route::post('/appointment', [OnboardingController::class, 'storeAppointment'])->name('appointment.store');
});
