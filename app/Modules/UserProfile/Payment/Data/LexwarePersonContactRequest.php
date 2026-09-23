<?php

namespace App\Modules\UserProfile\Payment\Data;

final readonly class LexwarePersonContactRequest
{
    public function __construct(
        public string $lastName,
        public string $street,
        public string $zip,
        public string $city,
        public string $countryCode = 'DE',
        public ?string $firstName = null,
        public ?string $salutation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'version' => 0,
            'roles' => ['customer' => new \stdClass],
            'person' => array_filter([
                'salutation' => $this->salutation,
                'firstName' => $this->firstName,
                'lastName' => $this->lastName,
            ], fn (?string $value) => $value !== null && $value !== ''),
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
