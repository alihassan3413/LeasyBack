<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One repair position of an order's **initial** appraisal (Gutachten), in
 * either channel. Nachgutachten positions are deliberately not stored here —
 * §17 excludes the final appraisal from the saving calculation, so mixing the
 * two in one table would make that exclusion a query detail rather than a
 * structural fact.
 *
 * The `b2b_` table prefix is a leftover from where this was first built and no
 * longer describes what it holds; renaming it is its own change, not a rider on
 * a behaviour one. Nothing in the row is company-scoped: `order_id` is the only
 * ownership key, and it is the same key for a private customer's car.
 *
 * All amounts are stored net. §9's rule that a *B2B customer* is never shown a
 * gross price is about presentation and belongs to the offer layer; it is not a
 * statement about what this table may contain.
 */
class AppraisalPosition extends Model
{
    protected $table = 'b2b_appraisal_positions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_EXTRACTED = 'extracted';

    protected $fillable = [
        'order_id',
        'auftragsnummer',
        'sort_order',
        'component',
        'damage_description',
        'original_amount_net',
        'chargeable_amount_net',
        'repair_method',
        'source',
        'damage_image_document_ids',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'original_amount_net' => 'decimal:2',
            'chargeable_amount_net' => 'decimal:2',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'order_id', 'id');
    }

    /**
     * The amount actually charged to the customer: the chargeable amount when
     * one was entered, otherwise the original appraisal amount.
     */
    public function effectiveAmountNet(): string
    {
        return (string) ($this->chargeable_amount_net ?? $this->original_amount_net);
    }
}
