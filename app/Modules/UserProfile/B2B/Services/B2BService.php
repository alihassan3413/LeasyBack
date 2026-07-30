<?php

namespace App\Modules\UserProfile\B2B\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class B2BService
{
    public function create(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $existing = DB::table('user_b2b')->where('user_id', $user->id)
                ->lockForUpdate()->value('b2b_id');
            if ($existing !== null) {
                throw new HttpResponseException(response()->json([
                    'error' => 'User already belongs to a company',
                    'b2b_id' => $existing,
                ], 409));
            }

            $addressId = $this->insertAddress($data['address']);
            $contactId = $this->insertContact($data['contact'], $addressId);
            $b2bId = (string) Str::uuid();
            $now = now();
            DB::table('b2b')->insert([
                'b2b_id' => $b2bId,
                'contact_id' => $contactId,
                'address_id' => $addressId,
                'company_name' => $data['company_name'],
                'vat_id' => $data['vat_id'] ?? null,
                'logo_url' => $data['logo_url'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('user_b2b')->insert([
                'user_id' => $user->id,
                'b2b_id' => $b2bId,
                'role' => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->creationResponse($b2bId);
        });
    }
    public function findForUser(int $userId): ?array
    {
        $row = DB::table('b2b as b')
            ->join('user_b2b as ub', 'ub.b2b_id', '=', 'b.b2b_id')
            ->leftJoin('contacts as c', 'c.contact_id', '=', 'b.contact_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'b.address_id')
            ->where('ub.user_id', $userId)
            ->orderByDesc('b.created_at')
            ->select(['b.b2b_id', 'b.company_name', 'b.logo_url', 'b.contact_email',
                'b.vat_id', 'b.created_at', 'b.updated_at', 'c.contact_id',
                'c.salutation', 'c.first_name', 'c.last_name', 'a.address_id',
                'a.street', 'a.number', 'a.additional_address', 'a.zip_code',
                'a.city', 'a.country'])
            ->first();

        if (! $row) {
            return null;
        }

        $phones = DB::table('phone_numbers')->where('contact_id', $row->contact_id)
            ->orderByDesc('is_primary_contact')->orderBy('created_at')
            ->get(['international_prefix', 'phone_number', 'is_primary_contact'])
            ->map(fn ($phone) => [
                'international_prefix' => $phone->international_prefix,
                'phone_number' => $phone->phone_number,
                'is_primary_contact' => (bool) $phone->is_primary_contact,
            ])->all();

        return [
            'b2b' => $row->b2b_id,
            'company_name' => $row->company_name,
            'logo_url' => $row->logo_url,
            'contact_email' => $row->contact_email,
            'vat_id' => $row->vat_id,
            'created_at' => Carbon::parse($row->created_at)->toISOString(),
            'updated_at' => Carbon::parse($row->updated_at)->toISOString(),
            'contact' => $row->contact_id === null ? null : [
                'contact_id' => $row->contact_id,
                'salutation' => $row->salutation,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'phone_numbers' => $phones,
            ],
            'address' => $this->addressResponse($row),
        ];
    }
    public function update(User $user, string $b2bId, array $data): array
    {
        return DB::transaction(function () use ($user, $b2bId, $data) {
            $owned = DB::table('user_b2b')->where('user_id', $user->id)
                ->where('b2b_id', $b2bId)->where('role', 'owner')
                ->lockForUpdate()->exists();
            if (! $owned) {
                $this->fail(403, 'Access denied: you are not the owner of this B2B company');
            }

            $company = DB::table('b2b')->where('b2b_id', $b2bId)
                ->lockForUpdate()->first(['address_id', 'contact_id']);
            if (! $company) {
                $this->fail(404, 'B2B company not found');
            }

            $changed = false;
            if (array_key_exists('address', $data)) {
                DB::table('addresses')->where('address_id', $company->address_id)
                    ->update([...$this->addressValues($data['address']), 'updated_at' => now()]);
                $changed = true;
            }
            if (array_key_exists('contact', $data)) {
                DB::table('contacts')->where('contact_id', $company->contact_id)->update([
                    'salutation' => $data['contact']['salutation'] ?? null,
                    'first_name' => $data['contact']['first_name'],
                    'last_name' => $data['contact']['last_name'],
                    'updated_at' => now(),
                ]);
                if (array_key_exists('phone_numbers', $data['contact'])) {
                    $this->replacePhones($company->contact_id, $data['contact']);
                }
                $changed = true;
            }

            $updates = [];
            foreach (['company_name', 'contact_email', 'vat_id', 'logo_url'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }
            if ($updates !== []) {
                DB::table('b2b')->where('b2b_id', $b2bId)
                    ->update([...$updates, 'updated_at' => now()]);
                $changed = true;
            }
            if (! $changed) {
                $this->fail(400, 'No fields provided to update');
            }

            return ['message' => 'B2B updated successfully', 'b2b_id' => $b2bId];
        });
    }
    private function insertAddress(array $address): string
    {
        $id = (string) Str::uuid();
        DB::table('addresses')->insert([
            'address_id' => $id,
            ...$this->addressValues($address),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertContact(array $contact, string $addressId): string
    {
        $id = (string) Str::uuid();
        DB::table('contacts')->insert([
            'contact_id' => $id,
            'address_id' => $addressId,
            'salutation' => $contact['salutation'] ?? null,
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->replacePhones($id, $contact);

        return $id;
    }

    private function replacePhones(string $contactId, array $contact): void
    {
        DB::table('phone_numbers')->where('contact_id', $contactId)->delete();
        $phones = [[
            'international_prefix' => $contact['international_prefix'],
            'phone_number' => $contact['primary_phone_number'],
            'is_primary_contact' => true,
        ]];
        foreach ($contact['phone_numbers'] ?? [] as $phone) {
            $phones[] = [...$phone, 'is_primary_contact' => false];
        }
        foreach ($phones as $phone) {
            DB::table('phone_numbers')->insert([
                'phone_id' => (string) Str::uuid(),
                'contact_id' => $contactId,
                ...$phone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
            'longitude' => 0,
            'latitude' => 0,
        ];
    }

    private function addressResponse(object $row): ?array
    {
        return $row->address_id === null ? null : [
            'address_id' => $row->address_id,
            'street' => $row->street,
            'number' => $row->number,
            'additional_address' => $row->additional_address,
            'zip_code' => $row->zip_code,
            'city' => $row->city,
            'country' => $row->country,
        ];
    }

    private function creationResponse(string $b2bId): array
    {
        $row = DB::table('b2b')->where('b2b_id', $b2bId)->first();

        return [
            'b2b_id' => $row->b2b_id,
            'contact_id' => $row->contact_id,
            'address_id' => $row->address_id,
            'company_name' => $row->company_name,
            'vat_id' => $row->vat_id,
            'logo_url' => $row->logo_url,
            'contact_email' => $row->contact_email,
            'created_at' => Carbon::parse($row->created_at)->toISOString(),
            'updated_at' => Carbon::parse($row->updated_at)->toISOString(),
        ];
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
