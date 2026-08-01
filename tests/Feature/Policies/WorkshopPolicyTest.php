<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WorkshopPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_owner_can_update_own_workshop(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $workshop = Workshop::factory()->create(['user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($owner))
            ->patchJson("/workshop/{$workshop->id}", ['workshop_name' => 'New Name']);

        $response->assertOk();
        $this->assertSame('New Name', $workshop->fresh()->workshop_name);
    }

    public function test_non_owner_cannot_update_workshop(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $intruder = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $workshop = Workshop::factory()->create(['user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($intruder))
            ->patchJson("/workshop/{$workshop->id}", ['workshop_name' => 'Hijacked']);

        $response->assertForbidden();
        $this->assertNotSame('Hijacked', $workshop->fresh()->workshop_name);
    }

    public function test_admin_can_update_any_workshop(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $workshop = Workshop::factory()->create(['user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($admin))
            ->patchJson("/workshop/{$workshop->id}", ['workshop_name' => 'Admin Edit']);

        $response->assertOk();
        $this->assertSame('Admin Edit', $workshop->fresh()->workshop_name);
    }

    public function test_owner_can_upload_logo(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $workshop = Workshop::factory()->create(['user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($owner))
            ->post("/workshop/{$workshop->id}/logo", [
                'file' => UploadedFile::fake()->image('logo.png'),
            ]);

        $response->assertOk();
        $this->assertNotNull($workshop->fresh()->logo_path);
    }

    public function test_non_owner_cannot_upload_logo(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $intruder = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $workshop = Workshop::factory()->create(['user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($intruder))
            ->post("/workshop/{$workshop->id}/logo", [
                'file' => UploadedFile::fake()->image('logo.png'),
            ]);

        $response->assertForbidden();
        $this->assertNull($workshop->fresh()->logo_path);
    }

    /**
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Workshop row: `create` is ❌
     * for Privatkunde/Firmenkunde — enforced inline in
     * WorkshopController::ensureWorkshopUser(), not (yet) a Policy method.
     */
    public function test_non_workshop_user_cannot_create_a_workshop(): void
    {
        $privatkunde = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $response = $this->withHeaders($this->bearer($privatkunde))
            ->postJson('/workshop/create', ['workshop_name' => 'Should Not Exist']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('workshops', ['workshop_name' => 'Should Not Exist']);
    }

    /**
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Workshop row: `view` is ❌ for
     * Privatkunde/Firmenkunde.
     */
    public function test_non_owner_privatkunde_cannot_view_a_workshop(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $privatkunde = User::factory()->create(['user_type' => UserType::Privatkunde]);
        Workshop::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->bearer($privatkunde))
            ->getJson("/workshop/user_id/{$owner->id}")
            ->assertForbidden();
    }

    public function test_non_owner_cannot_delete_logo(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $intruder = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $workshop = Workshop::factory()->create(['user_id' => $owner->id, 'logo_path' => 'logos/existing.png']);

        $response = $this->withHeaders($this->bearer($intruder))
            ->deleteJson("/workshop/{$workshop->id}/logo");

        $response->assertForbidden();
        $this->assertSame('logos/existing.png', $workshop->fresh()->logo_path);
    }
}
