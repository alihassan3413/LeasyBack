<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Models\Vehicle as ShimVehicle;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\OrderNumberGenerator;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The shared `auftragsnummer` generator.
 *
 * The pre-existing defect it fixes: the reference was registration number plus
 * `ymd` and unique application-wide, so a second order for the same vehicle on
 * the same calendar day — legal once the first has closed — hit the unique
 * index. What these tests pin is that the *first* order of a vehicle-day still
 * gets exactly the historical string (nothing that has already been printed,
 * filed or spoken changes shape) and that everything after it is suffixed
 * rather than refused.
 */
class OrderNumberGeneratorTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private OrderNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->generator = app(OrderNumberGenerator::class);
    }

    public function test_the_first_reference_of_a_day_keeps_the_historical_plate_plus_date_form(): void
    {
        $reference = $this->generator->reserve('B-XY 123');

        $this->assertSame('BXY123'.now()->format('ymd'), $reference);
    }

    public function test_a_second_reservation_on_the_same_day_takes_the_next_sequence(): void
    {
        $first = $this->generator->reserve('B-XY 123');
        $second = $this->generator->reserve('B-XY 123');
        $third = $this->generator->reserve('B-XY 123');

        $this->assertSame($first.'-02', $second);
        $this->assertSame($first.'-03', $third);
    }

    public function test_repeated_generation_never_repeats_a_reference(): void
    {
        $references = [];

        for ($i = 0; $i < 25; $i++) {
            $references[] = $this->generator->reserve('B-XY 123');
        }

        $this->assertCount(25, array_unique($references));
    }

    /**
     * The reservation index, not the scan, is what decides a race. Simulated by
     * claiming a candidate behind the generator's back after it has scanned:
     * the insert fails, the loop rescans and takes the next number instead of
     * raising or falling back to something random.
     */
    public function test_a_reference_claimed_by_a_concurrent_writer_is_skipped(): void
    {
        $base = $this->generator->base('B-XY 123');

        DB::table('order_number_reservations')->insert([
            'reference' => $base,
            'reference_base' => $base,
            'sequence' => 1,
            'created_at' => now(),
        ]);

        $this->assertSame($base.'-02', $this->generator->reserve('B-XY 123'));
    }

    /**
     * A reservation that never became an order still burns its number. Handing
     * it out again would give a second order a reference a partner may already
     * have seen on the failed request.
     */
    public function test_an_unused_reservation_is_not_recycled(): void
    {
        $first = $this->generator->reserve('B-XY 123');
        $second = $this->generator->reserve('B-XY 123');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, DB::table('order_number_reservations')->count());
    }

    /**
     * `BXY12` + `260806` and `BXY1` + `2260806` are different vehicle-days that
     * must not share a sequence, and neither may be read as a suffixed form of
     * the other.
     */
    public function test_similar_plates_do_not_share_a_sequence(): void
    {
        $short = $this->generator->reserve('B-XY 12');
        $long = $this->generator->reserve('B-XY 123');
        $shortAgain = $this->generator->reserve('B-XY 12');

        $this->assertNotSame($short, $long);
        $this->assertSame($short.'-02', $shortAgain);
        $this->assertSame($long, $this->generator->issuedFor($this->generator->base('B-XY 123'))[0]);
    }

    /**
     * Orders written before this table existed have a reference and no
     * reservation row. The scan has to see them, or the first post-upgrade
     * order of that vehicle-day would collide at the leasyback_orders index.
     */
    public function test_a_historical_order_without_a_reservation_row_is_still_counted(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company, ['license_plate' => 'B-XY 123']);
        $base = $this->generator->base('B-XY 123');

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => $base,
            'order_status' => 'completed',
        ]);

        DB::table('order_number_reservations')->truncate();

        $this->assertSame($base.'-02', $this->generator->reserve('B-XY 123'));
    }

    public function test_a_reference_with_an_unparsable_suffix_does_not_break_allocation(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company, ['license_plate' => 'B-XY 123']);
        $base = $this->generator->base('B-XY 123');

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => $base.'-MANUAL',
            'order_status' => 'completed',
        ]);

        $this->assertSame($base, $this->generator->reserve('B-XY 123'));
    }

    public function test_the_b2b_portal_creation_path_uses_the_shared_generator(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $created = $this->makeB2bVehicle($company, ['license_plate' => 'B-XY 123']);
        $vehicle = $this->shim($created->vehicle_id);
        $base = $this->generator->base('B-XY 123');

        $first = app(OrderService::class)->createB2bCollectionOrder($vehicle, $owner, $this->collectionPayload());
        $first->update(['order_status' => 'completed']);

        $second = app(OrderService::class)->createB2bCollectionOrder($vehicle, $owner, $this->collectionPayload());

        $this->assertSame($base, $first->auftragsnummer);
        $this->assertSame($base.'-02', $second->auftragsnummer);
    }

    /**
     * The B2C inspection path shares the generator, so a B2C order and a B2B
     * order can never be handed the same reference either.
     */
    public function test_the_b2c_creation_path_uses_the_shared_generator(): void
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $created = Vehicle::factory()->create([
            'b2c_user_id' => $customer->id,
            'license_plate' => 'B-XY 123',
        ]);
        $vehicle = $this->shim($created->vehicle_id);
        $base = $this->generator->base('B-XY 123');

        $first = app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherProviderPayload());
        $second = app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherProviderPayload());

        $this->assertSame($base, $first->auftragsnummer);
        $this->assertSame($base.'-02', $second->auftragsnummer);
    }

    /**
     * The ledger row below is deliberately inconsistent — the reference belongs
     * to one base, the `reference_base` column claims another — so the scan
     * never sees it but the unique index always does. Every attempt therefore
     * picks the same candidate and loses. What must happen then is a raised
     * exception with no reference issued, not a random one nobody could derive
     * from the plate and the date.
     */
    public function test_running_out_of_attempts_raises_rather_than_inventing_a_reference(): void
    {
        $base = $this->generator->base('B-XY 123');

        DB::table('order_number_reservations')->insert([
            'reference' => $base,
            'reference_base' => 'UNRELATED',
            'sequence' => 1,
            'created_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not allocate an order reference');

        $this->generator->reserve('B-XY 123');
    }

    /**
     * OrderService type-hints the `App\Models` shim rather than the canonical
     * vehicle model; the factory produces the canonical one, which is the
     * shim's parent and so does not satisfy the hint.
     */
    private function shim(string $vehicleId): ShimVehicle
    {
        return ShimVehicle::where('vehicle_id', $vehicleId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPayload(): array
    {
        return [
            'collection_date' => now()->addWeek()->toDateString(),
            'contact_name' => 'Anna Beispiel',
            'contact_phone' => '+49 30 1234567',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function otherProviderPayload(): array
    {
        return [
            'provider' => 'dekra',
            'station_id' => null,
            'termin' => now()->addWeek()->toDateTimeString(),
        ];
    }
}
