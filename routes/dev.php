<?php

use App\Http\Controllers\Dev\EmailPreviewController;
use App\Http\Controllers\Dev\ErrorPreviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('dev/emails')->name('dev.emails.')->group(function () {
    Route::get('/', [EmailPreviewController::class, 'index'])->name('index');
    Route::get('{key}', [EmailPreviewController::class, 'show'])->name('show');
});

Route::prefix('dev/errors')->name('dev.errors.')->group(function () {
    Route::get('/', [ErrorPreviewController::class, 'index'])->name('index');
    Route::get('{status}', [ErrorPreviewController::class, 'show'])->whereNumber('status')->name('show');
    Route::get('{status}/abort', [ErrorPreviewController::class, 'abort'])->whereNumber('status')->name('abort');
});
