<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the AdminPolicy/Gate refactor of TimController —
 * every endpoint here previously carried its own inline
 * `user_type->value !== 'Admin'` check (with a copy-pasted, slightly
 * inaccurate "Only admin can access vehicles" message on all four). This
 * only proves the admin gate itself; the actual TIM SOAP integration isn't
 * exercised here (unrelated to this checkpoint's scope).
 */
class TimControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_non_admin_cannot_refresh_tim_login(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->postJson('/tim/appraisal/login/refresh')
            ->assertForbidden();
    }

    public function test_non_admin_cannot_sync_appraisal(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->postJson('/tim/appraisal/xml/sync/1')
            ->assertForbidden();
    }

    public function test_non_admin_cannot_view_appraisal_xml(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/tim/appraisal/xml/1')
            ->assertForbidden();
    }

    public function test_non_admin_cannot_list_appraisal_documents(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/tim/appraisal/docs/AUF-1')
            ->assertForbidden();
    }
}
