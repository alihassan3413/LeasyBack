<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Enforces email uniqueness case-insensitively on every database driver
 * (Laravel's built-in `unique` rule compares equality on the raw value,
 * which is case-sensitive under Postgres' default collation). Used
 * anywhere a user's email is created or changed.
 */
class CaseInsensitiveUniqueEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = User::whereRaw('LOWER(email) = ?', [Str::lower($value)]);

        if ($this->ignoreUserId !== null) {
            $query->where('id', '!=', $this->ignoreUserId);
        }

        if ($query->exists()) {
            $fail('This email is already registered.');
        }
    }
}
