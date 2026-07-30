<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentDocument extends Model
{
    protected $table = 'assessment_documents';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'assessment_id',
        'doc_type',
        'external_id',
        'title',
        'mime',
        'file_format',
        'sort_order',
        'source_url',
        'source_sha1',
        'showroom_url',
        'caption',
        'image_kind',
        's3_bucket',
        's3_key',
        's3_url',
    ];

    protected $casts = [
        'external_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(VehicleAssessment::class, 'assessment_id', 'id');
    }
}
