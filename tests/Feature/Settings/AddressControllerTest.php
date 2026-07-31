<?php

namespace Tests\Feature\Settings;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AddressControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'address' => [
                'street' => 'Hauptstrasse',
                'number' => '12',
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
                ['international_prefix' => '+49', 'phone_number' => '1234567'],
            ],
        ];
    }

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $this->get(route('address.edit'))->assertRedirect(route('login'));
    }

    public function test_owner_can_view_create_and_update_own_address(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($owner)
            ->get(route('address.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('profile', null));

        $this->actingAs($owner)
            ->post(route('address.store'), $this->payload())
            ->assertRedirect(route('address.edit'));

        $this->actingAs($owner)
            ->get(route('address.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.contact.first_name', 'Max')
                ->where('profile.address.street', 'Hauptstrasse')
                ->where('profile.phones.0.phone_number', '1234567')
            );

        $address = Address::firstOrFail();
        $contact = Contact::firstOrFail();

        $updatePayload = array_merge($this->payload(), [
            'address_id' => $address->address_id,
            'contact_id' => $contact->contact_id,
        ]);
        $updatePayload['address']['street'] = 'Neue Strasse';
        $updatePayload['contact']['first_name'] = 'Erika';

        $this->actingAs($owner)
            ->put(route('address.update'), $updatePayload)
            ->assertRedirect(route('address.edit'));

        $this->assertSame('Neue Strasse', $address->fresh()->street);
        $this->assertSame('Erika', $contact->fresh()->first_name);
    }

    public function test_creating_address_twice_flashes_a_conflict_error(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($owner)->post(route('address.store'), $this->payload())->assertRedirect();

        $this->actingAs($owner)
            ->from(route('address.edit'))
            ->post(route('address.store'), $this->payload())
            ->assertRedirect(route('address.edit'))
            ->assertSessionHasErrors(['address']);
    }

    /**
     * Regression test for the fixed IDOR, exercised through the new
     * session-authenticated web route this checkpoint adds (not just the
     * Sanctum API route from Checkpoint 2) — the ownership check lives in
     * ProfileService, shared by both entry points, so it must hold here too.
     */
    public function test_non_owner_cannot_update_another_users_address_or_contact(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $address = Address::factory()->create(['street' => 'Original Strasse']);
        $contact = Contact::factory()->create(['address_id' => $address->address_id, 'first_name' => 'Original']);
        LeasybackUserProfile::factory()->create(['user_id' => $owner->id, 'contact_id' => $contact->contact_id]);

        $payload = array_merge($this->payload(), [
            'address_id' => $address->address_id,
            'contact_id' => $contact->contact_id,
        ]);
        $payload['address']['street'] = 'Hijacked Strasse';

        $this->actingAs($intruder)
            ->from(route('address.edit'))
            ->put(route('address.update'), $payload)
            ->assertRedirect(route('address.edit'))
            ->assertSessionHasErrors(['address']);

        $this->assertSame('Original Strasse', $address->fresh()->street);
    }

    public function test_admin_cannot_create_or_update_address(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)
            ->post(route('address.store'), $this->payload())
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('address.update'), array_merge($this->payload(), [
                'address_id' => '11111111-1111-4111-8111-111111111111',
                'contact_id' => '22222222-2222-4222-8222-222222222222',
            ]))
            ->assertForbidden();
    }
}
