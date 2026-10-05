<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * One phone rule for the whole application.
 *
 * There were four, and they disagreed: digits only and 4–14 long in B2B
 * registration, `max:20` on the API profile, `max:30` on the workshop API and
 * `max:64` on the workshop quotation. The same number was accepted in one
 * screen and refused in another.
 *
 * It checks against the real numbering plans rather than a pattern, because a
 * German area code is two to five digits and a regex that allows every valid
 * number also allows thousands of impossible ones. This is the same library
 * the frontend masks with (libphonenumber), so the server agrees with what the
 * field already told the user.
 *
 * The number may arrive formatted ("030 12345678"), as digits beside a
 * dialling-prefix field, or in full international form — all three parse.
 */
class PhoneNumber implements DataAwareRule, ValidationRule
{
    /** The dialling prefixes the forms offer, and the regions they mean. */
    public const PREFIXES = ['+49', '+43', '+41'];

    public const REGION_BY_PREFIX = ['+49' => 'DE', '+43' => 'AT', '+41' => 'CH'];

    public const DEFAULT_REGION = 'DE';

    /**
     * E.164 allows 15 digits in total, country code included. libphonenumber
     * accepts longer German strings as "valid" because some ranges have no
     * published ceiling, so the standard's own limit is applied here.
     */
    private const MAX_E164_DIGITS = 15;

    /** Enough room for the longest number plus its grouping spaces. */
    public const MAX_INPUT_LENGTH = 32;

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  string|null  $prefixSibling  Name of a neighbouring field holding
     *                                      the dialling prefix (e.g. `international_prefix`),
     *                                      for the forms that keep the two apart.
     */
    public function __construct(private readonly ?string $prefixSibling = null) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || mb_strlen($value) > self::MAX_INPUT_LENGTH) {
            $fail('Bitte geben Sie eine gültige Telefonnummer an.');

            return;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($value, $this->regionFor($attribute));
        } catch (Throwable) {
            $fail('Bitte geben Sie eine gültige Telefonnummer an.');

            return;
        }

        $digits = preg_replace('/\D/', '', $util->format($parsed, PhoneNumberFormat::E164)) ?? '';

        if (! $util->isValidNumber($parsed) || mb_strlen($digits) > self::MAX_E164_DIGITS) {
            $fail('Bitte geben Sie eine gültige Telefonnummer an.');
        }
    }

    /**
     * The region to parse against: the sibling prefix field where the form has
     * one, otherwise Germany — which is also what a number already written in
     * +49… form resolves to on its own.
     */
    private function regionFor(string $attribute): string
    {
        if ($this->prefixSibling === null) {
            return self::DEFAULT_REGION;
        }

        $siblingPath = preg_replace('/[^.]+$/', $this->prefixSibling, $attribute) ?? '';
        $prefix = trim((string) Arr::get($this->data, $siblingPath, ''));

        return self::REGION_BY_PREFIX[$prefix] ?? self::DEFAULT_REGION;
    }
}
