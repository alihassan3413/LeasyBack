<?php

namespace App\Modules\UserProfile\Payment\Data;

final readonly class LexwareContactRequest
{
    public function __construct(
        public string $companyName,
        public string $street,
        public string $zip,
        public string $city,
        public string $countryCode = 'DE',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'version' => 0,
            'roles' => ['customer' => new \stdClass],
            'company' => [
                'name' => $this->companyName,
                'allowTaxFreeInvoices' => false,
                'contactPersons' => [],
            ],
            'addresses' => [
                'billing' => [[
                    'street' => $this->street,
                    'zip' => $this->zip,
                    'city' => $this->city,
                    'countryCode' => $this->countryCode,
                ]],
            ],
        ];
    }
}
