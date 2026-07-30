<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleReportDocument extends Model
{
    protected $table = 'vehicle_report_documents';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'auftragsnummer',
        'vehicle_id',
        'document_type',
        'document_title',
        's3_bucket',
        's3_key',
        's3_url',
        'published',
        'source_assessment_document_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'published' => 'boolean',
        'source_assessment_document_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }
}
