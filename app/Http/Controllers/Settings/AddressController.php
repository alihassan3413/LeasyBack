<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Profile\Http\Requests\AddressContactRequest;
use App\Modules\UserProfile\Profile\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AddressController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(private readonly ProfileService $profileService) {}

    /**
     * Show the address & contact settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Address', [
            'profile' => $this->profileService->findForUser($request->user()),
        ]);
    }

    /**
     * Create the address, contact, and phone numbers for a user who doesn't
     * have a profile yet.
     */
    public function store(AddressContactRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'address',
            fn () => $this->profileService->createAddressContact($request->user(), $request->validated())
        ) ?? back();
    }

    /**
     * Update the address, contact, and phone numbers.
     */
    public function update(AddressContactRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'address',
            fn () => $this->profileService->updateAddressContact($request->user(), $request->validated())
        ) ?? back();
    }
}
