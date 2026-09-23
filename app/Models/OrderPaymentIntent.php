<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Payment\Models\OrderPaymentIntent
class_exists(\App\Modules\UserProfile\Payment\Models\OrderPaymentIntent::class);

class OrderPaymentIntent extends \App\Modules\UserProfile\Payment\Models\OrderPaymentIntent {}
