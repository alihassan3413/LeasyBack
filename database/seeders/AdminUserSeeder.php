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
        User::updateOrCreate(
            ['email' => 'admin@leasyback.com'],
            [
                'name' => 'Leasyback Admin',
                'email' => 'admin@leasyback.com',
                'user_type' => 'Admin',
                'password' => Hash::make('Admin@1234'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Admin user seeded: admin@leasyback.com / Admin@1234');
    }
}
