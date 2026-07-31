<?php

namespace Database\Factories;

use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeasybackOfferFactory extends Factory
{
    protected $model = LeasybackOffer::class;

    public function definition(): array
    {
        return [
            'order_id' => LeasybackOrder::factory(),
            'auftragsnummer' => 'AUF-'.fake()->unique()->numerify('########'),
            'offer_sequence' => 1,
            'offer_status' => 'draft',
            'repair_cost_net' => 100,
            'repair_cost_gross' => 119,
            'depreciation_value_net' => 50,
            'depreciation_value_gross' => 59.5,
            'workshop_repair_quote_net' => 0,
            'workshop_repair_quote_gross' => 0,
            'missing_parts_cost_net' => 0,
            'missing_parts_cost_gross' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['offer_status' => 'published', 'published_at' => now()]);
    }

    public function selected(): static
    {
        return $this->state(fn () => ['offer_status' => 'selected', 'published_at' => now(), 'selected_at' => now()]);
    }
}
