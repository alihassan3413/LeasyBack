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
import { AVAILABILITY_LABELS, SERVICES, type ServiceDefinition } from '@/lib/services';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import { type SharedData } from '@/types';
import type { B2bAnalytics, B2bStatistics } from '@/types/b2b';
import type { CustomerOrderRow, StationData } from '@/types/order';
import { formatEuro } from '@/types/payment';
import type { BookableVehicleData, VehicleData } from '@/types/vehicle';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiArrowRight from '~icons/mdi/arrow-right';

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
 * Company Administrator sees the whole dashboard while a Standard User and a
 * Read-only member get the service catalogue alone. Driven by the props the
 * server did or did not send, so the template can never show what the payload
 * does not contain.
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
            <header class="mb-6">
                <h1 class="text-brand-teal text-[22px] font-semibold md:text-[28px]">Mein Dashboard</h1>
                <p class="text-muted-foreground mt-1 text-sm">Leistung wählen, Fahrzeug zuordnen, Termin buchen.</p>
            </header>

            <!--
                Ordered by what a company administrator comes here to find
                out: how the account stands, then what is moving, and only
                then what can be started. Services used to lead the page with
                four tall marketing cards, which pushed every operational
                figure below the fold.
            -->

            <!-- ── 1. Company overview — Company Administrator only ── -->
            <section v-if="showCompanyOverview">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.13em] uppercase">Unternehmen im Überblick</h2>
                    <!--
                        A member limited to their own vehicles is looking at
                        their own figures, not the company's. Saying so is the
                        difference between a small number and a wrong one.
                    -->
                    <span v-if="!stats.scope.company_wide" class="text-muted-foreground text-[11px]"> Nur Ihre eigenen Fahrzeuge </span>
                </div>

                <!-- Separate tiles rather than one divided block: each figure
                     gets its own edge, which is what makes a row of five
                     scannable at a glance. -->
                <dl class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    <div v-for="kpi in kpis" :key="kpi.key" class="border-border bg-card min-w-0 rounded-xl border px-4 py-3.5">
                        <dt class="text-muted-foreground truncate text-[11px] font-semibold tracking-[0.06em] uppercase">{{ kpi.label }}</dt>
                        <dd class="text-brand-teal mt-2 truncate text-[26px] leading-none font-bold tabular-nums">{{ kpi.value }}</dd>
                        <p class="text-muted-foreground mt-2 truncate text-[11.5px]">{{ kpi.hint }}</p>
                    </div>
                </dl>
            </section>

            <!-- ── 2. Fleet + returns — travels with the overview ── -->
            <FleetOverview v-if="showCompanyOverview && analytics" :analytics="analytics" class="mt-4" />

            <!-- ── 3. Recent processes ──
                Deliberately a short read-only list and not a second orders
                page: it answers "what is moving right now", and every row
                opens the order it names.
            -->
            <section v-if="showCompanyOverview" class="mt-8">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.13em] uppercase">Letzte Vorgänge</h2>
                    <Link :href="route('orders.index')" class="text-brand-teal text-[12px] font-semibold hover:underline">Alle Aufträge</Link>
                </div>

                <div class="border-border bg-card mt-3 overflow-hidden rounded-xl border">
                    <p v-if="!recentOrders.length" class="text-muted-foreground px-5 py-8 text-center text-[13px]">
                        Noch keine Vorgänge. Buchen Sie unten eine Leistung für eines Ihrer Fahrzeuge.
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

            <!-- ── Member view: Meine Übersicht ──
                Shown instead of the company overview, never alongside it.
                Three counts, all scoped to what this reader may actually
                reach — no savings, no processing time, nobody else's work.
            -->
            <section v-if="myOverview">
                <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.13em] uppercase">Meine Übersicht</h2>

                <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div v-for="kpi in myKpis" :key="kpi.key" class="border-border bg-card min-w-0 rounded-xl border px-4 py-3.5">
                        <dt class="text-muted-foreground truncate text-[11px] font-semibold tracking-[0.06em] uppercase">{{ kpi.label }}</dt>
                        <dd class="text-brand-teal mt-2 text-[26px] leading-none font-bold tabular-nums">{{ kpi.value }}</dd>
                        <p class="text-muted-foreground mt-2 truncate text-[11.5px]">{{ kpi.hint }}</p>
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
                    <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.13em] uppercase">Meine Fahrzeuge</h2>
                    <Link :href="route('vehicles.index')" class="text-brand-teal text-[12px] font-semibold hover:underline">Alle Fahrzeuge</Link>
                </div>

                <div class="border-border bg-card mt-3 overflow-hidden rounded-xl border">
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

            <!-- ── 4. Services ──
                A dense panel of rows, not a grid of cards: this is the last
                block on an operations page, and six services as cards would
                take back all the height the reorder just freed. Icon, name,
                one line, and either the action or the reason there isn't one.
            -->
            <section class="mt-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.13em] uppercase">Leistungen</h2>
                    <span class="text-muted-foreground text-[11px]">{{ bookableCount }} von {{ SERVICES.length }} verfügbar</span>
                </div>

                <div class="border-border bg-card mt-3 grid overflow-hidden rounded-xl border sm:grid-cols-2 xl:grid-cols-3">
                    <component
                        :is="isLaunchable(service) ? 'button' : 'div'"
                        v-for="service in SERVICES"
                        :key="service.key"
                        :type="isLaunchable(service) ? 'button' : undefined"
                        class="border-border group flex items-center gap-3 border-r border-b px-4 py-3 text-left transition-colors last:border-b-0"
                        :class="isLaunchable(service) ? 'hover:bg-muted/60 cursor-pointer' : ''"
                        @click="startService(service)"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                            :class="service.availability === 'bookable' ? 'bg-brand-green/10 text-brand-teal' : 'bg-muted text-muted-foreground/70'"
                        >
                            <component :is="service.icon" class="size-[18px]" aria-hidden="true" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[13.5px] font-semibold"
                                :class="service.availability === 'bookable' ? 'text-brand-teal' : 'text-muted-foreground'"
                            >
                                {{ service.title }}
                            </span>
                            <span class="text-muted-foreground block truncate text-[11.5px]">{{ service.summary }}</span>
                        </span>

                        <!-- The right edge always says what can be done here:
                             the action, or why there is none. -->
                        <span v-if="isLaunchable(service)" class="text-brand-orange flex shrink-0 items-center gap-1 text-[12px] font-semibold">
                            <span class="hidden sm:inline">Starten</span>
                            <MdiArrowRight class="size-4 transition-transform duration-150 group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>

                        <span
                            v-else
                            class="bg-muted text-muted-foreground shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-medium whitespace-nowrap"
                        >
                            {{ service.availability === 'bookable' ? 'Keine Berechtigung' : AVAILABILITY_LABELS[service.availability] }}
                        </span>
                    </component>
                </div>
            </section>
        </div>

        <button
            type="button"
            aria-label="Einführung ansehen"
            title="Einführung ansehen"
            class="focus-visible:ring-brand-green/40 fixed right-4 bottom-20 z-[60] flex items-center gap-2 rounded-full py-2.5 pr-4 pl-2.5 text-white shadow-lg transition-all duration-200 hover:shadow-xl focus:outline-none focus-visible:ring-2 md:right-8 md:bottom-8"
            style="background-color: #10393b"
            @click="openOnboarding"
        >
            <span class="flex h-6 w-6 items-center justify-center rounded-full" style="background-color: #01b990">
                <IconMdiPlay class="h-4 w-4" />
            </span>
            <span class="text-sm font-medium">Einführung</span>
        </button>

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
