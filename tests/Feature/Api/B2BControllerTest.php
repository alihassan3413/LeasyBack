<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2B;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2BControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function companyWithMember(User $user, string $role = 'owner'): B2B
    {
        $b2b = B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => 'Acme GmbH',
        ]);

        DB::table('user_b2b')->insert([
            'user_id' => $user->id,
            'b2b_id' => $b2b->b2b_id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $b2b;
    }

    /**
     * Regression test for the fixed IDOR: showByUser() used to trust the
     * client-supplied {id} directly — any Firmenkunde could read any other
     * user's company by guessing their user id.
     */
    public function test_show_by_user_ignores_the_url_id_and_always_returns_the_callers_own_company(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $this->companyWithMember($owner);
        $strangersCompany = $this->companyWithMember($stranger);

        $this->withHeaders($this->bearer($stranger))
            ->getJson("/b2b/user_id/{$owner->id}")
            ->assertOk()
            ->assertJson(['b2b' => $strangersCompany->b2b_id]);
    }

    public function test_show_by_user_returns_the_callers_own_company(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $company = $this->companyWithMember($owner);

        $this->withHeaders($this->bearer($owner))
            ->getJson("/b2b/user_id/{$owner->id}")
            ->assertOk()
            ->assertJson(['b2b' => $company->b2b_id, 'company_name' => 'Acme GmbH']);
    }

    /**
     * Regression test for the fixed role bug: update() used to accept any
     * user_b2b role (owner or member) — now requires role === 'owner'.
     */
    public function test_member_cannot_update_the_company(): void
    {
        $member = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $company = $this->companyWithMember($member, role: 'member');

        $this->withHeaders($this->bearer($member))
            ->patchJson("/b2b/{$company->b2b_id}", ['company_name' => 'Renamed GmbH'])
            ->assertForbidden();

        $this->assertSame('Acme GmbH', $company->fresh()->company_name);
    }

    public function test_owner_can_update_the_company(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $company = $this->companyWithMember($owner, role: 'owner');

        $this->withHeaders($this->bearer($owner))
            ->patchJson("/b2b/{$company->b2b_id}", ['company_name' => 'Renamed GmbH'])
            ->assertOk();

        $this->assertSame('Renamed GmbH', $company->fresh()->company_name);
    }

    public function test_non_owner_cannot_update_a_different_companys_details(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $company = $this->companyWithMember($owner);
        $this->companyWithMember($intruder);

        $this->withHeaders($this->bearer($intruder))
            ->patchJson("/b2b/{$company->b2b_id}", ['company_name' => 'Hijacked GmbH'])
            ->assertForbidden();

        $this->assertSame('Acme GmbH', $company->fresh()->company_name);
    }
}
