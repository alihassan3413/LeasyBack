<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §19: "Role-based authorization on every server endpoint" and
 * "Frontend permissions alone are not sufficient. Every permission must be
 * enforced by the backend."
 *
 * Each `b2b.can:*` route is exercised twice — once by a member holding only
 * `vehicles.view`, who must be refused, and once by the owner, who must not
 * be. The owner assertion matters as much as the refusal: a route that
 * refuses everyone would otherwise pass the first half silently.
 */
class B2bPermissionMatrixTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    /**
     * Routes whose refusal can be asserted without building the whole entity
     * behind them: the middleware runs before the controller, so a member
     * lacking the permission is refused regardless of the payload.
     *
     * @return list<array{0: string, 1: string, 2: string}> [permission, method, routeName]
     */
    public static function guardedRoutes(): array
    {
        return [
            ['vehicles.create', 'post', 'vehicles.store'],
            ['vehicles.create', 'post', 'vehicles.import'],
            ['vehicles.create', 'get', 'vehicles.import.template'],
            ['analytics.view', 'get', 'b2b.statistics.index'],
            ['analytics.view', 'get', 'b2b.statistics.export'],
            ['members.view', 'get', 'b2b.members.index'],
        ];
    }

    public function test_every_guarded_route_refuses_a_member_without_the_permission(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);

        foreach (self::guardedRoutes() as [$permission, $method, $routeName]) {
            $response = $this->actingAs($member)->{$method}(route($routeName));

            $this->assertSame(
                403,
                $response->getStatusCode(),
                "Expected {$routeName} to refuse a member lacking {$permission}, got {$response->getStatusCode()}",
            );
        }
    }

    public function test_the_owner_is_not_refused_by_any_guarded_route(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        foreach (self::guardedRoutes() as [$permission, $method, $routeName]) {
            $response = $this->actingAs($owner)->{$method}(route($routeName));

            $this->assertNotSame(
                403,
                $response->getStatusCode(),
                "Owner should hold {$permission} implicitly, but {$routeName} returned 403",
            );
        }
    }

    public function test_a_member_granted_the_permission_is_allowed_through(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [
            B2bPermission::ViewVehicles->value,
            B2bPermission::ViewAnalytics->value,
        ]);

        $this->actingAs($member)->get(route('b2b.statistics.index'))->assertOk();
    }

    /**
     * EnsureB2bPermission waves every non-Firmenkunde account through by
     * design — company permissions are meaningless outside a company. That is
     * what keeps B2C working on shared routes, and it is why company-only
     * controllers must re-check the user type themselves.
     */
    public function test_a_privatkunde_passes_the_middleware_but_is_refused_by_company_only_controllers(): void
    {
        $privatkunde = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($privatkunde)->get(route('b2b.statistics.index'))->assertForbidden();
        $this->actingAs($privatkunde)->post(route('vehicles.import'))->assertForbidden();
        $this->actingAs($privatkunde)->get(route('vehicles.import.template'))->assertForbidden();
    }

    public function test_an_admin_is_refused_by_the_company_only_import(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('vehicles.import'))
            ->assertForbidden();
    }

    /**
     * A company user must not reach the admin area at all — the B2B
     * permission set says nothing about it, so this is the other half of the
     * authorization boundary.
     */
    public function test_a_company_user_cannot_reach_the_admin_area(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->makeOwner($company))
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_every_permission_case_is_covered_by_the_membership_check(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);

        $context = app(B2bContext::class);

        foreach (B2bPermission::cases() as $permission) {
            $this->assertTrue(
                $context->can($owner, $permission),
                "An owner must implicitly hold {$permission->value}",
            );
        }

        foreach (B2bPermission::cases() as $permission) {
            $expected = $permission === B2bPermission::ViewVehicles;

            $this->assertSame(
                $expected,
                $context->can($member, $permission),
                "A view-only member's answer for {$permission->value} is wrong",
            );
        }
    }
}
