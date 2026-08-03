<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevUserSeeder extends Seeder
{
    /**
     * Seed the convenience customer account used for local development.
     *
     * Deliberately factory-free and deterministic so the credentials are
     * stable across reseeds, and refuses to run in production — the account is
     * a development affordance, not application data.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DevUserSeeder skipped: it must not run in production.');

            return;
        }

        // forceFill() for the privilege-relevant, non-fillable attributes —
        // see AdminUserSeeder for why those are excluded from User::$fillable.
        User::firstOrNew(['email' => 'test@example.com'])
            ->forceFill([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'user_type' => UserType::Privatkunde,
                'password' => Hash::make('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ])
            ->save();

        $this->command?->info('Development user seeded: test@example.com / password');
    }
}
