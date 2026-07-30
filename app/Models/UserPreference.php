<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Profile\Models\UserPreference
class_exists(\App\Modules\UserProfile\Profile\Models\UserPreference::class);

class UserPreference extends \App\Modules\UserProfile\Profile\Models\UserPreference {}