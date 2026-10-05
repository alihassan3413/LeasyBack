<script setup lang="ts">
/**
 * The workshop's quotation form (b2b.txt §9). Reachable logged out on purpose:
 * a workshop has no portal account, only a time-limited link.
 *
 * Net amounts only — there is no gross field, by design. When the admin chose
 * not to disclose the appraisal amounts, `requested_amount_net` arrives null
 * and the column simply is not rendered.
 */
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import PhoneInput from '@/components/form/PhoneInput.vue';
import RequiredMark from '@/components/form/RequiredMark.vue';
import InputError from '@/components/InputError.vue';
import DamageGallery from '@/components/shared/DamageGallery.vue';
import { Input } from '@/components/ui/input';
import AdditionalDamageCard from '@/components/workshop/AdditionalDamageCard.vue';
import WorkshopDocumentActions from '@/components/workshop/WorkshopDocumentActions.vue';
import { formatPortalDate } from '@/lib/portalDate';
import type { AdditionalDamageDraft, DamageGalleryImage } from '@/types/order';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiPlus from '~icons/mdi/plus';

interface QuotationPosition {
    id: string;
    component: string;
    damage_description: string | null;
    repair_method: string | null;
    requested_amount_net: string | null;
    images: DamageGalleryImage[];
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
        max_additional_images: number;
        max_additional_images_total: number;
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
    additional_positions: [] as AdditionalDamageDraft[],
});

function addAdditionalPosition() {
    form.additional_positions.push({ component: '', damage_description: '', repair_method: '', amount_net: '', images: [] });
}

/**
 * PHP's max_file_uploads silently drops files past its limit, so the server
 * refuses a submission carrying more images than `max_additional_images_total`.
 * Spending that allowance here as well means the workshop is stopped at the
 * file picker instead of after uploading photos that were never going to be
 * accepted. Each card is offered whatever is left plus what it already holds.
 */
function imageAllowanceFor(index: number): number {
    const used = form.additional_positions.reduce((sum, position, other) => (other === index ? sum : sum + position.images.length), 0);

    return Math.max(0, Math.min(props.quotation.max_additional_images, props.quotation.max_additional_images_total - used));
}

function removeAdditionalPosition(index: number) {
    for (const image of form.additional_positions[index]?.images ?? []) {
        URL.revokeObjectURL(image.preview);
    }

    form.additional_positions.splice(index, 1);
}

function additionalError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`additional_positions.${index}.${field}`];
}

/**
 * Refusals about the set as a whole — the combined upload budget — which no
 * single field owns, so they would otherwise never be shown.
 */
const additionalPositionsError = computed(() => (form.errors as Record<string, string | undefined>).additional_positions ?? null);

const totalNet = computed(() =>
    [...form.items.filter((item) => !item.not_repairable), ...form.additional_positions].reduce((sum, row) => {
        const amount = Number.parseFloat(row.amount_net);

        return Number.isFinite(amount) ? sum + amount : sum;
    }, 0),
);

const showsAmounts = computed(() => props.quotation.shows_appraisal_amounts);

/**
 * The PDF is printed from the form as it stands, unsent prices included, so it
 * is posted to the draft endpoint. Photos picked for additional damage are not
 * sent — they only exist on the server once the quotation is submitted.
 */
const pdfUrl = computed(() => route('workshop.quotations.pdf.draft', props.token));

function pdfDraft(): Record<string, unknown> {
    return {
        company_name: form.company_name,
        contact_person: form.contact_person,
        earliest_repair_start: form.earliest_repair_start === '' ? null : form.earliest_repair_start,
        processing_days: form.processing_days === '' ? null : form.processing_days,
        cannot_repair_for_amount: form.cannot_repair_for_amount,
        cannot_repair_note: form.cannot_repair_note,
        items: form.items,
        additional_positions: form.additional_positions.map((position) => ({
            component: position.component,
            damage_description: position.damage_description,
            repair_method: position.repair_method,
            amount_net: position.amount_net,
        })),
    };
}

function formatEuro(value: number | string | null): string {
    const amount = typeof value === 'string' ? Number.parseFloat(value) : value;

    return amount !== null && Number.isFinite(amount)
        ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount as number)
        : '—';
}

function formatDate(value: string | null): string {
    return formatPortalDate(value) || '—';
}

function itemError(index: number, field: string): string | undefined {
    return form.errors[`items.${index}.${field}` as keyof typeof form.errors] as string | undefined;
}

/**
 * The whole-list refusal ("at least one price") — keyed `items`, so no field
 * owns it. Shown beside the positions and again at the submit button, which is
 * where the workshop is looking when the send is refused.
 */
const itemsError = computed(() => (form.errors as Record<string, string | undefined>).items ?? null);

function submit() {
    // The drafts carry a preview URL for the thumbnails; only the File itself
    // is uploaded. Inertia switches to multipart on its own once it sees one.
    form.transform((data) => ({
        ...data,
        processing_days: data.processing_days === '' ? null : data.processing_days,
        earliest_repair_start: data.earliest_repair_start === '' ? null : data.earliest_repair_start,
        additional_positions: data.additional_positions.map((position) => ({
            component: position.component,
            damage_description: position.damage_description,
            repair_method: position.repair_method,
            amount_net: position.amount_net,
            images: position.images.map((image) => image.file),
        })),
    })).post(route('workshop.quotations.submit', props.token));
}
</script>

<template>
    <Head title="Reparaturangebot abgeben" />

    <div class="min-h-screen bg-[#f6f9f8] px-4 py-10">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
            <header class="rounded-3xl border border-[#ececec] bg-white p-6">
                <p class="text-[12px] font-bold tracking-wide text-[#9bb0af] uppercase">Leasyback · Reparaturanfrage</p>
                <h1 class="mt-1 text-[22px] font-extrabold tracking-[-0.4px] text-[#10393b]">Angebot abgeben</h1>
                <p class="mt-2 text-[13px] text-[#6f8585]">
                    Bitte geben Sie Ihre Nettopreise je Position an. Der Link ist gültig bis
                    <strong>{{ formatDate(quotation.expires_at) }}</strong
                    >.
                </p>

                <WorkshopDocumentActions class="mt-4" :pdf-url="pdfUrl" :draft="pdfDraft" />
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
                            <label class="text-[12px] font-bold text-[#10393b]">Firma<RequiredMark /></label>
                            <Input v-model="form.company_name" />
                            <InputError :message="form.errors.company_name" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Ansprechpartner<RequiredMark /></label>
                            <Input v-model="form.contact_person" />
                            <InputError :message="form.errors.contact_person" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">E-Mail<RequiredMark /></label>
                            <Input v-model="form.contact_email" type="email" />
                            <InputError :message="form.errors.contact_email" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Telefon</label>
                            <PhoneInput v-model="form.contact_phone" />
                            <InputError :message="form.errors.contact_phone" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Frühester Reparaturbeginn</label>
                            <CalendarDateField v-model="form.earliest_repair_start" :invalid="!!form.errors.earliest_repair_start" />
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
                    <h2 class="mb-1 text-[15px] font-extrabold text-[#10393b]">Gutachtenpositionen</h2>
                    <p class="mb-3 text-[12px] text-[#9bb0af]">Alle Beträge netto in Euro.</p>

                    <p
                        v-if="itemsError"
                        role="alert"
                        class="mb-3 rounded-[13px] border border-[#c0392b]/25 bg-[#c0392b]/5 px-3 py-2.5 text-[12.5px] font-bold text-[#c0392b]"
                    >
                        {{ itemsError }}
                    </p>

                    <p v-if="!quotation.positions.length" class="py-8 text-center text-[13px] text-[#9bb0af]">
                        Für diesen Auftrag sind keine Positionen hinterlegt.
                    </p>

                    <div v-else class="flex flex-col gap-3">
                        <div v-for="(position, index) in quotation.positions" :key="position.id" class="rounded-[13px] border border-[#e9efee] p-3">
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

                            <div v-if="position.images?.length" class="mb-3">
                                <p class="mb-1.5 text-[11px] text-[#9bb0af]">Schadenbilder ({{ position.images.length }})</p>
                                <DamageGallery :images="position.images" :label="`Schadenbilder zu Position ${index + 1}: ${position.component}`" />
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
                            <InputError :message="itemError(index, 'not_repairable')" />
                            <InputError :message="itemError(index, 'appraisal_position_id')" />
                        </div>
                    </div>

                    <div class="mt-6 border-t border-dashed border-[#e9efee] pt-5" data-testid="additional-damages">
                        <h3 class="text-[15px] font-extrabold text-[#10393b]">Zusätzliche Schäden</h3>
                        <p class="mb-3 text-[12px] text-[#9bb0af]">
                            Von der Werkstatt festgestellt — nicht Teil des Gutachtens. Bitte nur Schäden melden, die oben nicht aufgeführt sind.
                        </p>

                        <p
                            v-if="additionalPositionsError"
                            role="alert"
                            class="mb-3 rounded-[13px] border border-[#c0392b]/25 bg-[#c0392b]/5 px-3 py-2.5 text-[12.5px] font-bold text-[#c0392b]"
                            data-testid="additional-damages-error"
                        >
                            {{ additionalPositionsError }}
                        </p>

                        <div v-if="form.additional_positions.length" class="mb-3 flex flex-col gap-3">
                            <AdditionalDamageCard
                                v-for="(position, index) in form.additional_positions"
                                :key="index"
                                v-model:position="form.additional_positions[index]"
                                :index="index"
                                :max-images="imageAllowanceFor(index)"
                                :disabled="form.processing"
                                :error="(field) => additionalError(index, field)"
                                @remove="removeAdditionalPosition(index)"
                            />
                        </div>

                        <button
                            type="button"
                            :disabled="form.processing"
                            class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-[13px] border border-dashed border-[#c9d6d5] bg-white px-4 py-3 text-[13px] font-bold text-[#10393b] transition-colors hover:border-[#01b990] hover:text-[#01b990] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] disabled:cursor-default disabled:opacity-50 motion-reduce:transition-none"
                            data-testid="additional-damage-add"
                            @click="addAdditionalPosition"
                        >
                            <MdiPlus class="size-5" aria-hidden="true" />
                            Zusätzlichen Schaden melden
                        </button>
                    </div>

                    <div
                        v-if="quotation.positions.length || form.additional_positions.length"
                        class="mt-4 flex items-center justify-between rounded-[13px] bg-[#f6f9f8] px-4 py-3"
                    >
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

                <p v-if="itemsError" role="alert" class="self-end text-right text-[12.5px] font-bold text-[#c0392b]">
                    {{ itemsError }}
                </p>

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
