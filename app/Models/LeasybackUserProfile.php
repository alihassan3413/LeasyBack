<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Profile\Models\LeasybackUserProfile
class_exists(\App\Modules\UserProfile\Profile\Models\LeasybackUserProfile::class);

class LeasybackUserProfile extends \App\Modules\UserProfile\Profile\Models\LeasybackUserProfile {}