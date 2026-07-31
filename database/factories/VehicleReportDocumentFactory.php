<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleReportDocumentFactory extends Factory
{
    protected $model = VehicleReportDocument::class;

    public function definition(): array
    {
        return [
            'auftragsnummer' => 'AUF-'.fake()->unique()->numerify('########'),
            'vehicle_id' => Vehicle::factory(),
            'document_type' => 'invoice',
            'document_title' => fake()->sentence(3),
            'path' => 'vehicle-reports/'.fake()->uuid().'.pdf',
            'published' => false,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['published' => true]);
    }
}
