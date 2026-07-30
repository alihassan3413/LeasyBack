<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Profile\Models\Address
class_exists(\App\Modules\UserProfile\Profile\Models\Address::class);

class Address extends \App\Modules\UserProfile\Profile\Models\Address {}