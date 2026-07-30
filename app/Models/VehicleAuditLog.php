<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Vehicle\Models\VehicleAuditLog
class_exists(\App\Modules\UserProfile\Vehicle\Models\VehicleAuditLog::class);

class VehicleAuditLog extends \App\Modules\UserProfile\Vehicle\Models\VehicleAuditLog {}