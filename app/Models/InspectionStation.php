<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\InspectionStation
class_exists(\App\Modules\UserProfile\Order\Models\InspectionStation::class);

class InspectionStation extends \App\Modules\UserProfile\Order\Models\InspectionStation {}