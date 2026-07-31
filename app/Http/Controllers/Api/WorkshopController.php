<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkshopController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureWorkshopUser($user->user_type, $user->isAdmin());

        $validated = $request->validate($this->createRules());

        if (Workshop::where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'A workshop profile already exists for this user.',
            ], 409);
        }

        $workshop = DB::transaction(function () use ($validated, $user) {
            return Workshop::create($this->creationAttributes($validated, $user->id));
        });

        return response()->json($this->createResponse($workshop), 201);
    }

    public function showByUser(Request $request, string $userId): JsonResponse
    {
        $workshop = Workshop::where('user_id', $userId)->firstOrFail();
        $request->user()->can('view', $workshop) || abort(403, 'You cannot access this workshop.');

        return response()->json($this->profileResponse($workshop));
    }

    public function update(Request $request, string $workshopId): JsonResponse
    {
        $workshop = Workshop::findOrFail($workshopId);
        $request->user()->can('update', $workshop) || abort(403, 'You cannot access this workshop.');
        $validated = $request->validate($this->updateRules());

        // logo_url is deliberately excluded here — it's derived from
        // logo_path, which is only ever set via uploadLogo() below, never
        // accepted as a free-text string from the client.
        $attributes = [];
        foreach (['workshop_name', 'contact_email', 'has_vat_id', 'vat_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        $addressMap = [
            'street' => 'street', 'number' => 'number',
            'additional_address' => 'additional_address', 'zip_code' => 'zip_code',
            'city' => 'city', 'country' => 'country',
        ];
        foreach ($addressMap as $input => $column) {
            if (array_key_exists($input, $validated['address'] ?? [])) {
                $attributes[$column] = $validated['address'][$input];
            }
        }

        $contactMap = [
            'salutation' => 'salutation', 'first_name' => 'first_name',
            'last_name' => 'last_name', 'international_prefix' => 'international_prefix',
            'primary_phone_number' => 'primary_phone_number',
            'phone_numbers' => 'phone_numbers',
        ];
        foreach ($contactMap as $input => $column) {
            if (array_key_exists($input, $validated['contact'] ?? [])) {
                $attributes[$column] = $validated['contact'][$input];
            }
        }

        $workshop->update($attributes);

        return response()->json('updated');
    }

    /**
     * POST /workshop/{workshopId}/logo
     *
     * Logos are public assets (shown on the workshop's public listing), so
     * they live on the 'public' disk — deliberately separate from the
     * private 'documents' disk used for customer/vehicle documents. Still
     * follows the same rule: the client never supplies a path, and every
     * write is Policy-authorized against a real, loaded Workshop record.
     */
    public function uploadLogo(Request $request, string $workshopId): JsonResponse
    {
        $workshop = Workshop::findOrFail($workshopId);
        $request->user()->can('manageLogo', $workshop) || abort(403, 'You cannot manage this workshop\'s logo.');

        $request->validate([
            'file' => 'required|file|mimes:png,jpg,jpeg|max:10240',
        ]);

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $path = "logos/{$workshop->id}-".Str::uuid().".{$ext}";

        Storage::disk('public')->put($path, file_get_contents($file));

        // Replace, don't accumulate: remove the previous logo file if one existed.
        if ($workshop->logo_path) {
            Storage::disk('public')->delete($workshop->logo_path);
        }

        $workshop->update([
            'logo_path' => $path,
            'logo_url' => Storage::disk('public')->url($path),
        ]);

        return response()->json([
            'workshop_id' => $workshop->id,
            'logo_url' => $workshop->logo_url,
        ]);
    }

    /**
     * DELETE /workshop/{workshopId}/logo
     */
    public function deleteLogo(Request $request, string $workshopId): JsonResponse
    {
        $workshop = Workshop::findOrFail($workshopId);
        $request->user()->can('manageLogo', $workshop) || abort(403, 'You cannot manage this workshop\'s logo.');

        if ($workshop->logo_path) {
            Storage::disk('public')->delete($workshop->logo_path);
        }

        $workshop->update(['logo_path' => null, 'logo_url' => null]);

        return response()->json(['workshop_id' => $workshop->id, 'logo_url' => null]);
    }

    private function ensureWorkshopUser(UserType $type, bool $isAdmin): void
    {
        abort_unless($type === UserType::Werkstatt || $isAdmin, 403, 'Only workshop users may manage a workshop profile.');
    }

    private function createRules(): array
    {
        return [
            'workshop_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email:rfc,dns', 'max:255'],
            'has_vat_id' => ['required', 'boolean'],
            'vat_id' => ['nullable', 'required_if:has_vat_id,true', 'string', 'max:50'],
            'iban' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9 ]+$/'],
            'bic' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'account_holder' => ['required', 'string', 'max:255'],
            'packages_selected' => ['required', 'in:Pro,Premium'],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
            'address' => ['required', 'array'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.number' => ['required', 'string', 'max:30'],
            'address.zip_code' => ['required', 'string', 'max:20'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.country' => ['required', 'string', 'max:100'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'contact' => ['required', 'array'],
            'contact.salutation' => ['required', 'string', 'max:30'],
            'contact.first_name' => ['required', 'string', 'max:100'],
            'contact.last_name' => ['required', 'string', 'max:100'],
            'contact.international_prefix' => ['required', 'string', 'max:10'],
            'contact.primary_phone_number' => ['required', 'string', 'max:30'],
            'contact.phone_numbers' => ['present', 'array', 'max:10'],
            'contact.phone_numbers.*.international_prefix' => ['required', 'string', 'max:10'],
            'contact.phone_numbers.*.phone_number' => ['required', 'string', 'max:30'],
        ];
    }

    private function updateRules(): array
    {
        return [
            'workshop_name' => ['sometimes', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'email:rfc,dns', 'max:255'],
            'has_vat_id' => ['sometimes', 'boolean'],
            'vat_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'array'],
            'address.street' => ['sometimes', 'string', 'max:255'],
            'address.number' => ['sometimes', 'string', 'max:30'],
            'address.additional_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.zip_code' => ['sometimes', 'string', 'max:20'],
            'address.city' => ['sometimes', 'string', 'max:100'],
            'address.country' => ['sometimes', 'string', 'max:100'],
            'contact' => ['sometimes', 'array'],
            'contact.salutation' => ['sometimes', 'string', 'max:30'],
            'contact.first_name' => ['sometimes', 'string', 'max:100'],
            'contact.last_name' => ['sometimes', 'string', 'max:100'],
            'contact.international_prefix' => ['sometimes', 'string', 'max:10'],
            'contact.primary_phone_number' => ['sometimes', 'string', 'max:30'],
            'contact.phone_numbers' => ['sometimes', 'array', 'max:10'],
            'contact.phone_numbers.*.international_prefix' => ['required', 'string', 'max:10'],
            'contact.phone_numbers.*.phone_number' => ['required', 'string', 'max:30'],
            'contact.phone_numbers.*.is_primary_contact' => ['sometimes', 'boolean'],
        ];
    }

    private function creationAttributes(array $data, int $userId): array
    {
        return [
            'id' => (string) Str::uuid(), 'user_id' => $userId,
            'workshop_name' => $data['workshop_name'], 'contact_email' => $data['contact_email'],
            'has_vat_id' => $data['has_vat_id'], 'vat_id' => $data['vat_id'] ?? null,
            'iban' => $data['iban'], 'bic' => $data['bic'],
            'account_holder' => $data['account_holder'],
            'packages_selected' => $data['packages_selected'],
            'terms_accepted' => true, 'privacy_accepted' => true,
            'address_id' => (string) Str::uuid(), 'street' => $data['address']['street'],
            'number' => $data['address']['number'], 'zip_code' => $data['address']['zip_code'],
            'city' => $data['address']['city'], 'country' => $data['address']['country'],
            'longitude' => $data['address']['longitude'] ?? null,
            'latitude' => $data['address']['latitude'] ?? null,
            'contact_id' => (string) Str::uuid(), 'salutation' => $data['contact']['salutation'],
            'first_name' => $data['contact']['first_name'], 'last_name' => $data['contact']['last_name'],
            'international_prefix' => $data['contact']['international_prefix'],
            'primary_phone_number' => $data['contact']['primary_phone_number'],
            'phone_numbers' => $data['contact']['phone_numbers'],
        ];
    }

    private function createResponse(Workshop $workshop): array
    {
        return [
            'workshop_id' => $workshop->id,
            'contact_id' => $workshop->contact_id,
            'address_id' => $workshop->address_id,
            'workshop_name' => $workshop->workshop_name,
            'created_at' => $workshop->created_at->toISOString(),
            'updated_at' => $workshop->updated_at->toISOString(),
        ];
    }

    private function profileResponse(Workshop $workshop): array
    {
        $phones = [[
            'international_prefix' => $workshop->international_prefix,
            'phone_number' => $workshop->primary_phone_number,
            'is_primary_contact' => true,
        ]];

        foreach ($workshop->phone_numbers ?? [] as $phone) {
            $phones[] = [
                'international_prefix' => $phone['international_prefix'],
                'phone_number' => $phone['phone_number'],
                'is_primary_contact' => (bool) ($phone['is_primary_contact'] ?? false),
            ];
        }

        return [
            'workshop_id' => $workshop->id,
            'workshop_name' => $workshop->workshop_name,
            'logo_url' => $workshop->logo_url,
            'contact_email' => $workshop->contact_email,
            'has_vat_id' => $workshop->has_vat_id,
            'vat_id' => $workshop->vat_id,
            'packages_selected' => $workshop->packages_selected,
            'imprint_text' => $workshop->imprint_text,
            'services_offered' => $workshop->services_offered,
            'created_at' => $workshop->created_at->toISOString(),
            'updated_at' => $workshop->updated_at->toISOString(),
            'contact' => [
                'contact_id' => $workshop->contact_id,
                'salutation' => $workshop->salutation,
                'first_name' => $workshop->first_name,
                'last_name' => $workshop->last_name,
                'phone_numbers' => $phones,
            ],
            'address' => [
                'address_id' => $workshop->address_id,
                'street' => $workshop->street,
                'number' => $workshop->number,
                'additional_address' => $workshop->additional_address,
                'zip_code' => $workshop->zip_code,
                'city' => $workshop->city,
                'country' => $workshop->country,
            ],
        ];
    }
}
