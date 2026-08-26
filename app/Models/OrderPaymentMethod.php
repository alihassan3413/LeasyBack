<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Payment\Models\OrderPaymentMethod
class_exists(\App\Modules\UserProfile\Payment\Models\OrderPaymentMethod::class);

class OrderPaymentMethod extends \App\Modules\UserProfile\Payment\Models\OrderPaymentMethod {}
