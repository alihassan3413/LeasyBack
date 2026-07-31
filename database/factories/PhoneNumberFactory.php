<?php

namespace Database\Factories;

use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Profile\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class PhoneNumberFactory extends Factory
{
    protected $model = PhoneNumber::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'international_prefix' => '+49',
            'phone_number' => fake()->numerify('##########'),
            'is_primary_contact' => true,
        ];
    }
}
