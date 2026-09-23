<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A workshop's net price for one appraisal position. The position itself is
 * referenced, never copied — the appraisal stays the single source of truth
 * for what is being repaired.
 */
class WorkshopQuotationItem extends Model
{
    protected $table = 'b2b_workshop_quotation_items';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'quotation_id',
        'appraisal_position_id',
        'amount_net',
        'repair_method',
        'not_repairable',
    ];

    protected function casts(): array
    {
        return [
            'amount_net' => 'decimal:2',
            'not_repairable' => 'boolean',
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

    public function position(): BelongsTo
    {
        return $this->belongsTo(AppraisalPosition::class, 'appraisal_position_id', 'id');
    }
}
