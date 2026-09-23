<script setup lang="ts">
/**
 * Replaces the legacy frontend's permanently-disabled "Angebot annehmen"
 * button (it never called anything — see docs/B2C_ADMIN_IMPLEMENTATION_PROGRESS.md,
 * Checkpoint 7 notes) with a real select action, now that OfferPolicy::select
 * ownership-checks it (Checkpoint 6). Only published/selected offers ever
 * reach this component — VehicleService::listVehiclesWithOrders() already
 * filters out draft/cancelled offers before they're sent to the frontend.
 */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import type { OfferData } from '@/types/order';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ offers: OfferData[] }>();

const { can } = useB2bPermissions();

/** Same right as OfferComparison — accepting is a spend decision. */
const canDecide = computed(() => can('offers.select'));

/**
 * Gross for B2C, net for B2B — a B2B payload has no gross keys at all
 * (b2b.txt §9), so reading `final_total_gross` there printed "—".
 */
const showsGross = computed(() => props.offers.some((offer) => offer.final_total_gross != null));

function displayedTotal(offer: OfferData): string | number | null {
    return (showsGross.value ? offer.final_total_gross : offer.final_total_net) ?? null;
}

const selectingOfferId = ref<string | null>(null);
const hasSelectedOffer = computed(() => props.offers.some((offer) => offer.offer_status === 'selected'));

function formatCurrency(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value));
}

function selectOffer(offer: OfferData) {
    selectingOfferId.value = offer.offer_id;
    router.post(
        route('offers.select', offer.offer_id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                selectingOfferId.value = null;
            },
        },
    );
}
</script>

<template>
    <Card v-if="offers.length > 0">
        <CardHeader>
            <CardTitle class="text-base">Angebote</CardTitle>
            <CardDescription>
                {{
                    canDecide
                        ? 'Wählen Sie ein Angebot aus, um den Vorgang fortzusetzen.'
                        : 'Ein Unternehmens-Administrator gibt eines der Angebote frei.'
                }}
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-3">
            <div v-for="offer in offers" :key="offer.offer_id" class="flex items-center justify-between gap-4 rounded-lg border p-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-medium">Angebot {{ offer.offer_sequence }}</span>
                        <Badge v-if="offer.offer_status === 'selected'" variant="success">Angenommen</Badge>
                    </div>
                    <p class="text-lg font-semibold">
                        {{ formatCurrency(displayedTotal(offer)) }}
                        <span class="text-muted-foreground text-xs font-normal">{{ showsGross ? 'brutto' : 'netto' }}</span>
                    </p>
                    <p v-if="offer.additional_notes" class="text-muted-foreground text-sm">{{ offer.additional_notes }}</p>
                </div>
                <Button
                    v-if="offer.offer_status === 'published' && !hasSelectedOffer && canDecide"
                    size="sm"
                    :loading="selectingOfferId === offer.offer_id"
                    @click="selectOffer(offer)"
                >
                    Angebot annehmen
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
