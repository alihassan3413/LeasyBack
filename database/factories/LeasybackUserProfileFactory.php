<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeasybackUserProfileFactory extends Factory
{
    protected $model = LeasybackUserProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'contact_id' => Contact::factory(),
            'is_admin' => false,
            'image_url' => null,
        ];
    }
}
