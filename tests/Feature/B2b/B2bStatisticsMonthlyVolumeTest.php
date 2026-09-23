<?php

namespace Tests\Feature\B2b;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The monthly order volume is bucketed by German calendar month. Timestamps
 * are stored in UTC, so near midnight at a month boundary the UTC month and
 * the month the customer placed the order in differ.
 */
class B2bStatisticsMonthlyVolumeTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_orders_are_bucketed_by_berlin_month_not_utc_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15 12:00:00', 'UTC'));

        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company);

        // 1 March 00:30 in Berlin (CET, +1) — still February in UTC.
        $this->makeB2bOrder($vehicle, 'cancelled')->forceFill(['created_at' => '2026-02-28 23:30:00'])->save();
        // 1 April 00:30 in Berlin (CEST, +2) — still March in UTC.
        $this->makeB2bOrder($vehicle, 'cancelled')->forceFill(['created_at' => '2026-03-31 22:30:00'])->save();
        // 1 May 2025 00:30 in Berlin — the first day of the 12-month window,
        // which in UTC starts at 30 April 22:00.
        $this->makeB2bOrder($vehicle, 'cancelled')->forceFill(['created_at' => '2025-04-30 22:30:00'])->save();

        $volume = collect(
            $this->actingAs($owner)->get(route('b2b.statistics.index'))->assertOk()->viewData('page')['props']['statistics']['monthly_volume']
        )->pluck('count', 'month');

        $this->assertCount(12, $volume);
        $this->assertSame('2025-05', $volume->keys()->first());
        $this->assertSame('2026-04', $volume->keys()->last());

        $this->assertSame(1, $volume['2025-05']);
        $this->assertSame(0, $volume['2026-02']);
        $this->assertSame(1, $volume['2026-03']);
        $this->assertSame(1, $volume['2026-04']);
        $this->assertSame(3, $volume->sum());
    }
}
