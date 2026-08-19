<script setup lang="ts">
/**
 * "Gewählte Werkstatt beauftragen" — the step between the customer accepting an
 * offer and the car going into the workshop.
 *
 * There is deliberately no workshop picker. The workshop is whichever one
 * quoted the accepted offer, resolved server-side; this card only names it and
 * asks for confirmation, because commissioning sends a binding repair order to
 * a real business. Every enable/disable decision below mirrors a server-side
 * rule rather than standing in for one — the button being visible is never what
 * makes the action legal.
 */
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { AdminWorkshopCommission } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiAlertOutline from '~icons/mdi/alert-outline';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
import MdiEmailFastOutline from '~icons/mdi/email-fast-outline';
import MdiWrenchOutline from '~icons/mdi/wrench-outline';

const props = defineProps<{ orderId: string; commission: AdminWorkshopCommission }>();

const confirmOpen = ref(false);
const busy = ref(false);

const workshopName = computed(() => props.commission.workshop?.company_name ?? props.commission.workshop?.label ?? 'Unbekannte Werkstatt');

const BLOCKED_COPY: Record<string, string> = {
    no_selected_offer: 'Sobald der Kunde ein Angebot angenommen hat, kann die zugehörige Werkstatt hier beauftragt werden.',
    manual_offer:
        'Das angenommene Angebot wurde manuell erstellt und hat keine hinterlegte Werkstatt. Die Werkstatt muss manuell beauftragt und der Status anschließend von Hand gesetzt werden.',
    no_workshop_contact: 'Für die gewählte Werkstatt ist keine E-Mail-Adresse hinterlegt. Bitte beauftragen Sie sie manuell.',
    wrong_status: 'In diesem Auftragsstatus kann keine Werkstatt beauftragt werden.',
};

const blockedCopy = computed(() => (props.commission.blocked_reason ? BLOCKED_COPY[props.commission.blocked_reason] : null));

function formatCurrency(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const amount = Number.parseFloat(value);

    return Number.isFinite(amount) ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount) : '—';
}

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE');
}

function commission() {
    busy.value = true;
    router.post(
        route('admin.orders.commission-workshop', props.orderId),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                confirmOpen.value = false;
            },
        },
    );
}

function resend() {
    busy.value = true;
    router.post(
        route('admin.orders.commission-workshop.resend', props.orderId),
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiWrenchOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Werkstattbeauftragung</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">
                    {{ commission.is_commissioned ? 'Beauftragt' : 'Noch nicht beauftragt' }}
                </p>
            </div>
        </div>

        <div v-if="commission.workshop" class="rounded-[13px] bg-[#f8faf9] px-3.5 py-3">
            <p class="text-[13.5px] font-bold text-[#10393b]">{{ workshopName }}</p>
            <dl class="mt-2 flex flex-col gap-1 text-[12px]">
                <div v-if="commission.workshop.contact_email" class="flex justify-between gap-3">
                    <dt class="text-[#9bb0af]">E-Mail</dt>
                    <dd class="truncate text-[#10393b]">{{ commission.workshop.contact_email }}</dd>
                </div>
                <div v-if="commission.workshop.contact_person" class="flex justify-between gap-3">
                    <dt class="text-[#9bb0af]">Ansprechpartner</dt>
                    <dd class="truncate text-[#10393b]">{{ commission.workshop.contact_person }}</dd>
                </div>
                <div v-if="commission.workshop.earliest_repair_start" class="flex justify-between gap-3">
                    <dt class="text-[#9bb0af]">Frühester Beginn</dt>
                    <dd class="text-[#10393b]">{{ formatDate(commission.workshop.earliest_repair_start) }}</dd>
                </div>
                <div v-if="commission.workshop.processing_days != null" class="flex justify-between gap-3">
                    <dt class="text-[#9bb0af]">Dauer</dt>
                    <dd class="text-[#10393b]">{{ commission.workshop.processing_days }} Arbeitstage</dd>
                </div>
                <div v-if="commission.offer_total_gross" class="flex justify-between gap-3">
                    <dt class="text-[#9bb0af]">Angenommenes Angebot</dt>
                    <dd class="font-bold text-[#10393b] tabular-nums">{{ formatCurrency(commission.offer_total_gross) }}</dd>
                </div>
            </dl>
        </div>

        <div v-if="commission.is_commissioned" class="mt-3 flex flex-col gap-2">
            <p class="flex items-start gap-1.5 text-[12.5px] font-bold text-[#00856a]">
                <MdiCheckCircleOutline class="mt-px size-[15px] shrink-0" />
                Beauftragt am {{ formatDate(commission.commissioned_at) }}
            </p>

            <p v-if="commission.notified_at" class="flex items-start gap-1.5 text-[12px] text-[#6f8585]">
                <MdiEmailFastOutline class="mt-px size-[15px] shrink-0" />
                Werkstatt benachrichtigt am {{ formatDate(commission.notified_at) }}
            </p>
            <p v-else class="flex items-start gap-1.5 text-[12px] font-bold text-[#c0392b]">
                <MdiAlertOutline class="mt-px size-[15px] shrink-0" />
                Die Benachrichtigung wurde nicht zugestellt. Die Beauftragung selbst besteht.
            </p>

            <button
                type="button"
                :disabled="busy"
                class="self-start text-[11.5px] font-bold text-[#10393b] hover:opacity-70 disabled:opacity-50"
                @click="resend"
            >
                Benachrichtigung erneut senden
            </button>
        </div>

        <template v-else>
            <p v-if="blockedCopy" class="mt-3 rounded-[13px] bg-[#fdf6f5] px-3.5 py-3 text-[12.5px] leading-relaxed text-[#8f4b3d]">
                {{ blockedCopy }}
            </p>

            <button
                v-if="commission.can_commission"
                type="button"
                :disabled="busy"
                class="mt-3 w-full rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                @click="confirmOpen = true"
            >
                Gewählte Werkstatt beauftragen
            </button>
        </template>

        <AppModal
            v-model:open="confirmOpen"
            title="Werkstatt verbindlich beauftragen"
            description="Die Werkstatt erhält sofort einen Reparaturauftrag per E-Mail."
        >
            <dl class="flex flex-col gap-2 text-[13px]">
                <div class="flex justify-between gap-3">
                    <dt class="text-[#6f8585]">Werkstatt</dt>
                    <dd class="text-right font-bold text-[#10393b]">{{ workshopName }}</dd>
                </div>
                <div v-if="commission.workshop?.contact_email" class="flex justify-between gap-3">
                    <dt class="text-[#6f8585]">Benachrichtigung an</dt>
                    <dd class="truncate text-right text-[#10393b]">{{ commission.workshop.contact_email }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-[#6f8585]">Angenommenes Angebot</dt>
                    <dd class="text-right font-bold text-[#10393b] tabular-nums">
                        {{ formatCurrency(commission.offer_total_gross) }}
                        <span v-if="commission.offer_total_net" class="block text-[11.5px] font-normal text-[#9bb0af]">
                            {{ formatCurrency(commission.offer_total_net) }} netto
                        </span>
                    </dd>
                </div>
                <div v-if="commission.workshop?.earliest_repair_start" class="flex justify-between gap-3">
                    <dt class="text-[#6f8585]">Frühester Beginn</dt>
                    <dd class="text-right text-[#10393b]">{{ formatDate(commission.workshop.earliest_repair_start) }}</dd>
                </div>
                <div v-if="commission.workshop?.processing_days != null" class="flex justify-between gap-3">
                    <dt class="text-[#6f8585]">Dauer</dt>
                    <dd class="text-right text-[#10393b]">{{ commission.workshop.processing_days }} Arbeitstage</dd>
                </div>
            </dl>

            <template #footer>
                <AppModalButton variant="secondary" :disabled="busy" @click="confirmOpen = false">Abbrechen</AppModalButton>
                <AppModalButton :disabled="busy" @click="commission">
                    {{ busy ? 'Wird beauftragt…' : 'Verbindlich beauftragen' }}
                </AppModalButton>
            </template>
        </AppModal>
    </div>
</template>
