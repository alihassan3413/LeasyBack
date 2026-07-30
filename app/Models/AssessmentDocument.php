<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Vehicle\Models\AssessmentDocument
class_exists(\App\Modules\UserProfile\Vehicle\Models\AssessmentDocument::class);

class AssessmentDocument extends \App\Modules\UserProfile\Vehicle\Models\AssessmentDocument {}