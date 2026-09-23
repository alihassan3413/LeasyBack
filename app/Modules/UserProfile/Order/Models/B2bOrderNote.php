<?php

namespace App\Modules\UserProfile\Order\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One Admin-authored note on an order, with an explicit audience (§16).
 *
 * Notes are not messages. `OrderMessage` is the two-way customer↔Admin thread;
 * this is a one-way annotation. Nothing here has read state, an unread count
 * or a reply path, deliberately — adding them would make this a second
 * messaging system, which §21 forbids.
 */
class B2bOrderNote extends Model
{
    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITY_CUSTOMER = 'customer';

    protected $table = 'b2b_order_notes';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_id',
        'auftragsnummer',
        'visibility',
        'body',
        'author_user_id',
        'author_name',
    ];

    /**
     * @return array<int, string>
     */
    public static function visibilities(): array
    {
        return [self::VISIBILITY_INTERNAL, self::VISIBILITY_CUSTOMER];
    }

    /**
     * The customer-facing subset. Every read that can reach a customer must go
     * through this scope rather than filtering at the call site, so there is
     * one place to audit instead of one per reader.
     */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_CUSTOMER);
    }

    public function isInternal(): bool
    {
        return $this->visibility === self::VISIBILITY_INTERNAL;
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id', 'id');
    }
}
