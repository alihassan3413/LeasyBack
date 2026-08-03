<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed a default Admin user for local development / testing.
     */
    public function run(): void
    {
        // forceFill() is deliberate here: `user_type`, `is_active`, and
        // `email_verified_at` are intentionally excluded from User::$fillable
        // (privilege-relevant fields must never be mass-assignable from
        // request input), but this seeder is trusted, non-HTTP-facing
        // bootstrap code, not request input.
        User::firstOrNew(['email' => 'admin@leasyback.com'])
            ->forceFill([
                'name' => 'Leasyback Admin',
                'email' => 'admin@leasyback.com',
                'user_type' => 'Admin',
                'password' => Hash::make('12345678'),
                'is_active' => true,
                'email_verified_at' => now(),
            ])
            ->save();

        $this->command->info('Admin user seeded: admin@leasyback.com / 12345678');
    }
}
