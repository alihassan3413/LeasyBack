<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and all of them will
| be assigned the "api" middleware group.
|
| Prefix: /api
|
*/

// Public auth routes (rate limited)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')  // 5 requests per minute
        ->name('api.auth.register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1') // 10 requests per minute
        ->name('api.auth.login');
});

// Protected routes (require Sanctum token)
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Auth endpoints
    Route::prefix('auth')->group(function () {
        Route::post('/changepassword', [AuthController::class, 'changePassword'])
            ->middleware('throttle:5,1')
            ->name('api.auth.changepassword');

        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('api.auth.logout');

        Route::get('/me', [AuthController::class, 'me'])
            ->name('api.auth.me');
    });

    // User Profile endpoints
    Route::prefix('profile')->group(function () {
        Route::get('/', [UserProfileController::class, 'show'])
            ->name('api.profile.show');

        Route::put('/', [UserProfileController::class, 'update'])
            ->name('api.profile.update');

        Route::post('/avatar', [UserProfileController::class, 'uploadAvatar'])
            ->name('api.profile.avatar.upload');

        Route::delete('/avatar', [UserProfileController::class, 'deleteAvatar'])
            ->name('api.profile.avatar.delete');

        Route::delete('/', [UserProfileController::class, 'destroy'])
            ->name('api.profile.destroy');
    });
});

require app_path('Modules/DekraProcess/api_routes.php');
require app_path('Modules/UserProfile/routes.php');
