<?php

use App\Http\Controllers\Admin\ImpersonationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::delete('impersonate', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('impersonate.destroy');

require __DIR__.'/vehicles.php';
require __DIR__.'/orders.php';
require __DIR__.'/onboarding.php';
require __DIR__.'/b2b.php';
require __DIR__.'/admin.php';
require __DIR__.'/notifications.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

if (app()->environment('local')) {
    require __DIR__.'/dev.php';
}

load_module_routes();
