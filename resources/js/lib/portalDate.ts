/**
 * Portal dates, rendered in German business time.
 *
 * Every timestamp the portal shows is a fact about LeasyBack's own working day
 * — when an order was confirmed, when a report was released, when a case
 * closed. Those belong to a German business, not to wherever the reader
 * happens to be sitting, so they are always rendered in Europe/Berlin and
 * never in the viewer's own zone. Admin and customer therefore quote the same
 * clock time for the same event, which is what makes a support conversation
 * about "the 13:32 transition" possible at all.
 *
 * The parsing half matters as much as the formatting half. The database keeps
 * timestamps in UTC and the portal payloads used to carry them bare —
 * `2026-08-26 10:05:28`, no offset — and `new Date()` reads a string in that
 * shape as *local* time. So the portal printed the raw UTC clock as though it
 * were the reader's: two hours early in a German summer, five in Karachi. The
 * payloads now carry their offset (App\Support\PortalTimestamp), but
 * parsePortalDate() still reads an offset-less value as the UTC it actually
 * is — the display must not depend on every endpoint having been converted.
 */

export const PORTAL_TIME_ZONE = 'Europe/Berlin';

export const PORTAL_LOCALE = 'de-DE';

/** `2026-09-01` — a business date, with no instant behind it. */
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/** `2026-08-26 10:05:28` or `2026-08-26T10:05:28.123` — an instant, no zone. */
const ZONELESS_DATETIME = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?$/;

/**
 * Read a portal timestamp into a real instant, or null if there isn't one.
 *
 * Exported because a few callers need the Date itself — to compare or sort —
 * rather than a formatted string, and they must read it the same way.
 */
export function parsePortalDate(value: string | null | undefined): Date | null {
    const raw = (value ?? '').trim();

    if (!raw) {
        return null;
    }

    /*
     * A date-only value carries no time, so any zone shift can only move it to
     * the wrong day: `new Date('2026-09-01')` is midnight UTC, which is the
     * 31st anywhere west of Greenwich. Anchoring at midday keeps it inside the
     * same calendar day in every zone, and the time is then discarded anyway.
     */
    const normalized = DATE_ONLY.test(raw) ? `${raw}T12:00:00Z` : ZONELESS_DATETIME.test(raw) ? `${raw.replace(' ', 'T')}Z` : raw;

    const date = new Date(normalized);

    return Number.isNaN(date.getTime()) ? null : date;
}

const DATE_PARTS: Intl.DateTimeFormatOptions = {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: PORTAL_TIME_ZONE,
};

/**
 * `hourCycle: 'h23'` rather than `hour12: false`, which some engines resolve to
 * h24 and print midnight as 24:00.
 */
const TIME_PARTS: Intl.DateTimeFormatOptions = {
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
    timeZone: PORTAL_TIME_ZONE,
};

/** `26.08.2026`, or '' when there is no date to show. */
export function formatPortalDate(value: string | null | undefined): string {
    const date = parsePortalDate(value);

    return date ? date.toLocaleDateString(PORTAL_LOCALE, DATE_PARTS) : '';
}

/**
 * How the date and the time are joined. The portal grew three spellings of the
 * same stamp — the timeline's `· … Uhr`, the Admin cards' comma, and the
 * message list's bare `·` — and they are kept because changing what a screen
 * reads was never the point of centralising this.
 */
export interface PortalDateTimeStyle {
    separator?: string;
    suffix?: string;
}

/** `26.08.2026 · 12:05 Uhr`, or '' when there is no date to show. */
export function formatPortalDateTime(value: string | null | undefined, style: PortalDateTimeStyle = {}): string {
    const date = parsePortalDate(value);

    if (!date) {
        return '';
    }

    const day = date.toLocaleDateString(PORTAL_LOCALE, DATE_PARTS);

    /*
     * A date-only value is parsed at a midday anchor so no zone can move it off
     * its calendar day, and printing that anchor back as "14:00 Uhr" would
     * invent a time the record never had. These formatters are reached with
     * whatever a column happens to hold, so the bare date is the honest answer.
     */
    if (DATE_ONLY.test((value ?? '').trim())) {
        return day;
    }

    const { separator = ' · ', suffix = ' Uhr' } = style;

    return `${day}${separator}${date.toLocaleTimeString(PORTAL_LOCALE, TIME_PARTS)}${suffix}`;
}

/** `26.08.2026, 12:05` — the compact form the Admin cards use. */
export function formatPortalDateTimeShort(value: string | null | undefined): string {
    return formatPortalDateTime(value, { separator: ', ', suffix: '' });
}
