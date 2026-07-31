<?php

namespace App\Modules\UserProfile\Profile\Http\Controllers;

use App\Modules\UserProfile\Profile\Http\Requests\AddressContactRequest;
use App\Modules\UserProfile\Profile\Http\Requests\PreferencesRequest;
use App\Modules\UserProfile\Profile\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profileService) {}

    /**
     * POST /userprofile/address-contact
     */
    public function storeAddressContact(AddressContactRequest $request): JsonResponse
    {
        $result = $this->profileService->createAddressContact($request->user(), $request->validated());

        return response()->json($result, 201);
    }

    /**
     * PUT /userprofile/address-contact
     *
     * The fixed IDOR: this used to update `Address`/`Contact` rows straight
     * from the client-supplied `address_id`/`contact_id` with no check that
     * either one belonged to the caller. ProfileService::updateAddressContact
     * verifies both ids resolve to the caller's own profile (via a scoped
     * join, locked for update) before writing anything, and returns a clean
     * 404 otherwise instead of silently no-op'ing or leaking which id was
     * wrong.
     */
    public function updateAddressContact(AddressContactRequest $request): JsonResponse
    {
        $result = $this->profileService->updateAddressContact($request->user(), $request->validated());

        return response()->json($result);
    }

    /**
     * POST /userprofile/user-preferences
     */
    public function storePreferences(PreferencesRequest $request): JsonResponse
    {
        $result = $this->profileService->createPreferences($request->user(), $request->validated());

        return response()->json($result, 201);
    }

    /**
     * PUT /userprofile/user-preferences
     */
    public function updatePreferences(PreferencesRequest $request): JsonResponse
    {
        $result = $this->profileService->updatePreferences($request->user(), $request->validated());

        return response()->json($result);
    }

    /**
     * GET /userprofile/user-profile
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->profileService->findForUser($request->user());

        if ($profile === null) {
            return response()->json(['error' => 'Not Found: User profile not found'], 404);
        }

        return response()->json($profile);
    }
}
