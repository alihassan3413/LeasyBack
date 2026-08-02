<?php

namespace App\Enums;

/**
 * A user's role inside one B2B company. Roles are coarse — the fine-grained
 * access is B2bPermission — but the distinction matters because an owner is
 * not merely "a member with every permission": ownership is what allows
 * managing other members, and the last owner of a company can never be
 * removed or demoted (see B2bMembershipService).
 */
enum B2bRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Roles an owner may hand out when inviting or editing someone. */
    public static function assignableValues(): array
    {
        return self::values();
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Inhaber',
            self::Member => 'Mitglied',
        };
    }
}
