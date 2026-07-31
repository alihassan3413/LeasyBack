<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\UserProfile\Profile\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserPreferenceFactory extends Factory
{
    protected $model = UserPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'timezone' => 'Europe/Berlin',
            'sprache' => 'de',
            'benachrichtigungseinstellungen_push' => true,
            'benachrichtigungseinstellungen_email' => true,
        ];
    }
}
