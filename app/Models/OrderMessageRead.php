<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\OrderMessageRead
class_exists(\App\Modules\UserProfile\Order\Models\OrderMessageRead::class);

class OrderMessageRead extends \App\Modules\UserProfile\Order\Models\OrderMessageRead {}
