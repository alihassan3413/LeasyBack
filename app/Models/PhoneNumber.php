<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Profile\Models\PhoneNumber
class_exists(\App\Modules\UserProfile\Profile\Models\PhoneNumber::class);

class PhoneNumber extends \App\Modules\UserProfile\Profile\Models\PhoneNumber {}