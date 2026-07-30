<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Tim\Models\TimBewertung
class_exists(\App\Modules\UserProfile\Tim\Models\TimBewertung::class);

class TimBewertung extends \App\Modules\UserProfile\Tim\Models\TimBewertung {}