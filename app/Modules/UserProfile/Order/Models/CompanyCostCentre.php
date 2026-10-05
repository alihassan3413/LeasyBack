<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A company's reusable cost centre: a name and an optional number. */
class CompanyCostCentre extends Model
{
    protected $table = 'company_cost_centres';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'b2b_id', 'name', 'number', 'created_by_user_id'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}