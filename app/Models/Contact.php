<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Profile\Models\Contact
class_exists(\App\Modules\UserProfile\Profile\Models\Contact::class);

class Contact extends \App\Modules\UserProfile\Profile\Models\Contact {}