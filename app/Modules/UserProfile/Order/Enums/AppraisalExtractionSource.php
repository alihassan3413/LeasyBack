<?php

namespace App\Modules\UserProfile\Order\Enums;

enum AppraisalExtractionSource: string
{
    case Parser = 'parser';
    case Ai = 'ai';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
