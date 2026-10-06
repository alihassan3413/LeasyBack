// Validation and normalisation helpers for German license plates ("Kennzeichen").
//
// A plate is entered in three sections in the UI:
//   1. Unterscheidungszeichen (location/district code) — 1–3 letters
//   2. Erkennungszeichen (identification letters)        — 1–2 letters
//   3. Erkennungsnummer (number, optional electric "E")  — 1–4 digits + optional final "E"
//
// Overall rule: the concatenated plate may contain at most 10 alphanumeric
// characters (separators/spaces/hyphens never count — the three section values
// hold no separators anyway). That is exactly the sum of what the three sections
// accept: 3 + 2 + 5.
//
// The cap used to be 8, which contradicted this file's own per-section rules:
// the number section reserves five characters for "four digits plus the electric
// E", so a three-letter district could never be combined with an electric plate
// ("SÜW AB 1234E"), and even the widest non-electric plate ("ABC AB 1234") was
// rejected — while the inputs happily accepted the typing and only complained
// afterwards. Three-letter districts are ordinary (BR, SÜW, WES, SG), so this
// turned away real plates.
//
// These helpers only VALIDATE and (for casing) NORMALISE — they never silently
// strip or truncate content, so invalid input surfaces a German error message
// instead of disappearing.

export const PLATE_MAX_TOTAL = 10;

// Interpolated rather than written out, so the sentence cannot drift away from
// the cap it reports.
export const PLATE_MESSAGES = {
    city: 'Bitte geben Sie 1 bis 3 Buchstaben ein.',
    letters: 'Bitte geben Sie 1 bis 2 Buchstaben ein.',
    number: 'Bitte geben Sie 1 bis 4 Ziffern ein. Optional kann am Ende ein E stehen.',
    invalidE: 'Das E darf nur einmal und nur am Ende des Kennzeichens stehen.',
    maxLength: `Das Kennzeichen darf insgesamt höchstens ${PLATE_MAX_TOTAL} Zeichen enthalten.`,
};

/** Upper-cases the value (lowercase → uppercase) without stripping anything. */
export function toPlateUpperCase(value: string): string {
    return value.toUpperCase();
}

/**
 * Live input sanitiser for section 3 (number): upper-cases, hard-caps the
 * digit count at 4, and hard-caps the electric-vehicle "E" to a single
 * occurrence, so the user can never type more than four digits or more than
 * one E. Any other mistyped letter is kept in place so the position/format
 * validation messages still fire.
 */
export function sanitizePlateNumber(value: string): string {
    let digitCount = 0;
    let eUsed = false;
    let result = '';
    for (const char of value.toUpperCase()) {
        if (char >= '0' && char <= '9') {
            if (digitCount >= 4) continue; // ignore any digit beyond the fourth
            digitCount += 1;
        } else if (char === 'E') {
            if (eUsed) continue; // ignore any E beyond the first
            eUsed = true;
        }
        result += char;
    }
    return result;
}

/** Section 1: 1–3 letters only. Returns a German error message, or "" if valid/empty. */
export function validatePlateCity(value: string): string {
    const v = value.trim().toUpperCase();
    if (!v) return '';
    return /^[A-ZÄÖÜ]{1,3}$/.test(v) ? '' : PLATE_MESSAGES.city;
}

/** Section 2: 1–2 letters only. Returns a German error message, or "" if valid/empty. */
export function validatePlateLetters(value: string): string {
    const v = value.trim().toUpperCase();
    if (!v) return '';
    return /^[A-ZÄÖÜ]{1,2}$/.test(v) ? '' : PLATE_MESSAGES.letters;
}

/**
 * Section 3: 1–4 digits with an optional single trailing "E".
 * Returns a German error message, or "" if valid/empty.
 */
export function validatePlateNumber(value: string): string {
    const v = value.trim().toUpperCase();
    if (!v) return '';

    // Only digits and the letter E are permitted at all.
    if (/[^0-9E]/.test(v)) return PLATE_MESSAGES.number;

    // "E" may appear at most once and only as the final character.
    const eCount = (v.match(/E/g) || []).length;
    if (eCount > 1 || (eCount === 1 && !v.endsWith('E'))) return PLATE_MESSAGES.invalidE;

    const digits = v.replace(/E$/, '');
    return /^\d{1,4}$/.test(digits) ? '' : PLATE_MESSAGES.number;
}

/** Count of alphanumeric characters across all three sections (separators excluded). */
export function platePartsLength(city: string, letters: string, number: string): number {
    return `${city}${letters}${number}`.replace(/\s+/g, '').length;
}

/**
 * Validate all three sections plus the overall length rule.
 * Returns an ordered, de-duplicated list of German error messages ("" free).
 */
export function validatePlateParts(city: string, letters: string, number: string): string[] {
    const errors: string[] = [];
    const cityError = validatePlateCity(city);
    const lettersError = validatePlateLetters(letters);
    const numberError = validatePlateNumber(number);
    if (cityError) errors.push(cityError);
    if (lettersError) errors.push(lettersError);
    if (numberError) errors.push(numberError);

    // Only enforce the overall limit once the user has typed something.
    if ((city || letters || number) && platePartsLength(city, letters, number) > PLATE_MAX_TOTAL) {
        errors.push(PLATE_MESSAGES.maxLength);
    }

    return [...new Set(errors)];
}

/**
 * Build the combined plate string sent to the backend. Preserves the
 * space-separated, upper-cased format (e.g. "K LB 2026").
 */
export function normalizePlate(city: string, letters: string, number: string): string {
    return `${city} ${letters} ${number}`.replace(/\s+/g, ' ').trim().toUpperCase();
}

/**
 * Split a stored plate back into its three sections — the inverse of
 * {@link normalizePlate}.
 *
 * Accepts every separator the format is written with in the wild. The form
 * normalises to spaces, but a plate can arrive from the Excel import holding
 * whatever the sheet contained, and German plates are conventionally written
 * with a hyphen between the district code and the letter code ("K-LB 2026").
 * Splitting on whitespace alone would push "K-LB" into the first section and
 * "2026" into the second, so both segments fail validation — on a field the
 * customer cannot edit, because the plate is immutable after creation.
 *
 * Extras beyond three sections are dropped, matching what the three inputs can
 * hold, so a malformed value shows the parts it does have rather than nothing.
 */
export function splitPlate(plate: string): [string, string, string] {
    const parts = plate
        .trim()
        .split(/[\s-]+/)
        .filter(Boolean);

    return [parts[0] ?? '', parts[1] ?? '', parts[2] ?? ''];
}
