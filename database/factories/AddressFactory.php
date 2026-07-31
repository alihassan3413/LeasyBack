<?php

namespace Database\Factories;

use App\Modules\UserProfile\Profile\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'additional_address' => null,
            'zip_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => 'Germany',
            'longitude' => 0,
            'latitude' => 0,
        ];
    }
}
