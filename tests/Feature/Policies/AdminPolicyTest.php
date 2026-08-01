<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct ability tests for the Gates registered from AdminPolicy in
 * AuthServiceProvider — these have no model to attach a live route's
 * regression test to the same way OrderPolicyTest does, so they're
 * exercised here instead, plus at least one regression test per consuming
 * controller (see AdminControllerTest, TimControllerTest, OfferControllerTest).
 */
class AdminPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_use_any_admin_operational_ability(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        foreach (['viewDashboardSummary', 'viewAdminListings', 'updateCustomerStatus', 'syncAppraisal', 'manageDekraProcess'] as $ability) {
            $this->assertTrue($admin->can($ability), "Admin should be able to {$ability}");
            $this->assertFalse($customer->can($ability), "Customer should not be able to {$ability}");
        }
    }

    public function test_firmenkunde_and_werkstatt_cannot_use_admin_abilities(): void
    {
        $firmenkunde = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);

        foreach (['viewDashboardSummary', 'viewAdminListings', 'updateCustomerStatus', 'syncAppraisal', 'manageDekraProcess'] as $ability) {
            $this->assertFalse($firmenkunde->can($ability), "Firmenkunde should not be able to {$ability}");
            $this->assertFalse($werkstatt->can($ability), "Werkstatt should not be able to {$ability}");
        }
    }
}
