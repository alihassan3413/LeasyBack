<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
