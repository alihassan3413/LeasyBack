<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeasybackOrderFactory extends Factory
{
    protected $model = LeasybackOrder::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'auftragsnummer' => 'AUF-'.fake()->unique()->numerify('########'),
            'leasyback_partner' => 'tuvsud',
            'order_status' => OrderStatus::OrderPlaced->value,
            'request_payload' => ['source' => 'factory'],
            'created_by_user_id' => User::factory(),
        ];
    }

    public function withStatus(OrderStatus $status): static
    {
        return $this->state(fn () => ['order_status' => $status->value]);
    }
}
