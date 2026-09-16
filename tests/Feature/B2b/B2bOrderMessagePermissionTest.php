<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Events\OrderMessageSent;
use App\Models\LeasybackOrder as ShimOrder;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §3: the read-only role "must not modify" anything, and a message on
 * the order is customer-visible content. Reading the thread stays open to
 * everyone who may see the order; writing to it needs a right that already
 * lets a member act on orders.
 */
class B2bOrderMessagePermissionTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private function companyOrder(): array
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company));

        return [$company, $order];
    }

    private function shim(LeasybackOrder $order): ShimOrder
    {
        return ShimOrder::findOrFail($order->id);
    }

    public function test_a_read_only_member_may_read_but_not_send(): void
    {
        Event::fake([OrderMessageSent::class]);
        [$company, $order] = $this->companyOrder();
        $viewer = $this->makeMember($company, B2bRolePreset::ReadOnly->permissions()->toArray());

        $this->assertTrue($viewer->can('viewMessages', $this->shim($order)));
        $this->assertFalse($viewer->can('sendMessage', $this->shim($order)));

        $this->actingAs($viewer)
            ->getJson(route('orders.messages.index', $order->id))
            ->assertOk()
            ->assertJsonPath('can_send', false);

        $this->actingAs($viewer)
            ->postJson(route('orders.messages.store', $order->id), ['body' => 'Hallo'])
            ->assertForbidden();

        $this->assertDatabaseMissing('order_messages', ['order_id' => $order->id]);
    }

    public function test_members_who_may_act_on_orders_can_send(): void
    {
        Event::fake([OrderMessageSent::class]);
        [$company, $order] = $this->companyOrder();

        $senders = [
            'standard user' => $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray()),
            'offer approver' => $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::SelectOffers->value]),
            'owner' => $this->makeOwner($company),
        ];

        foreach ($senders as $who => $user) {
            $this->assertTrue($user->can('sendMessage', $this->shim($order)), "{$who} should be able to send");

            $this->actingAs($user)
                ->getJson(route('orders.messages.index', $order->id))
                ->assertJsonPath('can_send', true);

            $this->actingAs($user)
                ->postJson(route('orders.messages.store', $order->id), ['body' => "Von {$who}"])
                ->assertCreated();
        }
    }

    public function test_a_member_who_cannot_see_the_vehicle_cannot_send_either(): void
    {
        [$company, $order] = $this->companyOrder();
        $scoped = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray(), 'own');

        $this->assertFalse($scoped->can('sendMessage', $this->shim($order)));
    }

    public function test_admin_and_a_private_customer_are_unchanged(): void
    {
        Event::fake([OrderMessageSent::class]);
        [, $order] = $this->companyOrder();

        $this->assertTrue($this->makeAdmin()->can('sendMessage', $this->shim($order)));

        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $customer->id]);
        $privateOrder = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->assertTrue($customer->can('sendMessage', $this->shim($privateOrder)));

        $this->actingAs($customer)
            ->getJson(route('orders.messages.index', $privateOrder->id))
            ->assertJsonPath('can_send', true);
    }
}
