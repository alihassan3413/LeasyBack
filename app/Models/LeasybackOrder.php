<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\LeasybackOrder
class_exists(\App\Modules\UserProfile\Order\Models\LeasybackOrder::class);

class LeasybackOrder extends \App\Modules\UserProfile\Order\Models\LeasybackOrder {}