<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A company's reusable billing address. One per company may be the default,
 * which the order forms preselect.
 */
class CompanyBillingAddress extends Model
{
    protected $table = 'company_billing_addresses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'b2b_id', 'name', 'details', 'is_default', 'created_by_user_id'];

    protected $casts = [
        'details' => 'array',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}