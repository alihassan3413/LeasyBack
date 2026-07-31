<?php

namespace Database\Factories;

use App\Modules\UserProfile\Order\Models\InspectionStation;
use Illuminate\Database\Eloquent\Factories\Factory;

class InspectionStationFactory extends Factory
{
    protected $model = InspectionStation::class;

    public function definition(): array
    {
        return [
            'provider' => 'tuvsud',
            'name' => fake()->company(),
            'strasse' => fake()->streetAddress(),
            'plz' => fake()->postcode(),
            'ort' => fake()->city(),
            'bundesland' => null,
            'land' => 'de',
            'is_active' => true,
        ];
    }
}
