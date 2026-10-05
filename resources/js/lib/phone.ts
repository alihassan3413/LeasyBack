/**
 * Phone numbers, formatted the way the country writes them.
 *
 * A fixed mask cannot do this job: a German area code is two to five digits
 * (030, 0221, 08031), so where the space falls depends on the number itself,
 * and Austria and Switzerland group differently again. libphonenumber carries
 * the real numbering plans, so `AsYouType` puts the separators where that
 * country actually puts them while the user is still typing.
 *
 * Display is national ("030 12345678") because the dialling prefix is its own
 * field beside it. Storage is E.164 ("+493012345678"), which is the one form
 * that compares and dials reliably.
 */
import { AsYouType, parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js';

/** The prefixes the forms offer, as libphonenumber's country codes. */
const COUNTRY_BY_PREFIX: Record<string, CountryCode> = {
    '+49': 'DE',
    '+43': 'AT',
    '+41': 'CH',
};

export const DEFAULT_PREFIX = '+49';

export function countryFor(prefix: string | null | undefined): CountryCode {
    return COUNTRY_BY_PREFIX[(prefix ?? '').trim()] ?? 'DE';
}

/**
 * What the field shows while it is being typed. Anything that is not a digit
 * or a leading + is dropped, then the country's own grouping is applied.
 */
export function formatAsYouType(value: string, prefix?: string | null): string {
    const cleaned = (value ?? '').replace(/[^\d+]/g, '').replace(/(?!^)\+/g, '');

    if (cleaned === '') {
        return '';
    }

    return new AsYouType(countryFor(prefix)).input(cleaned);
}

/**
 * The canonical form to store and compare. Returns null when the number is not
 * a real number for that country, so a caller can tell "not finished yet" from
 * "wrong".
 */
export function toE164(value: string, prefix?: string | null): string | null {
    const parsed = parsePhoneNumberFromString((value ?? '').trim(), countryFor(prefix));

    return parsed?.isValid() ? parsed.number : null;
}

/** Empty counts as valid here; `required` is a separate question. */
export function isValidPhone(value: string, prefix?: string | null): boolean {
    return (value ?? '').trim() === '' ? true : toE164(value, prefix) !== null;
}

/** Formats a stored number for display, in national form where possible. */
export function formatStored(value: string | null | undefined, prefix?: string | null): string {
    if (!value) {
        return '';
    }

    const parsed = parsePhoneNumberFromString(value.trim(), countryFor(prefix));

    return parsed?.isValid() ? parsed.formatNational() : value;
}
