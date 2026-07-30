<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\OrderStatusUpdate
class_exists(\App\Modules\UserProfile\Order\Models\OrderStatusUpdate::class);

class OrderStatusUpdate extends \App\Modules\UserProfile\Order\Models\OrderStatusUpdate {}