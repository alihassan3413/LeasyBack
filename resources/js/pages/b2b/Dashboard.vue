<script setup lang="ts">
import FleetOverview from '@/components/b2b/FleetOverview.vue';
import OnboardingModal from '@/components/dashboard/OnboardingModal.vue';
import { Badge } from '@/components/ui/badge';
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import SelectVehicleModal from '@/components/vehicle/SelectVehicleModal.vue';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { useOnboarding } from '@/composables/useOnboarding';
import AppLayout from '@/layouts/AppLayout.vue';
import { ONBOARDING_VIDEO_POSTER_URL, ONBOARDING_VIDEO_URL } from '@/lib/onboarding';
import { formatPortalDate } from '@/lib/portalDate';
import { AVAILABILITY_LABELS, BOOKABLE_SERVICE, SERVICES, type ServiceDefinition } from '@/lib/services';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import { type SharedData } from '@/types';
import type { B2bAnalytics, B2bStatistics } from '@/types/b2b';
import type { CustomerOrderRow, StationData } from '@/types/order';
import { formatEuro } from '@/types/payment';
import type { BookableVehicleData, VehicleData } from '@/types/vehicle';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * The company's landing page: what LeasyBack does, and the way into booking
 * it. The fleet and the orders each have their own page — this one exists to
 * answer "which service, for which vehicle" and hand off to the booking form.
 *
 * Firmenkunde only. A Privatkunde's dashboard is their vehicle list, as it
 * has always been — see DashboardController.
 */
const props = defineProps<{
    bookableVehicles: BookableVehicleData[];
    /** The newest few processes — the full list lives on `orders.index`. */
    recentOrders: CustomerOrderRow[];
    stations: StationData[];
    analytics: B2bAnalytics | null;
    /**
     * Order totals, processing time and savings. Null for a member without
     * `analytics.view` — the company overview is withheld from the payload
     * rather than hidden in the template, so it never reaches the page source.
     */
    statistics: B2bStatistics | null;
    /**
     * The member's own operating figures. Null for a Company Administrator,
     * who gets the company overview instead — the two are alternatives, never
     * both, so the page is never two dashboards stacked.
     */
    myOverview: { vehicles: number; active_orders: number; bookable_vehicles: number } | null;
    /** A few of the vehicles this member can reach, newest first. */
    myVehicles: VehicleData[];
}>();

const { can } = useB2bPermissions();

/*
 * The company overview. Every figure here is computed by
 * B2bStatisticsService/B2bAnalyticsService — the dashboard formats them and
 * derives nothing of its own, so it can never disagree with the statistics
 * page about what a number means.
 *
 * Formatting matches that page: German locale, one decimal, and an em dash
 * where a figure is genuinely undefined rather than zero (no accepted offer
 * yet is not "0 % saved").
 */
const decimal = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const DASH = '—';

/**
 * Whether this reader gets the company overview at all.
 *
 * `analytics.view` is the existing permission for "Kennzahlen und
 * Auswertungen", and an owner holds every permission implicitly — so a
 * Company Administrator sees the whole overview while a Standard User and a
 * Read-only member get their own scoped figures instead. Driven by the props
 * the server did or did not send, so the template can never show what the
 * payload does not contain. Complementary with `myOverview` by construction
 * (DashboardController only ever populates one of the two).
 */
const showCompanyOverview = computed(() => props.statistics !== null);

/**
 * The figures, once `showCompanyOverview` has established there are any. The
 * KPI list is only ever read inside `v-if="showCompanyOverview"`, so this is
 * a narrowing helper rather than a default worth rendering.
 */
const EMPTY_STATISTICS: B2bStatistics = {
    orders: { active: 0, completed: 0, cancelled: 0, total: 0 },
    savings: {
        orders_counted: 0,
        vehicles_counted: 0,
        appraisal_total_net: '0.00',
        repair_total_net: '0.00',
        saving_total_net: '0.00',
        average_saving_per_vehicle_net: null,
        saving_percentage: null,
    },
    processing_time: { average_days: null, measured_orders: 0 },
    status_distribution: [],
    monthly_volume: [],
    scope: { company_wide: true },
};

const stats = computed<B2bStatistics>(() => props.statistics ?? EMPTY_STATISTICS);

/**
 * The member's own figures. Deliberately three counts and no money: savings,
 * processing time and anyone else's activity are the company's numbers and
 * belong to the administrator's view.
 */
const myKpis = computed(() => {
    const overview = props.myOverview;

    if (overview === null) {
        return [];
    }

    return [
        { key: 'vehicles', label: 'Fahrzeuge', value: overview.vehicles, hint: 'für Sie sichtbar' },
        { key: 'active', label: 'Laufende Aufträge', value: overview.active_orders, hint: 'derzeit in Bearbeitung' },
        { key: 'bookable', label: 'Ohne Vorgang', value: overview.bookable_vehicles, hint: 'bereit für eine Leistung' },
    ];
});

const kpis = computed(() => [
    {
        key: 'vehicles',
        label: 'Fahrzeuge',
        value: String(props.analytics?.totals.vehicles ?? 0),
        hint: 'im Fuhrpark',
    },
    {
        key: 'active',
        label: 'Laufende Aufträge',
        value: String(stats.value.orders.active),
        hint: `von ${stats.value.orders.total} gesamt`,
    },
    {
        key: 'completed',
        label: 'Abgeschlossen',
        value: String(stats.value.orders.completed),
        hint: 'Rückgaben beendet',
    },
    {
        key: 'processing',
        label: 'Ø Bearbeitungszeit',
        value: stats.value.processing_time.average_days === null ? DASH : `${decimal.format(stats.value.processing_time.average_days)} Tage`,
        hint:
            stats.value.processing_time.measured_orders === 0 ? 'noch keine Messung' : `aus ${stats.value.processing_time.measured_orders} Vorgängen`,
    },
    {
        key: 'savings',
        label: 'Ersparnis',
        value: stats.value.savings.saving_percentage === null ? DASH : `${decimal.format(Number(stats.value.savings.saving_percentage))} %`,
        hint: stats.value.savings.orders_counted === 0 ? 'noch kein Angebot angenommen' : formatEuro(stats.value.savings.saving_total_net),
    },
]);

/** A member without `orders.create` may look at the catalogue but not start one. */
const canBook = computed(() => can('orders.create'));

const activeService = ref<ServiceDefinition | null>(null);
const selectVehicleOpen = ref(false);

const orderModalOpen = ref(false);
const orderVehicle = ref<BookableVehicleData | null>(null);

/** How many services the reader could actually start right now. */
const bookableCount = computed(() => SERVICES.filter((service) => service.availability === 'bookable').length);

/** Everything but the one workflow the page leads with — the reference list beneath it. */
const futureServices = computed(() => SERVICES.filter((service) => service.key !== BOOKABLE_SERVICE.key));

/** A row is a button only when it can actually start something. */
function isLaunchable(service: ServiceDefinition): boolean {
    return service.availability === 'bookable' && canBook.value;
}

function startService(service: ServiceDefinition) {
    if (service.availability !== 'bookable' || !canBook.value) {
        return;
    }

    activeService.value = service;
    selectVehicleOpen.value = true;
}

function onVehicleChosen(vehicle: BookableVehicleData) {
    selectVehicleOpen.value = false;
    orderVehicle.value = vehicle;
    orderModalOpen.value = true;
}

const page = usePage<SharedData>();

// The button below only ever opens it by hand: the first-visit auto-open is a
// Privatkunde behaviour, and they never reach this page.
const { isOpen: onboardingOpen, open: openOnboarding, dismiss: dismissOnboarding } = useOnboarding(() => page.props.auth.user?.email);

function onOnboardingOpenChange(value: boolean) {
    if (!value) {
        dismissOnboarding();
    }
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="flex flex-col">
            <header class="mb-6 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div>
                    <h1 class="text-brand-teal text-[22px] font-semibold md:text-[28px]">Mein Dashboard</h1>
                    <p class="text-muted-foreground mt-1 text-sm">Leistung wählen, Fahrzeug zuordnen, Termin buchen.</p>
                </div>

                <!-- Was a floating pill over the content; a plain text action
                     next to the title is discoverable without sitting on top
                     of the page. -->
                <button
                    type="button"
                    class="text-muted-foreground hover:text-brand-teal mt-1 shrink-0 text-[12.5px] font-medium transition-colors hover:underline"
                    @click="openOnboarding"
                >
                    Einführung ansehen
                </button>
            </header>

            <!-- ════════════════════════════════════════════════════════════
                 Leistungen — the primary workflow, and the first section on
                 the page for every role. Starting a return is what a company
                 account comes here to do; everything below is the record of
                 what is already running, not the reason to visit.

                 Content sits directly on the page, not inside a panel: the
                 one real workflow (Leasingrückgabe) is its own row, closed
                 off by a hairline rule, and every announced-but-not-yet-
                 bookable service follows as its own plain, headed list. A
                 rule and a heading do the separating a card would otherwise
                 be reached for — this is a workspace, not a widget.
            ═════════════════════════════════════════════════════════════ -->
            <section>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-brand-teal text-[20px] leading-tight font-bold">Leistungen</h2>
                    <span class="text-muted-foreground text-[12.5px]">{{ bookableCount }} von {{ SERVICES.length }} verfügbar</span>
                </div>

                <!-- The one real workflow. Weight comes from type scale, a
                     real CTA and the accent border — not from a box or a
                     tinted background. -->
                <component
                    :is="isLaunchable(BOOKABLE_SERVICE) ? 'button' : 'div'"
                    :type="isLaunchable(BOOKABLE_SERVICE) ? 'button' : undefined"
                    class="border-border border-brand-orange mt-4 flex w-full items-center gap-4 border-b border-l-2 py-5 pl-4 text-left transition-colors"
                    :class="isLaunchable(BOOKABLE_SERVICE) ? 'hover:bg-muted/30 cursor-pointer' : ''"
                    @click="startService(BOOKABLE_SERVICE)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="text-brand-teal block text-[18px] font-bold">{{ BOOKABLE_SERVICE.title }}</span>
                        <span class="text-muted-foreground mt-1 block text-[13.5px]">{{ BOOKABLE_SERVICE.summary }}</span>
                    </span>

                    <span
                        v-if="isLaunchable(BOOKABLE_SERVICE)"
                        class="bg-brand-orange hover:bg-brand-orange/90 shrink-0 rounded-[6px] px-5 py-2.5 text-[13.5px] font-bold whitespace-nowrap text-white transition-colors"
                    >
                        Starten
                    </span>
                    <span v-else class="text-muted-foreground shrink-0 text-[12px] whitespace-nowrap">Keine Berechtigung</span>
                </component>

                <!-- Everything else LeasyBack has announced, as a plain
                     reference list under its own heading: no icons, no
                     per-row box, availability as text. A catalogue entry, not
                     a second action. -->
                <div class="mt-6">
                    <h3 class="text-brand-teal text-[16px] font-semibold">Weitere Leistungen</h3>

                    <ul class="divide-border mt-2 divide-y">
                        <li v-for="service in futureServices" :key="service.key" class="flex items-center justify-between gap-3 py-2">
                            <span class="text-muted-foreground truncate text-[13px] font-medium">{{ service.title }}</span>
                            <span class="text-muted-foreground shrink-0 text-[12px] whitespace-nowrap">{{
                                AVAILABILITY_LABELS[service.availability]
                            }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- ════════════════════════════════════════════════════════════
                 Everything below is supporting information: the record of
                 what is already running, scoped by the same `analytics.view`
                 permission as before. Headings step down a size from
                 "Leistungen" so the page reads as one workflow with a status
                 record beneath it, not several equally-weighted sections.
            ═════════════════════════════════════════════════════════════ -->

            <!-- ── Company overview — Company Administrator / Read-only ── -->
            <section v-if="showCompanyOverview" class="mt-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-brand-teal text-[16px] font-semibold">Unternehmen im Überblick</h3>
                    <!--
                        A member limited to their own vehicles is looking at
                        their own figures, not the company's. Saying so is the
                        difference between a small number and a wrong one.
                    -->
                    <span v-if="!stats.scope.company_wide" class="text-muted-foreground text-[12px]"> Nur Ihre eigenen Fahrzeuge </span>
                </div>

                <!-- A plain figure row, not a tile grid: no fill, no border
                     box, just hairline dividers between values — a status
                     readout, not a set of stat cards asking for attention. -->
                <dl class="divide-border mt-3 flex divide-x overflow-x-auto">
                    <div v-for="kpi in kpis" :key="kpi.key" class="min-w-[120px] flex-1 shrink-0 px-4 py-1 first:pl-0">
                        <dt class="text-muted-foreground truncate text-[11px] font-semibold tracking-[0.06em] uppercase">{{ kpi.label }}</dt>
                        <dd class="text-brand-teal mt-1 truncate text-[18px] leading-none font-semibold tabular-nums">{{ kpi.value }}</dd>
                        <p class="text-muted-foreground mt-1 truncate text-[11.5px]">{{ kpi.hint }}</p>
                    </div>
                </dl>
            </section>

            <FleetOverview v-if="showCompanyOverview && analytics" :analytics="analytics" class="mt-4" />

            <!-- ── Recent processes ──
                Deliberately a short read-only list and not a second orders
                page: it answers "what is moving right now", and every row
                opens the order it names.
            -->
            <section v-if="showCompanyOverview" class="mt-8">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-brand-teal text-[16px] font-semibold">Letzte Vorgänge</h3>
                    <Link :href="route('orders.index')" class="text-brand-teal text-[12px] font-semibold hover:underline">Alle Aufträge</Link>
                </div>

                <div class="border-border bg-card mt-3 overflow-hidden rounded-[10px] border">
                    <p v-if="!recentOrders.length" class="text-muted-foreground px-5 py-8 text-center text-[13px]">
                        Noch keine Vorgänge. Buchen Sie oben eine Leistung für eines Ihrer Fahrzeuge.
                    </p>

                    <ul v-else class="divide-border divide-y">
                        <li v-for="order in recentOrders" :key="order.id">
                            <Link
                                :href="route('orders.show', order.id)"
                                class="hover:bg-muted/50 flex items-center gap-3 px-5 py-3.5 transition-colors"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="text-brand-teal block truncate text-[14px] font-semibold">
                                        {{ order.license_plate }}
                                        <span class="text-muted-foreground font-normal">
                                            · {{ [order.make, order.model].filter(Boolean).join(' ') || '—' }}
                                        </span>
                                    </span>
                                    <span class="text-muted-foreground block truncate text-[12px]">
                                        Auftrag {{ order.auftragsnummer }} · {{ formatPortalDate(order.created_at) }}
                                        <template v-if="order.appointment"> · Termin {{ formatPortalDate(order.appointment) }} </template>
                                    </span>
                                </span>

                                <Badge :variant="getVehicleStatusDisplay(order.order_status).variant" class="shrink-0">
                                    {{ getVehicleStatusDisplay(order.order_status).label }}
                                </Badge>
                            </Link>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- ── Member view: Meine Übersicht — Standard User ──
                Three counts, all scoped to what this reader may actually
                reach — no savings, no processing time, nobody else's work.
            -->
            <section v-if="myOverview" class="mt-8">
                <h3 class="text-brand-teal text-[16px] font-semibold">Meine Übersicht</h3>

                <dl class="divide-border mt-3 flex divide-x overflow-x-auto">
                    <div v-for="kpi in myKpis" :key="kpi.key" class="min-w-[120px] flex-1 shrink-0 px-4 py-1 first:pl-0">
                        <dt class="text-muted-foreground truncate text-[11px] font-semibold tracking-[0.06em] uppercase">{{ kpi.label }}</dt>
                        <dd class="text-brand-teal mt-1 truncate text-[18px] leading-none font-semibold tabular-nums">{{ kpi.value }}</dd>
                        <p class="text-muted-foreground mt-1 truncate text-[11.5px]">{{ kpi.hint }}</p>
                    </div>
                </dl>
            </section>

            <!-- ── Member view: Meine Fahrzeuge ──
                A short preview, not the fleet page. Status comes from the
                same helper the fleet rows use, so a vehicle reads the same
                here as it does there.
            -->
            <section v-if="myOverview" class="mt-8">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-brand-teal text-[16px] font-semibold">Meine Fahrzeuge</h3>
                    <Link :href="route('vehicles.index')" class="text-brand-teal text-[12px] font-semibold hover:underline">Alle Fahrzeuge</Link>
                </div>

                <div class="border-border bg-card mt-3 overflow-hidden rounded-[10px] border">
                    <p v-if="!myVehicles.length" class="text-muted-foreground px-5 py-8 text-center text-[13px]">
                        Ihnen ist derzeit kein Fahrzeug zugeordnet.
                    </p>

                    <ul v-else class="divide-border divide-y">
                        <li v-for="vehicle in myVehicles" :key="vehicle.vehicle_id">
                            <Link
                                :href="route('vehicles.show', vehicle.vehicle_id)"
                                class="hover:bg-muted/50 flex items-center gap-3 px-5 py-3.5 transition-colors"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="text-brand-teal block truncate text-[14px] font-semibold">{{ vehicle.license_plate }}</span>
                                    <span class="text-muted-foreground block truncate text-[12px]">
                                        {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || '—' }}
                                        <template v-if="vehicle.leasing_end_date">
                                            · Leasingende {{ formatPortalDate(vehicle.leasing_end_date) }}
                                        </template>
                                    </span>
                                </span>

                                <Badge :variant="getVehicleStatusDisplay(vehicle.current_order?.order_status).variant" class="shrink-0">
                                    {{ getVehicleStatusDisplay(vehicle.current_order?.order_status).label }}
                                </Badge>
                            </Link>
                        </li>
                    </ul>
                </div>
            </section>
        </div>

        <!-- Step 1: which vehicle. Step 2: the appointment itself. -->
        <SelectVehicleModal v-model:open="selectVehicleOpen" :service="activeService" :vehicles="bookableVehicles" @confirm="onVehicleChosen" />

        <OrderCreationModal
            v-if="orderVehicle"
            v-model:open="orderModalOpen"
            :vehicle-id="orderVehicle.vehicle_id"
            :stations="stations"
            :vehicle="orderVehicle"
        />

        <OnboardingModal
            :open="onboardingOpen"
            :video-url="ONBOARDING_VIDEO_URL"
            :poster-url="ONBOARDING_VIDEO_POSTER_URL"
            @update:open="onOnboardingOpenChange"
        />
    </AppLayout>
</template>
