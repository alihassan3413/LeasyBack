<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\PortalTimestamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Portal timestamps carry their zone.
 *
 * The database keeps UTC and the payloads used to hand it over bare —
 * `2026-08-26 10:05:28` — which JavaScript reads as the *reader's* local time.
 * Every portal date was therefore the stored UTC clock relabelled: two hours
 * early in a German summer, five in Karachi. The rendering half of the fix
 * lives in resources/js/lib/portalDate.ts; what is pinned here is the half PHP
 * owns — that the instant leaves the server unambiguous, and that Admin and the
 * customer are handed the identical string for one event.
 */
class PortalTimestampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_timestamp_is_serialised_with_its_offset(): void
    {
        $this->assertSame('2026-08-26T10:05:28+00:00', PortalTimestamp::iso('2026-08-26 10:05:28'));
        $this->assertSame('2026-08-26T10:05:28+00:00', PortalTimestamp::iso(now()->parse('2026-08-26 10:05:28')));
    }

    public function test_nothing_is_invented_where_there_is_no_timestamp(): void
    {
        $this->assertNull(PortalTimestamp::iso(null));
        $this->assertNull(PortalTimestamp::iso(''));
    }

    /**
     * A value it cannot read is handed back rather than nulled: this class not
     * understanding something is no reason to drop it from the payload.
     */
    public function test_an_unreadable_value_survives_untouched(): void
    {
        $this->assertSame('not-a-date', PortalTimestamp::iso('not-a-date'));
    }

    public function test_named_keys_are_converted_and_the_rest_are_left_alone(): void
    {
        $row = (object) ['id' => 'abc', 'created_at' => '2026-08-26 10:05:28', 'label' => '2026-08-26 10:05:28'];

        $normalized = PortalTimestamp::normalizeRow($row, ['created_at', 'absent_column']);

        $this->assertSame('2026-08-26T10:05:28+00:00', $normalized['created_at']);
        $this->assertSame('abc', $normalized['id']);
        // Only what the call site named — a key that merely looks like a date
        // must not be swept up.
        $this->assertSame('2026-08-26 10:05:28', $normalized['label']);
        $this->assertArrayNotHasKey('absent_column', $normalized);
    }

    /**
     * The regression that started this: Admin and the customer rendering the
     * same transition at different clock times. They can only agree if they are
     * handed the same instant in the same shape.
     */
    public function test_admin_and_the_customer_receive_the_identical_instant(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $owner->id,
        ]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'reinspection',
        ]);
        app(TransitionOrderStatus::class)($order, 'delivered', 'admin', 'tester');

        $adminHistory = app(AdminQueryService::class)->orderDetail($order->id)['status_updates'][0];

        $customerHistory = data_get(
            $this->actingAs($owner)->get(route('dashboard'))->assertOk()->viewData('page'),
            'props.vehicles.0.orders.0.status_updates.0',
        );

        $this->assertSame('delivered', $adminHistory['new_status']);
        $this->assertSame('delivered', $customerHistory['new_status']);
        $this->assertSame($adminHistory['created_at'], $customerHistory['created_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            (string) $customerHistory['created_at'],
            'a portal timestamp without an offset is one the browser will read as local time',
        );
    }
}
