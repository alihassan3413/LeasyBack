<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use App\Modules\UserProfile\Profile\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function addressContactPayload(): array
    {
        return [
            'address' => [
                'street' => 'Hauptstrasse',
                'number' => '12',
                'zip_code' => '10115',
                'city' => 'Berlin',
                'country' => 'Germany',
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

    private function preferencesPayload(): array
    {
        return [
            'timezone' => 'Europe/Berlin',
            'sprache' => 'de',
            'benachrichtigungseinstellungen_push' => true,
            'benachrichtigungseinstellungen_email' => false,
        ];
    }

    // -- Unauthenticated --

    public function test_unauthenticated_cannot_access_any_profile_endpoint(): void
    {
        $this->postJson('/userprofile/address-contact', $this->addressContactPayload())->assertUnauthorized();
        $this->putJson('/userprofile/address-contact', $this->addressContactPayload())->assertUnauthorized();
        $this->postJson('/userprofile/user-preferences', $this->preferencesPayload())->assertUnauthorized();
        $this->putJson('/userprofile/user-preferences', $this->preferencesPayload())->assertUnauthorized();
        $this->getJson('/userprofile/user-profile')->assertUnauthorized();
    }

    // -- Address/contact: owner happy path + the fixed IDOR --

    public function test_owner_can_create_view_and_update_own_profile(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $create = $this->withHeaders($this->bearer($owner))
            ->postJson('/userprofile/address-contact', $this->addressContactPayload());
        $create->assertCreated();
        $addressId = $create->json('address_id');
        $contactId = $create->json('contact_id');

        $show = $this->withHeaders($this->bearer($owner))->getJson('/userprofile/user-profile');
        $show->assertOk();
        $this->assertSame('Hauptstrasse', $show->json('address.street'));
        $this->assertSame('Max', $show->json('contact.first_name'));

        $updatePayload = array_merge($this->addressContactPayload(), [
            'address_id' => $addressId,
            'contact_id' => $contactId,
        ]);
        $updatePayload['address']['street'] = 'Neue Strasse';
        $updatePayload['contact']['first_name'] = 'Erika';

        $update = $this->withHeaders($this->bearer($owner))->putJson('/userprofile/address-contact', $updatePayload);
        $update->assertOk();

        $show = $this->withHeaders($this->bearer($owner))->getJson('/userprofile/user-profile');
        $this->assertSame('Neue Strasse', $show->json('address.street'));
        $this->assertSame('Erika', $show->json('contact.first_name'));
    }

    public function test_creating_profile_twice_returns_conflict(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($owner))
            ->postJson('/userprofile/address-contact', $this->addressContactPayload())
            ->assertCreated();

        $this->withHeaders($this->bearer($owner))
            ->postJson('/userprofile/address-contact', $this->addressContactPayload())
            ->assertStatus(409);
    }

    /**
     * Regression test for the fixed IDOR: ProfileController::updateAddressContact
     * used to update `Address`/`Contact` rows straight from client-supplied
     * ids with zero ownership check. An intruder who knows (or guesses)
     * another user's real address_id/contact_id must not be able to modify
     * it, and the response must not reveal that the ids exist for someone
     * else — a clean 404, not a silent no-op 200 (the pre-fix behavior) and
     * not a 403 that would confirm the ids are real.
     */
    public function test_non_owner_cannot_update_another_users_address_or_contact(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $address = Address::factory()->create(['street' => 'Original Strasse']);
        $contact = Contact::factory()->create(['address_id' => $address->address_id, 'first_name' => 'Original']);
        LeasybackUserProfile::factory()->create(['user_id' => $owner->id, 'contact_id' => $contact->contact_id]);

        $payload = array_merge($this->addressContactPayload(), [
            'address_id' => $address->address_id,
            'contact_id' => $contact->contact_id,
        ]);
        $payload['address']['street'] = 'Hijacked Strasse';
        $payload['contact']['first_name'] = 'Hijacked';

        $response = $this->withHeaders($this->bearer($intruder))
            ->putJson('/userprofile/address-contact', $payload);

        $response->assertNotFound();
        $this->assertSame(['error' => 'Address or contact not found.'], $response->json());
        $this->assertSame('Original Strasse', $address->fresh()->street);
        $this->assertSame('Original', $contact->fresh()->first_name);
    }

    public function test_update_with_nonexistent_but_well_formed_identifiers_returns_clean_404(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $payload = array_merge($this->addressContactPayload(), [
            'address_id' => '11111111-1111-4111-8111-111111111111',
            'contact_id' => '22222222-2222-4222-8222-222222222222',
        ]);

        $response = $this->withHeaders($this->bearer($owner))
            ->putJson('/userprofile/address-contact', $payload);

        $response->assertNotFound();
        $body = $response->json();
        $this->assertArrayNotHasKey('exception', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertArrayNotHasKey('line', $body);
        $this->assertArrayNotHasKey('trace', $body);
    }

    public function test_update_with_malformed_identifier_returns_validation_error_not_leak(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $payload = array_merge($this->addressContactPayload(), [
            'address_id' => 'not-a-uuid',
            'contact_id' => 'also-not-a-uuid',
        ]);

        $response = $this->withHeaders($this->bearer($owner))
            ->putJson('/userprofile/address-contact', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['address_id', 'contact_id']);
        $body = $response->json();
        $this->assertArrayNotHasKey('exception', $body);
        $this->assertArrayNotHasKey('trace', $body);
    }

    public function test_admin_cannot_create_or_update_profile(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->postJson('/userprofile/address-contact', $this->addressContactPayload())
            ->assertForbidden();

        $payload = array_merge($this->addressContactPayload(), [
            'address_id' => '11111111-1111-4111-8111-111111111111',
            'contact_id' => '22222222-2222-4222-8222-222222222222',
        ]);
        $this->withHeaders($this->bearer($admin))
            ->putJson('/userprofile/address-contact', $payload)
            ->assertForbidden();
    }

    public function test_show_returns_not_found_when_no_profile_exists(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($owner))
            ->getJson('/userprofile/user-profile')
            ->assertNotFound();
    }

    // -- Preferences --

    public function test_owner_can_create_and_update_own_preferences(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $create = $this->withHeaders($this->bearer($owner))
            ->postJson('/userprofile/user-preferences', $this->preferencesPayload());
        $create->assertCreated();
        $preferenceId = $create->json('preference_id');

        $updatePayload = array_merge($this->preferencesPayload(), [
            'preference_id' => $preferenceId,
            'timezone' => 'Europe/London',
        ]);

        $this->withHeaders($this->bearer($owner))
            ->putJson('/userprofile/user-preferences', $updatePayload)
            ->assertOk();

        $this->assertSame('Europe/London', UserPreference::find($preferenceId)->timezone);
    }

    public function test_creating_preferences_twice_returns_conflict(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($owner))
            ->postJson('/userprofile/user-preferences', $this->preferencesPayload())
            ->assertCreated();

        $this->withHeaders($this->bearer($owner))
            ->postJson('/userprofile/user-preferences', $this->preferencesPayload())
            ->assertStatus(409);
    }

    public function test_non_owner_cannot_update_another_users_preferences(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $preference = UserPreference::factory()->create(['user_id' => $owner->id, 'timezone' => 'Europe/Berlin']);

        $payload = array_merge($this->preferencesPayload(), [
            'preference_id' => $preference->preference_id,
            'timezone' => 'America/New_York',
        ]);

        $response = $this->withHeaders($this->bearer($intruder))
            ->putJson('/userprofile/user-preferences', $payload);

        $response->assertNotFound();
        $this->assertSame('Europe/Berlin', $preference->fresh()->timezone);
    }

    public function test_admin_cannot_create_or_update_preferences(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->postJson('/userprofile/user-preferences', $this->preferencesPayload())
            ->assertForbidden();

        $payload = array_merge($this->preferencesPayload(), [
            'preference_id' => '11111111-1111-4111-8111-111111111111',
        ]);
        $this->withHeaders($this->bearer($admin))
            ->putJson('/userprofile/user-preferences', $payload)
            ->assertForbidden();
    }
}
