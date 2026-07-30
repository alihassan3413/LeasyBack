<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\OrderLogistics
class_exists(\App\Modules\UserProfile\Order\Models\OrderLogistics::class);

class OrderLogistics extends \App\Modules\UserProfile\Order\Models\OrderLogistics {}