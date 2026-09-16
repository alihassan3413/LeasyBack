<?php

namespace Tests\Feature\B2b\Concerns;

use App\Enums\UserType;
use App\Models\Address;
use App\Models\B2B;
use App\Models\Contact;
use App\Models\User;
use App\Models\Vehicle as ShimVehicle;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderBilling;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
// Canonical models, not the App\Models shims: the factories return canonical
// instances, and a shim is a subclass of one rather than the other way round.
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Support\Facades\DB;

/**
 * Company/member/vehicle scaffolding for the §20 acceptance tests.
 *
 * Every B2B test needs the same four things — a company, a member with a
 * specific permission set, a vehicle owned by that company and an order on it
 * — and building them inline made each test's actual assertion hard to find.
 */
trait BuildsB2bCompanies
{
    protected function makeCompany(string $name = 'Test GmbH'): B2B
    {
        return B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => $name,
            'contact_email' => fake()->unique()->safeEmail(),
        ]);
    }

    /**
     * @param  list<string>|null  $permissions  null means owner (implicitly all)
     */
    protected function makeMember(
        B2B $company,
        ?array $permissions = null,
        string $vehicleScope = 'all',
        string $role = 'member',
    ): User {
        $user = User::factory()->create(['user_type' => UserType::Firmenkunde]);

        DB::table('user_b2b')->insert([
            'user_id' => $user->id,
            'b2b_id' => $company->b2b_id,
            'role' => $permissions === null ? 'owner' : $role,
            'permissions' => $permissions === null ? null : json_encode($permissions),
            'vehicle_scope' => $vehicleScope,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    protected function makeOwner(B2B $company): User
    {
        return $this->makeMember($company, null, 'all', 'owner');
    }

    protected function makeAdmin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    protected function makeB2bVehicle(B2B $company, array $attributes = []): Vehicle
    {
        return Vehicle::factory()->forB2b($company->b2b_id)->create($attributes);
    }

    /**
     * The `App\Models` shim instance for an order's vehicle.
     *
     * Several services type-hint the shim rather than the canonical model, and
     * `$order->vehicle` returns the canonical one — which is the parent class,
     * so it does *not* satisfy a shim hint. Production callers happen to load
     * the shim directly; tests have to do the same.
     */
    protected function shimVehicle(LeasybackOrder $order): ShimVehicle
    {
        return ShimVehicle::where('vehicle_id', $order->vehicle_id)->firstOrFail();
    }

    /**
     * Records the fact a B2B status claims before the order is moved to it.
     *
     * TransitionOrderStatus refuses to move a B2B order to a status whose
     * underlying fact does not exist (a confirmed collection date, an uploaded
     * appraisal, an accepted offer, a repair appointment, processed billing).
     * Tests that walk the graph for its own sake use this to satisfy that
     * rule without re-enacting the whole business flow.
     */
    protected function meetB2bPrerequisite(LeasybackOrder $order, string $toStatus): void
    {
        match ($toStatus) {
            'vehicle_collected' => OrderLogistics::updateOrCreate(
                ['auftragsnummer' => $order->auftragsnummer],
                ['confirmed_collection_date' => now()->toDateString()],
            ),
            'inspected' => VehicleReportDocument::factory()->create([
                'auftragsnummer' => $order->auftragsnummer,
                'vehicle_id' => $order->vehicle_id,
                'document_type' => 'gutachten',
            ]),
            'workshop_commissioned' => LeasybackOffer::factory()->selected()->create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
            ]),
            'workshop' => OrderLogistics::updateOrCreate(
                ['auftragsnummer' => $order->auftragsnummer],
                ['confirmed_repair_start_date' => now()->toDateString()],
            ),
            'invoice_processed', 'completed' => OrderBilling::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'auftragsnummer' => $order->auftragsnummer,
                    'billing_status' => OrderBilling::STATUS_PROCESSED,
                    'invoice_reference' => 'RE-TEST',
                    'processed_at' => now(),
                ],
            ),
            default => null,
        };
    }

    protected function makeB2bOrder(Vehicle $vehicle, string $status = 'order_requested'): LeasybackOrder
    {
        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
            'leasyback_partner' => 'leasyback',
            'request_payload' => ['order_type' => 'b2b_collection'],
        ]);
    }
}
