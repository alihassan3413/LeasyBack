<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Vehicle\Models\VehicleDocument
class_exists(\App\Modules\UserProfile\Vehicle\Models\VehicleDocument::class);

class VehicleDocument extends \App\Modules\UserProfile\Vehicle\Models\VehicleDocument {}