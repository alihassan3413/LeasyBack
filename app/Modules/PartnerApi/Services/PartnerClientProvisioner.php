<?php

namespace App\Modules\PartnerApi\Services;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\PartnerApi\Data\IssuedPartnerToken;
use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use App\Modules\UserProfile\B2B\Models\B2B;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the three things a working integration needs — a dedicated user, a
 * company membership for it, and a client row — as one atomic unit.
 *
 * The dedicated user matters more than it looks. Every partner write goes
 * through the same services a human member's request does, and those services
 * ask "what may this user do in this company". Reusing a real employee's
 * account would make the partner's writes indistinguishable from that
 * person's, and would hand the partner whatever that person happened to be
 * granted. A purpose-built account with an explicit permission set keeps the
 * audit trail honest and the scope bounded.
 *
 * The account is created with a random unusable password and no email
 * verification path: it is not meant to log in anywhere, and the Partner API
 * has no login endpoint by design.
 */
class PartnerClientProvisioner
{
    /**
     * What the integration user may do inside its company.
     *
     * Deliberately narrower than an owner: no member management, no company
     * master data changes, no analytics. Everything the Partner API's own
     * abilities will eventually cover, and nothing else — an ability the token
     * does not carry is refused at the route, and a permission this user does
     * not hold is refused again at the service.
     *
     * @var list<string>
     */
    public const INTEGRATION_USER_PERMISSIONS = [
        B2bPermission::ViewVehicles->value,
        B2bPermission::CreateVehicles->value,
        B2bPermission::UpdateVehicles->value,
        B2bPermission::UploadVehicleDocuments->value,
        B2bPermission::CreateOrders->value,
        B2bPermission::SelectOffers->value,
        B2bPermission::ViewCompany->value,
    ];

    public function __construct(
        private readonly PartnerTokenService $tokens,
        private readonly B2bContext $b2bContext,
    ) {}

    /**
     * Provision a partner for one environment and issue its first token.
     *
     * @param  list<string>|null  $abilities  null grants every defined ability.
     */
    public function provision(
        string $slug,
        string $name,
        PartnerEnvironment $environment,
        B2B $company,
        ?string $integrationUserEmail = null,
        ?array $abilities = null,
        ?CarbonInterface $expiresAt = null,
        ?string $contactEmail = null,
        ?string $issuedBy = null,
    ): IssuedPartnerToken {
        $slug = Str::of($slug)->lower()->slug()->value();

        if ($slug === '') {
            throw new RuntimeException('A partner slug is required.');
        }

        $existing = PartnerIntegrationClient::query()
            ->where('slug', $slug)
            ->where('environment', $environment->value)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException(
                "An integration client already exists for '{$slug}' in {$environment->value}. "
                .'Use partner:token:rotate to issue a replacement credential.'
            );
        }

        return DB::transaction(function () use (
            $slug, $name, $environment, $company, $integrationUserEmail,
            $abilities, $expiresAt, $contactEmail, $issuedBy
        ) {
            $user = $this->resolveIntegrationUser(
                $integrationUserEmail ?? $this->defaultIntegrationEmail($slug, $environment),
                $name,
                $environment,
            );

            $this->ensureMembership($user, $company);

            $client = new PartnerIntegrationClient([
                'slug' => $slug,
                'name' => $name,
                'environment' => $environment,
                'is_active' => true,
                'contact_email' => $contactEmail,
            ]);

            // Ownership is set here, never mass-assigned: these two columns
            // are the whole authorization boundary of the Partner API.
            $client->b2b_id = $company->b2b_id;
            $client->user_id = $user->id;
            $client->save();

            return $this->tokens->issue(
                client: $client,
                name: 'initial',
                abilities: $abilities,
                expiresAt: $expiresAt,
                issuedBy: $issuedBy,
            );
        });
    }

    /**
     * Reuse the integration account if the operator names an existing one,
     * otherwise create it.
     *
     * A reused account must not already back another client — the unique
     * constraint on `partner_integration_clients.user_id` enforces that, and
     * this check turns it into a readable error instead of a driver
     * exception.
     */
    private function resolveIntegrationUser(string $email, string $name, PartnerEnvironment $environment): User
    {
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if ($user === null) {
            $user = new User([
                'name' => $name.' Integration ('.$environment->value.')',
                'email' => Str::lower($email),
            ]);

            // Not mass-assignable on User by design — set explicitly, as
            // AuthController::register does.
            $user->user_type = UserType::Firmenkunde;
            $user->is_active = true;
            $user->password = Hash::make(Str::random(64));
            $user->email_verified_at = now();
            $user->save();

            return $user;
        }

        if (PartnerIntegrationClient::where('user_id', $user->id)->exists()) {
            throw new RuntimeException(
                "The account {$email} already backs another integration client. "
                .'Each client needs its own dedicated integration user.'
            );
        }

        if ($user->user_type !== UserType::Firmenkunde) {
            throw new RuntimeException(
                "The account {$email} is a {$user->user_type->value} account. "
                .'An integration user must be a Firmenkunde account.'
            );
        }

        return $user;
    }

    /**
     * Give the integration user an active membership of exactly this company.
     *
     * `active_b2b_id` is pinned too, so B2bContext resolves the right company
     * for it without falling back to "earliest membership" — the integration
     * user is only ever meant to have this one.
     */
    private function ensureMembership(User $user, B2B $company): void
    {
        $existing = DB::table('user_b2b')
            ->where('user_id', $user->id)
            ->where('b2b_id', $company->b2b_id)
            ->first();

        $attributes = [
            'role' => 'member',
            'permissions' => json_encode(self::INTEGRATION_USER_PERMISSIONS),
            'vehicle_scope' => 'all',
            'status' => 'active',
            'updated_at' => now(),
        ];

        if ($existing === null) {
            DB::table('user_b2b')->insert([
                'user_id' => $user->id,
                'b2b_id' => $company->b2b_id,
                'created_at' => now(),
                ...$attributes,
            ]);
        } else {
            DB::table('user_b2b')
                ->where('user_id', $user->id)
                ->where('b2b_id', $company->b2b_id)
                ->update($attributes);
        }

        $user->forceFill(['active_b2b_id' => $company->b2b_id])->saveQuietly();

        // The membership just changed; anything holding a memoised copy for
        // this user in the current process must not keep serving the old one.
        $this->b2bContext->forget($user);
    }

    private function defaultIntegrationEmail(string $slug, PartnerEnvironment $environment): string
    {
        $domain = Str::of((string) config('mail.from.address', 'integrations@leasyback.de'))
            ->after('@')
            ->whenEmpty(fn () => Str::of('leasyback.de'))
            ->value();

        return "partner+{$slug}-{$environment->value}@{$domain}";
    }
}
