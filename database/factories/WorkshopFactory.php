<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkshopFactory extends Factory
{
    protected $model = Workshop::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['user_type' => UserType::Werkstatt]),
            'workshop_name' => fake()->company(),
            'contact_email' => fake()->unique()->safeEmail(),
            'has_vat_id' => false,
            'vat_id' => null,
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'account_holder' => fake()->name(),
            'packages_selected' => 'Pro',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'address_id' => (string) Str::uuid(),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'additional_address' => null,
            'zip_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => 'Germany',
            'contact_id' => (string) Str::uuid(),
            'salutation' => 'Herr',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'international_prefix' => '+49',
            'primary_phone_number' => fake()->numerify('##########'),
            'phone_numbers' => [],
        ];
    }
}
