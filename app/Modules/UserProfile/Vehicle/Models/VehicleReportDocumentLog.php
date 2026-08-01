<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Audit trail for VehicleReportService's actions (upload/publish/unpublish/
 * delete/transfer) — this table existed with zero consumers before this
 * checkpoint; VehicleReportService is its evident intended consumer, per
 * docs/B2C_ADMIN_MIGRATION_AUDIT.md §5's "report-document audit trail"
 * description.
 */
class VehicleReportDocumentLog extends Model
{
    protected $table = 'vehicle_report_document_logs';

    protected $primaryKey = 'log_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'log_id',
        'document_id',
        'auftragsnummer',
        'vehicle_id',
        'action',
        'old_values',
        'new_values',
        's3_bucket',
        's3_key',
        's3_url',
        'changed_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->log_id)) {
                $model->log_id = (string) Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(VehicleReportDocument::class, 'document_id', 'id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id', 'id');
    }
}
