<?php

namespace App\Modules\UserProfile\Order\Models;

use App\Modules\UserProfile\Order\Enums\AppraisalExtractionSource;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Database\Factories\AppraisalExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AppraisalExtraction extends Model
{
    use HasFactory;

    protected static function newFactory(): AppraisalExtractionFactory
    {
        return AppraisalExtractionFactory::new();
    }

    protected $table = 'appraisal_extractions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'attempts' => 0,
    ];

    protected $fillable = [
        'order_id',
        'auftragsnummer',
        'source_document_id',
        'status',
        'source',
        'extractor_version',
        'input_sha256',
        'proposal',
        'warnings',
        'error_code',
        'error_message',
        'attempts',
        'started_at',
        'completed_at',
        'failed_at',
        'applied_at',
        'discarded_at',
        'requested_by_user_id',
        'applied_by_user_id',
        'discarded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => AppraisalExtractionStatus::class,
            'source' => AppraisalExtractionSource::class,
            'proposal' => 'array',
            'warnings' => 'array',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'applied_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }

            $model->status ??= AppraisalExtractionStatus::Pending;
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'order_id', 'id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(VehicleReportDocument::class, 'source_document_id', 'id');
    }

    public function transitionTo(AppraisalExtractionStatus $next, array $attributes = []): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw AppraisalExtractionException::invalidTransition($this->status, $next);
        }

        $this->forceFill([...$attributes, 'status' => $next])->save();
    }
}
