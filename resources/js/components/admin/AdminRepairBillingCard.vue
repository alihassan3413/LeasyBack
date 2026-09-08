<script setup lang="ts">
import type { RepairPaymentStage } from '@/lib/customerOrderFlow';
import { repairPaymentStageLabel } from '@/lib/customerOrderFlow';
import { formatPortalDate, formatPortalDateTimeShort } from '@/lib/portalDate';
import type { AdminLexwareInvoice, AdminRepairPayment } from '@/types/admin';
import { computed, ref } from 'vue';
import MdiAlertOutline from '~icons/mdi/alert-outline';
import MdiContentCopy from '~icons/mdi/content-copy';
import MdiReceiptTextOutline from '~icons/mdi/receipt-text-outline';

const props = defineProps<{
    invoice: AdminLexwareInvoice | null;
    payment: AdminRepairPayment | null;
    stage: RepairPaymentStage;
}>();

const copied = ref(false);

const stageLabel = computed(() => repairPaymentStageLabel(props.stage, 'admin'));

const isSettled = computed(() => props.stage === 'payment_settled' || props.stage === 'payment_not_required');

const needsReview = computed(() => props.invoice?.status === 'needs_reconciliation');

const failureReason = computed(() => props.invoice?.failure_reason ?? null);

const amount = computed(() => {
    if (!props.payment) {
        return null;
    }

    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: (props.payment.currency || 'eur').toUpperCase(),
    }).format(props.payment.amount_cents / 100);
});

const paymentLink = computed(() => props.payment?.payment_link_url ?? null);

async function copyLink() {
    if (!paymentLink.value) {
        return;
    }

    await navigator.clipboard.writeText(paymentLink.value);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 1600);
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiReceiptTextOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Rechnung &amp; Zahlung</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">
                    {{ props.invoice?.voucher_number ? `Rechnung ${props.invoice.voucher_number}` : 'Rechnung noch nicht erstellt' }}
                </p>
            </div>
            <span
                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                :class="isSettled ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#f4f7f6] text-[#6f8585]'"
            >
                {{ stageLabel }}
            </span>
        </div>

        <p
            v-if="needsReview"
            class="mb-3 flex items-start gap-1.5 rounded-[11px] bg-[#E5533D]/8 px-3 py-2 text-[11.5px] font-bold text-[#c0392b]"
            data-role="needs-review"
        >
            <MdiAlertOutline class="mt-px size-[14px] shrink-0" />
            Die Rechnung erfordert eine manuelle Prüfung.
        </p>

        <p
            v-else-if="failureReason"
            class="mb-3 flex items-start gap-1.5 rounded-[11px] bg-[#ef8450]/10 px-3 py-2 text-[11.5px] font-bold text-[#c0562a]"
            data-role="billing-failure"
        >
            <MdiAlertOutline class="mt-px size-[14px] shrink-0" />
            Die Rechnung konnte nicht erstellt werden: {{ failureReason }}
        </p>

        <dl class="flex flex-col">
            <div v-if="props.invoice?.voucher_number" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#6f8585]">Rechnungsnummer</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b] tabular-nums">{{ props.invoice.voucher_number }}</dd>
            </div>

            <div v-if="props.invoice?.invoiced_at" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#6f8585]">Rechnungsdatum</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b] tabular-nums">{{ formatPortalDate(props.invoice.invoiced_at) }}</dd>
            </div>

            <div v-if="amount" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#6f8585]">Reparaturkosten</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b] tabular-nums">{{ amount }}</dd>
            </div>

            <div v-if="props.payment?.paid_at" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#6f8585]">Bezahlt am</dt>
                <dd class="text-[12.5px] font-bold text-[#00856a] tabular-nums">{{ formatPortalDateTimeShort(props.payment.paid_at) }}</dd>
            </div>

            <div v-if="paymentLink && !isSettled" class="flex items-center justify-between gap-3 py-2">
                <dt class="text-[12px] font-medium text-[#6f8585]">Zahlungslink</dt>
                <dd>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-[9px] border border-[#e9efee] bg-white px-2.5 py-1 text-[11.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                        @click="copyLink"
                    >
                        <MdiContentCopy class="size-[13px]" />
                        {{ copied ? 'Kopiert' : 'Link kopieren' }}
                    </button>
                </dd>
            </div>
        </dl>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
