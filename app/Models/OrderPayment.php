<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Payment\Models\OrderPayment
class_exists(\App\Modules\UserProfile\Payment\Models\OrderPayment::class);

class OrderPayment extends \App\Modules\UserProfile\Payment\Models\OrderPayment {}
