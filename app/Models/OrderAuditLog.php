<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\OrderAuditLog
class_exists(\App\Modules\UserProfile\Order\Models\OrderAuditLog::class);

class OrderAuditLog extends \App\Modules\UserProfile\Order\Models\OrderAuditLog {}
