<?php

namespace App\Modules\UserProfile\Profile\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfileService
{
    public function createAddressContact(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            if (DB::table('user_profiles')->where('user_id', $user->id)->lockForUpdate()->exists()) {
                $this->fail(409, 'A user profile already exists.');
            }

            $addressId = (string) Str::uuid();
            $contactId = (string) Str::uuid();
            $now = now();
            DB::table('addresses')->insert([
                'address_id' => $addressId,
                ...$this->addressValues($data['address']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('contacts')->insert([
                'contact_id' => $contactId,
                'address_id' => $addressId,
                ...$this->contactValues($data['contact']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertPhones($contactId, $data['phones'], false);
            DB::table('user_profiles')->insert([
                'user_id' => $user->id,
                'email' => $user->email,
                'contact_id' => $contactId,
                'is_admin' => $user->isAdmin(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['status' => 'created', 'address_id' => $addressId, 'contact_id' => $contactId];
        });
    }

    public function updateAddressContact(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $owned = DB::table('user_profiles as up')
                ->join('contacts as c', 'c.contact_id', '=', 'up.contact_id')
                ->where('up.user_id', $user->id)
                ->where('up.contact_id', $data['contact_id'])
                ->where('c.address_id', $data['address_id'])
                ->lockForUpdate()
                ->exists();

            if (! $owned) {
                $this->fail(404, 'Address or contact not found.');
            }

            DB::table('addresses')->where('address_id', $data['address_id'])->update([
                ...$this->addressValues($data['address']),
                'updated_at' => now(),
            ]);
            DB::table('contacts')->where('contact_id', $data['contact_id'])->update([
                ...$this->contactValues($data['contact']),
                'updated_at' => now(),
            ]);
            DB::table('phone_numbers')->where('contact_id', $data['contact_id'])->delete();
            $this->insertPhones($data['contact_id'], $data['phones'], false);

            return [
                'status' => 'updated',
                'address_id' => $data['address_id'],
                'contact_id' => $data['contact_id'],
            ];
        });
    }

    public function createPreferences(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            if (DB::table('user_preferences')->where('user_id', $user->id)->lockForUpdate()->exists()) {
                $this->fail(409, 'User preferences already exist.');
            }

            $id = (string) Str::uuid();
            DB::table('user_preferences')->insert([
                'preference_id' => $id,
                'user_id' => $user->id,
                ...$this->preferenceValues($data),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => 'created', 'preference_id' => $id];
        });
    }

    public function updatePreferences(User $user, array $data): array
    {
        $updated = DB::table('user_preferences')
            ->where('preference_id', $data['preference_id'])
            ->where('user_id', $user->id)
            ->update([...$this->preferenceValues($data), 'updated_at' => now()]);

        if ($updated === 0 && ! DB::table('user_preferences')
            ->where('preference_id', $data['preference_id'])
            ->where('user_id', $user->id)->exists()) {
            $this->fail(404, 'User preferences not found.');
        }

        return ['status' => 'updated', 'preference_id' => $data['preference_id']];
    }

    public function findForUser(User $user): ?array
    {
        $row = DB::table('user_profiles as up')
            ->leftJoin('contacts as c', 'c.contact_id', '=', 'up.contact_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'c.address_id')
            ->where('up.user_id', $user->id)
            ->select(['up.email', 'up.is_admin', 'c.contact_id', 'c.salutation',
                'c.first_name', 'c.last_name', 'a.address_id', 'a.street', 'a.number',
                'a.additional_address', 'a.zip_code', 'a.city', 'a.country',
                'a.longitude', 'a.latitude'])
            ->first();

        if (! $row) {
            return null;
        }

        $phones = DB::table('phone_numbers')->where('contact_id', $row->contact_id)
            ->orderBy('created_at')->get(['phone_id', 'international_prefix', 'phone_number'])
            ->map(fn ($phone) => (array) $phone)->all();
        $preferences = DB::table('user_preferences')->where('user_id', $user->id)
            ->first(['preference_id', 'timezone', 'sprache',
                'benachrichtigungseinstellungen_push',
                'benachrichtigungseinstellungen_email']);

        return [
            'user_id' => $user->id,
            'email' => $row->email,
            'is_admin' => (bool) $row->is_admin,
            'address' => $this->addressResponse($row),
            'contact' => $this->contactResponse($row),
            'phones' => $phones,
            'preferences' => $preferences ? $this->preferencesResponse($preferences) : null,
        ];
    }

    /**
     * Preferences live in their own table, independent of user_profiles —
     * unlike findForUser(), this doesn't return null just because the user
     * hasn't created an address/contact profile yet.
     */
    public function findPreferencesForUser(User $user): ?array
    {
        $preferences = DB::table('user_preferences')->where('user_id', $user->id)
            ->first(['preference_id', 'timezone', 'sprache',
                'benachrichtigungseinstellungen_push',
                'benachrichtigungseinstellungen_email']);

        return $preferences ? $this->preferencesResponse($preferences) : null;
    }

    private function addressValues(array $address): array
    {
        return [
            'street' => $address['street'],
            'number' => $address['number'],
            'additional_address' => $address['additional_address'] ?? null,
            'zip_code' => $address['zip_code'],
            'city' => $address['city'],
            'country' => $address['country'],
            // Rust explicitly skips deserializing supplied coordinates for this DTO.
            'longitude' => 0,
            'latitude' => 0,
        ];
    }

    private function contactValues(array $contact): array
    {
        return [
            'salutation' => $contact['salutation'],
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
        ];
    }

    private function preferenceValues(array $data): array
    {
        return [
            'timezone' => $data['timezone'],
            'sprache' => $data['sprache'],
            'benachrichtigungseinstellungen_push' => $data['benachrichtigungseinstellungen_push'],
            'benachrichtigungseinstellungen_email' => $data['benachrichtigungseinstellungen_email'],
        ];
    }

    private function insertPhones(string $contactId, array $phones, bool $primary): void
    {
        $now = now();
        foreach ($phones as $phone) {
            DB::table('phone_numbers')->insert([
                'phone_id' => (string) Str::uuid(),
                'contact_id' => $contactId,
                'international_prefix' => $phone['international_prefix'],
                'phone_number' => $phone['phone_number'],
                'is_primary_contact' => $primary,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function addressResponse(object $row): ?array
    {
        return $row->address_id === null ? null : [
            'address_id' => $row->address_id,
            'street' => $row->street,
            'number' => $row->number,
            'additional_address' => $row->additional_address ?? '',
            'zip_code' => $row->zip_code,
            'city' => $row->city,
            'country' => $row->country,
            'longitude' => (float) ($row->longitude ?? 0),
            'latitude' => (float) ($row->latitude ?? 0),
        ];
    }

    private function contactResponse(object $row): ?array
    {
        return $row->contact_id === null ? null : [
            'contact_id' => $row->contact_id,
            'salutation' => $row->salutation,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
        ];
    }

    private function preferencesResponse(object $preferences): array
    {
        return [
            'preference_id' => $preferences->preference_id,
            'timezone' => $preferences->timezone,
            'sprache' => $preferences->sprache,
            'benachrichtigungseinstellungen_push' => (bool) $preferences->benachrichtigungseinstellungen_push,
            'benachrichtigungseinstellungen_email' => (bool) $preferences->benachrichtigungseinstellungen_email,
        ];
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
