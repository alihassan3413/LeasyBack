<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPortalDate } from '@/lib/portalDate';
import { serviceTitle } from '@/lib/services';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiCheckCircle from '~icons/mdi/check-circle';

/**
 * Shown right after a booking (Unfallschaden): the order exists, here is its
 * number, and here is where to follow it. Rendered by
 * OrderController::confirmation(), only for someone who may see the vehicle.
 */
const props = defineProps<{
    order: {
        id: string;
        auftragsnummer: string;
        service_type: string | null;
        order_status: string;
        created_at: string | null;
    };
    vehicle: {
        license_plate: string;
        make: string | null;
        model: string | null;
        vin: string | null;
    };
    attachmentCount: number;
    /** Every vehicle of a multi-vehicle order (Gutachten); empty otherwise. */
    vehicles?: { license_plate: string | null; make: string | null; model: string | null; vin: string | null }[];
}>();

const vehicleList = computed(() => props.vehicles ?? []);

const title = computed(() => serviceTitle(props.order.service_type));
const status = computed(() => getVehicleStatusDisplay(props.order.order_status));
</script>

<template>
    <Head :title="`${title} beauftragt`" />

    <AppLayout>
        <div class="mx-auto flex max-w-[640px] flex-col items-center py-8 text-center">
            <MdiCheckCircle class="text-brand-green size-14" />

            <h1 class="text-brand-teal mt-4 text-[22px] font-semibold md:text-[26px]">{{ title }} beauftragt</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Vielen Dank. Ihr Auftrag ist bei LeasyBack eingegangen. Wir melden uns, sobald der nächste Schritt feststeht. Eine Bestätigung
                haben Sie per E-Mail erhalten.
            </p>

            <dl class="border-border bg-card mt-6 w-full divide-y rounded-[10px] border text-left text-sm">
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted-foreground">Auftragsnummer</dt>
                    <dd class="text-brand-teal font-semibold">{{ order.auftragsnummer }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted-foreground">Erstellt am</dt>
                    <dd class="text-brand-teal font-semibold">{{ formatPortalDate(order.created_at) || '—' }}</dd>
                </div>
                <div v-if="vehicleList.length > 1" class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted-foreground">Fahrzeuge ({{ vehicleList.length }})</dt>
                    <dd class="text-brand-teal text-right font-semibold">
                        <span v-for="entry in vehicleList" :key="entry.license_plate ?? ''" class="block">
                            {{ entry.license_plate }}
                            <span class="text-muted-foreground text-xs font-normal">
                                · {{ [entry.make, entry.model].filter(Boolean).join(' ') || '—' }}
                                <template v-if="entry.vin"> · FIN {{ entry.vin }}</template>
                            </span>
                        </span>
                    </dd>
                </div>
                <div v-else class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted-foreground">Fahrzeug</dt>
                    <dd class="text-brand-teal text-right font-semibold">
                        {{ vehicle.license_plate }}
                        <span class="text-muted-foreground block text-xs font-normal">
                            {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || '—' }}
                            <template v-if="vehicle.vin"> · FIN {{ vehicle.vin }}</template>
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted-foreground">Leistung</dt>
                    <dd class="text-brand-teal font-semibold">{{ title }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 px-5 py-3">
                    <dt class="text-muted-foreground">Status</dt>
                    <dd><Badge :variant="status.variant">{{ status.label }}</Badge></dd>
                </div>
                <div v-if="order.service_type === 'unfallschaden'" class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted-foreground">Hochgeladene Dateien</dt>
                    <dd class="text-brand-teal font-semibold">{{ attachmentCount }}</dd>
                </div>
            </dl>

            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <Link
                    :href="route('orders.show', order.id)"
                    class="bg-brand-orange hover:bg-brand-orange/90 rounded-[6px] px-5 py-2.5 text-[13.5px] font-bold text-white transition-colors"
                >
                    Auftrag ansehen
                </Link>
                <Link
                    :href="route('dashboard')"
                    class="border-border text-brand-teal hover:bg-muted/40 rounded-[6px] border px-5 py-2.5 text-[13.5px] font-semibold transition-colors"
                >
                    Zum Dashboard
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
