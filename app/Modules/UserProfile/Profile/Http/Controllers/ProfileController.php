<?php

namespace App\Modules\UserProfile\Profile\Http\Controllers;

use App\Models\Address;
use App\Models\Contact;
use App\Models\LeasybackUserProfile;
use App\Models\PhoneNumber;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * POST /userprofile/address-contact
     */
    public function storeAddressContact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address.street' => 'required|string',
            'address.number' => 'required|string',
            'address.additional_address' => 'nullable|string',
            'address.zip_code' => 'required|string',
            'address.city' => 'required|string',
            'address.country' => 'required|string',
            'contact.salutation' => 'required|string',
            'contact.first_name' => 'required|string',
            'contact.last_name' => 'required|string',
            'phones' => 'required|array',
            'phones.*.international_prefix' => 'required|string',
            'phones.*.phone_number' => 'required|string',
        ]);

        $user = $request->user();

        $result = DB::transaction(function () use ($validated, $user) {
            $address = Address::create($validated['address']);

            $contact = Contact::create([
                ...$validated['contact'],
                'address_id' => $address->address_id,
            ]);

            foreach ($validated['phones'] as $phone) {
                PhoneNumber::create([
                    'contact_id' => $contact->contact_id,
                    ...$phone,
                ]);
            }

            LeasybackUserProfile::create([
                'email' => $user->email,
                'user_id' => $user->id,
                'contact_id' => $contact->contact_id,
                'is_admin' => false,
                'image_url' => null,
            ]);

            return [
                'address_id' => $address->address_id,
                'contact_id' => $contact->contact_id,
            ];
        });

        return response()->json([
            'status' => 'created',
            'address_id' => $result['address_id'],
            'contact_id' => $result['contact_id'],
        ]);
    }

    /**
     * PUT /userprofile/address-contact
     */
    public function updateAddressContact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => 'required|uuid',
            'contact_id' => 'required|uuid',
            'address.street' => 'required|string',
            'address.number' => 'required|string',
            'address.additional_address' => 'nullable|string',
            'address.zip_code' => 'required|string',
            'address.city' => 'required|string',
            'address.country' => 'required|string',
            'contact.salutation' => 'required|string',
            'contact.first_name' => 'required|string',
            'contact.last_name' => 'required|string',
            'phones' => 'required|array',
            'phones.*.international_prefix' => 'required|string',
            'phones.*.phone_number' => 'required|string',
        ]);

        DB::transaction(function () use ($validated) {
            Address::where('address_id', $validated['address_id'])
                ->update([...$validated['address'], 'updated_at' => now()]);

            Contact::where('contact_id', $validated['contact_id'])
                ->update([...$validated['contact'], 'updated_at' => now()]);

            // Replace phones
            PhoneNumber::where('contact_id', $validated['contact_id'])->delete();
            foreach ($validated['phones'] as $phone) {
                PhoneNumber::create([
                    'contact_id' => $validated['contact_id'],
                    ...$phone,
                ]);
            }
        });

        return response()->json([
            'status' => 'updated',
            'address_id' => $validated['address_id'],
            'contact_id' => $validated['contact_id'],
        ]);
    }

    /**
     * POST /userprofile/user-preferences
     */
    public function storePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timezone' => 'required|string',
            'sprache' => 'required|string|in:de,en,fr,es,it',
            'benachrichtigungseinstellungen_push' => 'required|boolean',
            'benachrichtigungseinstellungen_email' => 'required|boolean',
        ]);

        $user = $request->user();

        $preference = UserPreference::create([
            'user_id' => $user->id,
            ...$validated,
        ]);

        return response()->json([
            'status' => 'created',
            'preference_id' => $preference->preference_id,
        ]);
    }

    /**
     * PUT /userprofile/user-preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preference_id' => 'required|uuid',
            'timezone' => 'required|string',
            'sprache' => 'required|string|in:de,en,fr,es,it',
            'benachrichtigungseinstellungen_push' => 'required|boolean',
            'benachrichtigungseinstellungen_email' => 'required|boolean',
        ]);

        $user = $request->user();

        UserPreference::where('preference_id', $validated['preference_id'])
            ->where('user_id', $user->id)
            ->update([
                'timezone' => $validated['timezone'],
                'sprache' => $validated['sprache'],
                'benachrichtigungseinstellungen_push' => $validated['benachrichtigungseinstellungen_push'],
                'benachrichtigungseinstellungen_email' => $validated['benachrichtigungseinstellungen_email'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => 'updated',
            'preference_id' => $validated['preference_id'],
        ]);
    }

    /**
     * GET /userprofile/user-profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $profile = DB::table('user_profiles as up')
            ->leftJoin('contacts as c', 'up.contact_id', '=', 'c.contact_id')
            ->leftJoin('addresses as a', 'c.address_id', '=', 'a.address_id')
            ->where('up.user_id', $user->id)
            ->select([
                'up.email', 'up.is_admin',
                'c.contact_id', 'c.salutation', 'c.first_name', 'c.last_name',
                'a.address_id', 'a.street', 'a.number', 'a.additional_address',
                'a.zip_code', 'a.city', 'a.country', 'a.longitude', 'a.latitude',
            ])
            ->first();

        if (!$profile) {
            return response()->json(['error' => 'Not Found: User profile not found'], 404);
        }

        // Build address
        $address = $profile->address_id ? [
            'address_id' => $profile->address_id,
            'street' => $profile->street,
            'number' => $profile->number,
            'additional_address' => $profile->additional_address ?? '',
            'zip_code' => $profile->zip_code,
            'city' => $profile->city,
            'country' => $profile->country,
            'longitude' => (float) ($profile->longitude ?? 0),
            'latitude' => (float) ($profile->latitude ?? 0),
        ] : null;

        // Build contact
        $contact = $profile->contact_id ? [
            'contact_id' => $profile->contact_id,
            'salutation' => $profile->salutation,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
        ] : null;

        // Phones
        $phones = DB::table('phone_numbers')
            ->whereIn('contact_id', function ($q) use ($user) {
                $q->select('contact_id')
                  ->from('user_profiles')
                  ->where('user_id', $user->id);
            })
            ->select('phone_id', 'international_prefix', 'phone_number')
            ->get()
            ->toArray();

        // Preferences
        $pref = DB::table('user_preferences')
            ->where('user_id', $user->id)
            ->select('preference_id', 'timezone', 'sprache',
                'benachrichtigungseinstellungen_push',
                'benachrichtigungseinstellungen_email')
            ->first();

        $preferences = $pref ? (array) $pref : null;

        return response()->json([
            'user_id' => $user->id,
            'email' => $profile->email,
            'is_admin' => (bool) $profile->is_admin,
            'address' => $address,
            'contact' => $contact,
            'phones' => $phones,
            'preferences' => $preferences,
        ]);
    }
}
