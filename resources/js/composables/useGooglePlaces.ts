import { ref } from 'vue';

const API_KEY = import.meta.env.VITE_GOOGLE_PLACES_API_KEY as string | undefined;

const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
const DETAILS_URL = 'https://places.googleapis.com/v1/places';
const SEARCH_TEXT_URL = 'https://places.googleapis.com/v1/places:searchText';

/**
 * Germany bounding box. Autocomplete is scoped to `de`, so any genuine address
 * coordinate falls inside it — used to reject (0,0), swapped lat/lng, or junk
 * before it reaches the map.
 */
const DE_BOUNDS = { latMin: 47, latMax: 56, lngMin: 5, lngMax: 16 };

export const isValidDeCoord = (lat: unknown, lng: unknown): lat is number =>
    typeof lat === 'number' &&
    typeof lng === 'number' &&
    Number.isFinite(lat) &&
    Number.isFinite(lng) &&
    lat >= DE_BOUNDS.latMin &&
    lat <= DE_BOUNDS.latMax &&
    lng >= DE_BOUNDS.lngMin &&
    lng <= DE_BOUNDS.lngMax;

export interface PlaceSuggestion {
    placeId: string;
    mainText: string;
    secondaryText: string;
}

export interface ResolvedPlaceAddress {
    street?: string;
    number?: string;
    zip_code?: string;
    city?: string;
    /** The form's country label (Deutschland/Österreich/Schweiz); unset for any other country. */
    country?: string;
    latitude: number;
    longitude: number;
}

export interface GoogleAddressComponent {
    longText: string;
    shortText: string;
    types: string[];
}

function findComponent(components: GoogleAddressComponent[], ...types: string[]): string | undefined {
    for (const type of types) {
        const match = components.find((component) => component.types.includes(type));

        if (match) {
            return match.longText;
        }
    }

    return undefined;
}

/** ISO country codes Google returns, mapped to the labels the address forms store. */
const COUNTRY_LABELS: Record<string, string> = { DE: 'Deutschland', AT: 'Österreich', CH: 'Schweiz' };

/**
 * Google address components -> the form's address fields. One field per
 * component type, never derived from another field's text: the street is
 * `route` only, so street text can never land in the house number.
 */
export function mapAddressComponents(components: GoogleAddressComponent[]): Omit<ResolvedPlaceAddress, 'latitude' | 'longitude'> {
    const countryCode = components.find((component) => component.types.includes('country'))?.shortText?.toUpperCase();

    return {
        street: findComponent(components, 'route'),
        number: findComponent(components, 'street_number'),
        zip_code: findComponent(components, 'postal_code'),
        city: findComponent(components, 'locality', 'postal_town', 'administrative_area_level_2'),
        country: countryCode ? COUNTRY_LABELS[countryCode] : undefined,
    };
}

/**
 * A v4 UUID for the Places session token. `crypto.randomUUID` only exists in
 * secure contexts (https, localhost); on a plain-http host — the AWS rehearsal
 * is served over its IP — calling it throws, and the address field that set
 * up this client never rendered. `getRandomValues` is available everywhere.
 */
export function newSessionToken(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * Thin client for the Google Places API (New) REST endpoints, using the
 * recommended session-token pattern: autocomplete() predictions and the
 * closing getPlaceDetails() call are billed as one session.
 */
export function useGooglePlaces() {
    const isConfigured = ref<boolean>(Boolean(API_KEY));
    const error = ref<string | null>(null);

    let sessionToken = newSessionToken();

    function newSession() {
        sessionToken = newSessionToken();
    }

    async function autocomplete(input: string): Promise<PlaceSuggestion[]> {
        error.value = null;

        if (!API_KEY) {
            error.value = 'Google Places API-Schlüssel fehlt.';

            return [];
        }

        const trimmed = input.trim();

        if (trimmed.length < 3) {
            return [];
        }

        try {
            const response = await fetch(AUTOCOMPLETE_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Goog-Api-Key': API_KEY,
                },
                body: JSON.stringify({
                    input: trimmed,
                    languageCode: 'de',
                    includedRegionCodes: ['de'],
                    sessionToken,
                }),
            });

            if (!response.ok) {
                throw new Error(`Places autocomplete ${response.status}`);
            }

            const data = await response.json();
            const suggestions: any[] = data.suggestions ?? [];

            return suggestions
                .filter((suggestion) => suggestion.placePrediction)
                .map((suggestion) => {
                    const prediction = suggestion.placePrediction;

                    return {
                        placeId: prediction.placeId as string,
                        mainText: prediction.structuredFormat?.mainText?.text ?? prediction.text?.text ?? '',
                        secondaryText: prediction.structuredFormat?.secondaryText?.text ?? '',
                    } satisfies PlaceSuggestion;
                });
        } catch {
            error.value = 'Adresssuche fehlgeschlagen.';

            return [];
        }
    }

    async function getPlaceDetails(placeId: string): Promise<ResolvedPlaceAddress | null> {
        error.value = null;

        if (!API_KEY) {
            return null;
        }

        try {
            // The session token goes in the query string: the details endpoint's
            // CORS policy only allows the api-key and field-mask headers.
            const url = `${DETAILS_URL}/${placeId}?sessionToken=${encodeURIComponent(sessionToken)}&languageCode=de`;
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'X-Goog-Api-Key': API_KEY,
                    'X-Goog-FieldMask': 'addressComponents,location',
                },
            });

            if (!response.ok) {
                throw new Error(`Place details ${response.status}`);
            }

            const data = await response.json();
            const components: GoogleAddressComponent[] = data.addressComponents ?? [];

            const resolved: ResolvedPlaceAddress = {
                ...mapAddressComponents(components),
                latitude: data.location?.latitude,
                longitude: data.location?.longitude,
            };

            newSession();

            return resolved;
        } catch {
            error.value = 'Adressdetails konnten nicht geladen werden.';

            return null;
        }
    }

    /**
     * Forward-geocode free text into coordinates — the fallback for showing a
     * saved address whose stored coordinates are missing or out of bounds.
     */
    async function geocodeAddress(query: string): Promise<{ latitude: number; longitude: number } | null> {
        if (!API_KEY) {
            return null;
        }

        const text = query.trim();

        if (!text) {
            return null;
        }

        try {
            const response = await fetch(SEARCH_TEXT_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Goog-Api-Key': API_KEY,
                    'X-Goog-FieldMask': 'places.location',
                },
                body: JSON.stringify({
                    textQuery: text,
                    languageCode: 'de',
                    regionCode: 'DE',
                }),
            });

            if (!response.ok) {
                throw new Error(`Text search ${response.status}`);
            }

            const data = await response.json();
            const location = data.places?.[0]?.location;

            if (isValidDeCoord(location?.latitude, location?.longitude)) {
                return { latitude: location.latitude, longitude: location.longitude };
            }

            return null;
        } catch {
            return null;
        }
    }

    return { isConfigured, error, autocomplete, getPlaceDetails, geocodeAddress, newSession };
}
