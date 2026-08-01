<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Offer\Models\OfferAuditLog
class_exists(\App\Modules\UserProfile\Offer\Models\OfferAuditLog::class);

class OfferAuditLog extends \App\Modules\UserProfile\Offer\Models\OfferAuditLog {}
