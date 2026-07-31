<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use App\Models\User;
use Database\Factories\VehicleDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VehicleDocument extends Model
{
    use HasFactory;

    protected static function newFactory(): VehicleDocumentFactory
    {
        return VehicleDocumentFactory::new();
    }

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_id',
        'vehicle_id',
        'document_category',
        'document_type',
        'original_file_name',
        'path',
        'content_type',
        'file_size',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->document_id)) {
                $model->document_id = (string) Str::uuid();
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id', 'id');
    }
}
