<script setup lang="ts">
/**
 * Admin "create a draft offer for this order", by either of the two routes an
 * offer can come from.
 *
 * **Werkstattangebot** is the real path: a submitted quotation carries a named
 * workshop's per-position prices, an immutable snapshot, and a gross derived
 * server-side. **Manuell** is the fallback — four numbers somebody typed, with
 * no provenance, which AdminOffersCard labels "Manuell erfasst".
 *
 * Both live here because the task card's "Angebot erstellen" opens this modal,
 * and opening it straight onto the manual form quietly recommended the weaker
 * of the two: an admin with three workshop quotations sitting on the same page
 * was shown eight empty price fields and no hint the quotations existed. The
 * modal now opens on the quotations whenever there are any.
 *
 * Publishing an offer stays a row action on AdminOffersCard — nothing here
 * reaches the customer until that happens.
 *
 * Manual entry: each position is entered once, and typing a net fills the gross
 * at the German standard rate and vice versa, so eight fields behave like four.
 * Only the *paired* field is ever rewritten, never the one being typed in — a
 * two-way watcher reformats mid-keystroke and makes the inputs feel broken.
 *
 * The running total is a preview of what will be stored, not an input:
 * LeasybackOffer's `saving` hook computes final_total_net/gross as the sum of
 * the four positions, so it is summed the same way here.
 */
import WorkshopQuotationComparison from '@/components/admin/WorkshopQuotationComparison.vue';
import FormField from '@/components/form/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { AdminWorkshopQuotation } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MdiCheckCircle from '~icons/mdi/check-circle';
import MdiPencilOutline from '~icons/mdi/pencil-outline';
import MdiWrenchOutline from '~icons/mdi/wrench-outline';

/**
 * Mirrors config('offers.vat_rate'), which is the authority — this copy exists
 * only so typing a net amount fills the gross beside it as you type. What gets
 * stored is whatever ends up in the two fields; nothing here derives a price
 * the server then trusts.
 */
const VAT_RATE = 0.19;

interface OfferFormFields {
    repair_cost_net: string;
    repair_cost_gross: string;
    depreciation_value_net: string;
    depreciation_value_gross: string;
    workshop_repair_quote_net: string;
    workshop_repair_quote_gross: string;
    missing_parts_cost_net: string;
    missing_parts_cost_gross: string;
    additional_notes: string;
}

type CostField = Exclude<keyof OfferFormFields, 'additional_notes'>;
type OfferSource = 'workshop' | 'manual';

const props = withDefaults(
    defineProps<{
        open: boolean;
        orderId: string;
        /** Absent where the host has no order context — the menu on the vehicle list. */
        quotations?: AdminWorkshopQuotation[];
    }>(),
    { quotations: () => [] },
);

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

/** Only a submitted quotation has prices to build an offer from. */
const submittedQuotations = computed(() => props.quotations.filter((quotation) => quotation.status === 'submitted'));

const source = ref<OfferSource>('manual');
const selectedQuotationId = ref<string | null>(null);
const comparisonOpen = ref<string | null>(null);

/**
 * The initial data is a *function*, not an object, and that is load-bearing.
 * Inertia's useForm() overwrites its stored defaults with the submitted values
 * inside onSuccess (`defaults = clone(this.data())`), so after one successful
 * create, `form.reset()` restores the previous offer's numbers instead of
 * clearing them — reopening the modal showed the last offer again. Given a
 * function, reset() re-invokes it and genuinely starts empty.
 */
const emptyOffer = (): OfferFormFields => ({
    repair_cost_net: '',
    repair_cost_gross: '',
    depreciation_value_net: '',
    depreciation_value_gross: '',
    workshop_repair_quote_net: '',
    workshop_repair_quote_gross: '',
    missing_parts_cost_net: '',
    missing_parts_cost_gross: '',
    additional_notes: '',
});

const form = useForm<OfferFormFields>(emptyOffer);

const quotationForm = useForm(() => ({ workshop_quotation_id: '', valid_until: '', customer_note: '' }));

const positions: { label: string; net: CostField; gross: CostField }[] = [
    { label: 'Reparaturkosten', net: 'repair_cost_net', gross: 'repair_cost_gross' },
    { label: 'Wertminderung', net: 'depreciation_value_net', gross: 'depreciation_value_gross' },
    { label: 'Werkstattkosten', net: 'workshop_repair_quote_net', gross: 'workshop_repair_quote_gross' },
    { label: 'Fehlteile', net: 'missing_parts_cost_net', gross: 'missing_parts_cost_gross' },
];

function toNumber(value: string): number | null {
    const parsed = Number((value ?? '').toString().replace(',', '.').trim());

    return value === '' || !Number.isFinite(parsed) ? null : parsed;
}

function round(value: number): string {
    return value.toFixed(2);
}

function onNetInput(position: (typeof positions)[number], value: string) {
    form[position.net] = value;

    const net = toNumber(value);
    form[position.gross] = net === null ? '' : round(net * (1 + VAT_RATE));
}

function onGrossInput(position: (typeof positions)[number], value: string) {
    form[position.gross] = value;

    const gross = toNumber(value);
    form[position.net] = gross === null ? '' : round(gross / (1 + VAT_RATE));
}

const totals = computed(() => {
    const sum = (fields: CostField[]) => fields.reduce((carry, field) => carry + (toNumber(form[field]) ?? 0), 0);

    return {
        net: sum(positions.map((position) => position.net)),
        gross: sum(positions.map((position) => position.gross)),
    };
});

const currency = (value: number) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value);

function formatEuro(value: string | null): string {
    const amount = value === null ? NaN : Number.parseFloat(value);

    return Number.isFinite(amount) ? currency(amount) : '—';
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE');
}

/** Every position must carry a number — the endpoint requires all eight. */
const isComplete = computed(() => positions.every((position) => toNumber(form[position.net]) !== null && toNumber(form[position.gross]) !== null));

const busy = computed(() => form.processing || quotationForm.processing);
const canSubmit = computed(() => (source.value === 'workshop' ? selectedQuotationId.value !== null : isComplete.value));

const title = computed(() => (source.value === 'workshop' ? 'Werkstattangebot übernehmen' : 'Angebot erstellen'));
const description = computed(() =>
    source.value === 'workshop'
        ? 'Wählen Sie das Werkstattangebot, das der Kunde erhalten soll. Der Entwurf wird erst durch „Veröffentlichen" sichtbar.'
        : 'Manuelles Entwurfs-Angebot ohne Werkstattbezug. Netto und brutto werden automatisch umgerechnet.',
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        quotationForm.reset();
        quotationForm.clearErrors();
        selectedQuotationId.value = null;
        comparisonOpen.value = null;

        // Open on the quotations whenever there are any: that is the path with
        // provenance, and the manual form is the fallback, not the default.
        source.value = submittedQuotations.value.length ? 'workshop' : 'manual';
    },
);

function close() {
    emit('update:open', false);
}

function select(quotationId: string) {
    selectedQuotationId.value = quotationId;
}

function submit() {
    if (source.value === 'workshop') {
        submitFromQuotation();

        return;
    }

    form.post(route('admin.orders.offers.store', props.orderId), {
        preserveScroll: true,
        onSuccess: close,
    });
}

/**
 * Creates the customer offer as a *draft*, exactly as the Werkstattangebote
 * card's own action does — same endpoint, same result. This is a second door
 * onto one workflow, never a second workflow.
 */
function submitFromQuotation() {
    if (selectedQuotationId.value === null) {
        return;
    }

    quotationForm.workshop_quotation_id = selectedQuotationId.value;
    quotationForm.post(route('admin.orders.b2b-offer.store', props.orderId), {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <AppModal :open="open" :title="title" :description="description" @update:open="(value) => emit('update:open', value)">
        <div class="flex flex-col gap-4 px-2">
            <!--
                Shown only when there is a real choice to make. With no submitted
                quotation the workshop tab would lead to an empty list, so the
                modal is simply the manual form it has always been.
            -->
            <div v-if="submittedQuotations.length" class="grid grid-cols-2 gap-1 rounded-[14px] bg-[#f4f7f6] p-1">
                <button
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-[11px] px-3 py-2 text-[12.5px] font-bold transition-colors"
                    :class="source === 'workshop' ? 'bg-white text-[#10393b] shadow-sm' : 'text-[#6f8585] hover:text-[#10393b]'"
                    @click="source = 'workshop'"
                >
                    <MdiWrenchOutline class="size-4" />
                    Werkstattangebot ({{ submittedQuotations.length }})
                </button>
                <button
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-[11px] px-3 py-2 text-[12.5px] font-bold transition-colors"
                    :class="source === 'manual' ? 'bg-white text-[#10393b] shadow-sm' : 'text-[#6f8585] hover:text-[#10393b]'"
                    @click="source = 'manual'"
                >
                    <MdiPencilOutline class="size-4" />
                    Manuell erfassen
                </button>
            </div>

            <!-- ---- from a workshop quotation ---- -->
            <div v-if="source === 'workshop'" class="flex flex-col gap-2.5">
                <InputError :message="quotationForm.errors.workshop_quotation_id" />

                <!--
                    The label covers the summary only. Nesting the comparison
                    toggle inside it would make every click on that button also
                    select the quotation — a preventDefault away from working,
                    and one refactor away from silently selecting the wrong
                    workshop's prices for a customer.
                -->
                <div
                    v-for="quotation in submittedQuotations"
                    :key="quotation.id"
                    class="rounded-[16px] border transition-colors"
                    :class="selectedQuotationId === quotation.id ? 'border-[#01B990] bg-[#01B990]/5' : 'border-[#e9efee] hover:border-[#dbe7e5]'"
                >
                    <label class="flex cursor-pointer items-start gap-3 p-3.5">
                        <input
                            type="radio"
                            name="workshop-quotation"
                            class="mt-1 size-4 shrink-0 accent-[#01b990]"
                            :checked="selectedQuotationId === quotation.id"
                            @change="select(quotation.id)"
                        />

                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-[13.5px] font-bold text-[#10393b]">
                                    {{ quotation.company_name || quotation.workshop_label }}
                                </span>
                                <span class="shrink-0 text-[15px] font-extrabold text-[#10393b] tabular-nums">
                                    {{ formatEuro(quotation.total_net) }}
                                    <span class="text-[10.5px] font-bold text-[#9bb0af]">netto</span>
                                </span>
                            </span>

                            <span class="mt-1 block text-[11.5px] text-[#6f8585]">
                                {{ quotation.processing_days ?? '—' }} Arbeitstage · ab {{ formatDate(quotation.earliest_repair_start) }} · Gutachten
                                {{ formatEuro(quotation.appraisal_total_net) }}
                            </span>

                            <span v-if="quotation.cannot_repair_for_amount" class="mt-1.5 block text-[11px] font-bold text-[#c0392b]">
                                Nicht zum angefragten Betrag durchführbar
                            </span>
                        </span>
                    </label>

                    <div class="border-t border-[#f2f6f5] px-3.5 py-2">
                        <button
                            type="button"
                            class="text-[11.5px] font-bold text-[#00856a] hover:opacity-70"
                            @click="comparisonOpen = comparisonOpen === quotation.id ? null : quotation.id"
                        >
                            {{ comparisonOpen === quotation.id ? 'Vergleich schließen' : 'Vergleich anzeigen' }}
                        </button>
                    </div>

                    <div v-if="comparisonOpen === quotation.id" class="border-t border-[#f2f6f5] p-3.5">
                        <WorkshopQuotationComparison :quotation="quotation" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-x-4 gap-y-1 md:grid-cols-2">
                    <FormField v-slot="{ id, describedBy, invalid }" label="Gültig bis (optional)" :error="quotationForm.errors.valid_until">
                        <Input :id="id" v-model="quotationForm.valid_until" type="date" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>

                    <FormField
                        v-slot="{ id, describedBy, invalid }"
                        label="Anmerkung für den Kunden (optional)"
                        :error="quotationForm.errors.customer_note"
                    >
                        <Input :id="id" v-model="quotationForm.customer_note" :aria-invalid="invalid" :aria-describedby="describedBy" />
                    </FormField>
                </div>

                <p class="flex items-start gap-1.5 rounded-[13px] bg-[#f6f9f8] px-3 py-2.5 text-[11.5px] text-[#6f8585]">
                    <MdiCheckCircle class="mt-px size-3.5 shrink-0 text-[#00856a]" />
                    Preise und Positionen werden aus dem Werkstattangebot übernommen. Der Bruttobetrag für den Kunden wird serverseitig berechnet.
                </p>
            </div>

            <!-- ---- entered by hand ---- -->
            <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
                <div class="grid grid-cols-[1fr_1fr] gap-x-4 px-1">
                    <span class="text-[11px] font-bold tracking-[0.08em] text-[#9bb0af] uppercase">Netto</span>
                    <span class="text-[11px] font-bold tracking-[0.08em] text-[#9bb0af] uppercase">Brutto (inkl. 19% MwSt.)</span>
                </div>

                <div v-for="position in positions" :key="position.net" class="grid grid-cols-1 gap-x-4 gap-y-1 md:grid-cols-2">
                    <FormField v-slot="{ id, describedBy, invalid }" :label="`${position.label} (netto)`" required :error="form.errors[position.net]">
                        <Input
                            :id="id"
                            :model-value="form[position.net]"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                            @update:model-value="(value) => onNetInput(position, String(value ?? ''))"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ id, describedBy, invalid }"
                        :label="`${position.label} (brutto)`"
                        required
                        :error="form.errors[position.gross]"
                    >
                        <Input
                            :id="id"
                            :model-value="form[position.gross]"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            :aria-invalid="invalid"
                            :aria-describedby="describedBy"
                            @update:model-value="(value) => onGrossInput(position, String(value ?? ''))"
                        />
                    </FormField>
                </div>

                <div class="flex items-center justify-between gap-4 rounded-2xl bg-[#f6f9f8] px-4 py-3">
                    <div>
                        <p class="text-[13px] font-bold text-[#10393b]">Gesamtsumme</p>
                        <p class="mt-0.5 text-[11.5px] text-[#6f8585]">Wird aus den vier Positionen berechnet.</p>
                    </div>

                    <div class="text-right">
                        <p class="text-[17px] font-extrabold text-[#10393b] tabular-nums">{{ currency(totals.gross) }}</p>
                        <p class="mt-0.5 text-[11.5px] text-[#6f8585] tabular-nums">{{ currency(totals.net) }} netto</p>
                    </div>
                </div>

                <FormField v-slot="{ id, describedBy, invalid }" label="Anmerkungen" :error="form.errors.additional_notes">
                    <Input :id="id" v-model="form.additional_notes" :aria-invalid="invalid" :aria-describedby="describedBy" />
                </FormField>
            </form>
        </div>

        <template #footer>
            <AppModalButton :disabled="!canSubmit || busy" @click="submit">
                <template v-if="busy">Wird erstellt...</template>
                <template v-else-if="source === 'workshop'">Als Kundenangebot erstellen</template>
                <template v-else>Erstellen</template>
            </AppModalButton>
        </template>
    </AppModal>
</template>
