<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\LogisticsAddressProfile
class_exists(\App\Modules\UserProfile\Order\Models\LogisticsAddressProfile::class);

class LogisticsAddressProfile extends \App\Modules\UserProfile\Order\Models\LogisticsAddressProfile {}