<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\OrderConfirmation
class_exists(\App\Modules\UserProfile\Order\Models\OrderConfirmation::class);

class OrderConfirmation extends \App\Modules\UserProfile\Order\Models\OrderConfirmation {}