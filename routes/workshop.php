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
        ->middleware('throttle:workshop-page')
        ->name('thanks');

    Route::get('{token}', [QuotationSubmissionController::class, 'show'])
        ->middleware('throttle:workshop-page')
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('show');

    // Throttled harder than the page: rendering a PDF costs real work, and the
    // token is the only credential in front of it. Limits live in
    // AppServiceProvider, which is also where the buckets are kept apart.
    Route::get('{token}/pdf', [QuotationSubmissionController::class, 'pdf'])
        ->middleware('throttle:workshop-pdf')
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('pdf');

    Route::get('{token}/bilder/{documentId}', [QuotationSubmissionController::class, 'image'])
        ->middleware('throttle:workshop-images')
        ->where('token', '[A-Za-z0-9]{64}')
        ->whereUuid('documentId')
        ->name('images.show');

    Route::post('{token}', [QuotationSubmissionController::class, 'store'])
        ->middleware('throttle:workshop-submit')
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('submit');
});
