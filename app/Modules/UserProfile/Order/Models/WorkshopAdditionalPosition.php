<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Damage the workshop found that the Gutachten does not list, priced by the
 * workshop that found it. It belongs to the quotation, never to the appraisal:
 * an admin decides whether it becomes an appraisal position, and nothing here
 * makes that decision for them.
 */
class WorkshopAdditionalPosition extends Model
{
    protected $table = 'workshop_additional_positions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'quotation_id',
        'sort_order',
        'component',
        'damage_description',
        'repair_method',
        'amount_net',
        'damage_image_document_ids',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'amount_net' => 'decimal:2',
            'damage_image_document_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(WorkshopQuotation::class, 'quotation_id', 'id');
    }
}
