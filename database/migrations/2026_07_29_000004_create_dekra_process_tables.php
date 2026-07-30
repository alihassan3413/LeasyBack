<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->string('client_id', 5)->primary();
            $table->string('client_name', 255);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('dienstleistungsobjekt', function (Blueprint $table) {
            $table->id('objekt_id');
            $table->string('client_id', 5);
            $table->string('objekt_art', 50)->default('PKW');
            $table->string('amtliches_kennzeichen', 50);
            $table->string('erstzulassung', 10);
            $table->string('fahrzeugidentifizierungsnummer', 17);
            $table->string('hersteller', 255);
            $table->string('verkaufsbezeichnung', 255);
            $table->string('leasing_nummer', 50);
            $table->timestamp('objekt_create_date');

            $table->foreign('client_id')
                ->references('client_id')
                ->on('clients')
                ->onDelete('restrict');

            $table->index('client_id', 'idx_dienstleistungsobjekt_client_id');
        });

        Schema::create('besichtigung_orte', function (Blueprint $table) {
            $table->id('orte_id');
            $table->string('orte_name', 255);
            $table->string('name4', 255)->nullable();
            $table->string('strasse', 255);
            $table->string('plz', 10);
            $table->string('ort', 100);
            $table->string('rolle', 100);
            $table->boolean('is_valid')->default(true);
            $table->timestamp('orte_create_date');
        });

        Schema::create('kunden_auftrag', function (Blueprint $table) {
            $table->id('auftrag_id');
            $table->string('beauftragungsnummer', 50)->unique();
            $table->string('client_id', 5);
            $table->unsignedBigInteger('objekt_id');
            $table->unsignedBigInteger('orte_id');
            $table->timestamp('auftrag_created_date');
            $table->boolean('bestellung_bestaetigen')->default(false);
            $table->text('auftrag_bemerkung')->nullable();

            $table->foreign('client_id')
                ->references('client_id')
                ->on('clients')
                ->onDelete('restrict');
            $table->foreign('objekt_id')
                ->references('objekt_id')
                ->on('dienstleistungsobjekt')
                ->onDelete('restrict');
            $table->foreign('orte_id')
                ->references('orte_id')
                ->on('besichtigung_orte')
                ->onDelete('restrict');

            $table->index('client_id', 'idx_kunden_auftrag_client_id');
            $table->index('objekt_id', 'idx_kunden_auftrag_objekt_id');
            $table->index('orte_id', 'idx_kunden_auftrag_orte_id');
        });

        Schema::create('anlage_liste', function (Blueprint $table) {
            $table->id('anlage_id');
            $table->string('beauftragungsnummer', 50);
            $table->string('client_id', 5);
            $table->text('beschreibung');
            $table->text('inhalt');
            $table->string('mime_type', 100);
            $table->string('feile_name', 255);
            $table->string('feile_typ', 50);
            $table->timestamp('anlage_created_date');

            $table->foreign('client_id')
                ->references('client_id')
                ->on('clients')
                ->onDelete('restrict');
            $table->foreign('beauftragungsnummer')
                ->references('beauftragungsnummer')
                ->on('kunden_auftrag')
                ->onDelete('cascade');

            $table->index('beauftragungsnummer', 'idx_anlage_liste_beauftragungsnummer');
            $table->index('client_id', 'idx_anlage_liste_client_id');
        });

        Schema::create('auftrag_partner', function (Blueprint $table) {
            $table->id('partner_id');
            $table->string('partner_name', 255);
            $table->string('partner_nummer', 50)->unique();
            $table->string('partner_rolle', 100);
            $table->boolean('partner_valid')->default(true);
            $table->timestamp('partner_create_date');

            $table->index('partner_nummer', 'idx_partner_partner_nummer');
        });

        Schema::create('quittungen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versandweg');
            $table->string('schema_version');
            $table->timestamp('erstellt_am');
            $table->string('amtliches_kennzeichen');
            $table->string('beauftragungsnummer')->unique();
            $table->string('sap_auftragsnummer');
            $table->string('vorgangsnummer');

            $table->index('beauftragungsnummer', 'idx_quittungen_beauftragungsnummer');
        });

        Schema::create('quittung_kundenreferenzen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quittung_id');
            $table->string('typ');
            $table->string('nummer');

            $table->foreign('quittung_id')
                ->references('id')
                ->on('quittungen')
                ->onDelete('cascade');
        });

        Schema::create('quittung_partner', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quittung_id');
            $table->string('name');
            $table->string('name2')->nullable();
            $table->string('name4')->nullable();
            $table->string('strasse')->nullable();
            $table->string('plz')->nullable();
            $table->string('ort')->nullable();
            $table->string('land')->nullable();
            $table->string('nummer')->nullable();
            $table->string('telefonnummer')->nullable();
            $table->string('faxnummer')->nullable();

            $table->foreign('quittung_id')
                ->references('id')
                ->on('quittungen')
                ->onDelete('cascade');
        });

        Schema::create('quittung_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_id');
            $table->string('bezeichnung')->nullable();

            $table->foreign('partner_id')
                ->references('id')
                ->on('quittung_partner')
                ->onDelete('cascade');
        });

        Schema::create('quittung_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quittung_id');
            $table->string('bezeichnung');
            $table->timestamp('zusatzinformation')->nullable();

            $table->foreign('quittung_id')
                ->references('id')
                ->on('quittungen')
                ->onDelete('cascade');
        });

        Schema::create('quittung_partner_rollen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_id');
            $table->string('rolle');

            $table->foreign('partner_id')
                ->references('id')
                ->on('quittung_partner')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quittung_partner_rollen');
        Schema::dropIfExists('quittung_emails');
        Schema::dropIfExists('quittung_status');
        Schema::dropIfExists('quittung_partner');
        Schema::dropIfExists('quittung_kundenreferenzen');
        Schema::dropIfExists('quittungen');
        Schema::dropIfExists('auftrag_partner');
        Schema::dropIfExists('anlage_liste');
        Schema::dropIfExists('kunden_auftrag');
        Schema::dropIfExists('besichtigung_orte');
        Schema::dropIfExists('dienstleistungsobjekt');
        Schema::dropIfExists('clients');
    }
};
