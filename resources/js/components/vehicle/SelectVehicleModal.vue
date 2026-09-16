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
 * itself is OrderCreationModal's job, which the caller opens with whatever
 * this confirms.
 *
 * Only vehicles a new order can actually be placed for are ever passed in
 * (VehicleService::listBookableVehicles), so there is no disabled row here —
 * a vehicle already in a process is on the fleet page, where its running
 * order is the thing to look at.
 */
const props = defineProps<{
    open: boolean;
    service: ServiceDefinition | null;
    vehicles: BookableVehicleData[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm', vehicle: BookableVehicleData): void;
}>();

const search = ref('');
const selectedId = ref<string | null>(null);

const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) {
        return props.vehicles;
    }

    return props.vehicles.filter((vehicle) =>
        [vehicle.license_plate, vehicle.make, vehicle.model, vehicle.vin].filter(Boolean).join(' ').toLowerCase().includes(needle),
    );
});

const selected = computed(() => props.vehicles.find((vehicle) => vehicle.vehicle_id === selectedId.value) ?? null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            search.value = '';
            selectedId.value = null;
        }
    },
);

function confirm() {
    if (selected.value) {
        emit('confirm', selected.value);
    }
}
</script>

<template>
    <AppModal
        :open="open"
        :title="service ? `${service.title} buchen` : 'Fahrzeug wählen'"
        description="Wählen Sie das Fahrzeug, für das die Leistung gebucht werden soll."
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

                <p v-if="!filtered.length" class="text-muted-foreground py-6 text-center text-sm">Kein Fahrzeug gefunden.</p>

                <div v-else class="flex flex-col gap-2">
                    <button
                        v-for="vehicle in filtered"
                        :key="vehicle.vehicle_id"
                        type="button"
                        class="hover:border-brand-green flex items-center gap-3 rounded-xl border px-4 py-3 text-left transition-colors"
                        :class="selectedId === vehicle.vehicle_id ? 'border-brand-green bg-brand-green/[0.06]' : 'border-border'"
                        @click="selectedId = vehicle.vehicle_id"
                    >
                        <span
                            class="flex size-4 shrink-0 items-center justify-center rounded-full border"
                            :class="selectedId === vehicle.vehicle_id ? 'border-brand-green bg-brand-green' : 'border-muted-foreground/40'"
                        >
                            <span v-if="selectedId === vehicle.vehicle_id" class="size-1.5 rounded-full bg-white" />
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
            <AppModalButton :disabled="!selected" @click="confirm">Weiter</AppModalButton>
        </template>
    </AppModal>
</template>
