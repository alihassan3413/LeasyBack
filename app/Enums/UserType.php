<?php

namespace App\Enums;

enum UserType: string
{
    case Privatkunde = 'Privatkunde';
    case Firmenkunde = 'Firmenkunde';
    case Werkstatt = 'Werksatatt';
    case Admin = 'Admin';

    /**
     * Get all valid user type values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * User types that can be selected during public registration.
     * Admin cannot self-register.
     *
     * @return array<string>
     */
    public static function registrableValues(): array
    {
        return [
            self::Privatkunde->value,
            self::Firmenkunde->value,
            self::Werkstatt->value,
        ];
    }
}
