<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Vehicle\Models\Vehicle
class_exists(\App\Modules\UserProfile\Vehicle\Models\Vehicle::class);

class Vehicle extends \App\Modules\UserProfile\Vehicle\Models\Vehicle {}