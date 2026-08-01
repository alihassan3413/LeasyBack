<script setup lang="ts">
/**
 * Admin counterpart to the customer-facing OffersCard.vue: shows every
 * offer (not just published/selected) and lets Admin create/publish/cancel
 * them, rather than a customer selecting one.
 */
import CreateOfferModal from '@/components/admin/CreateOfferModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { AdminOfferRow } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{ orderId: string; offers: AdminOfferRow[] }>();

const createModalOpen = ref(false);
const publishingId = ref<string | null>(null);
const cancellingId = ref<string | null>(null);
const confirmingCancelId = ref<string | null>(null);

const STATUS_LABELS: Record<AdminOfferRow['offer_status'], string> = {
    draft: 'Entwurf',
    published: 'Veröffentlicht',
    selected: 'Angenommen',
    closed: 'Geschlossen',
    cancelled: 'Storniert',
};

const STATUS_VARIANTS: Record<AdminOfferRow['offer_status'], 'secondary' | 'default' | 'success' | 'outline'> = {
    draft: 'secondary',
    published: 'default',
    selected: 'success',
    closed: 'outline',
    cancelled: 'outline',
};

function formatCurrency(value: string | number | null): string {
    if (value === null) {
        return '—';
    }
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value));
}

function publish(offer: AdminOfferRow) {
    publishingId.value = offer.offer_id;
    router.patch(
        route('admin.orders.offers.publish', offer.offer_id),
        {},
        { preserveScroll: true, onFinish: () => (publishingId.value = null) },
    );
}

function cancel(offer: AdminOfferRow) {
    cancellingId.value = offer.offer_id;
    router.patch(
        route('admin.orders.offers.cancel', offer.offer_id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                cancellingId.value = null;
                confirmingCancelId.value = null;
            },
        },
    );
}
</script>

<template>
    <Card>
        <CardHeader class="flex-row items-start justify-between gap-4 space-y-0">
            <div>
                <CardTitle class="text-base">Angebote</CardTitle>
                <CardDescription>Angebote für diesen Auftrag verwalten.</CardDescription>
            </div>
            <Button size="sm" variant="outline" @click="createModalOpen = true">Angebot erstellen</Button>
        </CardHeader>
        <CardContent class="space-y-3">
            <p v-if="offers.length === 0" class="text-muted-foreground text-sm">Noch keine Angebote.</p>
            <div v-for="offer in offers" :key="offer.offer_id" class="flex items-center justify-between gap-4 rounded-lg border p-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-medium">Angebot {{ offer.offer_sequence }}</span>
                        <Badge :variant="STATUS_VARIANTS[offer.offer_status]">{{ STATUS_LABELS[offer.offer_status] }}</Badge>
                    </div>
                    <p class="text-lg font-semibold">{{ formatCurrency(offer.final_total_gross) }}</p>
                    <p v-if="offer.additional_notes" class="text-muted-foreground text-sm">{{ offer.additional_notes }}</p>
                    <p v-if="offer.cancellation_reason" class="text-destructive text-sm">Grund: {{ offer.cancellation_reason }}</p>
                </div>
                <div v-if="offer.offer_status === 'draft'" class="flex items-center gap-2">
                    <Button size="sm" :loading="publishingId === offer.offer_id" @click="publish(offer)"> Veröffentlichen </Button>
                    <template v-if="confirmingCancelId === offer.offer_id">
                        <Button variant="ghost" size="sm" @click="confirmingCancelId = null">Abbrechen</Button>
                        <Button variant="destructive" size="sm" :loading="cancellingId === offer.offer_id" @click="cancel(offer)">
                            Bestätigen
                        </Button>
                    </template>
                    <Button v-else variant="destructive" size="sm" @click="confirmingCancelId = offer.offer_id"> Verwerfen </Button>
                </div>
                <div v-else-if="offer.offer_status === 'published'" class="flex items-center gap-2">
                    <template v-if="confirmingCancelId === offer.offer_id">
                        <Button variant="ghost" size="sm" @click="confirmingCancelId = null">Abbrechen</Button>
                        <Button variant="destructive" size="sm" :loading="cancellingId === offer.offer_id" @click="cancel(offer)">
                            Bestätigen
                        </Button>
                    </template>
                    <Button v-else variant="destructive" size="sm" @click="confirmingCancelId = offer.offer_id"> Zurückziehen </Button>
                </div>
            </div>
        </CardContent>
    </Card>

    <CreateOfferModal v-model:open="createModalOpen" :order-id="orderId" />
</template>
