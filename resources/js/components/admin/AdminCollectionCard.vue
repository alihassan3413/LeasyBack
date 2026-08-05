<script setup lang="ts">
/**
 * B2B collection appointment: the customer's requested date and address as
 * submitted, plus Admin's confirmation. Requested and confirmed dates stay
 * separate — confirming copies the requested date into the confirmed field
 * rather than overwriting it, so the original wish is always visible.
 *
 * Rendered only for B2B orders; the server refuses the endpoint for a B2C
 * order regardless of what this card offers.
 */
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import type { OrderCollectionData } from '@/types/order';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiTruckOutline from '~icons/mdi/truck-outline';

const props = defineProps<{ orderId: string; collection: OrderCollectionData | null }>();

const form = useForm(() => ({
    confirmed_collection_date: props.collection?.confirmed_collection_date ?? '',
    internal_note: props.collection?.internal_note ?? '',
    collection_address: {
        street: props.collection?.collection_address?.street ?? '',
        number: props.collection?.collection_address?.number ?? '',
        additional_address: props.collection?.collection_address?.additional_address ?? '',
        zip_code: props.collection?.collection_address?.zip_code ?? '',
        city: props.collection?.collection_address?.city ?? '',
        country: props.collection?.collection_address?.country ?? '',
    },
}));

const requestedDate = computed(() => props.collection?.requested_collection_date ?? null);

const canAdoptRequested = computed(() => requestedDate.value !== null && form.confirmed_collection_date !== requestedDate.value);

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function adoptRequested() {
    form.confirmed_collection_date = requestedDate.value ?? '';
}

function submit() {
    form.patch(route('admin.orders.collection', props.orderId), { preserveScroll: true });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiTruckOutline class="size-[17px]" />
            </span>
            <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Abholung</h2>
        </div>

        <dl class="mb-4 flex flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Wunschtermin Kunde</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b]">{{ formatDate(requestedDate) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3 py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Hinweis Kunde</dt>
                <dd class="text-right text-[12.5px] font-bold text-[#10393b]">{{ collection?.collection_note || '—' }}</dd>
            </div>
        </dl>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Bestätigter Abholtermin</label>
                <CalendarDateField v-model="form.confirmed_collection_date" allow-past :invalid="!!form.errors.confirmed_collection_date" />
                <InputError :message="form.errors.confirmed_collection_date" />
                <button
                    v-if="canAdoptRequested"
                    type="button"
                    class="self-start text-[11.5px] font-bold text-[#00856a] hover:opacity-70"
                    @click="adoptRequested"
                >
                    Wunschtermin übernehmen
                </button>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Straße</label>
                    <Input v-model="form.collection_address.street" />
                    <InputError :message="form.errors['collection_address.street']" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Hausnummer</label>
                    <Input v-model="form.collection_address.number" />
                    <InputError :message="form.errors['collection_address.number']" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Adresszusatz</label>
                    <Input v-model="form.collection_address.additional_address" />
                    <InputError :message="form.errors['collection_address.additional_address']" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">PLZ</label>
                    <Input v-model="form.collection_address.zip_code" />
                    <InputError :message="form.errors['collection_address.zip_code']" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Ort</label>
                    <Input v-model="form.collection_address.city" />
                    <InputError :message="form.errors['collection_address.city']" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Land</label>
                    <Input v-model="form.collection_address.country" />
                    <InputError :message="form.errors['collection_address.country']" />
                </div>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Interne Notiz</label>
                <textarea
                    v-model="form.internal_note"
                    rows="3"
                    class="w-full resize-none rounded-[13px] border border-[#e9efee] px-3 py-2 text-[12.5px] outline-none focus:border-[#01b990]"
                    placeholder="Nur für Leasyback sichtbar..."
                />
                <InputError :message="form.errors.internal_note" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Speichert...' : 'Abholung speichern' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
