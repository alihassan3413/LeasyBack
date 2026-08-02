<script setup lang="ts">
import { isGoogleMapsConfigured, loadGoogleMaps } from '@/composables/useGoogleMapsLoader';
import { isValidDeCoord, useGooglePlaces, type ResolvedPlaceAddress } from '@/composables/useGooglePlaces';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        latitude: number | null;
        longitude: number | null;
        interactive?: boolean;
        /** Full address line, geocoded when the stored coordinates are missing or out of bounds. */
        address?: string;
    }>(),
    {
        interactive: false,
        address: '',
    },
);

const emit = defineEmits<{ resolved: [address: ResolvedPlaceAddress] }>();

const { geocodeAddress } = useGooglePlaces();

const mapContainer = ref<HTMLDivElement | null>(null);
const loadFailed = ref(!isGoogleMapsConfigured());

let gmaps: any = null;
let map: any = null;
let marker: any = null;
let geocoder: any = null;

const DEFAULT_CENTER = { lat: 50.9375, lng: 6.9603 };

async function reverseGeocode(lat: number, lng: number): Promise<ResolvedPlaceAddress> {
    if (!geocoder) {
        return { latitude: lat, longitude: lng };
    }

    try {
        const { results } = await geocoder.geocode({ location: { lat, lng }, language: 'de', region: 'DE' });
        const components: { long_name: string; types: string[] }[] = results?.[0]?.address_components ?? [];
        const find = (...types: string[]) => {
            for (const type of types) {
                const match = components.find((component) => component.types.includes(type));

                if (match) {
                    return match.long_name;
                }
            }

            return undefined;
        };

        return {
            street: find('route'),
            number: find('street_number'),
            zip_code: find('postal_code'),
            city: find('locality', 'postal_town', 'administrative_area_level_2'),
            latitude: lat,
            longitude: lng,
        };
    } catch {
        return { latitude: lat, longitude: lng };
    }
}

function placeMarker(lat: number, lng: number) {
    if (!map || !gmaps) {
        return;
    }

    const position = { lat, lng };

    if (marker) {
        marker.setPosition(position);
    } else {
        marker = new gmaps.Marker({ position, map, draggable: props.interactive });
        marker.addListener('dragend', async (event: any) => {
            emit('resolved', await reverseGeocode(event.latLng.lat(), event.latLng.lng()));
        });
    }

    marker.setDraggable(props.interactive);
}

let resolveSeq = 0;

async function resolvePosition(): Promise<[number, number] | null> {
    if (props.interactive && isValidDeCoord(props.latitude, props.longitude)) {
        return [props.latitude as number, props.longitude as number];
    }

    if (props.address?.trim()) {
        const geo = await geocodeAddress(props.address);

        if (geo) {
            return [geo.latitude, geo.longitude];
        }
    }

    if (isValidDeCoord(props.latitude, props.longitude)) {
        return [props.latitude as number, props.longitude as number];
    }

    return null;
}

async function renderPosition() {
    const seq = ++resolveSeq;
    const position = await resolvePosition();

    if (seq !== resolveSeq || !map) {
        return;
    }

    if (position) {
        placeMarker(position[0], position[1]);
        map.setCenter({ lat: position[0], lng: position[1] });
    }
}

let renderTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleRender() {
    if (renderTimer) {
        clearTimeout(renderTimer);
    }

    renderTimer = setTimeout(renderPosition, 400);
}

onMounted(async () => {
    try {
        gmaps = await loadGoogleMaps();
    } catch {
        loadFailed.value = true;

        return;
    }

    if (!mapContainer.value) {
        return;
    }

    geocoder = new gmaps.Geocoder();

    const center =
        props.interactive && isValidDeCoord(props.latitude, props.longitude)
            ? { lat: props.latitude as number, lng: props.longitude as number }
            : DEFAULT_CENTER;

    map = new gmaps.Map(mapContainer.value, {
        center,
        zoom: 14,
        zoomControl: true,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        clickableIcons: false,
        gestureHandling: 'cooperative',
        scrollwheel: props.interactive,
        keyboardShortcuts: false,
    });

    map.addListener('click', async (event: any) => {
        if (!props.interactive) {
            return;
        }

        const lat = event.latLng.lat();
        const lng = event.latLng.lng();
        placeMarker(lat, lng);
        emit('resolved', await reverseGeocode(lat, lng));
    });

    renderPosition();
});

watch(
    () => [props.latitude, props.longitude, props.address] as const,
    () => scheduleRender(),
);

watch(
    () => props.interactive,
    (interactive) => {
        if (!map) {
            return;
        }

        map.setOptions({ scrollwheel: interactive });
        marker?.setDraggable(interactive);
    },
);

onBeforeUnmount(() => {
    if (renderTimer) {
        clearTimeout(renderTimer);
    }

    if (marker) {
        gmaps?.event.clearInstanceListeners(marker);
        marker.setMap(null);
        marker = null;
    }

    if (map) {
        gmaps?.event.clearInstanceListeners(map);
        map = null;
    }

    geocoder = null;
});
</script>

<template>
    <div ref="mapContainer" class="size-full">
        <div v-if="loadFailed" class="flex size-full items-center justify-center bg-[#F1F5F5] text-xs text-[#7A9699]">
            Karte konnte nicht geladen werden.
        </div>
    </div>
</template>
