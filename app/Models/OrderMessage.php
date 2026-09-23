<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\OrderMessage
class_exists(\App\Modules\UserProfile\Order\Models\OrderMessage::class);

class OrderMessage extends \App\Modules\UserProfile\Order\Models\OrderMessage {}
