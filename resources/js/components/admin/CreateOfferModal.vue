<script setup lang="ts">
/**
 * Admin "create a draft offer for this order" — mirrors
 * UploadReportDocumentModal.vue's form/dialog pattern. Publishing/cancelling
 * an existing offer is a row action on AdminOffersCard, not part of this
 * modal.
 *
 * Each position is entered once: typing a net fills the gross at the German
 * standard VAT rate and vice versa, so eight fields become four. Only the
 * *paired* field is ever rewritten, never the one being typed in — a
 * two-way watcher reformats the value mid-keystroke and makes the inputs
 * feel broken (the same trap v1's modal documents).
 *
 * The running total is a preview of what will be stored, not an input:
 * LeasybackOffer's `saving` hook computes final_total_net/gross as the sum
 * of the four positions, so it is deliberately summed the same way here
 * rather than derived from the net total.
 */
import FormField from '@/components/form/FormField.vue';
import { Input } from '@/components/ui/input';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

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

const props = defineProps<{ open: boolean; orderId: string }>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

/**
 * The initial data is a *function*, not an object, and that is load-bearing.
 * Inertia's useForm() overwrites its stored defaults with the submitted
 * values inside onSuccess (`defaults = clone(this.data())`), so after one
 * successful create, `form.reset()` restores the previous offer's numbers
 * instead of clearing them — reopening the modal showed the last offer
 * again. Given a function, reset() re-invokes it and genuinely starts empty.
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

/** Every position must carry a number — the endpoint requires all eight. */
const isComplete = computed(() => positions.every((position) => toNumber(form[position.net]) !== null && toNumber(form[position.gross]) !== null));

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        form.reset();
        form.clearErrors();
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    form.post(route('admin.orders.offers.store', props.orderId), {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <AppModal
        :open="open"
        title="Angebot erstellen"
        description="Neues Entwurfs-Angebot für diesen Auftrag. Netto und brutto werden automatisch umgerechnet."
        @update:open="(value) => emit('update:open', value)"
    >
        <form @submit.prevent="submit">
            <div class="flex flex-col gap-3 px-2">
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
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="!isComplete || form.processing" @click="submit">
                {{ form.processing ? 'Wird erstellt...' : 'Erstellen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>
