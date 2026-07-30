<?php

namespace App\Modules\UserProfile\Vehicle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleAssessment extends Model
{
    protected $table = 'vehicle_assessments';
    protected $primaryKey = 'id';

    protected $fillable = [
        'uid',
        'gutachtennummer',
        'auftragsnummer',
        'fin',
        'gutachtendatum',
    ];

    protected $casts = [
        'gutachtendatum' => 'date',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(AssessmentDocument::class, 'assessment_id', 'id');
    }
}
