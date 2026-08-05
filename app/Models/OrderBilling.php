<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\OrderBilling
class_exists(\App\Modules\UserProfile\Order\Models\OrderBilling::class);

class OrderBilling extends \App\Modules\UserProfile\Order\Models\OrderBilling {}
