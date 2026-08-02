<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\InspectionStation;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VehicleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    public function test_non_admin_cannot_view_the_vehicle_list(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->get(route('admin.vehicles.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_all_vehicles(): void
    {
        $admin = $this->admin();
        Vehicle::factory()->create(['license_plate' => 'K LB 1']);
        Vehicle::factory()->create(['license_plate' => 'K LB 2']);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Vehicles/Index')
                ->has('vehicles.data', 2)
            );
    }

    public function test_admin_can_view_a_vehicle_detail(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'license_plate' => 'K LB 1']);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Vehicles/Show')
                ->where('vehicle.vehicle_id', $vehicle->vehicle_id)
                ->where('vehicle.license_plate', 'K LB 1')
                ->where('vehicle.user_id', $owner->id)
            );
    }

    /**
     * Regression test for a real, silently-corrupted bug found while
     * building this checkpoint: AdminQueryService::enrichVehicles()'s
     * vehicle_documents query still selected the literal string 's3_key'
     * (the column was renamed to `path` in an earlier migration) — SQLite
     * didn't error, it silently returned the fake string "s3_key" as every
     * document's path instead of the real value.
     */
    public function test_vehicle_detail_includes_the_customers_uploaded_documents(): void
    {
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        VehicleDocument::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'document_type' => 'Leasingvertrag',
            'path' => 'vehicle-documents/real-path.pdf',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('vehicle.documents', 1)
                ->where('vehicle.documents.0.document_type', 'Leasingvertrag')
                ->where('vehicle.documents.0.path', 'vehicle-documents/real-path.pdf')
            );
    }

    /**
     * Regression test for a second real bug found alongside the one above:
     * AdminQueryService::reportDocuments() still read $document->s3_key
     * (also renamed to `path`) and generated the signed URL from the wrong
     * disk (the TIM/s3 disk instead of the `documents` disk report
     * documents actually live on) — signed_url was silently always null.
     */
    public function test_vehicle_detail_includes_report_documents_with_a_working_signed_url(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('vehicle-reports/AUF-1/rechnung.pdf', 'content');
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'auftragsnummer' => 'AUF-1']);
        VehicleReportDocument::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => $order->auftragsnummer,
            'path' => 'vehicle-reports/AUF-1/rechnung.pdf',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('vehicle.order_history.0.report_documents', 1)
                ->where('vehicle.order_history.0.report_documents.0.path', 'vehicle-reports/AUF-1/rechnung.pdf')
                ->where('vehicle.order_history.0.report_documents.0.signed_url', fn ($url) => $url !== null)
            );
    }

    /**
     * The Admin detail page renders the customer dashboard's
     * VehicleExpandedPanel.vue through lib/adminVehicle.ts's adapter, which
     * needs three things enrichVehicles() does not load for the list:
     * `request_payload` (Besichtigungsort), `status_updates` (the customer
     * flow timeline) and `offers`. `available_transitions` comes along for
     * the per-order three-dots action menu.
     */
    public function test_vehicle_detail_hydrates_each_order_for_the_expanded_panel(): void
    {
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-PANEL',
            'order_status' => OrderStatus::Confirmed->value,
            'request_payload' => ['besichtigungsort' => ['name' => 'TÜV SÜD Köln', 'ort' => 'Köln']],
        ]);
        OrderStatusUpdate::create([
            'auftragsnummer' => $order->auftragsnummer,
            'old_status' => 'order_placed',
            'new_status' => 'confirmed',
            'updated_by' => 'tuvsud',
            'auth_source' => 'api',
        ]);
        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.order_history.0.request_payload.besichtigungsort.name', 'TÜV SÜD Köln')
                ->has('vehicle.order_history.0.status_updates', 1)
                ->where('vehicle.order_history.0.status_updates.0.new_status', 'confirmed')
                ->has('vehicle.order_history.0.offers', 1)
                ->where('vehicle.order_history.0.offers.0.offer_status', 'draft')
                ->where('vehicle.order_history.0.available_transitions', fn (Collection $transitions) => $transitions->contains('inspected')
                    && ! $transitions->contains('order_placed'))
            );
    }

    /**
     * The counterpart of the test above: that hydration is deliberately
     * detail-only, so the list page — ten rows, none of which renders a
     * payload, a status trail or an offer until it is expanded — does not
     * pay for it on every row.
     */
    public function test_vehicle_list_does_not_load_the_detail_only_order_payloads(): void
    {
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('vehicles.data.0.order_history.0')
                ->missing('vehicles.data.0.order_history.0.request_payload')
                ->missing('vehicles.data.0.order_history.0.status_updates')
                ->missing('vehicles.data.0.order_history.0.offers')
                ->where('expandedVehicle', null)
            );
    }

    /**
     * Expanding a row in the list (v1's Admin vehicle table behaviour) asks
     * for exactly one fully-hydrated vehicle through `?expanded=`, so the
     * in-place panel gets its data without the list loading it for every row.
     */
    public function test_expanding_a_list_row_hydrates_only_that_vehicle(): void
    {
        $admin = $this->admin();
        $expanded = Vehicle::factory()->create();
        $other = Vehicle::factory()->create();
        LeasybackOrder::factory()->create([
            'vehicle_id' => $expanded->vehicle_id,
            'auftragsnummer' => 'AUF-EXPAND',
            'request_payload' => ['besichtigungsort' => ['name' => 'TÜV SÜD Köln']],
        ]);
        LeasybackOrder::factory()->create(['vehicle_id' => $other->vehicle_id]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['expanded' => $expanded->vehicle_id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('expandedVehicle.vehicle_id', $expanded->vehicle_id)
                ->where('expandedVehicle.order_history.0.request_payload.besichtigungsort.name', 'TÜV SÜD Köln')
                ->has('expandedVehicle.order_history.0.status_updates')
                ->has('expandedVehicle.order_history.0.offers')
                ->has('vehicles.data', 2)
            );
    }

    public function test_expanding_an_unknown_vehicle_leaves_the_list_usable(): void
    {
        $admin = $this->admin();
        Vehicle::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['expanded' => fake()->uuid()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('expandedVehicle', null)
                ->has('vehicles.data', 1)
            );
    }

    /**
     * The list row's action menu needs to know which statuses it may offer.
     * That is an enum lookup rather than a query, so unlike the rest of the
     * detail-only hydration it is computed for every row.
     */
    public function test_list_rows_carry_the_current_orders_allowed_transitions(): void
    {
        $admin = $this->admin();
        $withOrder = Vehicle::factory()->create();
        LeasybackOrder::factory()->create([
            'vehicle_id' => $withOrder->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
        ]);
        $withoutOrder = Vehicle::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['search' => $withOrder->license_plate]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.data.0.current_order_transitions', fn (Collection $transitions) => $transitions->contains('inspected')
                    && ! $transitions->contains('order_placed'))
            );

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['search' => $withoutOrder->license_plate]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.data.0.current_order_id', null)
                ->where('vehicles.data.0.current_order_transitions', [])
            );
    }

    /**
     * The panel links a customer document by `url`; the list only ever
     * showed its name, so enrichVehicles() never generated one.
     */
    public function test_vehicle_detail_documents_carry_a_signed_url(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('vehicle-documents/vertrag.pdf', 'content');
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        VehicleDocument::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'path' => 'vehicle-documents/vertrag.pdf',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.documents.0.url', fn ($url) => $url !== null)
            );
    }

    /**
     * Guards lib/adminStatus.ts's ADMIN_ORDER_STATUS_FILTERS against drifting
     * away from the enum again: every chip it offers has to be a status the
     * list endpoint will actually accept. It previously offered `completed`,
     * which is not an OrderStatus, so that chip 302'd the page back with a
     * validation error instead of filtering.
     */
    public function test_every_admin_status_filter_is_accepted_by_the_list_endpoints(): void
    {
        $admin = $this->admin();

        foreach (OrderStatus::values() as $status) {
            $this->actingAs($admin)
                ->get(route('admin.vehicles.index', ['status' => $status]))
                ->assertOk();

            $this->actingAs($admin)
                ->get(route('admin.orders.index', ['status' => $status]))
                ->assertOk();
        }

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['status' => 'completed']))
            ->assertRedirect();
    }

    /**
     * Admin order creation needs no admin-specific route: VehicleScopeService's
     * Admin branch is unfiltered, so orders.store already accepts an admin
     * booking for a customer's vehicle. This pins that, since the Admin row
     * menu's "Auftrag erstellen" depends on it.
     */
    public function test_admin_can_create_an_order_for_a_customers_vehicle(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'vehicle_belongs' => 'B2C']);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'tuvsud',
            'created_by_user_id' => $admin->id,
        ]);
    }

    public function test_a_non_admin_still_cannot_create_an_order_for_someone_elses_vehicle(): void
    {
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'vehicle_belongs' => 'B2C']);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud', 'is_active' => true]);

        $this->actingAs($stranger)
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('leasyback_orders', 0);
    }

    /** The list/detail pages hand the picker its stations, so no extra endpoint is needed. */
    public function test_vehicle_pages_provide_the_inspection_stations_for_the_order_picker(): void
    {
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        InspectionStation::factory()->create(['provider' => 'tuvsud', 'is_active' => true]);
        InspectionStation::factory()->create(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('stations', 1));

        $this->actingAs($admin)
            ->get(route('admin.vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('stations', 1));
    }

    /**
     * `can_pull_documents` drives the row menu's "Dokumente abrufen" entry.
     * It has to be a computed boolean: the Gutachtennummer lives in the
     * order's raw `response_body`, which is a third-party payload the
     * frontend has no business receiving.
     */
    public function test_pull_documents_availability_is_exposed_without_the_raw_response_body(): void
    {
        $admin = $this->admin();
        $ready = Vehicle::factory()->create(['license_plate' => 'K PULL 1']);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $ready->vehicle_id,
            'leasyback_partner' => 'tuvsud',
            'response_body' => 987654,
        ]);
        $notReady = Vehicle::factory()->create(['license_plate' => 'K PULL 2']);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $notReady->vehicle_id,
            'leasyback_partner' => 'tuvsud',
            'response_body' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['search' => 'K PULL 1']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.data.0.can_pull_documents', true)
                ->missing('vehicles.data.0.order_history.0.response_body')
            );

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['search' => 'K PULL 2']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('vehicles.data.0.can_pull_documents', false));
    }

    public function test_has_open_order_blocks_a_second_order(): void
    {
        $admin = $this->admin();
        $busy = Vehicle::factory()->create(['license_plate' => 'K OPEN 1']);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $busy->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
        ]);
        $free = Vehicle::factory()->create(['license_plate' => 'K OPEN 2']);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $free->vehicle_id,
            'order_status' => OrderStatus::Cancelled->value,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['search' => 'K OPEN 1']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('vehicles.data.0.has_open_order', true));

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index', ['search' => 'K OPEN 2']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('vehicles.data.0.has_open_order', false));
    }

    public function test_show_returns_404_for_unknown_vehicle(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.vehicles.show', fake()->uuid()))
            ->assertNotFound();
    }

    public function test_admin_can_create_a_vehicle_on_behalf_of_a_b2c_customer(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $response = $this->actingAs($admin)
            ->post(route('admin.vehicles.store'), [
                'license_plate' => 'K LB 2026',
                'vehicle_belongs' => 'B2C',
                'b2c_user_id' => $customer->id,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vehicles', [
            'license_plate' => 'K LB 2026',
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $customer->id,
        ]);
    }

    public function test_non_admin_cannot_create_a_vehicle_on_behalf_of_a_customer(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->post(route('admin.vehicles.store'), [
                'license_plate' => 'K LB 2026',
                'vehicle_belongs' => 'B2C',
                'b2c_user_id' => $customer->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('vehicles', ['license_plate' => 'K LB 2026']);
    }
}
