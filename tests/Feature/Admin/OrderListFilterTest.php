<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The order list's two newer questions: which service an order belongs to, and
 * when it came in. Both are server-side so they filter the whole table, not
 * the page the admin happens to be looking at.
 */
class OrderListFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_filter_returns_only_relocations(): void
    {
        $this->order('AUF-RELO', OrderService::SERVICE_RELOCATION);
        $this->order('AUF-LEASE', OrderService::SERVICE_LEASING_RETURN);

        $this->assertSame(['AUF-RELO'], $this->listed(['service' => OrderService::SERVICE_RELOCATION]));
    }

    public function test_the_service_filter_returns_only_leasing_returns(): void
    {
        $this->order('AUF-RELO', OrderService::SERVICE_RELOCATION);
        $this->order('AUF-LEASE', OrderService::SERVICE_LEASING_RETURN);

        $this->assertSame(['AUF-LEASE'], $this->listed(['service' => OrderService::SERVICE_LEASING_RETURN]));
    }

    public function test_an_order_predating_the_relocation_counts_as_a_leasing_return(): void
    {
        // The column was added with a leasingrueckgabe default, so a row that
        // never set it must still answer the filter rather than vanish.
        $order = LeasybackOrder::factory()->create(['auftragsnummer' => 'AUF-OLD']);
        $this->assertSame(OrderService::SERVICE_LEASING_RETURN, $order->fresh()->service_type);

        $this->assertContains('AUF-OLD', $this->listed(['service' => OrderService::SERVICE_LEASING_RETURN]));
    }

    public function test_no_service_filter_returns_both(): void
    {
        $this->order('AUF-RELO', OrderService::SERVICE_RELOCATION);
        $this->order('AUF-LEASE', OrderService::SERVICE_LEASING_RETURN);

        $this->assertCount(2, $this->listed([]));
    }

    public function test_an_unknown_service_is_rejected_rather_than_ignored(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['service' => 'motorrad']))
            ->assertSessionHasErrors('service');
    }

    public function test_the_date_range_returns_only_orders_created_inside_it(): void
    {
        $this->order('AUF-OLD', OrderService::SERVICE_LEASING_RETURN, now()->subDays(40));
        $this->order('AUF-NEW', OrderService::SERVICE_LEASING_RETURN, now()->subDay());

        $listed = $this->listed([
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $this->assertSame(['AUF-NEW'], $listed);
    }

    public function test_the_end_of_the_range_includes_that_whole_day(): void
    {
        $this->order('AUF-LATE', OrderService::SERVICE_LEASING_RETURN, now()->subDay()->setTime(23, 45));

        $listed = $this->listed([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(['AUF-LATE'], $listed);
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['start_date' => '2026-05-10', 'end_date' => '2026-05-01']))
            ->assertSessionHasErrors('end_date');
    }

    public function test_service_and_date_narrow_together(): void
    {
        $this->order('AUF-RELO-OLD', OrderService::SERVICE_RELOCATION, now()->subDays(40));
        $this->order('AUF-RELO-NEW', OrderService::SERVICE_RELOCATION, now()->subDay());
        $this->order('AUF-LEASE-NEW', OrderService::SERVICE_LEASING_RETURN, now()->subDay());

        $listed = $this->listed([
            'service' => OrderService::SERVICE_RELOCATION,
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $this->assertSame(['AUF-RELO-NEW'], $listed);
    }

    public function test_the_page_is_told_which_filters_are_active(): void
    {
        $filters = $this->actingAs($this->admin())
            ->get(route('admin.orders.index', [
                'service' => OrderService::SERVICE_RELOCATION,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
            ]))
            ->viewData('page')['props']['filters'];

        $this->assertSame(OrderService::SERVICE_RELOCATION, $filters['service']);
        $this->assertSame('2026-05-01', $filters['start_date']);
        $this->assertSame('2026-05-31', $filters['end_date']);
    }

    public function test_a_customer_still_cannot_reach_the_list(): void
    {
        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->get(route('admin.orders.index', ['service' => OrderService::SERVICE_RELOCATION]))
            ->assertForbidden();
    }

    /**
     * @param  array<string, string>  $query
     * @return array<int, string>
     */
    private function listed(array $query): array
    {
        $response = $this->actingAs($this->admin())->get(route('admin.orders.index', $query));
        $response->assertOk();

        return array_values(array_map(
            fn (array $row) => $row['auftragsnummer'],
            $response->viewData('page')['props']['orders']['data'],
        ));
    }

    private function order(string $auftragsnummer, string $serviceType, ?Carbon $createdAt = null): LeasybackOrder
    {
        $order = LeasybackOrder::factory()->create([
            'auftragsnummer' => $auftragsnummer,
            'service_type' => $serviceType,
        ]);

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        return $order;
    }

    private function admin(): User
    {
        return User::firstWhere('user_type', UserType::Admin)
            ?? User::factory()->create(['user_type' => UserType::Admin]);
    }
}
