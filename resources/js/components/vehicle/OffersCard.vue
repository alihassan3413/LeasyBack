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
import type { OfferData } from '@/types/order';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ offers: OfferData[] }>();

const selectingOfferId = ref<string | null>(null);
const hasSelectedOffer = computed(() => props.offers.some((offer) => offer.offer_status === 'selected'));

function formatCurrency(value: string | number | null): string {
    if (value === null) {
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
            <CardDescription>Wählen Sie ein Angebot aus, um den Vorgang fortzusetzen.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-3">
            <div v-for="offer in offers" :key="offer.offer_id" class="flex items-center justify-between gap-4 rounded-lg border p-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-medium">Angebot {{ offer.offer_sequence }}</span>
                        <Badge v-if="offer.offer_status === 'selected'" variant="success">Angenommen</Badge>
                    </div>
                    <p class="text-lg font-semibold">{{ formatCurrency(offer.final_total_gross) }}</p>
                    <p v-if="offer.additional_notes" class="text-muted-foreground text-sm">{{ offer.additional_notes }}</p>
                </div>
                <Button
                    v-if="offer.offer_status === 'published' && !hasSelectedOffer"
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
