<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The fixed starting point for the Playwright B2C journey.
 *
 * Deliberately factory-free and deterministic — the browser tests assert on
 * these exact names and addresses, so a `fake()` value would make them flap.
 * Refuses to run in production for the same reason DevUserSeeder does: these
 * are test affordances, not application data.
 *
 * The customer starts with no profile, no vehicle and no order, because the
 * journey under test begins at registration and the onboarding wizard is the
 * first thing it exercises.
 *
 * The station is DEKRA rather than TÜV SÜD on purpose. A TÜV SÜD booking calls
 * the live partner API from OrderService::createTuvsudOrder(); a DEKRA one is
 * handled entirely in-process, so the suite never depends on a third party
 * being reachable.
 */
class E2eSeeder extends Seeder
{
    public const CUSTOMER_EMAIL = 'e2e.customer@leasyback.test';

    public const CUSTOMER_PASSWORD = 'e2e-password';

    public const ADMIN_EMAIL = 'e2e.admin@leasyback.test';

    public const ADMIN_PASSWORD = 'e2e-password';

    public const STATION_NAME = 'DEKRA E2E Prüfstelle Berlin';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('E2eSeeder skipped: it must not run in production.');

            return;
        }

        $this->user(self::ADMIN_EMAIL, 'E2E Admin', UserType::Admin, self::ADMIN_PASSWORD);
        $this->user(self::CUSTOMER_EMAIL, 'E2E Kunde', UserType::Privatkunde, self::CUSTOMER_PASSWORD);

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

        $this->command?->info('E2E fixtures seeded.');
    }

    /**
     * forceFill() for the privilege-relevant attributes, exactly as
     * AdminUserSeeder does — `user_type`, `is_active` and `email_verified_at`
     * are excluded from User::$fillable so request input can never set them.
     */
    private function user(string $email, string $name, UserType $type, string $password): void
    {
        User::firstOrNew(['email' => $email])
            ->forceFill([
                'name' => $name,
                'email' => $email,
                'user_type' => $type,
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ])
            ->save();
    }
}
