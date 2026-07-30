<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds profile fields to users table:
     * - phone: user phone number
     * - address: street address
     * - city: city name
     * - zip_code: postal code
     * - country: country name
     * - avatar_path: path to uploaded avatar file
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('user_type');
            $table->string('address', 500)->nullable()->after('phone');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('zip_code', 10)->nullable()->after('city');
            $table->string('country', 100)->nullable()->after('zip_code');
            $table->string('avatar_path')->nullable()->after('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'address',
                'city',
                'zip_code',
                'country',
                'avatar_path',
            ]);
        });
    }
};
