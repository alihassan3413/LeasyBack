<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use Database\Factories\VehicleReportDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VehicleReportDocument extends Model
{
    use HasFactory;

    protected static function newFactory(): VehicleReportDocumentFactory
    {
        return VehicleReportDocumentFactory::new();
    }

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
        'path',
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
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }
}
