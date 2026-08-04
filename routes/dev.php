<?php

use App\Http\Controllers\Dev\EmailPreviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('dev/emails')->name('dev.emails.')->group(function () {
    Route::get('/', [EmailPreviewController::class, 'index'])->name('index');
    Route::get('{key}', [EmailPreviewController::class, 'show'])->name('show');
});
