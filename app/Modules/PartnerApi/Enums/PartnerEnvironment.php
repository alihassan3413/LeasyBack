<?php

namespace App\Modules\PartnerApi\Enums;

/**
 * The environment an integration client belongs to.
 *
 * Sandbox and production are separate client rows with separate tokens and,
 * normally, separate B2B companies — never the same credential pointed at a
 * different dataset by a flag. A sandbox token can therefore never reach
 * production data, whatever the caller sends.
 */
enum PartnerEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The segment embedded in a plaintext token, so a leaked one is placeable. */
    public function tokenSegment(): string
    {
        return match ($this) {
            self::Sandbox => 'sbx',
            self::Production => 'live',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
