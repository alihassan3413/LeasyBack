<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The Sanctum API (`vehicle_order_api_routes.php`) must enforce exactly what
 * the session routes enforce: the active account, the company permission of
 * the web counterpart, company isolation and a member's vehicle scope.
 *
 * Every request is made with a real bearer token, and the auth guards and the
 * request-scoped B2bContext are reset between requests, so each call is
 * resolved exactly as an independent API request would be.
 */
class B2bApiAuthorizationTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private B2B $company;

    private User $companyAdmin;

    private User $standardUser;

    private User $readOnly;

    /** Standard user plus update, document and offer rights — but only their own vehicles. */
    private User $ownScopeMember;

    /** Full rights, but the membership itself has been deactivated. */
    private User $deactivatedMember;

    /** Full rights in the company, but the user account is deactivated. */
    private User $deactivatedAccount;

    private User $otherCompanyOwner;

    private Vehicle $fleetVehicle;

    private Vehicle $ownVehicle;

    private Vehicle $foreignVehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake('documents');
        Http::fake();

        $this->company = $this->makeCompany('Flotte A GmbH');
        $this->companyAdmin = $this->makePresetMember($this->company, B2bRolePreset::CompanyAdministrator);
        $this->standardUser = $this->makePresetMember($this->company, B2bRolePreset::StandardUser);
        $this->readOnly = $this->makePresetMember($this->company, B2bRolePreset::ReadOnly);
        $this->ownScopeMember = $this->makeMember($this->company, [
            ...B2bRolePreset::StandardUser->permissions()->toArray(),
            B2bPermission::UpdateVehicles->value,
            B2bPermission::UploadVehicleDocuments->value,
            B2bPermission::DeleteVehicleDocuments->value,
            B2bPermission::SelectOffers->value,
        ], 'own');
        $this->deactivatedMember = $this->makeOwner($this->company);
        DB::table('user_b2b')->where('user_id', $this->deactivatedMember->id)->update(['status' => 'inactive']);
        $this->deactivatedAccount = $this->makeOwner($this->company);
        $this->deactivatedAccount->forceFill(['is_active' => false])->save();

        $other = $this->makeCompany('Flotte B AG');
        $this->otherCompanyOwner = $this->makeOwner($other);

        $this->fleetVehicle = $this->makeB2bVehicle($this->company, ['created_by_user_id' => $this->companyAdmin->id]);
        $this->ownVehicle = $this->makeB2bVehicle($this->company, ['created_by_user_id' => $this->ownScopeMember->id]);
        $this->foreignVehicle = $this->makeB2bVehicle($other, ['created_by_user_id' => $this->otherCompanyOwner->id]);
    }

    // ------------------------------------------------------------ vehicles

    public function test_vehicle_creation_requires_vehicles_create(): void
    {
        $this->api($this->companyAdmin, 'POST', '/api/vehicle/create', $this->vehiclePayload('A-1'))->assertCreated();
        $this->api($this->standardUser, 'POST', '/api/vehicle/create', $this->vehiclePayload('A-2'))->assertCreated();

        $this->api($this->readOnly, 'POST', '/api/vehicle/create', $this->vehiclePayload('A-3'))->assertForbidden();
        $this->api($this->deactivatedMember, 'POST', '/api/vehicle/create', $this->vehiclePayload('A-4'))->assertForbidden();
        $this->api($this->deactivatedAccount, 'POST', '/api/vehicle/create', $this->vehiclePayload('A-5'))->assertForbidden();

        $this->assertSame(0, Vehicle::whereIn('license_plate', ['A-3', 'A-4', 'A-5'])->count());
    }

    public function test_a_created_vehicle_always_belongs_to_the_callers_own_company(): void
    {
        $this->api($this->otherCompanyOwner, 'POST', '/api/vehicle/create', [
            ...$this->vehiclePayload('B-1'),
            'b2b_id' => $this->company->b2b_id,
        ])->assertCreated();

        $this->assertSame($this->foreignVehicle->b2b_id, Vehicle::where('license_plate', 'B-1')->value('b2b_id'));
    }

    public function test_vehicle_update_requires_vehicles_update_and_scope(): void
    {
        $patch = fn (User $user, Vehicle $vehicle) => $this->api($user, 'PATCH', "/api/vehicle/{$vehicle->vehicle_id}", ['model' => 'Geändert '.$user->id]);

        $patch($this->companyAdmin, $this->fleetVehicle)->assertOk();
        $patch($this->ownScopeMember, $this->ownVehicle)->assertOk();

        $patch($this->standardUser, $this->fleetVehicle)->assertForbidden();
        $patch($this->readOnly, $this->fleetVehicle)->assertForbidden();
        $patch($this->ownScopeMember, $this->fleetVehicle)->assertNotFound();
        $patch($this->otherCompanyOwner, $this->fleetVehicle)->assertNotFound();
        $patch($this->deactivatedMember, $this->fleetVehicle)->assertForbidden();
        $patch($this->deactivatedAccount, $this->fleetVehicle)->assertForbidden();

        $this->assertSame('Geändert '.$this->companyAdmin->id, $this->fleetVehicle->fresh()->model);
    }

    public function test_profile_assignment_requires_vehicles_update(): void
    {
        $this->api($this->readOnly, 'PUT', "/api/vehicle/{$this->fleetVehicle->vehicle_id}", ['profile_id' => 1])->assertForbidden();
        $this->api($this->standardUser, 'PUT', "/api/vehicle/{$this->fleetVehicle->vehicle_id}", ['profile_id' => 1])->assertForbidden();
        $this->api($this->otherCompanyOwner, 'PUT', "/api/vehicle/{$this->fleetVehicle->vehicle_id}", ['profile_id' => 1])->assertNotFound();
        $this->api($this->deactivatedMember, 'PUT', "/api/vehicle/{$this->fleetVehicle->vehicle_id}", ['profile_id' => 1])->assertForbidden();

        // Allowed through the gate; refused on its own merits (no such profile).
        $this->api($this->companyAdmin, 'PUT', "/api/vehicle/{$this->fleetVehicle->vehicle_id}", ['profile_id' => 999999])->assertStatus(422);
    }

    public function test_finding_a_vehicle_respects_company_and_member_scope(): void
    {
        $find = fn (User $user, Vehicle $vehicle) => $this->api($user, 'GET', "/api/vehicle/find/{$vehicle->vehicle_id}/{$vehicle->b2b_id}");

        $find($this->companyAdmin, $this->fleetVehicle)->assertOk();
        $find($this->readOnly, $this->fleetVehicle)->assertOk();
        $find($this->ownScopeMember, $this->ownVehicle)->assertOk();

        $find($this->ownScopeMember, $this->fleetVehicle)->assertNotFound();
        $find($this->otherCompanyOwner, $this->fleetVehicle)->assertNotFound();
        $find($this->deactivatedMember, $this->fleetVehicle)->assertForbidden();
        $find($this->deactivatedAccount, $this->fleetVehicle)->assertForbidden();
    }

    public function test_listing_the_fleet_respects_company_and_member_scope(): void
    {
        $list = fn (User $user, string $ownerId) => $this->api($user, 'GET', "/api/vehicle/list/{$ownerId}");
        $ids = fn (TestResponse $response) => collect($response->json())->pluck('vehicle_id')->sort()->values()->all();

        $all = collect([$this->fleetVehicle->vehicle_id, $this->ownVehicle->vehicle_id])->sort()->values()->all();

        $this->assertSame($all, $ids($list($this->companyAdmin, $this->company->b2b_id)->assertOk()));
        $this->assertSame($all, $ids($list($this->readOnly, $this->company->b2b_id)->assertOk()));
        $this->assertSame([$this->ownVehicle->vehicle_id], $ids($list($this->ownScopeMember, $this->company->b2b_id)->assertOk()));

        $list($this->otherCompanyOwner, $this->company->b2b_id)->assertNotFound();
        $list($this->deactivatedMember, $this->company->b2b_id)->assertForbidden();
        $list($this->deactivatedAccount, $this->company->b2b_id)->assertForbidden();
    }

    public function test_the_dashboard_listing_respects_company_and_member_scope(): void
    {
        $ids = fn (User $user) => collect($this->api($user, 'GET', '/api/vehicle/list/report/status')->assertOk()->json())
            ->pluck('vehicle_id')->sort()->values()->all();

        $this->assertSame(
            collect([$this->fleetVehicle->vehicle_id, $this->ownVehicle->vehicle_id])->sort()->values()->all(),
            $ids($this->companyAdmin),
        );
        $this->assertSame([$this->ownVehicle->vehicle_id], $ids($this->ownScopeMember));
        $this->assertSame([$this->foreignVehicle->vehicle_id], $ids($this->otherCompanyOwner));

        $this->api($this->deactivatedMember, 'GET', '/api/vehicle/list/report/status')->assertForbidden();
        $this->api($this->deactivatedAccount, 'GET', '/api/vehicle/list/report/status')->assertForbidden();
    }

    public function test_a_member_without_vehicle_access_gets_nothing(): void
    {
        $membersOnly = $this->makeMember($this->company, [B2bPermission::ViewMembers->value]);

        $this->api($membersOnly, 'GET', '/api/vehicle/list/report/status')->assertForbidden();
        $this->api($membersOnly, 'GET', "/api/vehicle/list/{$this->company->b2b_id}")->assertForbidden();
    }

    // ----------------------------------------------------------- documents

    public function test_document_upload_requires_the_upload_permission_and_scope(): void
    {
        $upload = fn (User $user, Vehicle $vehicle) => $this->api($user, 'PUT', "/api/vehicle/{$vehicle->vehicle_id}/documents", [
            'file' => UploadedFile::fake()->create('vertrag.pdf', 100, 'application/pdf'),
            'document_type' => 'Leasingvertrag',
        ], multipart: true);

        $upload($this->companyAdmin, $this->fleetVehicle)->assertCreated();
        $upload($this->ownScopeMember, $this->ownVehicle)->assertCreated();

        $upload($this->standardUser, $this->fleetVehicle)->assertForbidden();
        $upload($this->readOnly, $this->fleetVehicle)->assertForbidden();
        $upload($this->ownScopeMember, $this->fleetVehicle)->assertNotFound();
        $upload($this->otherCompanyOwner, $this->fleetVehicle)->assertNotFound();
        $upload($this->deactivatedMember, $this->fleetVehicle)->assertForbidden();
        $upload($this->deactivatedAccount, $this->fleetVehicle)->assertForbidden();

        $this->assertSame(1, VehicleDocument::where('vehicle_id', $this->fleetVehicle->vehicle_id)->count());
    }

    public function test_reading_documents_respects_company_and_member_scope(): void
    {
        $document = $this->documentFor($this->fleetVehicle);
        $base = "/api/vehicle/{$this->fleetVehicle->vehicle_id}/documents";

        $this->api($this->readOnly, 'GET', $base)->assertOk()->assertJsonCount(1);
        $this->api($this->readOnly, 'GET', "{$base}/{$document->document_id}")->assertOk();

        $this->api($this->ownScopeMember, 'GET', $base)->assertNotFound();
        $this->api($this->ownScopeMember, 'GET', "{$base}/{$document->document_id}")->assertNotFound();
        $this->api($this->otherCompanyOwner, 'GET', $base)->assertNotFound();
        $this->api($this->otherCompanyOwner, 'GET', "{$base}/{$document->document_id}")->assertNotFound();
        $this->api($this->deactivatedMember, 'GET', $base)->assertForbidden();
        $this->api($this->deactivatedAccount, 'GET', $base)->assertForbidden();
    }

    public function test_document_deletion_requires_the_delete_permission_and_scope(): void
    {
        $fleetDocument = $this->documentFor($this->fleetVehicle);
        $ownDocument = $this->documentFor($this->ownVehicle);
        $delete = fn (User $user, VehicleDocument $document) => $this->api($user, 'DELETE', "/api/vehicle/{$document->vehicle_id}/documents/{$document->document_id}");

        $delete($this->readOnly, $fleetDocument)->assertForbidden();
        $delete($this->standardUser, $fleetDocument)->assertForbidden();
        $delete($this->ownScopeMember, $fleetDocument)->assertNotFound();
        $delete($this->otherCompanyOwner, $fleetDocument)->assertNotFound();
        $delete($this->deactivatedMember, $fleetDocument)->assertForbidden();
        $delete($this->deactivatedAccount, $fleetDocument)->assertForbidden();
        $this->assertNotNull($fleetDocument->fresh());

        $delete($this->ownScopeMember, $ownDocument)->assertOk();
        $delete($this->companyAdmin, $fleetDocument)->assertOk();
        $this->assertSame(0, VehicleDocument::count());
    }

    // -------------------------------------------------------------- offers

    public function test_listing_offers_respects_company_and_member_scope(): void
    {
        $offer = $this->publishedOfferOn($this->fleetVehicle);
        $list = fn (User $user) => $this->api($user, 'GET', "/api/vehicle/offers/customer/list/{$offer->auftragsnummer}");

        $list($this->companyAdmin)->assertOk()->assertJsonCount(1, 'offers');
        $list($this->readOnly)->assertOk()->assertJsonCount(1, 'offers');

        $list($this->ownScopeMember)->assertOk()->assertJsonCount(0, 'offers');
        $list($this->otherCompanyOwner)->assertOk()->assertJsonCount(0, 'offers');
        $list($this->deactivatedMember)->assertForbidden();
        $list($this->deactivatedAccount)->assertForbidden();
    }

    public function test_accepting_an_offer_requires_offers_select_and_scope(): void
    {
        $offer = $this->publishedOfferOn($this->fleetVehicle);
        $select = fn (User $user) => $this->api($user, 'POST', "/api/vehicle/offers/customer/select/{$offer->offer_id}");

        $select($this->readOnly)->assertForbidden();
        $select($this->standardUser)->assertForbidden();
        $select($this->ownScopeMember)->assertNotFound();
        $select($this->otherCompanyOwner)->assertNotFound();
        $select($this->deactivatedMember)->assertForbidden();
        $select($this->deactivatedAccount)->assertForbidden();
        $this->assertSame('published', $offer->fresh()->offer_status);

        $select($this->companyAdmin)->assertOk()->assertJsonPath('selected_offer.offer_status', 'selected');
        $this->assertSame('selected', $offer->fresh()->offer_status);
    }

    // -------------------------------------------------------------- orders

    public function test_creating_a_collection_order_requires_orders_create_and_scope(): void
    {
        $create = fn (User $user, Vehicle $vehicle) => $this->api($user, 'POST', "/api/order/b2b/create/{$vehicle->vehicle_id}", [
            'requested_collection_date' => now()->addDays(3)->toDateString(),
            'collection_address' => ['street' => 'Hauptstr. 1', 'zip_code' => '50667', 'city' => 'Köln'],
        ]);

        $create($this->readOnly, $this->fleetVehicle)->assertForbidden();
        $create($this->ownScopeMember, $this->fleetVehicle)->assertNotFound();
        $create($this->otherCompanyOwner, $this->fleetVehicle)->assertNotFound();
        $create($this->deactivatedMember, $this->fleetVehicle)->assertForbidden();
        $create($this->deactivatedAccount, $this->fleetVehicle)->assertForbidden();
        $this->assertSame(0, LeasybackOrder::where('vehicle_id', $this->fleetVehicle->vehicle_id)->count());

        $create($this->standardUser, $this->fleetVehicle)->assertOk()->assertJsonPath('order_status', 'order_requested');
        $create($this->ownScopeMember, $this->ownVehicle)->assertOk();

        $third = $this->makeB2bVehicle($this->company, ['created_by_user_id' => $this->companyAdmin->id]);
        $create($this->companyAdmin, $third)->assertOk();
    }

    public function test_the_other_order_creation_routes_are_gated_on_orders_create(): void
    {
        foreach (['tuvsud', 'others'] as $flow) {
            $this->api($this->readOnly, 'POST', "/api/order/{$flow}/create/{$this->fleetVehicle->vehicle_id}", [
                'station_id' => fake()->uuid(), 'termin' => now()->addWeek()->toIso8601String(),
            ])->assertForbidden();
            $this->api($this->deactivatedMember, 'POST', "/api/order/{$flow}/create/{$this->fleetVehicle->vehicle_id}", [])->assertForbidden();
        }

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_the_unprefixed_legacy_routes_enforce_the_same_rules(): void
    {
        $offer = $this->publishedOfferOn($this->fleetVehicle);

        $this->api($this->readOnly, 'POST', "/order/b2b/create/{$this->ownVehicle->vehicle_id}", [
            'requested_collection_date' => now()->addDays(3)->toDateString(),
            'collection_address' => ['street' => 'A', 'zip_code' => '1', 'city' => 'B'],
        ])->assertForbidden();
        $this->api($this->readOnly, 'POST', "/vehicle/offers/customer/select/{$offer->offer_id}")->assertForbidden();
        $this->api($this->readOnly, 'PATCH', "/vehicle/{$this->fleetVehicle->vehicle_id}", ['model' => 'X'])->assertForbidden();
        $this->api($this->ownScopeMember, 'GET', '/vehicle/list/report/status')->assertOk()->assertJsonCount(1);
    }

    // -------------------------------------------------- dual context & B2C

    public function test_a_private_customer_acting_as_a_company_is_held_to_their_membership(): void
    {
        $private = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $privateVehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null, 'b2c_user_id' => $private->id]);
        DB::table('user_b2b')->insert([
            'user_id' => $private->id, 'b2b_id' => $this->company->b2b_id, 'role' => 'member',
            'permissions' => json_encode(B2bRolePreset::ReadOnly->permissions()->toArray()),
            'vehicle_scope' => 'all', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Acting privately: their own car only, and full rights over it.
        $private->forceFill(['active_b2b_id' => null])->save();
        $this->assertSame([$privateVehicle->vehicle_id], collect($this->api($private, 'GET', '/api/vehicle/list/report/status')->assertOk()->json())->pluck('vehicle_id')->all());
        $this->api($private, 'PATCH', "/api/vehicle/{$privateVehicle->vehicle_id}", ['model' => 'Privat'])->assertOk();

        // Acting as the company: read-only there.
        $private->forceFill(['active_b2b_id' => $this->company->b2b_id])->save();
        $this->api($private, 'GET', '/api/vehicle/list/report/status')->assertOk()->assertJsonCount(2);
        $this->api($private, 'PATCH', "/api/vehicle/{$this->fleetVehicle->vehicle_id}", ['model' => 'X'])->assertForbidden();
    }

    public function test_existing_b2c_api_behaviour_is_unchanged(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->api($owner, 'POST', '/api/vehicle/create', ['license_plate' => 'P-1', 'vin' => 'WVWZZZ1JZXW000009'])->assertCreated();
        $vehicle = Vehicle::where('license_plate', 'P-1')->sole();
        $this->assertSame('B2C', $vehicle->vehicle_belongs);

        $this->api($owner, 'PATCH', "/api/vehicle/{$vehicle->vehicle_id}", ['model' => 'Golf'])->assertOk();
        $this->api($owner, 'GET', "/api/vehicle/list/{$owner->id}")->assertOk()->assertJsonCount(1);
        $this->api($owner, 'GET', '/api/vehicle/list/report/status')->assertOk()->assertJsonCount(1);
        $this->api($owner, 'PUT', "/api/vehicle/{$vehicle->vehicle_id}/documents", [
            'file' => UploadedFile::fake()->create('vertrag.pdf', 100, 'application/pdf'),
            'document_type' => 'Leasingvertrag',
        ], multipart: true)->assertCreated();

        $offer = $this->publishedOfferOn($vehicle);
        $this->api($stranger, 'GET', "/api/vehicle/offers/customer/list/{$offer->auftragsnummer}")->assertOk()->assertJsonCount(0, 'offers');
        $this->api($stranger, 'POST', "/api/vehicle/offers/customer/select/{$offer->offer_id}")->assertNotFound();
        $this->api($owner, 'GET', "/api/vehicle/offers/customer/list/{$offer->auftragsnummer}")->assertOk()->assertJsonCount(1, 'offers');
        $this->api($owner, 'POST', "/api/vehicle/offers/customer/select/{$offer->offer_id}")->assertOk();

        $this->api($stranger, 'GET', "/api/vehicle/list/{$owner->id}")->assertNotFound();
        $this->api($stranger, 'PATCH', "/api/vehicle/{$vehicle->vehicle_id}", ['model' => 'X'])->assertNotFound();
    }

    public function test_admin_api_access_is_unchanged(): void
    {
        $admin = $this->makeAdmin();

        $this->api($admin, 'GET', '/api/vehicle/list/report/status')->assertOk()->assertJsonCount(3);
        $this->api($admin, 'GET', "/api/vehicle/find/{$this->foreignVehicle->vehicle_id}/{$this->foreignVehicle->b2b_id}")->assertOk();
        $this->api($admin, 'PATCH', "/api/vehicle/{$this->fleetVehicle->vehicle_id}", ['model' => 'Admin'])->assertOk();
    }

    // ------------------------------------------------------------- helpers

    /**
     * One independent API request: a fresh bearer token, and no auth guard,
     * request-scoped company context or cached controller carried over from
     * the previous call.
     *
     * @param  array<string, mixed>  $data
     */
    private function api(User $user, string $method, string $uri, array $data = [], bool $multipart = false): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        // Laravel keeps a resolved controller on its route object, and with it
        // the constructor-injected B2bContext of the previous request. A real
        // request starts with neither, so neither may leak between calls here.
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('api-test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        return $multipart
            ? $this->withHeaders($headers)->call($method, $uri, $data, [], array_filter($data, fn ($value) => $value instanceof UploadedFile), $this->transformHeadersToServerVars($headers))
            : $this->withHeaders($headers)->json($method, $uri, $data);
    }

    private function makePresetMember(B2B $company, B2bRolePreset $preset): User
    {
        return $preset->role()->value === 'owner'
            ? $this->makeOwner($company)
            : $this->makeMember($company, $preset->permissions()->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function vehiclePayload(string $plate): array
    {
        return [
            'license_plate' => $plate,
            'vin' => 'WVWZZZ1JZXW'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'make' => 'VW',
            'model' => 'Passat',
            'leasinggeber' => 'LeasePlan',
        ];
    }

    private function documentFor(Vehicle $vehicle): VehicleDocument
    {
        $document = VehicleDocument::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'document_type' => 'Leasingvertrag',
        ]);
        Storage::disk('documents')->put($document->path, 'pdf');

        return $document;
    }

    private function publishedOfferOn(Vehicle $vehicle): LeasybackOffer
    {
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);

        return LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);
    }
}
