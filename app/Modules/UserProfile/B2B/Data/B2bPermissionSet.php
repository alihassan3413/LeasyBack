<?php

namespace App\Modules\UserProfile\B2B\Data;

use App\Enums\B2bPermission;

/**
 * A normalised, self-consistent set of B2bPermission values.
 *
 * Normalisation is deliberately done here rather than in the FormRequest, so
 * that every path that can produce a permission list — the invite form, the
 * member editor, a seeder, a future API — ends up with the same closure
 * applied. Two rules:
 *
 *  1. Unknown values are dropped rather than rejected. A stale client sending
 *     a permission that no longer exists should not fail the whole save.
 *  2. Dependencies are pulled in transitively (B2bPermission::requires()), so
 *     "may create vehicles" can never be stored without "may view vehicles"
 *     and produce a member who can create records they then cannot see.
 */
final class B2bPermissionSet
{
    /** @param array<string> $values */
    private function __construct(private readonly array $values) {}

    /**
     * @param  iterable<mixed>|null  $raw
     */
    public static function fromRaw(?iterable $raw): self
    {
        $known = [];

        foreach ($raw ?? [] as $value) {
            if (! is_string($value)) {
                continue;
            }

            $permission = B2bPermission::tryFrom($value);

            if ($permission !== null) {
                $known[$permission->value] = true;
            }
        }

        return new self(self::withDependencies(array_keys($known)));
    }

    /** Every permission there is — the effective set for an owner. */
    public static function all(): self
    {
        return new self(B2bPermission::values());
    }

    public static function memberDefaults(): self
    {
        return self::fromRaw(B2bPermission::memberDefaults());
    }

    public function has(B2bPermission $permission): bool
    {
        return in_array($permission->value, $this->values, true);
    }

    /**
     * @return array<string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Repeatedly folds in each permission's prerequisites until the set stops
     * growing, so a chain of dependencies resolves in one call.
     *
     * @param  array<string>  $values
     * @return array<string>
     */
    private static function withDependencies(array $values): array
    {
        $resolved = array_fill_keys($values, true);

        do {
            $before = count($resolved);

            foreach (array_keys($resolved) as $value) {
                foreach (B2bPermission::from($value)->requires() as $dependency) {
                    $resolved[$dependency] = true;
                }
            }
        } while (count($resolved) > $before);

        // Stable, enum-declaration order — keeps stored JSON diffable.
        return array_values(array_filter(
            B2bPermission::values(),
            fn (string $value) => isset($resolved[$value])
        ));
    }
}
