<script setup lang="ts">
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { formatPortalDate } from '@/lib/portalDate';
import type { ServiceDefinition } from '@/lib/services';
import type { BookableVehicleData } from '@/types/vehicle';
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MdiCarOutline from '~icons/mdi/car-outline';
import MdiMagnify from '~icons/mdi/magnify';

/**
 * Step one of booking a service: which vehicle it is for. The appointment
 * itself is the next modal's job, which the caller opens with whatever this
 * confirms.
 *
 * Only vehicles a new order can actually be placed for are ever passed in
 * (VehicleService::listBookableVehicles), so there is no disabled row here —
 * a vehicle already in a process is on the fleet page, where its running
 * order is the thing to look at.
 *
 * `multiple` turns the picker into a checklist (Überführung books several
 * vehicles at once) and confirms with `confirm-many`. Without it the picker
 * behaves exactly as before and confirms a single vehicle with `confirm`.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        service: ServiceDefinition | null;
        vehicles: BookableVehicleData[];
        multiple?: boolean;
    }>(),
    { multiple: false },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', vehicle: BookableVehicleData): void;
    (e: 'confirm-many', vehicles: BookableVehicleData[]): void;
}>();

const search = ref('');
const selectedIds = ref<string[]>([]);

const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) {
        return props.vehicles;
    }

    return props.vehicles.filter((vehicle) =>
        [vehicle.license_plate, vehicle.make, vehicle.model, vehicle.vin].filter(Boolean).join(' ').toLowerCase().includes(needle),
    );
});

const selected = computed(() => props.vehicles.filter((vehicle) => selectedIds.value.includes(vehicle.vehicle_id)));

const allFilteredSelected = computed(() => filtered.value.length > 0 && filtered.value.every((vehicle) => selectedIds.value.includes(vehicle.vehicle_id)));

watch(
    () => props.open,
    (open) => {
        if (open) {
            search.value = '';
            selectedIds.value = [];
        }
    },
);

function isSelected(vehicle: BookableVehicleData): boolean {
    return selectedIds.value.includes(vehicle.vehicle_id);
}

function toggle(vehicle: BookableVehicleData) {
    if (!props.multiple) {
        selectedIds.value = [vehicle.vehicle_id];

        return;
    }

    selectedIds.value = isSelected(vehicle)
        ? selectedIds.value.filter((id) => id !== vehicle.vehicle_id)
        : [...selectedIds.value, vehicle.vehicle_id];
}

function toggleAllFiltered() {
    const ids = filtered.value.map((vehicle) => vehicle.vehicle_id);

    selectedIds.value = allFilteredSelected.value
        ? selectedIds.value.filter((id) => !ids.includes(id))
        : Array.from(new Set([...selectedIds.value, ...ids]));
}

function confirm() {
    if (!selected.value.length) {
        return;
    }

    if (props.multiple) {
        emit('confirm-many', selected.value);

        return;
    }

    emit('confirm', selected.value[0]);
}

const confirmLabel = computed(() => {
    if (!props.multiple || selected.value.length <= 1) {
        return 'Weiter';
    }

    return `Weiter mit ${selected.value.length} Fahrzeugen`;
});
</script>

<template>
    <AppModal
        :open="open"
        :title="service ? `${service.title} buchen` : 'Fahrzeug wählen'"
        :description="
            multiple
                ? 'Wählen Sie ein oder mehrere Fahrzeuge, für die die Leistung gebucht werden soll.'
                : 'Wählen Sie das Fahrzeug, für das die Leistung gebucht werden soll.'
        "
        :width="560"
        @update:open="(value) => emit('update:open', value)"
    >
        <div class="px-2 pb-1">
            <!-- No vehicle is bookable: either none exists, or all are already in a process. -->
            <div v-if="!vehicles.length" class="flex flex-col items-center gap-3 py-8 text-center">
                <MdiCarOutline class="text-muted-foreground/50 size-9" />
                <p class="text-muted-foreground text-sm">Derzeit steht kein Fahrzeug für eine neue Buchung bereit.</p>
                <Link
                    :href="route('vehicles.index')"
                    class="text-brand-orange text-sm font-semibold hover:underline"
                    @click="emit('update:open', false)"
                >
                    Zu meinen Fahrzeugen
                </Link>
            </div>

            <template v-else>
                <div class="focus-within:border-brand-green border-border mb-3 flex h-10 items-center gap-2 rounded-full border px-4">
                    <MdiMagnify class="text-muted-foreground size-[18px] shrink-0" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Kennzeichen, Modell oder FIN"
                        class="placeholder:text-muted-foreground w-full bg-transparent text-sm outline-none"
                    />
                </div>

                <div v-if="multiple && filtered.length > 1" class="mb-2 flex items-center justify-between px-1">
                    <span class="text-muted-foreground text-xs">{{ selected.length }} ausgewählt</span>
                    <button type="button" class="text-brand-teal text-xs font-semibold hover:underline" @click="toggleAllFiltered">
                        {{ allFilteredSelected ? 'Auswahl aufheben' : 'Alle auswählen' }}
                    </button>
                </div>

                <p v-if="!filtered.length" class="text-muted-foreground py-6 text-center text-sm">Kein Fahrzeug gefunden.</p>

                <div v-else class="flex max-h-[360px] flex-col gap-2 overflow-y-auto">
                    <button
                        v-for="vehicle in filtered"
                        :key="vehicle.vehicle_id"
                        type="button"
                        class="hover:border-brand-green flex items-center gap-3 rounded-xl border px-4 py-3 text-left transition-colors"
                        :class="isSelected(vehicle) ? 'border-brand-green bg-brand-green/[0.06]' : 'border-border'"
                        :aria-pressed="isSelected(vehicle)"
                        @click="toggle(vehicle)"
                    >
                        <!-- Checkbox for multi-select, radio dot for single. -->
                        <span
                            v-if="multiple"
                            class="flex size-4 shrink-0 items-center justify-center rounded-[4px] border"
                            :class="isSelected(vehicle) ? 'border-brand-green bg-brand-green' : 'border-muted-foreground/40'"
                        >
                            <svg v-if="isSelected(vehicle)" viewBox="0 0 12 12" class="size-3 text-white" fill="none" aria-hidden="true">
                                <path d="M2.5 6.2 5 8.5l4.5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <span
                            v-else
                            class="flex size-4 shrink-0 items-center justify-center rounded-full border"
                            :class="isSelected(vehicle) ? 'border-brand-green bg-brand-green' : 'border-muted-foreground/40'"
                        >
                            <span v-if="isSelected(vehicle)" class="size-1.5 rounded-full bg-white" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="text-brand-teal block text-sm font-semibold">{{ vehicle.license_plate }}</span>
                            <span class="text-muted-foreground block truncate text-xs">
                                {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || '—' }}
                                <template v-if="vehicle.leasing_end_date"> · Leasingende {{ formatPortalDate(vehicle.leasing_end_date) }} </template>
                            </span>
                        </span>
                    </button>
                </div>
            </template>
        </div>

        <template v-if="vehicles.length" #footer>
            <AppModalButton :disabled="!selected.length" @click="confirm">{{ confirmLabel }}</AppModalButton>
        </template>
    </AppModal>
</template>