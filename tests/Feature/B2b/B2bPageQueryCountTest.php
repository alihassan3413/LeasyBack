<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bRolePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The main portal pages run a fixed number of queries whatever the fleet's
 * size: twelve vehicles with orders and members cost what two do. A lazy
 * relation in a list shows up here as a count that grows with the data.
 */
class B2bPageQueryCountTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'vehicles' => ['vehicles.index'],
            'orders' => ['orders.index'],
            'members' => ['b2b.members.index'],
            'statistics' => ['b2b.statistics.index'],
        ];
    }

    /** @dataProvider pages */
    public function test_the_page_does_not_query_per_row(string $route): void
    {
        $small = $this->queriesFor($route, $this->ownerOfFleet(2));
        $large = $this->queriesFor($route, $this->ownerOfFleet(12));

        $this->assertLessThanOrEqual($small + 2, $large, "{$route}: {$small} queries for 2 vehicles, {$large} for 12");
    }

    private function ownerOfFleet(int $vehicles): User
    {
        $company = $this->makeCompany('Flotte '.$vehicles);
        $owner = $this->makeOwner($company);

        for ($i = 0; $i < $vehicles; $i++) {
            $this->makeB2bOrder($this->makeB2bVehicle($company));
            $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());
        }

        return $owner;
    }

    private function queriesFor(string $route, User $user): int
    {
        $this->actingAs($user)->get(route($route))->assertOk(); // warm caches shared by every request

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route($route))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
