<?php

namespace Database\Seeders;

use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Models\Address;
use App\Models\B2B;
use App\Models\Contact;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use App\Modules\UserProfile\Offer\Services\OfferService;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\CompanyBillingAddress;
use App\Modules\UserProfile\Order\Models\CompanyCostCentre;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\LogisticsAddressProfile;
use App\Modules\UserProfile\Order\Services\RepairOfferService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The fixed starting point for the Playwright suite (tests/e2e).
 *
 * Deliberately deterministic — the browser tests assert on these exact names,
 * plates and addresses, so a `fake()` value would make them flap. Refuses to
 * run in production for the same reason DevUserSeeder does: these are test
 * affordances, not application data.
 *
 * B2C: a customer with no profile, vehicle or order (the onboarding wizard is
 * the first thing that journey exercises), and a DEKRA station — a DEKRA
 * booking is handled in-process, so no spec depends on a live partner API.
 *
 * B2B: a company with an owner, a Standard User and a Read-only member, free
 * vehicles, one vehicle with an open order, a billing address, a cost centre
 * and a saved pickup address; a second company the owner also belongs to (the
 * company switcher); a company whose owner has not enrolled MFA (admin
 * impersonation); an unrelated company (data isolation); an open invitation
 * with a known token; and a Firmenkunde who has not registered a company yet.
 *
 * Accounts MFA applies to (admins, company owners) are enrolled with a known
 * TOTP secret, so the suite signs in the way a person does — by code.
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD = 'e2e-password';

    public const CUSTOMER_EMAIL = 'e2e.customer@leasyback.test';

    public const CUSTOMER_PASSWORD = self::PASSWORD;

    public const ADMIN_EMAIL = 'e2e.admin@leasyback.test';

    public const ADMIN_PASSWORD = self::PASSWORD;

    public const STATION_NAME = 'DEKRA E2E Prüfstelle Berlin';

    /** One TOTP secret per signed-in account, so two specs never replay each other's code. */
    public const TOTP = [
        'e2e.admin@leasyback.test' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXA',
        'e2e.admin2@leasyback.test' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXB',
        'e2e.admin3@leasyback.test' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXC',
        'e2e.owner@leasyback.test' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXD',
        'e2e.owner2@leasyback.test' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXE',
    ];

    /** The raw token of the open invitation; only its hash is stored, as in production. */
    public const INVITATION_TOKEN = 'e2einvitationtoken0000000000000000000000000000000000000000000001';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('E2eSeeder skipped: it must not run in production.');

            return;
        }

        foreach (['e2e.admin@leasyback.test' => 'E2E Admin', 'e2e.admin2@leasyback.test' => 'E2E Admin Zwei', 'e2e.admin3@leasyback.test' => 'E2E Admin Drei'] as $email => $name) {
            $this->user($email, $name, UserType::Admin);
        }

        $this->user(self::CUSTOMER_EMAIL, 'E2E Kunde', UserType::Privatkunde);

        InspectionStation::updateOrCreate(
            ['name' => self::STATION_NAME],
            [
                'station_id' => (string) Str::uuid(),
                'provider' => 'dekra',
                'strasse' => 'Teststraße 1',
                'plz' => '10115',
                'ort' => 'Berlin',
                'bundesland' => 'Berlin',
                'land' => 'de',
                'is_active' => true,
            ],
        );

        $this->seedB2b();

        $this->command?->info('E2E fixtures seeded.');
    }

    private function seedB2b(): void
    {
        // The main company and its three roles.
        $fleet = $this->company('E2E Fleet GmbH', ['street' => 'Friedrichstraße', 'number' => '12', 'zip_code' => '10117', 'city' => 'Berlin']);
        $owner = $this->user('e2e.owner@leasyback.test', 'Olivia Owner', UserType::Firmenkunde);
        $standard = $this->user('e2e.standard@leasyback.test', 'Stefan Standard', UserType::Firmenkunde);
        $readOnly = $this->user('e2e.readonly@leasyback.test', 'Rita Readonly', UserType::Firmenkunde);

        $this->membership($owner, $fleet, null);
        $this->membership($standard, $fleet, B2bRolePreset::StandardUser);
        $this->membership($readOnly, $fleet, B2bRolePreset::ReadOnly);

        CompanyBillingAddress::create([
            'b2b_id' => $fleet->b2b_id,
            'name' => 'Zentrale Berlin',
            'details' => ['street' => 'Friedrichstraße', 'number' => '12', 'zip_code' => '10117', 'city' => 'Berlin', 'country' => 'Deutschland'],
            'is_default' => true,
            'created_by_user_id' => $owner->id,
        ]);
        CompanyCostCentre::create(['b2b_id' => $fleet->b2b_id, 'name' => 'Vertrieb', 'number' => '100', 'created_by_user_id' => $owner->id]);

        $pickup = LogisticsAddressProfile::create([
            'id' => (string) Str::uuid(),
            'owner_type' => 'B2B',
            'b2b_id' => $fleet->b2b_id,
            'profile_name' => 'Standort Berlin-Mitte',
            'details' => ['street' => 'Invalidenstraße', 'number' => '5', 'zip_code' => '10115', 'city' => 'Berlin', 'country' => 'Deutschland'],
            'is_default' => true,
            'created_by_user_id' => $owner->id,
        ]);

        foreach (['B-EA 1001', 'B-EA 1002', 'B-EA 1003', 'B-EA 1004', 'B-EA 1005', 'B-EA 1006'] as $index => $plate) {
            $this->vehicle($fleet, $owner, $plate, 'WVWZZZ1KZE2E0000'.$index, $pickup->id);
        }

        // One vehicle already in a process: the catalogue must not offer it again.
        $busy = $this->vehicle($fleet, $owner, 'B-EA 1099', 'WVWZZZ1KZE2E00099', $pickup->id);
        $this->order($busy, $owner, 'order_requested', 'E2E-BUSY-1099');

        // One in the offer phase with a published, quotation-backed offer, for
        // the accept/reject flow. Built through the real services so it carries
        // exactly what an Admin-published offer carries.
        $offered = $this->vehicle($fleet, $owner, 'B-EA 1098', 'WVWZZZ1KZE2E00098', $pickup->id);
        $this->publishOffer($this->order($offered, $owner, 'inspected', 'E2E-OFFER-1098'));

        // An open invitation, for the acceptance flow.
        B2bInvitation::create([
            'invitation_id' => (string) Str::uuid(),
            'b2b_id' => $fleet->b2b_id,
            'email' => 'e2e.invitee@leasyback.test',
            'role' => 'member',
            'permissions' => B2bRolePreset::StandardUser->permissions()->toArray(),
            'vehicle_scope' => 'all',
            'token_hash' => hash('sha256', self::INVITATION_TOKEN),
            'invited_by_user_id' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        // A second company the owner also belongs to, as an ordinary member.
        $second = $this->company('E2E Zweitfirma AG', ['street' => 'Hafenweg', 'number' => '3', 'zip_code' => '20095', 'city' => 'Hamburg']);
        $secondOwner = $this->user('e2e.owner2@leasyback.test', 'Otto Zweit', UserType::Firmenkunde);
        $this->membership($secondOwner, $second, null);
        $this->membership($owner, $second, B2bRolePreset::ReadOnly);
        $this->vehicle($second, $secondOwner, 'HH-ZW 2001', 'WVWZZZ1KZE2E02001', null);

        // A company owner who never enrolled MFA: the admin impersonates them.
        $target = $this->company('E2E Übernahme GmbH', ['street' => 'Marienplatz', 'number' => '1', 'zip_code' => '80331', 'city' => 'München']);
        $this->membership($this->user('e2e.target@leasyback.test', 'Tara Target', UserType::Firmenkunde, enrollMfa: false), $target, null);

        // An unrelated company: nothing of it may ever reach the E2E Fleet users.
        $foreign = $this->company('E2E Fremdfirma GmbH', ['street' => 'Königsallee', 'number' => '9', 'zip_code' => '40212', 'city' => 'Düsseldorf']);
        $foreignOwner = $this->user('e2e.foreign@leasyback.test', 'Fiona Fremd', UserType::Firmenkunde, enrollMfa: false);
        $this->membership($foreignOwner, $foreign, null);
        $foreignVehicle = $this->vehicle($foreign, $foreignOwner, 'D-FF 9001', 'WVWZZZ1KZE2E09001', null);
        $this->order($foreignVehicle, $foreignOwner, 'order_requested', 'E2E-FOREIGN-9001');

        // A Firmenkunde who has not registered a company yet.
        $this->user('e2e.newcompany@leasyback.test', 'Nora Neu', UserType::Firmenkunde, enrollMfa: false);
    }

    /**
     * forceFill() for the privilege-relevant attributes, exactly as
     * AdminUserSeeder does — `user_type`, `is_active` and `email_verified_at`
     * are excluded from User::$fillable so request input can never set them.
     */
    private function user(string $email, string $name, UserType $type, bool $enrollMfa = true): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'user_type' => $type,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        if ($enrollMfa && isset(self::TOTP[$email])) {
            $user->forceFill(['mfa_method' => 'totp', 'mfa_secret' => self::TOTP[$email], 'mfa_confirmed_at' => now()]);
        }

        $user->save();

        return $user;
    }

    /**
     * @param  array{street: string, number: string, zip_code: string, city: string}  $address
     */
    private function company(string $name, array $address): B2B
    {
        $addressRow = Address::create([...$address, 'country' => 'Deutschland', 'longitude' => 0, 'latitude' => 0]);
        $contact = Contact::create(['address_id' => $addressRow->address_id, 'salutation' => 'Herr', 'first_name' => 'Max', 'last_name' => 'Mustermann']);

        return B2B::create([
            'contact_id' => $contact->contact_id,
            'address_id' => $addressRow->address_id,
            'company_name' => $name,
            'contact_email' => 'kontakt@'.Str::slug($name).'.test',
        ]);
    }

    /** A null preset makes the user the company's owner (Company Administrator). */
    private function membership(User $user, B2B $company, ?B2bRolePreset $preset): void
    {
        DB::table('user_b2b')->insert([
            'user_id' => $user->id,
            'b2b_id' => $company->b2b_id,
            'role' => $preset === null ? 'owner' : 'member',
            'permissions' => $preset === null ? null : json_encode($preset->permissions()->toArray()),
            'vehicle_scope' => 'all',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($user->active_b2b_id === null) {
            $user->forceFill(['active_b2b_id' => $company->b2b_id])->save();
        }
    }

    private function vehicle(B2B $company, User $creator, string $plate, string $vin, ?string $pickupProfileId): Vehicle
    {
        $vehicle = new Vehicle;
        $vehicle->forceFill([
            'vehicle_id' => (string) Str::uuid(),
            'license_plate' => $plate,
            'vin' => $vin,
            'make' => 'Volkswagen',
            'model' => 'Passat Variant',
            'first_registration_date' => '2022-03-01',
            'leasing_end_date' => now()->addMonths(4)->toDateString(),
            'leasinggeber' => 'VW Leasing',
            'vehicle_belongs' => 'B2B',
            'b2b_id' => $company->b2b_id,
            'b2c_user_id' => null,
            'created_by_user_id' => $creator->id,
            'collection_address_profile_id' => $pickupProfileId,
        ])->save();

        return $vehicle;
    }

    private function publishOffer(LeasybackOrder $order): void
    {
        $admin = User::where('email', self::ADMIN_EMAIL)->firstOrFail();

        $position = AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger vorne',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '900.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        $quotations = app(WorkshopQuotationService::class);
        $quotation = $quotations->invite($order, $admin, ['workshop_label' => 'E2E Werkstatt'])['quotation'];
        $quotations->submit($quotation, [
            'company_name' => 'E2E Karosserie GmbH',
            'contact_person' => 'Kai Karosserie',
            'contact_email' => 'werkstatt@leasyback.test',
            'contact_phone' => '+49 30 123456',
            'items' => [['appraisal_position_id' => $position->id, 'amount_net' => '750.00']],
        ]);

        $offer = app(RepairOfferService::class)->createFromQuotation($order, $admin, ['workshop_quotation_id' => $quotation->id]);
        // publishOffer() types against the App\Models shim, which the module model is the parent of.
        app(OfferService::class)->publishOffer(LeasybackOffer::findOrFail($offer->offer_id), $admin);
    }

    private function order(Vehicle $vehicle, User $creator, string $status, string $auftragsnummer): LeasybackOrder
    {
        $order = new LeasybackOrder;
        $order->forceFill([
            'id' => (string) Str::uuid(),
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => $auftragsnummer,
            'leasyback_partner' => 'leasyback',
            'order_status' => $status,
            'request_payload' => ['order_type' => 'b2b_collection'],
            'created_by_user_id' => $creator->id,
        ])->save();

        return $order;
    }
}
