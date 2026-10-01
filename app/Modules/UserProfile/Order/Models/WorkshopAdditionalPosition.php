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
 *
 * That decision is recorded in `review_status`: every row starts `pending`;
 * accepting it creates an appraisal position (WorkshopAdditionalPositionReview
 * Service) and remembers it in `appraisal_position_id`; rejecting it only
 * marks it.
 */
class WorkshopAdditionalPosition extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

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
        'review_status',
        'reviewed_at',
        'reviewed_by_user_id',
        'appraisal_position_id',
    ];

    protected $attributes = [
        'review_status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'amount_net' => 'decimal:2',
            'damage_image_document_ids' => 'array',
            'reviewed_at' => 'datetime',
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

    public function isPendingReview(): bool
    {
        return ($this->review_status ?? self::STATUS_PENDING) === self::STATUS_PENDING;
    }
}