<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Data\LexwarePersonContactRequest;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use App\Modules\UserProfile\Payment\Models\LexwareContact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LexwareContactResolver
{
    private const COUNTRY_CODES = [
        'de' => 'DE',
        'deu' => 'DE',
        'deutschland' => 'DE',
        'germany' => 'DE',
        'at' => 'AT',
        'österreich' => 'AT',
        'oesterreich' => 'AT',
        'austria' => 'AT',
        'ch' => 'CH',
        'schweiz' => 'CH',
        'switzerland' => 'CH',
    ];

    public function __construct(private readonly LexwareGateway $lexware) {}

    public function forOrder(LeasybackOrder $order): string
    {
        $contact = $this->customerContact($order);
        $existing = LexwareContact::where('contact_id', $contact->contact_id)->first();

        if ($existing !== null) {
            return $existing->lexware_contact_id;
        }

        $lexwareContactId = $this->lexware->createPersonContact($this->request($contact));

        try {
            LexwareContact::create([
                'contact_id' => $contact->contact_id,
                'lexware_contact_id' => $lexwareContactId,
            ]);
        } catch (QueryException) {
            $winner = LexwareContact::where('contact_id', $contact->contact_id)->first();

            if ($winner !== null) {
                return $winner->lexware_contact_id;
            }

            throw LexwareGatewayException::apiError('The Lexware contact could not be persisted.');
        }

        return $lexwareContactId;
    }

    private function request(object $contact): LexwarePersonContactRequest
    {
        $lastName = trim((string) $contact->last_name);

        if ($lastName === '') {
            throw LexwareGatewayException::apiError('The customer has no last name — a Lexware person contact requires one.');
        }

        $street = trim(implode(' ', array_filter([
            trim((string) ($contact->street ?? '')),
            trim((string) ($contact->number ?? '')),
        ])));

        if ($street === '' || trim((string) ($contact->zip_code ?? '')) === '' || trim((string) ($contact->city ?? '')) === '') {
            throw LexwareGatewayException::apiError('The customer has no complete billing address.');
        }

        return new LexwarePersonContactRequest(
            lastName: $lastName,
            street: $street,
            zip: trim((string) $contact->zip_code),
            city: trim((string) $contact->city),
            countryCode: $this->countryCode((string) ($contact->country ?? '')),
            firstName: trim((string) $contact->first_name) ?: null,
            salutation: trim((string) ($contact->salutation ?? '')) ?: null,
        );
    }

    private function countryCode(string $country): string
    {
        $code = self::COUNTRY_CODES[mb_strtolower(trim($country))] ?? null;

        if ($code === null) {
            throw LexwareGatewayException::apiError(
                sprintf('No Lexware country code is defined for "%s".', $country),
            );
        }

        return $code;
    }

    private function customerContact(LeasybackOrder $order): object
    {
        $contact = DB::table('vehicles as v')
            ->join('user_profiles as p', 'p.user_id', '=', 'v.b2c_user_id')
            ->join('contacts as c', 'c.contact_id', '=', 'p.contact_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'c.address_id')
            ->where('v.vehicle_id', $order->vehicle_id)
            ->select([
                'c.contact_id', 'c.salutation', 'c.first_name', 'c.last_name',
                'a.street', 'a.number', 'a.zip_code', 'a.city', 'a.country',
            ])
            ->first();

        if ($contact === null) {
            throw LexwareGatewayException::apiError('The order has no private customer profile to invoice.');
        }

        return $contact;
    }
}
