<?php

namespace App\Modules\UserProfile\Vehicle\Http\Requests\Concerns;

trait ValidatesB2bVehicleFields
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function b2bFieldRules(bool $isB2bContext): array
    {
        if (! $isB2bContext) {
            return [
                'mileage' => ['prohibited'],
                'contract_number' => ['prohibited'],
                'cost_centre' => ['prohibited'],
                'driver_name' => ['prohibited'],
                'driver_contact' => ['prohibited'],
                'collection_address' => ['prohibited'],
            ];
        }

        return [
            'mileage' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'contract_number' => ['nullable', 'string', 'max:100'],
            'cost_centre' => ['nullable', 'string', 'max:100'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_contact' => ['nullable', 'string', 'max:255'],
            'collection_address' => ['nullable', 'array'],
            'collection_address.street' => ['nullable', 'string', 'max:255'],
            'collection_address.number' => ['nullable', 'string', 'max:50'],
            'collection_address.additional_address' => ['nullable', 'string', 'max:255'],
            'collection_address.zip_code' => ['nullable', 'string', 'max:20'],
            'collection_address.city' => ['nullable', 'string', 'max:100'],
            'collection_address.country' => ['nullable', 'string', 'max:100'],
        ];
    }
}
