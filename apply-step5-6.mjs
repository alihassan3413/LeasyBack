#!/usr/bin/env node
/**
 * LeasyBack V2 — Gutachten, steps 5 and 6 (statistics page, full test file).
 *
 * Run from the project root:
 *     node apply-step5-6.mjs --check     (verifies every edit, writes nothing)
 *     node apply-step5-6.mjs             (applies them)
 *
 * Only the lines named below are touched in existing files. If one edit cannot
 * be placed, nothing at all is written. Originals go to storage/app/step5-backup/.
 */
import fs from 'node:fs';
import path from 'node:path';

const CHECK_ONLY = process.argv.includes('--check');
const BACKUP_DIR = 'storage/app/step5-backup';
const raw = String.raw;

const NEW_FILES = {
    'app/Http/Controllers/Admin/AppraisalStatisticsController.php': raw`<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statistics for Gutachten orders (Vehicle Condition Appraisal brief): the
 * total and the counts for Requested, Scheduled and Completed, filterable by
 * date range, company, vehicle/registration number and status. No charts.
 *
 * The three brief statuses map onto the order statuses as:
 * REQUESTED = order_requested + order_placed, SCHEDULED = confirmed,
 * COMPLETED = completed. Total is every Gutachten order in the period.
 *
 * One order can cover several vehicles, so the vehicle filter matches an
 * order by any of them — the counts stay counts of orders.
 */
class AppraisalStatisticsController extends Controller
{
    private const SERVICE_TYPE = 'gutachten';

    private const STATUS_GROUPS = [
        'requested' => ['order_requested', 'order_placed'],
        'scheduled' => ['confirmed'],
        'completed' => ['completed'],
    ];

    /** GET admin/statistics/gutachten */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'company' => ['nullable', 'uuid'],
            'vehicle' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:requested,scheduled,completed'],
        ]);

        $filters = [
            'start_date' => $validated['start_date'] ?? '',
            'end_date' => $validated['end_date'] ?? '',
            'company' => $validated['company'] ?? '',
            'vehicle' => trim((string) ($validated['vehicle'] ?? '')),
            'status' => $validated['status'] ?? '',
        ];

        $base = $this->filtered($filters);

        return Inertia::render('Admin/Statistics/Appraisal', [
            'totals' => [
                'total' => (clone $base)->count(),
                'requested' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['requested'])->count(),
                'scheduled' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['scheduled'])->count(),
                'completed' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['completed'])->count(),
            ],
            'filters' => $filters,
            // Companies that have Gutachten orders, for the company filter.
            'companies' => DB::table('b2b as b')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')
                    ->from('leasyback_orders as o')
                    ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
                    ->whereColumn('v.b2b_id', 'b.b2b_id')
                    ->where('o.service_type', self::SERVICE_TYPE))
                ->orderBy('b.company_name')
                ->get(['b.b2b_id', 'b.company_name'])
                ->map(fn (object $company) => ['id' => $company->b2b_id, 'name' => $company->company_name])
                ->all(),
        ]);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filtered(array $filters): Builder
    {
        // Joined on the order's first vehicle: one row per order, and every
        // vehicle of an order belongs to the same company.
        $query = DB::table('leasyback_orders as o')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
            ->where('o.service_type', self::SERVICE_TYPE);

        if ($filters['start_date'] !== '') {
            $query->where('o.created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $filters['start_date'])->startOfDay());
        }

        if ($filters['end_date'] !== '') {
            $query->where('o.created_at', '<=', CarbonImmutable::createFromFormat('Y-m-d', $filters['end_date'])->endOfDay());
        }

        if ($filters['company'] !== '') {
            $query->where('v.b2b_id', $filters['company']);
        }

        if ($filters['vehicle'] !== '') {
            $term = '%'.addcslashes($filters['vehicle'], '%_\\').'%';

            $query->where(fn (Builder $q) => $q
                ->where('v.license_plate', 'like', $term)
                ->orWhere('v.vin', 'like', $term)
                ->orWhere('v.make', 'like', $term)
                ->orWhere('v.model', 'like', $term)
                // …or any further vehicle of the same order.
                ->orWhereExists(fn (Builder $linked) => $linked->selectRaw('1')
                    ->from('leasyback_order_vehicles as ov')
                    ->join('vehicles as lv', 'lv.vehicle_id', '=', 'ov.vehicle_id')
                    ->whereColumn('ov.order_id', 'o.id')
                    ->where(fn (Builder $match) => $match
                        ->where('lv.license_plate', 'like', $term)
                        ->orWhere('lv.vin', 'like', $term)
                        ->orWhere('lv.make', 'like', $term)
                        ->orWhere('lv.model', 'like', $term))));
        }

        if ($filters['status'] !== '') {
            $query->whereIn('o.order_status', self::STATUS_GROUPS[$filters['status']]);
        }

        return $query;
    }
}
`,

    'resources/js/pages/Admin/Statistics/Appraisal.vue': raw`<script setup lang="ts">
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
`,

    'tests/Feature/B2b/AppraisalOrderFlowTest.php': raw`<?php

namespace Tests\Feature\B2b;

use App\Mail\Orders\AppraisalCompletedMail;
use App\Mail\Orders\AppraisalScheduledMail;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Modules\UserProfile\Order\Models\OrderVehicle;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The Gutachten (Vehicle Condition Appraisal) service end to end through the
 * Admin pages, against its brief of 17 September 2026: operations see the
 * whole order, confirm or adjust the appointment, coordinate the inspection
 * site, add the report that completes the order; the customer sees it all;
 * the two timed tasks; the statistics view — and that the other services stay
 * as they were. Booking itself is covered by AppraisalOrderBookingTest.
 */
class AppraisalOrderFlowTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private B2B $company;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake(OrderAttachment::DISK);

        $this->company = $this->makeCompany('Flotte GmbH');
        $this->owner = $this->makeOwner($this->company);
        $this->admin = $this->makeAdmin();
    }

    // ------------------------------------------------------- operations

    public function test_operations_see_the_whole_order(): void
    {
        $order = $this->bookAppraisal(2);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame('gutachten', $detail['service_type']);
        $this->assertSame('VW Leasing', data_get($detail, 'request_payload.leasing_company'));
        $this->assertSame('Köln', data_get($detail, 'request_payload.vehicle_location.city'));
        $this->assertCount(2, $detail['vehicles']);
        $this->assertSame(
            OrderVehicle::where('order_id', $order->id)->orderBy('position')->pluck('vehicle_id')->all(),
            array_column($detail['vehicles'], 'vehicle_id'),
        );
        $this->assertTrue($detail['editable']['collection']);
        $this->assertSame('appraisal_schedule_appointment', $detail['tasks']['next']['key']);

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['service' => 'gutachten']))->assertOk();
    }

    public function test_confirming_the_appointment_schedules_the_order(): void
    {
        $order = $this->bookAppraisal(2);

        $this->schedule($order)->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $order->fresh()->order_status);

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame('08:00-12:00', $logistics->confirmed_collection_time_slot);
        $this->assertSame('DEKRA Köln', $logistics->inspection_site_name);
        $this->assertTrue((bool) $logistics->transport_confirmed);

        Mail::assertQueued(AppraisalScheduledMail::class);
        $this->assertSame('appraisal_add_report', app(AdminQueryService::class)->orderDetail($order->id)['tasks']['next']['key']);

        // The customer sees the confirmed appointment and the inspection site.
        $this->actingAs($this->owner)
            ->get(route('orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.collection.confirmed_collection_time_slot', '08:00-12:00')
                ->where('order.collection.inspection_site_name', 'DEKRA Köln')
                ->where('order.collection.transport_confirmed', true)
                ->has('order.vehicles', 2));
    }

    public function test_the_appointment_can_be_adjusted_afterwards(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order)->assertSessionHasNoErrors();

        $moved = now()->addDays(9)->toDateString();

        $this->schedule($order, [
            'confirmed_collection_date' => $moved,
            'confirmed_time_from' => '13:00',
            'confirmed_time_to' => '16:00',
        ])->assertSessionHasNoErrors();

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame($moved, substr((string) $logistics->confirmed_collection_date, 0, 10));
        $this->assertSame('13:00-16:00', $logistics->confirmed_collection_time_slot);
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_a_date_needs_a_full_time_window_of_two_hours(): void
    {
        $order = $this->bookAppraisal(1);

        $this->schedule($order, ['confirmed_time_from' => null, 'confirmed_time_to' => null])
            ->assertSessionHasErrors('confirmed_time_from');

        $this->schedule($order, ['confirmed_time_from' => '10:00', 'confirmed_time_to' => '11:30'])
            ->assertSessionHasErrors('confirmed_time_to');

        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    public function test_the_order_cannot_be_completed_without_the_report(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order);

        $this->assertNotContains('completed', app(AdminQueryService::class)->orderDetail($order->id)['available_transitions']);

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'completed'])->assertSessionHasErrors();
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_the_report_cannot_be_added_before_scheduling(): void
    {
        $order = $this->bookAppraisal(1);

        $this->uploadReport($order)->assertSessionHasErrors('files');

        $this->assertSame(0, OrderAttachment::where('order_id', $order->id)->count());
        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    public function test_the_report_completes_the_order_and_reaches_the_customer(): void
    {
        $order = $this->bookAppraisal(2);
        $this->schedule($order);

        $this->uploadReport($order)->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->order_status);
        Mail::assertQueued(AppraisalCompletedMail::class);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);
        $this->assertTrue($detail['tasks']['is_closed']);
        $this->assertCount(1, $detail['attachments']);

        $this->actingAs($this->owner)
            ->get(route('orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.order_status', 'completed')
                ->has('order.attachments', 1)
                ->where('order.attachments.0.kind', 'final_document')
                ->where('order.attachments.0.original_name', 'gutachten.pdf'));
    }

    public function test_a_completed_order_always_keeps_a_report(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order);
        $this->uploadReport($order);

        $first = OrderAttachment::where('order_id', $order->id)->sole();

        $this->adminDelete(route('admin.orders.appraisal.report.destroy', $first->id))->assertSessionHasErrors('files');
        $this->assertSame(1, OrderAttachment::where('order_id', $order->id)->count());

        // A corrected report is added first, then the old one can go.
        $this->uploadReport($order, 'gutachten-korrigiert.pdf')->assertSessionHasNoErrors();
        $this->adminDelete(route('admin.orders.appraisal.report.destroy', $first->id))->assertSessionHasNoErrors();

        $this->assertSame('gutachten-korrigiert.pdf', OrderAttachment::where('order_id', $order->id)->sole()->original_name);
        $this->assertSame('completed', $order->fresh()->order_status);
    }

    public function test_an_appraisal_never_enters_the_repair_process(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order);

        $this->assertNotContains('vehicle_collected', TransitionOrderStatus::allowedNextStatuses('confirmed', true, true));
        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'vehicle_collected'])->assertSessionHasErrors();
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    // ----------------------------------------------------- traffic lights

    public function test_the_appointment_task_turns_yellow_at_24_hours_and_red_at_48_hours(): void
    {
        $order = $this->bookAppraisal(1);

        $this->assertSame('green', $this->taskPriority($order, 'appraisal_schedule_appointment'));

        $this->travel(25)->hours();
        $this->assertSame('yellow', $this->taskPriority($order, 'appraisal_schedule_appointment'));

        $this->travel(24)->hours();
        $this->assertSame('red', $this->taskPriority($order, 'appraisal_schedule_appointment'));
    }

    public function test_the_report_task_counts_calendar_days_from_the_appointment_date(): void
    {
        $order = $this->bookAppraisal(1);
        $date = now()->addDays(2)->toDateString();
        $this->schedule($order, ['confirmed_collection_date' => $date]);

        $noon = CarbonImmutable::parse($date.' 12:00:00', 'Europe/Berlin');

        $this->assertSame('green', $this->taskPriority($order, 'appraisal_add_report'), 'before the appointment');

        $this->travelTo($noon);
        $this->assertSame('green', $this->taskPriority($order, 'appraisal_add_report'), 'on the appointment day');

        $this->travelTo($noon->addDay());
        $this->assertSame('yellow', $this->taskPriority($order, 'appraisal_add_report'), 'the day after');

        $this->travelTo($noon->addDays(2));
        $this->assertSame('red', $this->taskPriority($order, 'appraisal_add_report'), 'from the second day after');
    }

    // ------------------------------------------------------ customer list

    public function test_the_customer_order_list_speaks_for_every_vehicle(): void
    {
        $order = $this->bookAppraisal(3);
        $plates = Vehicle::whereIn('vehicle_id', OrderVehicle::where('order_id', $order->id)->orderBy('position')->pluck('vehicle_id'))
            ->pluck('license_plate', 'vehicle_id');
        $lastVehicleId = OrderVehicle::where('order_id', $order->id)->orderByDesc('position')->value('vehicle_id');

        $service = app(VehicleService::class);

        $rows = $service->listCustomerOrders($this->company->b2b_id, 'B2B', [], $this->owner);
        $this->assertCount(1, $rows, 'one order, not one row per vehicle');
        $this->assertSame('gutachten', $rows[0]['service_type']);
        $this->assertSame(3, $rows[0]['vehicle_count']);
        $this->assertSame('Köln', $rows[0]['location']);

        // Found by a vehicle that is not the first one of the order.
        $found = $service->listCustomerOrders($this->company->b2b_id, 'B2B', ['search' => $plates[$lastVehicleId]], $this->owner);
        $this->assertCount(1, $found);
        $this->assertSame($order->id, $found[0]['id']);
    }

    // -------------------------------------------------------- statistics

    public function test_the_statistics_count_the_three_statuses_and_filter(): void
    {
        $this->bookAppraisal(1);

        $scheduled = $this->bookAppraisal(2);
        $this->schedule($scheduled);

        $done = $this->bookAppraisal(1);
        $this->schedule($done);
        $this->uploadReport($done);

        // Other services are not counted.
        $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'confirmed');

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Statistics/Appraisal')
                ->where('totals.total', 3)
                ->where('totals.requested', 1)
                ->where('totals.scheduled', 1)
                ->where('totals.completed', 1)
                ->has('companies', 1));

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['status' => 'completed']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 1)->where('totals.completed', 1));

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['company' => $this->company->b2b_id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 3));

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['start_date' => now()->addDay()->toDateString()]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 0));

        // The vehicle filter finds an order by its second vehicle too.
        $secondVehicleId = OrderVehicle::where('order_id', $scheduled->id)->orderByDesc('position')->value('vehicle_id');
        $plate = Vehicle::where('vehicle_id', $secondVehicleId)->value('license_plate');

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['vehicle' => $plate]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 1)->where('totals.scheduled', 1));
    }

    // ------------------------------------------------ other services kept

    public function test_the_other_services_are_unchanged(): void
    {
        $this->assertSame(['vehicle_collected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('confirmed', true));
        $this->assertSame(['inspected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('vehicle_collected', true));

        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');
        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame('neutral', $detail['tasks']['priority']);
        $this->assertSame([], $detail['vehicles'], 'a single-vehicle order lists no further vehicles');
        $this->assertFalse(TransitionOrderStatus::isAppraisalOrder($order));
        $this->assertFalse(TransitionOrderStatus::isShortPathOrder($order));
    }

    // ------------------------------------------------------------ helpers

    private function bookAppraisal(int $vehicleCount): LeasybackOrder
    {
        $vehicles = [];

        for ($i = 0; $i < $vehicleCount; $i++) {
            $vehicles[] = $this->makeB2bVehicle($this->company);
        }

        $known = LeasybackOrder::where('service_type', 'gutachten')->pluck('id')->all();

        $this->actingAs($this->owner)->from('/dashboard')->post(route('orders.b2b.appraisal.store'), [
            'vehicle_ids' => array_map(fn (Vehicle $vehicle) => $vehicle->vehicle_id, $vehicles),
            'billing_address' => ['name' => 'Zentrale', 'street' => 'Hauptstr.', 'number' => '1', 'zip_code' => '50667', 'city' => 'Köln', 'country' => 'Deutschland'],
            'vehicle_location' => ['street' => 'Domstr.', 'number' => '3', 'zip_code' => '50667', 'city' => 'Köln'],
            'pickup_requested' => true,
            'return_transport' => false,
            'leasing_company' => 'VW Leasing',
            'preferred_date' => now()->addDays(5)->toDateString(),
            'time_from' => '08:00',
            'time_to' => '12:00',
            'location_contact' => ['name' => 'Anna Standort', 'phone' => '+49 221 1234', 'email' => 'anna@example.test'],
            'notes' => 'Schlüssel beim Empfang',
        ])->assertSessionHasNoErrors();

        return LeasybackOrder::where('service_type', 'gutachten')->whereNotIn('id', $known)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function schedule(LeasybackOrder $order, array $overrides = []): TestResponse
    {
        return $this->adminPatch(route('admin.orders.appraisal.schedule', $order->id), array_replace([
            'confirmed_collection_date' => now()->addDays(6)->toDateString(),
            'confirmed_time_from' => '08:00',
            'confirmed_time_to' => '12:00',
            'inspection_site_name' => 'DEKRA Köln',
            'inspection_site_address' => 'Prüfweg 1, 50667 Köln',
            'transport_confirmed' => true,
        ], $overrides));
    }

    private function uploadReport(LeasybackOrder $order, string $name = 'gutachten.pdf'): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')
            ->post(route('admin.orders.appraisal.report.store', $order->id), [
                'files' => [UploadedFile::fake()->create($name, 300, 'application/pdf')],
            ]);
    }

    /** The colour of the order's next Admin task, after checking it is the expected one. */
    private function taskPriority(LeasybackOrder $order, string $expectedTask): string
    {
        $tasks = app(AdminQueryService::class)->orderDetail($order->id)['tasks'];

        $this->assertSame($expectedTask, $tasks['next']['key'] ?? null);

        return $tasks['priority'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adminPatch(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->patch($url, $data);
    }

    private function adminDelete(string $url): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->delete($url);
    }
}
`,
};

const EDITS = {
    /* 1 ─ the statistics route, copied from the Unfallschaden one so it sits in the same group */
    'routes/admin.php': [
        {
            after: 'use App\\Http\\Controllers\\Admin\\AppraisalPositionController;',
            insert: 'use App\\Http\\Controllers\\Admin\\AppraisalStatisticsController;',
        },
        {
            cloneStatement: 'AccidentDamageStatisticsController::class',
            swaps: [
                ['AccidentDamageStatisticsController', 'AppraisalStatisticsController'],
                ['unfallschaden', 'gutachten'],
                ['accident-damage', 'appraisal'],
            ],
            mustContain: ['AppraisalStatisticsController::class', 'gutachten', 'appraisal'],
            comment: '// Gutachten: the same statistics view for Vehicle Condition Appraisal orders.',
            done: 'AppraisalStatisticsController::class',
        },
    ],

    /* 2 ─ "Statistik öffnen" on the Gutachten tab of the Admin order list */
    'resources/js/pages/Admin/Orders/Index.vue': [
        {
            replace: `v-if="serviceFilter === 'unfallschaden'"`,
            with: `v-if="serviceFilter === 'unfallschaden' || serviceFilter === 'gutachten'"`,
        },
        {
            replace: `:href="route('admin.statistics.accident-damage')"`,
            with: `:href="route(serviceFilter === 'gutachten' ? 'admin.statistics.appraisal' : 'admin.statistics.accident-damage')"`,
        },
    ],
};

/* ───────────────────────────── the engine ───────────────────────────── */

function countOf(text, needle) {
    return text.split(needle).length - 1;
}

function has(text, marker) {
    return marker instanceof RegExp ? marker.test(text) : text.includes(marker);
}

/** Returns the new text, or throws with a message naming the edit. */
function applyEdit(text, edit) {
    const marker = edit.done ?? edit.with ?? edit.insert;

    if (has(text, marker)) {
        return { text, skipped: true };
    }

    // New methods go in front of the class's closing brace — the last "}" of the file.
    if (edit.appendToClass !== undefined) {
        const at = text.lastIndexOf('}');

        if (at < 0) {
            throw new Error('no closing brace found to append to');
        }

        return { text: text.slice(0, at) + edit.appendToClass.replace(/^\n/, '\n') + text.slice(at), skipped: false };
    }

    // Copies one existing route statement (it may span several lines) and
    // renames it, so the copy sits in the same group with the same middleware.
    if (edit.cloneStatement !== undefined) {
        const lines = text.split('\n');
        const hits = lines.map((line, index) => (line.includes(edit.cloneStatement) ? index : -1)).filter((index) => index >= 0);

        if (hits.length !== 1) {
            throw new Error('expected 1 line, found ' + hits.length + ' containing: ' + edit.cloneStatement);
        }

        let start = hits[0];
        let end = hits[0];

        while (start > 0 && !lines[start].trim().startsWith('Route::')) {
            start--;
        }

        while (end < lines.length - 1 && !lines[end].trim().endsWith(';')) {
            end++;
        }

        let copy = lines.slice(start, end + 1).join('\n');

        for (const [from, to] of edit.swaps) {
            copy = copy.split(from).join(to);
        }

        for (const needed of edit.mustContain) {
            if (!copy.includes(needed)) {
                throw new Error('the copied route does not contain "' + needed + '". The statement found was:\n' + lines.slice(start, end + 1).join('\n'));
            }
        }

        const indent = lines[start].match(/^\s*/)[0];
        lines.splice(end + 1, 0, '', indent + edit.comment, copy);

        return { text: lines.join('\n'), skipped: false };
    }

    if (edit.replace !== undefined) {
        const expected = edit.count ?? 1;
        const found = countOf(text, edit.replace);

        if (found !== expected) {
            throw new Error('expected ' + expected + ' occurrence(s), found ' + found + ' of: ' + edit.replace);
        }

        return { text: text.split(edit.replace).join(edit.with), skipped: false };
    }

    const anchor = edit.after ?? edit.before;
    const lines = text.split('\n');
    const hits = lines.map((line, index) => (line.includes(anchor) ? index : -1)).filter((index) => index >= 0);

    if (hits.length !== 1) {
        throw new Error('expected 1 line, found ' + hits.length + ' containing: ' + anchor);
    }

    const at = edit.after !== undefined ? hits[0] + 1 : hits[0];
    lines.splice(at, 0, ...edit.insert.split('\n'));

    return { text: lines.join('\n'), skipped: false };
}

if (!fs.existsSync('artisan')) {
    console.error('Run this from the LeasyBack project root (the folder that contains "artisan").');
    process.exit(1);
}

const results = [];
const problems = [];

for (const [file, edits] of Object.entries(EDITS)) {
    if (!fs.existsSync(file)) {
        problems.push(file + '\n    file not found');
        continue;
    }

    const original = fs.readFileSync(file, 'utf8');
    const crlf = original.includes('\r\n');
    let text = original.replace(/\r\n/g, '\n');
    let applied = 0;
    let skipped = 0;

    edits.forEach((edit, index) => {
        try {
            const outcome = applyEdit(text, edit);
            text = outcome.text;
            outcome.skipped ? skipped++ : applied++;
        } catch (error) {
            problems.push(file + '\n    edit ' + (index + 1) + ' of ' + edits.length + ': ' + error.message);
        }
    });

    results.push({ file, original, updated: crlf ? text.replace(/\n/g, '\r\n') : text, applied, skipped });
}

if (problems.length) {
    console.error('\nNOTHING WAS CHANGED. These edits could not be placed:\n');
    problems.forEach((problem) => console.error('  ' + problem + '\n'));
    console.error('Paste this output back and the edits will be adjusted.');
    process.exit(1);
}

for (const result of results) {
    const state = result.applied === 0 ? 'already up to date' : result.applied + ' edit(s)' + (result.skipped ? ', ' + result.skipped + ' already there' : '');
    console.log((CHECK_ONLY ? 'OK       ' : result.applied ? 'UPDATED  ' : 'SKIPPED  ') + result.file + '  (' + state + ')');

    if (!CHECK_ONLY && result.applied > 0) {
        const backup = path.join(BACKUP_DIR, result.file);
        fs.mkdirSync(path.dirname(backup), { recursive: true });

        if (!fs.existsSync(backup)) {
            fs.writeFileSync(backup, result.original);
        }

        fs.writeFileSync(result.file, result.updated);
    }
}

for (const [file, content] of Object.entries(NEW_FILES)) {
    const exists = fs.existsSync(file);
    const same = exists && fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n') === content;

    console.log((CHECK_ONLY ? 'OK       ' : same ? 'SKIPPED  ' : 'CREATED  ') + file + (same ? '  (already there)' : '  (new file)'));

    if (!CHECK_ONLY && !same) {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.writeFileSync(file, content);
    }
}

console.log(CHECK_ONLY ? '\nCheck passed. Run again without --check to apply.' : '\nDone. Originals are in ' + BACKUP_DIR + '/');
