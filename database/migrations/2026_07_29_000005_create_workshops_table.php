<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('workshop_name');
            $table->string('logo_url')->nullable();
            $table->string('contact_email');
            $table->boolean('has_vat_id')->default(false);
            $table->string('vat_id', 50)->nullable();
            $table->text('iban');
            $table->text('bic');
            $table->text('account_holder');
            $table->string('packages_selected', 20);
            $table->boolean('terms_accepted');
            $table->boolean('privacy_accepted');
            $table->uuid('address_id')->unique();
            $table->string('street');
            $table->string('number', 30);
            $table->string('additional_address')->nullable();
            $table->string('zip_code', 20);
            $table->string('city', 100);
            $table->string('country', 100);
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->uuid('contact_id')->unique();
            $table->string('salutation', 30);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('international_prefix', 10);
            $table->string('primary_phone_number', 30);
            $table->json('phone_numbers')->nullable();
            $table->text('imprint_text')->nullable();
            $table->json('services_offered')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshops');
    }
};
