<?php

namespace App\Modules\UserProfile\B2B\Models;

use App\Models\User;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class B2B extends Model
{
    protected $table = 'b2b';
    protected $primaryKey = 'b2b_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'b2b_id',
        'contact_id',
        'address_id',
        'company_name',
        'vat_id',
        'logo_url',
        'contact_email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->b2b_id)) {
                $model->b2b_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'contact_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'address_id', 'address_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_b2b', 'b2b_id', 'user_id')
            ->withPivot('role');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'b2b_id', 'b2b_id');
    }
}
