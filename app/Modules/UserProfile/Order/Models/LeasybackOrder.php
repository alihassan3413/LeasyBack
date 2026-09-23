<?php

namespace App\Modules\UserProfile\Order\Models;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Database\Factories\LeasybackOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class LeasybackOrder extends Model
{
    use HasFactory;

    protected static function newFactory(): LeasybackOrderFactory
    {
        return LeasybackOrderFactory::new();
    }

    protected $table = 'leasyback_orders';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'vehicle_id',
        'auftragsnummer',
        'leasyback_partner',
        'order_status',
        'request_payload',
        'response_status',
        'response_body',
        'created_by_user_id',
        'sent_at',
        'created_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_body' => 'json',
        'response_status' => 'integer',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });

        /*
         * The application half of the "one active order per vehicle" unique
         * index (see the 2026_08_19 migration). The index itself is a plain
         * unique on `active_vehicle_id` and knows nothing about statuses; this
         * is the predicate that decides whether a row claims its vehicle's
         * slot, kept next to OrderStatus so a new status cannot leave the two
         * disagreeing.
         *
         * It lives on the model rather than at the call sites because
         * `order_status` has exactly two writers, both of them Eloquent —
         * creation in OrderService, and TransitionOrderStatus's locked update,
         * which its own docblock establishes as the single place the column
         * may change — so one hook covers every writer, including ones not
         * written yet.
         *
         * The one way to defeat it is a query-builder mass update
         * (`LeasybackOrder::query()->update([...])`), which fires no model
         * events. Nothing in the application does that, and it would already
         * be bypassing the transition guard; anything closing orders in bulk
         * must iterate the models, as PartnerOrderEndpointTest::closeAllOrders()
         * does.
         *
         * `active_vehicle_id` is intentionally absent from $fillable: it is
         * derived here, never supplied by a caller.
         */
        static::saving(function (self $model) {
            $model->active_vehicle_id = in_array($model->order_status, OrderStatus::closedValues(), true)
                ? null
                : $model->vehicle_id;
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    public function confirmation(): HasOne
    {
        return $this->hasOne(OrderConfirmation::class, 'auftragsnummer', 'auftragsnummer');
    }

    public function statusUpdates(): HasMany
    {
        return $this->hasMany(OrderStatusUpdate::class, 'auftragsnummer', 'auftragsnummer');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(LeasybackOffer::class, 'order_id', 'id');
    }
}
