<?php

namespace App\Modules\UserProfile\Offer\Models;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeasybackOffer extends Model
{
    protected $table = 'leasyback_offers';
    protected $primaryKey = 'offer_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'offer_id',
        'order_id',
        'auftragsnummer',
        'offer_sequence',
        'offer_status',
        'repair_cost_net',
        'repair_cost_gross',
        'depreciation_value_net',
        'depreciation_value_gross',
        'workshop_repair_quote_net',
        'workshop_repair_quote_gross',
        'missing_parts_cost_net',
        'missing_parts_cost_gross',
        'final_total_net',
        'final_total_gross',
        'additional_notes',
        'cancellation_reason',
        'published_at',
        'published_by_user_id',
        'selected_at',
        'selected_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'closed_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'offer_sequence' => 'integer',
        'repair_cost_net' => 'decimal:2',
        'repair_cost_gross' => 'decimal:2',
        'depreciation_value_net' => 'decimal:2',
        'depreciation_value_gross' => 'decimal:2',
        'workshop_repair_quote_net' => 'decimal:2',
        'workshop_repair_quote_gross' => 'decimal:2',
        'missing_parts_cost_net' => 'decimal:2',
        'missing_parts_cost_gross' => 'decimal:2',
        'final_total_net' => 'decimal:2',
        'final_total_gross' => 'decimal:2',
        'published_at' => 'datetime',
        'selected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->offer_id)) {
                $model->offer_id = (string) \Illuminate\Support\Str::uuid();
            }
        });

        // Auto-compute final totals on save
        static::saving(function (self $model) {
            $model->final_total_net = bcadd(
                bcadd(bcadd($model->repair_cost_net, $model->depreciation_value_net, 2), $model->workshop_repair_quote_net, 2),
                $model->missing_parts_cost_net,
                2
            );
            $model->final_total_gross = bcadd(
                bcadd(bcadd($model->repair_cost_gross, $model->depreciation_value_gross, 2), $model->workshop_repair_quote_gross, 2),
                $model->missing_parts_cost_gross,
                2
            );
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'order_id', 'id');
    }
}
