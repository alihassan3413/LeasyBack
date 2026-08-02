const API_KEY = import.meta.env.VITE_GOOGLE_PLACES_API_KEY as string | undefined;
const CALLBACK = '__leasybackInitGoogleMaps__';

let loadPromise: Promise<any> | null = null;

export const isGoogleMapsConfigured = (): boolean => Boolean(API_KEY);

/**
 * Loads the Google Maps JavaScript API exactly once and resolves with
 * `google.maps`. Concurrent callers share the same promise, so several map
 * instances never inject the script twice. Requires the Maps JavaScript API
 * (and, for reverse geocoding, the Geocoding API) enabled on the key.
 */
export function loadGoogleMaps(): Promise<any> {
    if (loadPromise) {
        return loadPromise;
    }

    loadPromise = new Promise((resolve, reject) => {
        const globalWindow = window as any;

        if (globalWindow.google?.maps) {
            resolve(globalWindow.google.maps);

            return;
        }

        if (!API_KEY) {
            reject(new Error('Google Maps API key missing'));

            return;
        }

        globalWindow[CALLBACK] = () => {
            resolve(globalWindow.google.maps);
            delete globalWindow[CALLBACK];
        };

        const script = document.createElement('script');
        script.src =
            `https://maps.googleapis.com/maps/api/js?key=${API_KEY}` +
            `&v=weekly&loading=async&language=de&region=DE&callback=${CALLBACK}`;
        script.async = true;
        script.onerror = () => {
            loadPromise = null;
            reject(new Error('Failed to load Google Maps JS'));
        };

        document.head.appendChild(script);
    });

    return loadPromise;
}
