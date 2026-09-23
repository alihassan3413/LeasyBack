<?php

use App\Http\Controllers\Workshop\QuotationSubmissionController;
use Illuminate\Support\Facades\Route;

/*
 * Public workshop quotation links (b2b.txt §9). Guests by design — a workshop
 * has no portal account. The token is the only secret involved, so every route
 * is throttled, and the token is never used to look anything up beyond its own
 * quotation. No order or vehicle id is ever accepted from the URL.
 */
Route::prefix('werkstatt/angebot')->name('workshop.quotations.')->group(function () {
    Route::get('danke', [QuotationSubmissionController::class, 'thanks'])
        ->middleware('throttle:30,1')
        ->name('thanks');

    Route::get('{token}', [QuotationSubmissionController::class, 'show'])
        ->middleware('throttle:30,1')
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('show');

    Route::post('{token}', [QuotationSubmissionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('submit');
});
