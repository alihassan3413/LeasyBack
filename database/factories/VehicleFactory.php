<?php

namespace Database\Factories;

use App\Enums\VehicleOwnerType;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'license_plate' => strtoupper(fake()->unique()->bothify('??-###??')),
            'first_registration_date' => fake()->date(),
            'leasing_end_date' => fake()->dateTimeBetween('now', '+3 years')->format('Y-m-d'),
            'leasinggeber' => fake()->company(),
            'vin' => strtoupper(fake()->regexify('[A-Z0-9]{17}')),
            'make' => fake()->randomElement(['Volkswagen', 'BMW', 'Mercedes-Benz', 'Audi']),
            'model' => fake()->word(),
            'vehicle_belongs' => VehicleOwnerType::B2C->value,
            'b2c_user_id' => User::factory(),
            'b2b_id' => null,
        ];
    }

    /**
     * A vehicle owned by a B2B company rather than a B2C user.
     */
    public function forB2b(string $b2bId): static
    {
        return $this->state(fn () => [
            'vehicle_belongs' => VehicleOwnerType::B2B->value,
            'b2b_id' => $b2bId,
            'b2c_user_id' => null,
        ]);
    }
}
