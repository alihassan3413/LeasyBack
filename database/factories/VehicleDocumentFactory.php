<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleDocumentFactory extends Factory
{
    protected $model = VehicleDocument::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'document_category' => 'Fahrzeug',
            'document_type' => fake()->randomElement(['Fahrzeugschein', 'Fahrzeugbrief', 'TUV']),
            'original_file_name' => fake()->word().'.pdf',
            'path' => 'vehicle-documents/'.fake()->uuid().'.pdf',
            'content_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(1000, 500000),
            'uploaded_by_user_id' => User::factory(),
        ];
    }
}
