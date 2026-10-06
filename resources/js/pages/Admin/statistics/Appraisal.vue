<script setup lang="ts">
/**
 * Statistics for Gutachten orders (Vehicle Condition Appraisal brief): total
 * and the counts for Requested, Scheduled and Completed, filtered by date
 * range, company, vehicle/registration number and status. Totals update when
 * a filter changes. No charts.
 */
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    totals: { total: number; requested: number; scheduled: number; completed: number };
    filters: { start_date: string; end_date: string; company: string; vehicle: string; status: string };
    companies: { id: string; name: string }[];
}>();

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const company = ref(props.filters.company);
const vehicle = ref(props.filters.vehicle);
const status = ref(props.filters.status);
const loading = ref(false);

const STATUSES = [
    { value: '', label: 'Alle Status' },
    { value: 'requested', label: 'Angefragt' },
    { value: 'scheduled', label: 'Terminiert' },
    { value: 'completed', label: 'Abgeschlossen' },
];

const cards = computed(() => [
    { key: 'total', label: 'Aufträge gesamt', value: props.totals.total },
    { key: 'requested', label: 'Angefragt', value: props.totals.requested },
    { key: 'scheduled', label: 'Terminiert', value: props.totals.scheduled },
    { key: 'completed', label: 'Abgeschlossen', value: props.totals.completed },
]);

const hasFilters = computed(() => [startDate.value, endDate.value, company.value, vehicle.value, status.value].some((value) => value !== ''));

function reload() {
    loading.value = true;

    router.get(
        route('admin.statistics.appraisal'),
        {
            start_date: startDate.value || undefined,
            end_date: endDate.value || undefined,
            company: company.value || undefined,
            vehicle: vehicle.value || undefined,
            status: status.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['totals', 'filters'], onFinish: () => (loading.value = false) },
    );
}

let timer: number | undefined;

watch([startDate, endDate, company, status], () => reload());
watch(vehicle, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(reload, 350);
});

function reset() {
    startDate.value = '';
    endDate.value = '';
    company.value = '';
    vehicle.value = '';
    status.value = '';
}

const fieldClass = 'rounded-[6px] border border-[#eef3f2] bg-white px-2.5 py-2 text-[12.5px] font-semibold text-[#5a6e6c]';
</script>

<template>
    <Head title="Statistik Gutachten" />

    <AdminLayout>
        <template #header>
            <h1 class="text-[16px] font-extrabold tracking-[-0.3px] text-[#10393b]">Statistik Gutachten</h1>
        </template>

        <section class="flex flex-col gap-5 rounded-[10px] border border-[#eef3f2] bg-white p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-[18px] font-extrabold tracking-[-0.3px] text-[#10393b]">Gutachten-Aufträge</h2>
                    <p class="mt-1 text-[12.5px] text-[#6f8585]">Gesamtzahl und Aufteilung nach Status. Ein Auftrag kann mehrere Fahrzeuge umfassen.</p>
                </div>
                <Link :href="route('admin.orders.index', { service: 'gutachten' })" class="text-[12.5px] font-bold text-[#00856a] hover:underline">
                    Zu den Aufträgen
                </Link>
            </div>

            <!-- Filters -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label class="flex flex-col gap-1 text-[11.5px] font-bold text-[#9bb0af]">
                    Von
                    <input v-model="startDate" type="date" :max="endDate || undefined" :class="fieldClass" />
                </label>
                <label class="flex flex-col gap-1 text-[11.5px] font-bold text-[#9bb0af]">
                    Bis
                    <input v-model="endDate" type="date" :min="startDate || undefined" :class="fieldClass" />
                </label>
                <label class="flex flex-col gap-1 text-[11.5px] font-bold text-[#9bb0af]">
                    Kunde / Unternehmen
                    <select v-model="company" :class="fieldClass">
                        <option value="">Alle Unternehmen</option>
                        <option v-for="entry in companies" :key="entry.id" :value="entry.id">{{ entry.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-[11.5px] font-bold text-[#9bb0af]">
                    Fahrzeug / Kennzeichen
                    <input v-model="vehicle" type="search" placeholder="Kennzeichen, FIN, Modell" :class="fieldClass" />
                </label>
                <label class="flex flex-col gap-1 text-[11.5px] font-bold text-[#9bb0af]">
                    Status
                    <select v-model="status" :class="fieldClass">
                        <option v-for="option in STATUSES" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
            </div>

            <button v-if="hasFilters" type="button" class="self-start text-[12px] font-bold text-[#c0392b] hover:underline" @click="reset">
                Filter zurücksetzen
            </button>

            <!-- Totals -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" :class="loading ? 'opacity-60' : ''">
                <div v-for="card in cards" :key="card.key" class="rounded-[10px] border border-[#eef3f2] bg-[#f8faf9] px-4 py-4">
                    <p class="text-[11px] font-bold tracking-[0.06em] text-[#9bb0af] uppercase">{{ card.label }}</p>
                    <p class="mt-1 text-[26px] leading-none font-extrabold text-[#10393b] tabular-nums">{{ card.value }}</p>
                </div>
            </div>
        </section>
    </AdminLayout>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
