<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * This seeder runs in production (`php artisan db:seed --force` on
     * deploy), so it must never touch model factories or fake(): production
     * installs run `composer install --no-dev` and fakerphp/faker is absent.
     * Everything called from here is deterministic and rerunnable.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DevUserSeeder::class);
        }
    }
}
