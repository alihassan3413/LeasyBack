<script setup lang="ts">
/**
 * Workshop quotations for one B2B order (b2b.txt §9): issue a revocable link,
 * then compare what came back against the appraisal, position by position.
 *
 * Submitted quotations stay listed after one has been presented or accepted,
 * as §9 requires — nothing here removes a row.
 *
 * The generated link is shown once, right after creation: only its hash is
 * stored, so it cannot be displayed again later.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import type { AdminWorkshopQuotation } from '@/types/admin';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiContentCopy from '~icons/mdi/content-copy';
import MdiLinkVariant from '~icons/mdi/link-variant';

const props = defineProps<{ orderId: string; quotations: AdminWorkshopQuotation[]; hasPositions: boolean }>();

const page = usePage();
const expanded = ref<string | null>(null);
const copied = ref(false);

const form = useForm({
    workshop_label: '',
    invited_email: '',
    show_appraisal_amounts: true,
    ttl_days: 14,
});

const offerForm = useForm({ workshop_quotation_id: '', valid_until: '', customer_note: '' });

const issuedLink = computed(() => (page.props.flash as Record<string, string | undefined> | undefined)?.workshop_link ?? null);

const statusStyles: Record<string, { label: string; class: string }> = {
    invited: { label: 'Offen', class: 'bg-[#f4f7f6] text-[#6f8585]' },
    submitted: { label: 'Angebot eingegangen', class: 'bg-[#01B990]/10 text-[#00856a]' },
    expired: { label: 'Abgelaufen', class: 'bg-[#f4f7f6] text-[#9bb0af]' },
    revoked: { label: 'Widerrufen', class: 'bg-[#c0392b]/10 text-[#c0392b]' },
};

function formatEuro(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const amount = Number.parseFloat(value);

    return Number.isFinite(amount) ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount) : '—';
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE');
}

function toggle(id: string) {
    expanded.value = expanded.value === id ? null : id;
}

function submit() {
    form.post(route('admin.orders.workshop-quotations.store', props.orderId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function revoke(quotationId: string) {
    router.delete(route('admin.orders.workshop-quotations.revoke', quotationId), { preserveScroll: true });
}

/**
 * Creates the customer offer as a *draft*. It only becomes visible to the
 * customer through the existing publish action on the Angebote card, which is
 * also the moment the presented lines are snapshotted.
 */
function createOffer(quotationId: string) {
    offerForm.workshop_quotation_id = quotationId;
    offerForm.post(route('admin.orders.b2b-offer.store', props.orderId), { preserveScroll: true });
}

async function copyLink(link: string) {
    await navigator.clipboard.writeText(link);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiLinkVariant class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Werkstattangebote</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">{{ quotations.length }} Anfragen · Nettopreise</p>
            </div>
        </div>

        <div v-if="issuedLink" class="mb-4 rounded-[13px] border border-[#01B990]/30 bg-[#01B990]/5 p-3">
            <p class="text-[12px] font-bold text-[#00856a]">Link erstellt — jetzt kopieren, er wird nur einmal angezeigt.</p>
            <div class="mt-2 flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate rounded-[9px] bg-white px-2 py-1.5 text-[11.5px] text-[#10393b]">{{ issuedLink }}</code>
                <button
                    type="button"
                    class="flex h-8 shrink-0 items-center gap-1 rounded-[9px] bg-[#10393b] px-2.5 text-[11.5px] font-bold text-white hover:opacity-90"
                    @click="copyLink(issuedLink)"
                >
                    <MdiContentCopy class="size-[13px]" />
                    {{ copied ? 'Kopiert' : 'Kopieren' }}
                </button>
            </div>
        </div>

        <p v-if="!hasPositions" class="mb-4 rounded-[13px] bg-[#f6f9f8] px-3 py-3 text-[12px] text-[#9bb0af]">
            Erfassen Sie zuerst die Gutachtenpositionen — eine Werkstatt kann sonst nichts bepreisen.
        </p>

        <form class="mb-4 flex flex-col gap-2 rounded-[13px] border border-[#e9efee] p-3" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Werkstatt</label>
                <Input v-model="form.workshop_label" placeholder="Name der Werkstatt" />
                <InputError :message="form.errors.workshop_label" />
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">E-Mail (optional)</label>
                    <Input v-model="form.invited_email" type="email" />
                    <InputError :message="form.errors.invited_email" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Gültig (Tage)</label>
                    <Input v-model="form.ttl_days" type="number" min="1" max="90" />
                    <InputError :message="form.errors.ttl_days" />
                </div>
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-[12px] text-[#10393b]">
                <input v-model="form.show_appraisal_amounts" type="checkbox" class="size-3.5 accent-[#01b990]" />
                Gutachtenbeträge für die Werkstatt sichtbar
            </label>

            <button
                type="submit"
                :disabled="form.processing || !hasPositions"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2 text-[12.5px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Erstellt...' : 'Link erstellen' }}
            </button>
        </form>

        <p v-if="!quotations.length" class="py-6 text-center text-[12.5px] text-[#9bb0af]">Noch keine Werkstattanfragen.</p>

        <div v-else class="flex flex-col gap-2">
            <div v-for="quotation in quotations" :key="quotation.id" class="rounded-[13px] border border-[#e9efee]">
                <div class="flex items-center gap-2 p-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[13px] font-bold text-[#10393b]">
                            {{ quotation.company_name || quotation.workshop_label }}
                        </p>
                        <p class="mt-0.5 text-[11.5px] text-[#6f8585]">
                            <template v-if="quotation.status === 'submitted'">
                                {{ formatEuro(quotation.total_net) }} · {{ quotation.processing_days ?? '—' }} AT · ab
                                {{ formatDate(quotation.earliest_repair_start) }}
                            </template>
                            <template v-else> gültig bis {{ formatDate(quotation.expires_at) }} </template>
                        </p>
                    </div>

                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold" :class="statusStyles[quotation.status].class">
                        {{ statusStyles[quotation.status].label }}
                    </span>
                </div>

                <div class="flex items-center gap-2 border-t border-[#f2f6f5] px-3 py-2">
                    <button
                        v-if="quotation.status === 'submitted'"
                        type="button"
                        class="text-[11.5px] font-bold text-[#00856a] hover:opacity-70"
                        @click="toggle(quotation.id)"
                    >
                        {{ expanded === quotation.id ? 'Vergleich schließen' : 'Vergleich anzeigen' }}
                    </button>

                    <button
                        v-if="quotation.status === 'invited'"
                        type="button"
                        class="text-[11.5px] font-bold text-[#c0392b] hover:opacity-70"
                        @click="revoke(quotation.id)"
                    >
                        Link widerrufen
                    </button>

                    <button
                        v-if="quotation.status === 'submitted'"
                        type="button"
                        :disabled="offerForm.processing"
                        class="text-[11.5px] font-bold text-[#10393b] hover:opacity-70 disabled:opacity-50"
                        @click="createOffer(quotation.id)"
                    >
                        Als Kundenangebot übernehmen
                    </button>

                    <span v-if="quotation.cannot_repair_for_amount" class="ml-auto text-[11px] font-bold text-[#c0392b]">
                        Nicht zum angefragten Betrag durchführbar
                    </span>
                </div>

                <div v-if="expanded === quotation.id" class="border-t border-[#f2f6f5] p-3">
                    <div v-if="quotation.contact_person || quotation.contact_email" class="mb-3 text-[11.5px] text-[#6f8585]">
                        {{ quotation.contact_person }} · {{ quotation.contact_email }}
                        <template v-if="quotation.contact_phone"> · {{ quotation.contact_phone }}</template>
                    </div>

                    <p v-if="quotation.cannot_repair_note" class="mb-3 rounded-[9px] bg-[#c0392b]/5 px-2.5 py-2 text-[11.5px] text-[#c0392b]">
                        {{ quotation.cannot_repair_note }}
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[420px] border-collapse text-left">
                            <thead>
                                <tr class="border-b border-[#e9efee]">
                                    <th class="py-1.5 pr-2 text-[11px] font-bold text-[#9bb0af]">Position</th>
                                    <th class="py-1.5 pr-2 text-right text-[11px] font-bold text-[#9bb0af]">Gutachten</th>
                                    <th class="py-1.5 pr-2 text-right text-[11px] font-bold text-[#9bb0af]">Werkstatt</th>
                                    <th class="py-1.5 text-right text-[11px] font-bold text-[#9bb0af]">Differenz</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in quotation.comparison" :key="row.appraisal_position_id" class="border-b border-[#f6f9f8]">
                                    <td class="py-1.5 pr-2 text-[11.5px] text-[#10393b]">
                                        {{ row.component }}
                                        <span v-if="row.not_repairable" class="text-[10.5px] font-bold text-[#c0392b]"> · n. instandsetzbar</span>
                                        <span v-if="row.repair_method" class="block text-[10.5px] text-[#9bb0af]">{{ row.repair_method }}</span>
                                    </td>
                                    <td class="py-1.5 pr-2 text-right text-[11.5px] text-[#6f8585]">{{ formatEuro(row.appraisal_amount_net) }}</td>
                                    <td class="py-1.5 pr-2 text-right text-[11.5px] font-bold text-[#10393b]">
                                        {{ formatEuro(row.workshop_amount_net) }}
                                    </td>
                                    <td
                                        class="py-1.5 text-right text-[11.5px] font-bold"
                                        :class="row.difference_net && Number.parseFloat(row.difference_net) >= 0 ? 'text-[#00856a]' : 'text-[#c0392b]'"
                                    >
                                        {{ formatEuro(row.difference_net) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td class="py-2 pr-2 text-[11.5px] font-extrabold text-[#10393b]">Summe</td>
                                    <td class="py-2 pr-2 text-right text-[11.5px] font-bold text-[#6f8585]">
                                        {{ formatEuro(quotation.appraisal_total_net) }}
                                    </td>
                                    <td class="py-2 pr-2 text-right text-[11.5px] font-extrabold text-[#10393b]">
                                        {{ formatEuro(quotation.total_net) }}
                                    </td>
                                    <td class="py-2 text-right text-[11.5px] font-extrabold text-[#00856a]"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
