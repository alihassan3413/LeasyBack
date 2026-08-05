<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2B;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\LogisticsAddressProfile;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Vehicle extends Model
{
    use HasFactory;

    protected static function newFactory(): VehicleFactory
    {
        return VehicleFactory::new();
    }

    protected $primaryKey = 'vehicle_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'vehicle_id',
        'license_plate',
        'first_registration_date',
        'leasing_end_date',
        'leasinggeber',
        'vin',
        'make',
        'model',
        'b2b_id',
        'b2c_user_id',
        'created_by_user_id',
        'assigned_profile_id',
        'vehicle_belongs',
        'mileage',
        'contract_number',
        'cost_centre',
        'driver_name',
        'driver_contact',
        'collection_address_profile_id',
    ];

    protected $casts = [
        'first_registration_date' => 'date',
        'leasing_end_date' => 'date',
        'mileage' => 'integer',
    ];

    public const B2B_ONLY_ATTRIBUTES = [
        'mileage',
        'contract_number',
        'cost_centre',
        'driver_name',
        'driver_contact',
        'collection_address_profile_id',
    ];

    public function isB2b(): bool
    {
        return $this->vehicle_belongs === 'B2B';
    }

    public function toArray(): array
    {
        $data = parent::toArray();

        if ($this->isB2b()) {
            return $data;
        }

        return Arr::except($data, self::B2B_ONLY_ATTRIBUTES);
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->vehicle_id)) {
                $model->vehicle_id = (string) Str::uuid();
            }
        });
    }

    public function b2b(): BelongsTo
    {
        return $this->belongsTo(B2B::class, 'b2b_id', 'b2b_id');
    }

    public function b2cUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'b2c_user_id', 'id');
    }

    public function collectionAddressProfile(): BelongsTo
    {
        return $this->belongsTo(LogisticsAddressProfile::class, 'collection_address_profile_id', 'id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class, 'vehicle_id', 'vehicle_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LeasybackOrder::class, 'vehicle_id', 'vehicle_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(VehicleAuditLog::class, 'vehicle_id', 'vehicle_id');
    }
}
