<?php

namespace App\Modules\UserProfile\B2B\Http\Controllers;

use App\Models\Address;
use App\Models\B2B;
use App\Models\Contact;
use App\Models\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class B2BController extends Controller
{
    /**
     * POST /b2b/create
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value !== 'Firmenkunde') {
            return response()->json(['error' => 'Access denied: insufficient privileges not b2b user'], 403);
        }

        // Check if user already has a B2B
        $existing = DB::table('user_b2b')->where('user_id', $user->id)->value('b2b_id');
        if ($existing) {
            return response()->json([
                'error' => 'User already belongs to a company',
                'b2b_id' => $existing,
            ], 409);
        }

        $validated = $request->validate([
            'company_name' => 'required|string|min:1',
            'vat_id' => 'nullable|string',
            'logo_url' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'address' => 'nullable|array',
            'address.street' => 'required_with:address|string',
            'address.number' => 'required_with:address|string',
            'address.additional_address' => 'nullable|string',
            'address.zip_code' => 'required_with:address|string',
            'address.city' => 'required_with:address|string',
            'address.country' => 'required_with:address|string',
            'contact' => 'nullable|array',
            'contact.first_name' => 'required_with:contact|string',
            'contact.last_name' => 'required_with:contact|string',
        ]);

        $b2b = DB::transaction(function () use ($validated, $user) {
            $addressId = null;
            $contactId = null;

            if (!empty($validated['address'])) {
                $address = Address::create($validated['address']);
                $addressId = $address->address_id;
            }

            if (!empty($validated['contact'])) {
                $contactData = $validated['contact'];
                $contact = Contact::create([
                    'salutation' => $contactData['salutation'] ?? null,
                    'first_name' => $contactData['first_name'],
                    'last_name' => $contactData['last_name'],
                    'address_id' => $contactData['address_id'] ?? $addressId,
                ]);
                $contactId = $contact->contact_id;

                // Primary phone
                if (!empty($contactData['international_prefix']) && !empty($contactData['primary_phone_number'])) {
                    PhoneNumber::create([
                        'contact_id' => $contactId,
                        'international_prefix' => $contactData['international_prefix'],
                        'phone_number' => $contactData['primary_phone_number'],
                        'is_primary_contact' => true,
                    ]);
                }

                // Additional phones
                if (!empty($contactData['phone_numbers'])) {
                    foreach ($contactData['phone_numbers'] as $phone) {
                        PhoneNumber::create([
                            'contact_id' => $contactId,
                            'international_prefix' => $phone['international_prefix'],
                            'phone_number' => $phone['phone_number'],
                            'is_primary_contact' => false,
                        ]);
                    }
                }
            }

            $b2b = B2B::create([
                'contact_id' => $contactId,
                'address_id' => $addressId,
                'company_name' => $validated['company_name'],
                'vat_id' => $validated['vat_id'] ?? null,
                'logo_url' => $validated['logo_url'] ?? null,
                'contact_email' => $validated['contact_email'] ?? null,
            ]);

            // Link user to B2B
            DB::table('user_b2b')->insert([
                'user_id' => $user->id,
                'b2b_id' => $b2b->b2b_id,
                'role' => 'owner',
            ]);

            return $b2b;
        });

        return response()->json($b2b);
    }

    /**
     * GET /b2b/user_id/{id}
     */
    public function showByUser(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value !== 'Firmenkunde') {
            return response()->json(['error' => 'Access denied: not b2b user'], 403);
        }

        $row = DB::table('b2b as b')
            ->join('user_b2b as ub', 'ub.b2b_id', '=', 'b.b2b_id')
            ->leftJoin('contacts as c', 'c.contact_id', '=', 'b.contact_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'b.address_id')
            ->where('ub.user_id', $id)
            ->select([
                'b.b2b_id', 'b.company_name', 'b.logo_url', 'b.contact_email',
                'b.vat_id', 'b.created_at', 'b.updated_at',
                'c.contact_id', 'c.salutation', 'c.first_name', 'c.last_name',
                'a.address_id', 'a.street', 'a.number', 'a.additional_address',
                'a.zip_code', 'a.city', 'a.country',
            ])
            ->orderByDesc('b.created_at')
            ->first();

        if (!$row) {
            return response()->json('No company found for this user', 404);
        }

        // Get phones
        $phones = [];
        if ($row->contact_id) {
            $phones = DB::table('phone_numbers')
                ->where('contact_id', $row->contact_id)
                ->select('international_prefix', 'phone_number', 'is_primary_contact')
                ->get()->toArray();
        }

        $contact = $row->contact_id ? [
            'contact_id' => $row->contact_id,
            'salutation' => $row->salutation,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'international_prefix' => null,
            'primary_phone_number' => null,
            'phone_numbers' => $phones,
        ] : null;

        $address = $row->address_id ? [
            'address_id' => $row->address_id,
            'street' => $row->street,
            'number' => $row->number,
            'additional_address' => $row->additional_address,
            'zip_code' => $row->zip_code,
            'city' => $row->city,
            'country' => $row->country,
        ] : null;

        return response()->json([
            'b2b' => $row->b2b_id,
            'company_name' => $row->company_name,
            'logo_url' => $row->logo_url,
            'contact_email' => $row->contact_email,
            'vat_id' => $row->vat_id,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'contact' => $contact,
            'address' => $address,
        ]);
    }

    /**
     * PATCH /b2b/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value !== 'Firmenkunde') {
            return response()->json(['error' => 'Access denied: insufficient privileges'], 403);
        }

        $authorized = DB::table('user_b2b')
            ->where('user_id', $user->id)
            ->where('b2b_id', $id)
            ->exists();

        if (!$authorized) {
            return response()->json(['error' => 'Access denied: you are not the owner of this B2B company'], 403);
        }

        $validated = $request->validate([
            'company_name' => 'nullable|string|min:1',
            'vat_id' => 'nullable|string',
            'logo_url' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'address' => 'nullable|array',
            'contact' => 'nullable|array',
        ]);

        DB::transaction(function () use ($id, $validated) {
            $b2b = B2B::find($id);
            if (!$b2b) return;

            // Update address
            if (!empty($validated['address'])) {
                if ($b2b->address_id) {
                    Address::where('address_id', $b2b->address_id)->update($validated['address']);
                } else {
                    $address = Address::create($validated['address']);
                    $b2b->address_id = $address->address_id;
                }
            }

            // Update contact
            if (!empty($validated['contact'])) {
                $contactData = $validated['contact'];
                if ($b2b->contact_id) {
                    Contact::where('contact_id', $b2b->contact_id)->update([
                        'salutation' => $contactData['salutation'] ?? null,
                        'first_name' => $contactData['first_name'] ?? null,
                        'last_name' => $contactData['last_name'] ?? null,
                    ]);
                } else {
                    $contact = Contact::create([
                        'salutation' => $contactData['salutation'] ?? null,
                        'first_name' => $contactData['first_name'],
                        'last_name' => $contactData['last_name'],
                        'address_id' => $b2b->address_id,
                    ]);
                    $b2b->contact_id = $contact->contact_id;
                }
            }

            // Update scalar fields
            if (!empty($validated['company_name'])) $b2b->company_name = $validated['company_name'];
            if (array_key_exists('vat_id', $validated)) $b2b->vat_id = $validated['vat_id'];
            if (array_key_exists('logo_url', $validated)) $b2b->logo_url = $validated['logo_url'];
            if (array_key_exists('contact_email', $validated)) $b2b->contact_email = $validated['contact_email'];

            $b2b->save();
        });

        return response()->json(['message' => 'B2B updated successfully', 'b2b_id' => $id]);
    }
}
