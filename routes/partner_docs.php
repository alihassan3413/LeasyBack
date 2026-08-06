<?php

use App\Http\Controllers\PartnerApiDocsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Partner API reference
|--------------------------------------------------------------------------
|
| The generated reference for /api/v1/partner, served to signed-in staff.
|
| Behind `['auth', 'active', 'verified', 'admin']`, the same gate the whole
| /admin section uses. That is deliberate and is the reason these docs are not
| generated into `public/`: this artefact describes an authentication scheme,
| an error contract and a webhook signing recipe for a named partner, and none
| of that belongs on an unauthenticated URL — even though it contains no
| secret. A partner receives the same bundle as a zip, out of band, from
| somebody who decided to send it.
|
| The paths are shaped by the generated HTML rather than chosen freely. Scribe
| writes a self-contained static bundle whose asset links are relative
| (`./css/…`, `./openapi.yaml`), and a browser resolves those against the
| *parent* of `/partner-api/docs` — so the assets and the two machine-readable
| artefacts sit one level up at `/partner-api/*`. Changing the docs path means
| changing both.
|
| Nothing here reads a path from the request. The controller resolves a fixed
| allow list of filenames inside the configured output directory, because the
| one thing a file-serving route must never do is take a caller's word for
| which file to open.
|
*/

Route::middleware(['auth', 'active', 'verified', 'admin'])
    ->prefix('partner-api')
    ->name('partner-api.docs')
    ->group(function () {
        Route::get('docs', [PartnerApiDocsController::class, 'index']);

        Route::get('openapi.yaml', [PartnerApiDocsController::class, 'openapi'])->name('.openapi');
        Route::get('collection.json', [PartnerApiDocsController::class, 'postman'])->name('.postman');
        Route::get('events.json', [PartnerApiDocsController::class, 'events'])->name('.events');

        Route::get('{directory}/{file}', [PartnerApiDocsController::class, 'asset'])
            ->where('directory', 'css|js|images')
            ->where('file', '[A-Za-z0-9._-]+')
            ->name('.asset');
    });
