<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\WorkshopQuotation
class_exists(\App\Modules\UserProfile\Order\Models\WorkshopQuotation::class);

class WorkshopQuotation extends \App\Modules\UserProfile\Order\Models\WorkshopQuotation {}
