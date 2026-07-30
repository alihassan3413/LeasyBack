<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Tim\Models\TimToken
class_exists(\App\Modules\UserProfile\Tim\Models\TimToken::class);

class TimToken extends \App\Modules\UserProfile\Tim\Models\TimToken {}