<?php

namespace Database\Factories;

use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppraisalExtractionFactory extends Factory
{
    protected $model = AppraisalExtraction::class;

    public function definition(): array
    {
        return [
            'order_id' => LeasybackOrder::factory(),
            'auftragsnummer' => fn (array $attributes) => LeasybackOrder::whereKey($attributes['order_id'])->value('auftragsnummer'),
            'source_document_id' => null,
            'status' => AppraisalExtractionStatus::Pending,
        ];
    }

    public function status(AppraisalExtractionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
