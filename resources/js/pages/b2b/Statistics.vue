<script setup lang="ts">
/**
 * The company's return statistics (b2b.txt §17).
 *
 * Every figure here is computed server-side by B2bStatisticsService and
 * arrives already scoped to the caller's company — this page never filters,
 * and could not, since it is only ever handed rows it is allowed to see.
 *
 * Money comes down as decimal strings and is only ever *formatted* here, never
 * re-derived: the totals, the average and the percentage are all decided by
 * the same server code the Excel export uses, so the two can never disagree.
 */
import StatCard from '@/components/b2b/StatCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { B2bStatistics } from '@/types/b2b';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiCalendarMonthOutline from '~icons/mdi/calendar-month-outline';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
import MdiClockOutline from '~icons/mdi/clock-outline';
import MdiPiggyBankOutline from '~icons/mdi/piggy-bank-outline';

const props = defineProps<{
    statistics: B2bStatistics;
}>();

const currency = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
const decimal = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

const DASH = '—';

function money(value: string | null): string {
    return value === null ? DASH : currency.format(Number(value));
}

const savings = computed(() => props.statistics.savings);

const headlineStats = computed(() => [
    {
        key: 'active',
        label: 'Laufende Aufträge',
        value: props.statistics.orders.active,
        icon: MdiClockOutline,
    },
    {
        key: 'completed',
        label: 'Abgeschlossene Aufträge',
        value: props.statistics.orders.completed,
        icon: MdiCheckCircleOutline,
    },
    {
        key: 'saving',
        label: 'Ersparnis gesamt (netto)',
        value: money(savings.value.saving_total_net),
        icon: MdiPiggyBankOutline,
    },
    {
        key: 'processing',
        label: 'Ø Bearbeitungsdauer',
        value:
            props.statistics.processing_time.average_days === null
                ? DASH
                : `${decimal.format(props.statistics.processing_time.average_days)} Tage`,
        icon: MdiCalendarMonthOutline,
    },
]);

/**
 * The saving bar reads left to right as "what the appraisal asked for", split
 * into the part actually paid and the part saved. Drawn from the two amounts
 * rather than the percentage so the bar and the number can't drift apart.
 */
const savingShare = computed(() => {
    const appraisal = Number(savings.value.appraisal_total_net);

    if (!Number.isFinite(appraisal) || appraisal <= 0) {
        return 0;
    }

    return Math.min(100, Math.max(0, (Number(savings.value.saving_total_net) / appraisal) * 100));
});

const savingRows = computed(() => [
    { key: 'appraisal', label: 'Gutachtenbetrag (netto)', value: money(savings.value.appraisal_total_net) },
    { key: 'repair', label: 'Freigegebener Reparaturbetrag (netto)', value: money(savings.value.repair_total_net) },
    { key: 'saving', label: 'Ersparnis (netto)', value: money(savings.value.saving_total_net), emphasis: true },
    {
        key: 'average',
        label: 'Ø Ersparnis je Fahrzeug (netto)',
        value: money(savings.value.average_saving_per_vehicle_net),
    },
]);

const statusTotal = computed(() => props.statistics.status_distribution.reduce((sum, entry) => sum + entry.count, 0));

const maxMonthlyCount = computed(() => Math.max(1, ...props.statistics.monthly_volume.map((entry) => entry.count)));

const hasAcceptedOffers = computed(() => savings.value.orders_counted > 0);
</script>

<template>
    <Head title="Statistik" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <h1 class="text-brand-teal shrink-0 text-base font-extrabold tracking-[-0.3px]">Statistik</h1>

                <!--
                    A plain anchor, not <Link>: the response is a file download,
                    and Inertia's visit would try to parse it as a page.
                -->
                <a
                    :href="route('b2b.statistics.export')"
                    class="bg-brand-orange ml-auto flex shrink-0 items-center justify-center gap-1.5 rounded-full px-3 py-2 text-sm font-bold text-white transition-opacity hover:opacity-90 sm:px-4"
                >
                    <IconMdiFileExcelOutline class="size-4" aria-hidden="true" />
                    <span class="hidden sm:inline">Excel-Export</span>
                    <span class="sr-only sm:hidden">Daten als Excel exportieren</span>
                </a>
            </div>
        </template>

        <div class="mx-auto w-full max-w-[1120px] space-y-6 pb-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard v-for="stat in headlineStats" :key="stat.key" :label="stat.label" :value="stat.value" :icon="stat.icon" />
            </div>

            <p v-if="!statistics.scope.company_wide" class="text-muted-foreground text-xs">
                Diese Auswertung umfasst nur die von Ihnen angelegten Fahrzeuge.
            </p>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- ── ERSPARNIS ── -->
                <section class="border-border bg-card flex h-full flex-col rounded-xl border p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">Ersparnis</p>
                            <p class="mt-1.5 flex items-baseline gap-2">
                                <span
                                    class="text-3xl leading-none font-bold tabular-nums"
                                    :class="hasAcceptedOffers ? 'text-brand-teal' : 'text-muted-foreground/50'"
                                >
                                    {{ savings.saving_percentage === null ? DASH : decimal.format(Number(savings.saving_percentage)) }}
                                    <span v-if="savings.saving_percentage !== null" class="text-xl">&#8239;%</span>
                                </span>
                                <span class="text-muted-foreground text-sm">gegenüber dem Gutachten</span>
                            </p>
                        </div>

                        <span class="bg-brand-green/10 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-lg">
                            <IconMdiPiggyBankOutline class="size-5" aria-hidden="true" />
                        </span>
                    </div>

                    <div
                        class="bg-muted mt-5 h-2.5 w-full overflow-hidden rounded-full"
                        role="img"
                        :aria-label="
                            hasAcceptedOffers
                                ? `${money(savings.saving_total_net)} von ${money(savings.appraisal_total_net)} eingespart`
                                : 'Noch keine freigegebenen Angebote'
                        "
                    >
                        <span class="bg-brand-green block h-full rounded-full transition-[width] duration-300" :style="{ width: `${savingShare}%` }" />
                    </div>

                    <ul v-if="hasAcceptedOffers" class="border-border mt-4 space-y-px border-t pt-2">
                        <li v-for="row in savingRows" :key="row.key" class="flex items-center gap-2.5 px-2 py-2">
                            <span class="text-muted-foreground flex-1 truncate text-sm">{{ row.label }}</span>
                            <span
                                class="text-sm tabular-nums"
                                :class="row.emphasis ? 'text-brand-teal font-bold' : 'text-brand-teal font-semibold'"
                            >
                                {{ row.value }}
                            </span>
                        </li>
                    </ul>

                    <p v-else class="text-muted-foreground mt-3 text-sm">
                        Sobald Sie ein Reparaturangebot freigegeben haben, sehen Sie hier Ihre Ersparnis.
                    </p>

                    <p v-if="hasAcceptedOffers" class="text-muted-foreground mt-3 text-xs">
                        Basis: {{ savings.orders_counted }}
                        {{ savings.orders_counted === 1 ? 'Auftrag' : 'Aufträge' }} mit freigegebenem Angebot,
                        {{ savings.vehicles_counted }} {{ savings.vehicles_counted === 1 ? 'Fahrzeug' : 'Fahrzeuge' }}. Ein
                        Nachgutachten fließt nicht in diese Berechnung ein.
                    </p>
                </section>

                <!-- ── STATUSVERTEILUNG ── -->
                <section class="border-border bg-card flex h-full flex-col rounded-xl border p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">Statusverteilung</p>
                            <p class="mt-1.5 flex items-baseline gap-2">
                                <span class="text-brand-teal text-3xl leading-none font-bold tabular-nums">{{ statistics.orders.total }}</span>
                                <span class="text-muted-foreground text-sm">{{ statistics.orders.total === 1 ? 'Auftrag' : 'Aufträge' }}</span>
                            </p>
                        </div>

                        <span class="bg-brand-green/10 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-lg">
                            <IconMdiClipboardTextOutline class="size-5" aria-hidden="true" />
                        </span>
                    </div>

                    <ul v-if="statistics.status_distribution.length" class="border-border mt-4 space-y-px border-t pt-2">
                        <li v-for="entry in statistics.status_distribution" :key="entry.status" class="px-2 py-1.5">
                            <div class="flex items-center gap-2.5">
                                <span class="text-muted-foreground flex-1 truncate text-sm">{{ entry.label }}</span>
                                <span class="text-brand-teal text-sm font-semibold tabular-nums">{{ entry.count }}</span>
                            </div>

                            <div class="bg-muted mt-1.5 h-1.5 w-full overflow-hidden rounded-full" aria-hidden="true">
                                <span
                                    class="bg-brand-teal block h-full rounded-full"
                                    :style="{ width: `${statusTotal === 0 ? 0 : (entry.count / statusTotal) * 100}%` }"
                                />
                            </div>
                        </li>
                    </ul>

                    <p v-else class="text-muted-foreground mt-3 text-sm">Noch keine Aufträge angelegt.</p>
                </section>
            </div>

            <!-- ── AUFTRAGSVOLUMEN ── -->
            <section class="border-border bg-card rounded-xl border p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">Auftragsvolumen</p>
                        <p class="text-muted-foreground mt-1.5 text-sm">Neue Aufträge je Monat, letzte 12 Monate.</p>
                    </div>

                    <span class="bg-brand-green/10 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-lg">
                        <IconMdiChartTimelineVariant class="size-5" aria-hidden="true" />
                    </span>
                </div>

                <ol class="mt-5 flex items-end gap-1.5 sm:gap-3">
                    <li v-for="entry in statistics.monthly_volume" :key="entry.month" class="flex min-w-0 flex-1 flex-col items-center gap-1.5">
                        <span class="text-brand-teal text-xs font-semibold tabular-nums">{{ entry.count }}</span>

                        <span class="bg-muted flex h-24 w-full items-end overflow-hidden rounded-md">
                            <span
                                class="bg-brand-green w-full rounded-md transition-[height] duration-300"
                                :style="{ height: `${(entry.count / maxMonthlyCount) * 100}%` }"
                                :aria-label="`${entry.label}: ${entry.count}`"
                            />
                        </span>

                        <span class="text-muted-foreground truncate text-[10px] tabular-nums sm:text-xs">{{ entry.label }}</span>
                    </li>
                </ol>
            </section>

            <!-- ── BEARBEITUNGSDAUER ── -->
            <section class="border-border bg-card rounded-xl border p-5 sm:p-6">
                <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">Bearbeitungsdauer</p>

                <p class="mt-1.5 flex items-baseline gap-2">
                    <span
                        class="text-3xl leading-none font-bold tabular-nums"
                        :class="statistics.processing_time.average_days === null ? 'text-muted-foreground/50' : 'text-brand-teal'"
                    >
                        {{
                            statistics.processing_time.average_days === null
                                ? DASH
                                : decimal.format(statistics.processing_time.average_days)
                        }}
                    </span>
                    <span class="text-muted-foreground text-sm">Tage im Durchschnitt</span>
                </p>

                <p class="text-muted-foreground mt-3 text-xs">
                    Gemessen vom Anlegen des Auftrags bis zum Abschluss, über
                    {{ statistics.processing_time.measured_orders }}
                    {{ statistics.processing_time.measured_orders === 1 ? 'abgeschlossenen Auftrag' : 'abgeschlossene Aufträge' }}.
                    Laufende Aufträge sind nicht enthalten.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
