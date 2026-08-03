<?php

namespace Tests\Feature;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Company registration is a one-time step: once the company exists its data is
 * shown and edited on "Mein Konto", and /onboarding/b2b stops being a page of
 * its own.
 */
class B2bRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'company_name' => 'Acme GmbH',
            'vat_id' => 'DE123456789',
            'contact_email' => 'anfragen@acme.test',
            'address' => [
                'street' => 'Hauptstrasse',
                'number' => '12',
                'additional_address' => 'Hinterhof',
                'zip_code' => '10115',
                'city' => 'Berlin',
                'country' => 'Deutschland',
            ],
            'contact' => [
                'salutation' => 'Herr',
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
            ],
            'phones' => [
                ['international_prefix' => '+49', 'phone_number' => '3012345'],
            ],
        ], $overrides);
    }

    private function firmenkunde(): User
    {
        return User::factory()->create(['user_type' => UserType::Firmenkunde]);
    }

    /**
     * Registers a company for $owner, then joins $member to it with exactly
     * the given permissions.
     */
    private function addMember(User $member, string $b2bId, array $permissions): void
    {
        DB::table('user_b2b')->insert([
            'user_id' => $member->id,
            'b2b_id' => $b2bId,
            'role' => 'member',
            'permissions' => json_encode($permissions),
            'vehicle_scope' => 'all',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function registerCompany(User $owner): string
    {
        $this->actingAs($owner)->post(route('onboarding.b2b.store'), $this->payload());

        return DB::table('user_b2b')->where('user_id', $owner->id)->value('b2b_id');
    }

    public function test_a_firmenkunde_without_a_company_sees_the_registration_form(): void
    {
        $this->actingAs($this->firmenkunde())
            ->get(route('onboarding.b2b.show'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('onboarding/B2bRegistration'));
    }

    public function test_registering_stores_the_company_and_lands_on_the_dashboard(): void
    {
        $owner = $this->firmenkunde();

        $this->actingAs($owner)
            ->post(route('onboarding.b2b.store'), $this->payload())
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('b2b', ['company_name' => 'Acme GmbH']);
    }

    public function test_the_registration_form_is_shown_only_once(): void
    {
        $owner = $this->firmenkunde();
        $this->registerCompany($owner);

        // The second visit has nothing left to register — company data lives
        // on "Mein Konto" from here on.
        $this->actingAs($owner)
            ->get(route('onboarding.b2b.show'))
            ->assertRedirect(route('profile.edit', absolute: false));
    }

    public function test_a_privatkunde_is_sent_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->get(route('onboarding.b2b.show'))
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_mein_konto_shows_the_address_and_contact_entered_during_registration(): void
    {
        $owner = $this->firmenkunde();
        $this->registerCompany($owner);

        $this->actingAs($owner)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.data.company_name', 'Acme GmbH')
                ->where('company.data.vat_id', 'DE123456789')
                ->where('company.data.address.street', 'Hauptstrasse')
                ->where('company.data.address.zip_code', '10115')
                ->where('company.data.address.city', 'Berlin')
                ->where('company.data.contact.first_name', 'Max')
                ->where('company.data.contact.phone_numbers.0.phone_number', '3012345')
                ->where('company.can_manage', true)
                ->where('company.can_register', false)
                ->etc()
            );
    }

    public function test_mein_konto_offers_registration_when_no_company_exists_yet(): void
    {
        $this->actingAs($this->firmenkunde())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.data', null)
                ->where('company.can_register', true)
                ->etc()
            );
    }

    public function test_mein_konto_has_no_company_half_for_a_privatkunde(): void
    {
        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('company', null)->etc());
    }

    public function test_a_member_without_company_view_is_not_shown_the_company(): void
    {
        $owner = $this->firmenkunde();
        $b2bId = $this->registerCompany($owner);

        $member = $this->firmenkunde();
        $this->addMember($member, $b2bId, [B2bPermission::ViewVehicles->value]);

        $this->actingAs($member)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.data', null)
                ->where('company.can_manage', false)
                // Belongs to a company already, so there is nothing to register.
                ->where('company.can_register', false)
                ->etc()
            );
    }

    public function test_a_member_with_view_but_not_manage_sees_the_company_read_only(): void
    {
        $owner = $this->firmenkunde();
        $b2bId = $this->registerCompany($owner);

        $member = $this->firmenkunde();
        $this->addMember($member, $b2bId, [B2bPermission::ViewCompany->value]);

        $this->actingAs($member)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.data.company_name', 'Acme GmbH')
                ->where('company.can_manage', false)
                ->etc()
            );

        $this->actingAs($member)
            ->put(route('company.update'), $this->payload(['company_name' => 'Renamed GmbH']))
            ->assertForbidden();

        $this->assertDatabaseHas('b2b', ['b2b_id' => $b2bId, 'company_name' => 'Acme GmbH']);
    }

    public function test_the_owner_edits_the_company_from_mein_konto(): void
    {
        $owner = $this->firmenkunde();
        $b2bId = $this->registerCompany($owner);

        $this->actingAs($owner)
            ->put(route('company.update'), $this->payload([
                'company_name' => 'Acme Fleet GmbH',
                'address' => ['city' => 'Hamburg', 'zip_code' => '20095'],
                'contact' => ['first_name' => 'Erika'],
            ]))
            ->assertRedirect(route('profile.edit', absolute: false))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('b2b', ['b2b_id' => $b2bId, 'company_name' => 'Acme Fleet GmbH']);
        $this->assertDatabaseHas('addresses', ['city' => 'Hamburg', 'zip_code' => '20095']);
        $this->assertDatabaseHas('contacts', ['first_name' => 'Erika']);
    }

    public function test_a_firmenkunde_without_a_company_cannot_reach_the_update_route(): void
    {
        // EnsureB2bPermission sends them to register one instead of 403-ing.
        $this->actingAs($this->firmenkunde())
            ->put(route('company.update'), $this->payload())
            ->assertRedirect(route('onboarding.b2b.show', absolute: false));
    }
}
