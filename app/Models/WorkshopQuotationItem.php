<?php

namespace App\Models;

// Re-export from module. Canonical source: App\Modules\UserProfile\Order\Models\WorkshopQuotationItem
class_exists(\App\Modules\UserProfile\Order\Models\WorkshopQuotationItem::class);

class WorkshopQuotationItem extends \App\Modules\UserProfile\Order\Models\WorkshopQuotationItem {}
