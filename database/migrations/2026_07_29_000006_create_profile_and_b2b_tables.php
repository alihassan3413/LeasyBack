<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('address_id')->primary();
            $table->string('street');
            $table->string('number', 50);
            $table->string('additional_address')->nullable();
            $table->string('zip_code', 20);
            $table->string('city', 100);
            $table->string('country', 100);
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('contact_id')->primary();
            $table->foreignUuid('address_id')->nullable()->constrained('addresses', 'address_id')->restrictOnDelete();
            $table->string('salutation', 50)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->timestamps();
        });

        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->uuid('phone_id')->primary();
            $table->foreignUuid('contact_id')->constrained('contacts', 'contact_id')->cascadeOnDelete();
            $table->string('international_prefix', 10);
            $table->string('phone_number', 50);
            $table->boolean('is_primary_contact')->default(false);
            $table->timestamps();
            $table->index(['contact_id', 'is_primary_contact']);
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id('profile_id');
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->unique();
            $table->foreignUuid('contact_id')->unique()->constrained('contacts', 'contact_id')->restrictOnDelete();
            $table->string('image_url', 2048)->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->uuid('preference_id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 100);
            $table->string('sprache', 2);
            $table->boolean('benachrichtigungseinstellungen_push')->default(false);
            $table->boolean('benachrichtigungseinstellungen_email')->default(false);
            $table->timestamps();
        });

        Schema::create('b2b', function (Blueprint $table) {
            $table->uuid('b2b_id')->primary();
            $table->foreignUuid('contact_id')->unique()->constrained('contacts', 'contact_id')->restrictOnDelete();
            $table->foreignUuid('address_id')->unique()->constrained('addresses', 'address_id')->restrictOnDelete();
            $table->string('company_name');
            $table->string('vat_id', 100)->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamps();
            $table->index('company_name');
        });

        Schema::create('user_b2b', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('b2b_id')->constrained('b2b', 'b2b_id')->cascadeOnDelete();
            $table->string('role', 50)->default('member');
            $table->timestamps();
            $table->primary(['user_id', 'b2b_id']);
            $table->unique('user_id');
        });
        Schema::table('workshops', function (Blueprint $table) {
            $table->boolean('has_alt_billing')->nullable()->after('account_holder');
            $table->text('alt_billing')->nullable()->after('has_alt_billing');

            $table->string('contact_email')->nullable()->change();
            $table->text('iban')->nullable()->change();
            $table->text('bic')->nullable()->change();
            $table->text('account_holder')->nullable()->change();
            $table->uuid('address_id')->nullable()->change();
            $table->string('street')->nullable()->change();
            $table->string('number', 30)->nullable()->change();
            $table->string('zip_code', 20)->nullable()->change();
            $table->string('city', 100)->nullable()->change();
            $table->string('country', 100)->nullable()->change();
            $table->uuid('contact_id')->nullable()->change();
            $table->string('salutation', 30)->nullable()->change();
            $table->string('first_name', 100)->nullable()->change();
            $table->string('last_name', 100)->nullable()->change();
            $table->string('international_prefix', 10)->nullable()->change();
            $table->string('primary_phone_number', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->dropColumn(['has_alt_billing', 'alt_billing']);
        });

        Schema::dropIfExists('user_b2b');
        Schema::dropIfExists('b2b');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('addresses');
    }
};
