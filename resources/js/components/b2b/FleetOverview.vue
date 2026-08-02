<script setup lang="ts">
/**
 * The dashboard's overview strip: two cards side by side.
 *
 * They are split along the line the data itself follows. "Fuhrpark" counts
 * *vehicles* — every one of them sits in exactly one state, so those counts
 * sum to the fleet and can honestly be drawn as one stacked bar. "Rückgaben"
 * counts *orders*, of which a single vehicle can carry several over its life;
 * those can never be parts of the same whole, which is why they get their own
 * card and their own noun rather than sharing a bar with the fleet.
 *
 * Colours are the ones the dashboard already uses on its own rows: orange for
 * not-yet-started, green for in flight, deep teal for finished, brand grey for
 * abandoned. No new palette.
 */
import type { B2bAnalytics, B2bVehicleState } from '@/types/b2b';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    analytics: B2bAnalytics;
    /** The status currently filtering the table, so entries can mark it. */
    activeFilter?: string;
}>();

const SEGMENT_CLASS: Record<B2bVehicleState['key'], string> = {
    planned: 'bg-brand-orange',
    in_progress: 'bg-brand-green',
    completed: 'bg-brand-teal',
    cancelled: 'bg-brand-green-gray',
};

/* ── Card 1: fleet composition ───────────────────────────────────────── */
const totalVehicles = computed(() => props.analytics.states.reduce((sum, state) => sum + state.count, 0));

/** Empty buckets are dropped entirely — a legend of zeroes is just noise. */
const segments = computed(() =>
    props.analytics.states
        .filter((state) => state.count > 0)
        .map((state) => ({
            ...state,
            percent: totalVehicles.value === 0 ? 0 : (state.count / totalVehicles.value) * 100,
            colorClass: SEGMENT_CLASS[state.key],
        })),
);

const barLabel = computed(() =>
    totalVehicles.value === 0
        ? 'Noch keine Fahrzeuge angelegt'
        : `Fuhrpark nach Status: ${segments.value.map((segment) => `${segment.count} ${segment.label}`).join(', ')}`,
);

/* ── Card 2: return progress ─────────────────────────────────────────── */
const openOrders = computed(() => props.analytics.totals.open_orders);
const completedOrders = computed(() => props.analytics.totals.completed_orders);
const totalOrders = computed(() => openOrders.value + completedOrders.value);

const completionPercent = computed(() => (totalOrders.value === 0 ? 0 : Math.round((completedOrders.value / totalOrders.value) * 100)));

const orderRows = computed(() => [
    { key: 'open', label: 'Laufende Aufträge', value: openOrders.value, filter: 'open', dotClass: 'bg-brand-green' },
    { key: 'completed', label: 'Abgeschlossene Aufträge', value: completedOrders.value, filter: 'delivered', dotClass: 'bg-brand-teal' },
]);

function isActive(filter: string | null): boolean {
    return !!filter && filter === props.activeFilter;
}
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <!-- ── FUHRPARK ── -->
        <section class="border-border bg-card flex h-full flex-col rounded-xl border p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">Fuhrpark</p>
                    <p class="mt-1.5 flex items-baseline gap-2">
                        <span class="text-brand-teal text-3xl leading-none font-bold tabular-nums">{{ totalVehicles }}</span>
                        <span class="text-muted-foreground text-sm">{{ totalVehicles === 1 ? 'Fahrzeug' : 'Fahrzeuge' }}</span>
                    </p>
                </div>

                <span class="bg-brand-green/10 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-lg">
                    <IconMdiCarMultiple class="size-5" aria-hidden="true" />
                </span>
            </div>

            <!-- Decorative: every figure in it is repeated in the legend below. -->
            <div class="bg-muted mt-5 flex h-2.5 w-full overflow-hidden rounded-full" role="img" :aria-label="barLabel">
                <span
                    v-for="segment in segments"
                    :key="segment.key"
                    class="h-full first:rounded-l-full last:rounded-r-full"
                    :class="segment.colorClass"
                    :style="{ width: `${segment.percent}%` }"
                />
            </div>

            <!-- Legend doubles as the filter for the table below. -->
            <ul v-if="segments.length" class="mt-3 flex flex-wrap items-center gap-x-1 gap-y-1">
                <li v-for="segment in segments" :key="segment.key">
                    <component
                        :is="segment.filter ? Link : 'span'"
                        v-bind="segment.filter ? { href: route('dashboard', { status: segment.filter }), preserveScroll: true } : {}"
                        class="flex items-center gap-2 rounded-full px-2.5 py-1.5 transition-colors"
                        :class="[segment.filter ? 'hover:bg-muted cursor-pointer' : '', isActive(segment.filter) ? 'bg-muted' : '']"
                    >
                        <span class="size-2 shrink-0 rounded-full" :class="segment.colorClass" aria-hidden="true" />
                        <span class="text-muted-foreground text-xs">{{ segment.label }}</span>
                        <span class="text-brand-teal text-xs font-semibold tabular-nums">{{ segment.count }}</span>
                    </component>
                </li>
            </ul>

            <p v-else class="text-muted-foreground mt-3 text-sm">
                Sobald Sie Fahrzeuge anlegen, sehen Sie hier, wie weit deren Rückgaben sind.
            </p>
        </section>

        <!-- ── RÜCKGABEN ── -->
        <section class="border-border bg-card flex h-full flex-col rounded-xl border p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">Rückgaben</p>
                    <p class="mt-1.5 flex items-baseline gap-2">
                        <span
                            class="text-3xl leading-none font-bold tabular-nums"
                            :class="totalOrders === 0 ? 'text-muted-foreground/50' : 'text-brand-teal'"
                        >
                            {{ completionPercent }}<span class="text-xl">&#8239;%</span>
                        </span>
                        <span class="text-muted-foreground text-sm">abgeschlossen</span>
                    </p>
                </div>

                <span class="bg-brand-green/10 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-lg">
                    <IconMdiSwapHorizontal class="size-5" aria-hidden="true" />
                </span>
            </div>

            <div
                class="bg-muted mt-5 h-2.5 w-full overflow-hidden rounded-full"
                role="img"
                :aria-label="
                    totalOrders === 0
                        ? 'Noch keine Aufträge'
                        : `${completedOrders} von ${totalOrders} Aufträgen abgeschlossen`
                "
            >
                <span class="bg-brand-teal block h-full rounded-full transition-[width] duration-300" :style="{ width: `${completionPercent}%` }" />
            </div>

            <ul v-if="totalOrders > 0" class="border-border mt-4 space-y-px border-t pt-2">
                <li v-for="row in orderRows" :key="row.key">
                    <Link
                        :href="route('dashboard', { status: row.filter })"
                        preserve-scroll
                        class="hover:bg-muted flex items-center gap-2.5 rounded-lg px-2 py-2 transition-colors"
                        :class="isActive(row.filter) ? 'bg-muted' : ''"
                    >
                        <IconMdiClockOutline v-if="row.key === 'open'" class="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                        <IconMdiCheckCircleOutline v-else class="text-muted-foreground size-4 shrink-0" aria-hidden="true" />

                        <span class="text-muted-foreground flex-1 truncate text-sm">{{ row.label }}</span>

                        <span class="size-2 shrink-0 rounded-full" :class="row.dotClass" aria-hidden="true" />
                        <span
                            class="text-sm font-semibold tabular-nums"
                            :class="row.value === 0 ? 'text-muted-foreground/50' : 'text-brand-teal'"
                        >
                            {{ row.value }}
                        </span>
                    </Link>
                </li>
            </ul>

            <p v-else class="text-muted-foreground mt-3 text-sm">Noch keine Rückgabe gestartet.</p>
        </section>
    </div>
</template>
