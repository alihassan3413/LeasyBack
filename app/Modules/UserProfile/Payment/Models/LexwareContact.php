<?php

namespace App\Modules\UserProfile\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LexwareContact extends Model
{
    protected $table = 'lexware_contacts';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'contact_id',
        'lexware_contact_id',
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
