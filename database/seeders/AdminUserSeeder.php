<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Password used when ADMIN_SEED_PASSWORD is not set. Only ever applied
     * outside production; production seeding aborts instead (see run()).
     */
    private const LOCAL_FALLBACK_PASSWORD = '12345678';

    /**
     * Seed the bootstrap Admin account.
     *
     * Deterministic and safe to rerun: the account is matched on its email,
     * so reseeding updates the existing row instead of creating a duplicate.
     * Uses no factories or fake() — this seeder runs in production, where
     * fakerphp/faker is not installed (composer install --no-dev).
     */
    public function run(): void
    {
        $email = (string) config('auth.admin_seed.email');
        $name = (string) config('auth.admin_seed.name');
        $password = (string) config('auth.admin_seed.password');
        $usedFallbackPassword = false;

        if ($password === '') {
            if (app()->isProduction()) {
                throw new RuntimeException(
                    'ADMIN_SEED_PASSWORD is not set. Set it in .env (and ADMIN_SEED_EMAIL if the '
                    .'default admin address is not wanted) before seeding in production.'
                );
            }

            $password = self::LOCAL_FALLBACK_PASSWORD;
            $usedFallbackPassword = true;
        }

        // forceFill() is deliberate here: `user_type`, `is_active`, and
        // `email_verified_at` are intentionally excluded from User::$fillable
        // (privilege-relevant fields must never be mass-assignable from
        // request input), but this seeder is trusted, non-HTTP-facing
        // bootstrap code, not request input.
        User::firstOrNew(['email' => $email])
            ->forceFill([
                'name' => $name,
                'email' => $email,
                'user_type' => 'Admin',
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ])
            ->save();

        $this->command?->info($usedFallbackPassword
            ? "Admin user seeded: {$email} / ".self::LOCAL_FALLBACK_PASSWORD
            : "Admin user seeded: {$email} (password taken from ADMIN_SEED_PASSWORD)");
    }
}
