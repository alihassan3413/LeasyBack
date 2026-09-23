<?php

namespace App\Modules\UserProfile\Payment\Enums;

enum LexwareInvoiceStatus: string
{
    case Pending = 'pending';
    case Invoiced = 'invoiced';
    case Documented = 'documented';
    case NeedsReconciliation = 'needs_reconciliation';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isSettled(): bool
    {
        return $this === self::Documented;
    }

    public function blocksAutomation(): bool
    {
        return $this === self::NeedsReconciliation;
    }
}
