<?php

use App\Modules\DekraProcess\Http\Controllers\DekraController;
use Illuminate\Support\Facades\Route;

// DEKRA calls this webhook directly and cannot provide a user Sanctum token.
Route::post('dekra/terminbestaetigung', [DekraController::class, 'receiveTerminbestaetigung'])
    ->middleware(['throttle:30,1', 'dekra.webhook'])
    ->name('dekra.terminbestaetigung.receive');

Route::middleware('auth:sanctum')
    ->prefix('dekra')
    ->name('dekra.')
    ->group(function () {
        Route::post('clients', [DekraController::class, 'createClient'])->name('clients.store');
        Route::post('dienstleistungsobjekt', [DekraController::class, 'createDienstleistungsobjekt'])->name('dienstleistungsobjekt.store');
        Route::post('partner', [DekraController::class, 'createPartner'])->name('partner.store');
        Route::post('besichtigungs_orte', [DekraController::class, 'createBesichtigungOrte'])->name('besichtigungs-orte.store');
        Route::post('kunden_auftrag', [DekraController::class, 'createKundenAuftrag'])->name('kunden-auftrag.store');
        Route::post('anlage_liste', [DekraController::class, 'createAnlageListe'])->name('anlage-liste.store');
        Route::post('auftrag', [DekraController::class, 'generateAndSendAuftrag'])->name('auftrag.send');
        Route::get('auftrag/info/{beauftragungsnummer}', [DekraController::class, 'getAuftragInfo'])->name('auftrag.info');

        // Backward-compatible aliases for the previously exposed Laravel URLs.
        Route::post('besichtigungs-orte', [DekraController::class, 'createBesichtigungOrte'])->name('besichtigungs-orte.compat');
        Route::post('kunden-auftrag', [DekraController::class, 'createKundenAuftrag'])->name('kunden-auftrag.compat');
        Route::post('anlage-liste', [DekraController::class, 'createAnlageListe'])->name('anlage-liste.compat');
    });
