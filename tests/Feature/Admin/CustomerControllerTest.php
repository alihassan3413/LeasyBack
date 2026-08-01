<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2B;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function companyWithMember(User $user): B2B
    {
        $b2b = B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => 'Acme GmbH',
        ]);

        DB::table('user_b2b')->insert([
            'user_id' => $user->id,
            'b2b_id' => $b2b->b2b_id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $b2b;
    }

    public function test_non_admin_cannot_view_the_customer_list(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->get(route('admin.customers.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_b2c_customers_by_default(): void
    {
        $admin = $this->admin();
        User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Customers/Index')
                ->where('type', 'b2c')
                ->has('customers.data', 1)
            );
    }

    public function test_admin_sees_b2b_customers_when_requested(): void
    {
        $admin = $this->admin();
        $firmenkunde = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $this->companyWithMember($firmenkunde);

        $this->actingAs($admin)
            ->get(route('admin.customers.index', ['type' => 'b2b']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Customers/Index')
                ->where('type', 'b2b')
                ->has('customers.data', 1)
                ->where('customers.data.0.company_name', 'Acme GmbH')
            );
    }

    public function test_admin_can_view_a_b2c_customer_detail_with_their_vehicles(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        Vehicle::factory()->create(['b2c_user_id' => $customer->id, 'license_plate' => 'K LB 1']);

        $this->actingAs($admin)
            ->get(route('admin.customers.show', ['type' => 'b2c', 'id' => $customer->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Customers/Show')
                ->where('customer.user_id', $customer->id)
                ->has('vehicles', 1)
                ->where('vehicles.0.license_plate', 'K LB 1')
            );
    }

    public function test_admin_can_view_a_b2b_customer_detail_with_company_vehicles(): void
    {
        $admin = $this->admin();
        $firmenkunde = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $company = $this->companyWithMember($firmenkunde);
        $vehicle = Vehicle::factory()->forB2b($company->b2b_id)->create(['license_plate' => 'K LB 2']);

        $this->actingAs($admin)
            ->get(route('admin.customers.show', ['type' => 'b2b', 'id' => $company->b2b_id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Customers/Show')
                ->where('customer.b2b_id', $company->b2b_id)
                ->has('vehicles', 1)
                ->where('vehicles.0.vehicle_id', $vehicle->vehicle_id)
            );
    }

    public function test_show_returns_404_for_unknown_customer(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.customers.show', ['type' => 'b2c', 'id' => 999999]))
            ->assertNotFound();
    }

    public function test_admin_can_deactivate_a_b2c_customer(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde, 'is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.customers.status', ['type' => 'b2c', 'id' => $customer->id]), ['is_active' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'is_active' => false]);
    }

    public function test_non_admin_cannot_deactivate_a_customer(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde, 'is_active' => true]);

        $this->actingAs($user)
            ->patch(route('admin.customers.status', ['type' => 'b2c', 'id' => $customer->id]), ['is_active' => false])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'is_active' => true]);
    }
}
