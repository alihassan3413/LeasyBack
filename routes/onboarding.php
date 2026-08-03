<?php

use App\Http\Controllers\B2bRegistrationController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('/', [OnboardingController::class, 'show'])->name('show');
    Route::post('/profile', [OnboardingController::class, 'storeProfile'])->name('profile.store');
    Route::put('/profile', [OnboardingController::class, 'updateProfile'])->name('profile.update');

    // Firmenkunde (B2B) counterpart of the wizard above: company master data
    // and the LeasyBack admin contact. Same middleware as step 1 — reachable
    // straight after registration, before the email is verified.
    // Deliberately ungated: a Firmenkunde who belongs to no company yet has no
    // permissions at all and must still be able to create one. This is a
    // one-time step — once a company exists `show` sends the user to "Mein
    // Konto", which is where the record is edited from then on
    // (see `company.update` in settings.php).
    Route::get('/b2b', [B2bRegistrationController::class, 'show'])->name('b2b.show');
    Route::post('/b2b', [B2bRegistrationController::class, 'store'])->name('b2b.store');
});

// Vehicle and appointment creation require a verified email, matching
// vehicles.php/orders.php elsewhere in the app — the wizard doesn't weaken
// that invariant just because it's reached right after registration.
Route::middleware(['auth', 'active', 'verified'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::post('/vehicle', [OnboardingController::class, 'storeVehicle'])->name('vehicle.store');
    Route::patch('/vehicle', [OnboardingController::class, 'updateVehicle'])->name('vehicle.update');
    Route::post('/appointment', [OnboardingController::class, 'storeAppointment'])->name('appointment.store');
});
