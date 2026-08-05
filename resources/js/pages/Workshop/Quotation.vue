<script setup lang="ts">
/**
 * The workshop's quotation form (b2b.txt §9). Reachable logged out on purpose:
 * a workshop has no portal account, only a time-limited link.
 *
 * Net amounts only — there is no gross field, by design. When the admin chose
 * not to disclose the appraisal amounts, `requested_amount_net` arrives null
 * and the column simply is not rendered.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface QuotationPosition {
    id: string;
    component: string;
    damage_description: string | null;
    repair_method: string | null;
    requested_amount_net: string | null;
}

interface QuotationVehicle {
    license_plate: string | null;
    make: string | null;
    model: string | null;
    vin: string | null;
    first_registration_date: string | null;
    mileage: number | null;
}

const props = defineProps<{
    token: string;
    quotation: {
        workshop_label: string;
        expires_at: string | null;
        shows_appraisal_amounts: boolean;
        vehicle: QuotationVehicle | null;
        positions: QuotationPosition[];
    };
}>();

const form = useForm({
    company_name: '',
    contact_person: '',
    contact_email: '',
    contact_phone: '',
    earliest_repair_start: '',
    processing_days: '',
    cannot_repair_for_amount: false,
    cannot_repair_note: '',
    items: props.quotation.positions.map((position) => ({
        appraisal_position_id: position.id,
        amount_net: '',
        repair_method: position.repair_method ?? '',
        not_repairable: false,
    })),
});

const totalNet = computed(() =>
    form.items.reduce((sum, item) => {
        if (item.not_repairable) {
            return sum;
        }

        const amount = Number.parseFloat(item.amount_net);

        return Number.isFinite(amount) ? sum + amount : sum;
    }, 0),
);

const showsAmounts = computed(() => props.quotation.shows_appraisal_amounts);

function formatEuro(value: number | string | null): string {
    const amount = typeof value === 'string' ? Number.parseFloat(value) : value;

    return amount !== null && Number.isFinite(amount)
        ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount as number)
        : '—';
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE');
}

function itemError(index: number, field: string): string | undefined {
    return form.errors[`items.${index}.${field}` as keyof typeof form.errors] as string | undefined;
}

function submit() {
    form.transform((data) => ({
        ...data,
        processing_days: data.processing_days === '' ? null : data.processing_days,
        earliest_repair_start: data.earliest_repair_start === '' ? null : data.earliest_repair_start,
    })).post(route('workshop.quotations.submit', props.token));
}
</script>

<template>
    <Head title="Reparaturangebot abgeben" />

    <div class="min-h-screen bg-[#f6f9f8] px-4 py-10">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
            <header class="rounded-3xl border border-[#ececec] bg-white p-6">
                <p class="text-[12px] font-bold uppercase tracking-wide text-[#9bb0af]">Leasyback · Reparaturanfrage</p>
                <h1 class="mt-1 text-[22px] font-extrabold tracking-[-0.4px] text-[#10393b]">Angebot abgeben</h1>
                <p class="mt-2 text-[13px] text-[#6f8585]">
                    Bitte geben Sie Ihre Nettopreise je Position an. Der Link ist gültig bis
                    <strong>{{ formatDate(quotation.expires_at) }}</strong
                    >.
                </p>
            </header>

            <section v-if="quotation.vehicle" class="rounded-3xl border border-[#ececec] bg-white p-6">
                <h2 class="mb-3 text-[15px] font-extrabold text-[#10393b]">Fahrzeug</h2>
                <dl class="grid grid-cols-2 gap-x-6 max-[560px]:grid-cols-1">
                    <div class="flex justify-between border-b border-[#f2f6f5] py-2">
                        <dt class="text-[12.5px] text-[#9bb0af]">Kennzeichen</dt>
                        <dd class="text-[12.5px] font-bold text-[#10393b]">{{ quotation.vehicle.license_plate || '—' }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-[#f2f6f5] py-2">
                        <dt class="text-[12.5px] text-[#9bb0af]">Fahrzeug</dt>
                        <dd class="text-[12.5px] font-bold text-[#10393b]">
                            {{ [quotation.vehicle.make, quotation.vehicle.model].filter(Boolean).join(' ') || '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between border-b border-[#f2f6f5] py-2">
                        <dt class="text-[12.5px] text-[#9bb0af]">FIN</dt>
                        <dd class="text-[12.5px] font-bold text-[#10393b]">{{ quotation.vehicle.vin || '—' }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-[#f2f6f5] py-2">
                        <dt class="text-[12.5px] text-[#9bb0af]">Kilometerstand</dt>
                        <dd class="text-[12.5px] font-bold text-[#10393b]">
                            {{ quotation.vehicle.mileage != null ? `${quotation.vehicle.mileage.toLocaleString('de-DE')} km` : '—' }}
                        </dd>
                    </div>
                </dl>
            </section>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <section class="rounded-3xl border border-[#ececec] bg-white p-6">
                    <h2 class="mb-3 text-[15px] font-extrabold text-[#10393b]">Ihre Kontaktdaten</h2>
                    <div class="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1">
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Firma *</label>
                            <Input v-model="form.company_name" />
                            <InputError :message="form.errors.company_name" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Ansprechpartner *</label>
                            <Input v-model="form.contact_person" />
                            <InputError :message="form.errors.contact_person" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">E-Mail *</label>
                            <Input v-model="form.contact_email" type="email" />
                            <InputError :message="form.errors.contact_email" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Telefon</label>
                            <Input v-model="form.contact_phone" />
                            <InputError :message="form.errors.contact_phone" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Frühester Reparaturbeginn</label>
                            <Input v-model="form.earliest_repair_start" type="date" />
                            <InputError :message="form.errors.earliest_repair_start" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Bearbeitungsdauer (Arbeitstage)</label>
                            <Input v-model="form.processing_days" type="number" min="0" step="1" />
                            <InputError :message="form.errors.processing_days" />
                        </div>
                    </div>
                </section>

                <section class="rounded-3xl border border-[#ececec] bg-white p-6">
                    <h2 class="mb-1 text-[15px] font-extrabold text-[#10393b]">Positionen</h2>
                    <p class="mb-3 text-[12px] text-[#9bb0af]">Alle Beträge netto in Euro.</p>

                    <p v-if="!quotation.positions.length" class="py-8 text-center text-[13px] text-[#9bb0af]">
                        Für diesen Auftrag sind keine Positionen hinterlegt.
                    </p>

                    <div v-else class="flex flex-col gap-3">
                        <div
                            v-for="(position, index) in quotation.positions"
                            :key="position.id"
                            class="rounded-[13px] border border-[#e9efee] p-3"
                        >
                            <div class="mb-2 flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[13px] font-bold text-[#10393b]">{{ index + 1 }}. {{ position.component }}</p>
                                    <p v-if="position.damage_description" class="mt-0.5 text-[12px] text-[#6f8585]">
                                        {{ position.damage_description }}
                                    </p>
                                </div>
                                <div v-if="showsAmounts" class="shrink-0 text-right">
                                    <p class="text-[11px] text-[#9bb0af]">Angefragt netto</p>
                                    <p class="text-[12.5px] font-bold text-[#10393b]">{{ formatEuro(position.requested_amount_net) }}</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2 max-[560px]:grid-cols-1">
                                <div class="flex flex-col gap-1">
                                    <label class="text-[12px] font-bold text-[#10393b]">Ihr Preis netto (€)</label>
                                    <Input
                                        v-model="form.items[index].amount_net"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        inputmode="decimal"
                                        :disabled="form.items[index].not_repairable"
                                    />
                                    <InputError :message="itemError(index, 'amount_net')" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-[12px] font-bold text-[#10393b]">Reparaturweg</label>
                                    <Input v-model="form.items[index].repair_method" />
                                    <InputError :message="itemError(index, 'repair_method')" />
                                </div>
                            </div>

                            <label class="mt-2 flex cursor-pointer items-center gap-2 text-[12px] text-[#10393b]">
                                <input v-model="form.items[index].not_repairable" type="checkbox" class="size-3.5 accent-[#01b990]" />
                                Diese Position kann nicht instand gesetzt werden
                            </label>
                        </div>
                    </div>

                    <div v-if="quotation.positions.length" class="mt-4 flex items-center justify-between rounded-[13px] bg-[#f6f9f8] px-4 py-3">
                        <span class="text-[13px] font-bold text-[#6f8585]">Gesamtsumme netto</span>
                        <span class="text-[15px] font-extrabold text-[#10393b]">{{ formatEuro(totalNet) }}</span>
                    </div>
                </section>

                <section class="rounded-3xl border border-[#ececec] bg-white p-6">
                    <label class="flex cursor-pointer items-start gap-2 text-[13px] text-[#10393b]">
                        <input v-model="form.cannot_repair_for_amount" type="checkbox" class="mt-0.5 size-4 accent-[#01b990]" />
                        <span>Die Reparatur ist zum angefragten Betrag nicht durchführbar.</span>
                    </label>

                    <textarea
                        v-if="form.cannot_repair_for_amount"
                        v-model="form.cannot_repair_note"
                        rows="3"
                        class="mt-3 w-full resize-none rounded-[13px] border border-[#e9efee] px-3 py-2 text-[12.5px] outline-none focus:border-[#01b990]"
                        placeholder="Begründung (optional)"
                    />
                    <InputError :message="form.errors.cannot_repair_note" />
                </section>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="self-end rounded-[13px] bg-[#10393b] px-6 py-3 text-[14px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                >
                    {{ form.processing ? 'Wird gesendet...' : 'Angebot verbindlich senden' }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
