<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Profile\Http\Requests\PreferencesRequest;
use App\Modules\UserProfile\Profile\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(private readonly ProfileService $profileService) {}

    /**
     * Show the preferences settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Preferences', [
            'preferences' => $this->profileService->findPreferencesForUser($request->user()),
        ]);
    }

    /**
     * Create preferences for a user who doesn't have any yet.
     */
    public function store(PreferencesRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'preferences',
            fn () => $this->profileService->createPreferences($request->user(), $request->validated())
        ) ?? to_route('preferences.edit')->with('success', 'Einstellungen wurden gespeichert.');
    }

    /**
     * Update preferences.
     */
    public function update(PreferencesRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'preferences',
            fn () => $this->profileService->updatePreferences($request->user(), $request->validated())
        ) ?? to_route('preferences.edit')->with('success', 'Einstellungen wurden aktualisiert.');
    }
}
