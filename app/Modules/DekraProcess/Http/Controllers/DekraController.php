<?php

namespace App\Modules\DekraProcess\Http\Controllers;

use App\Modules\DekraProcess\Models\AnlageListe;
use App\Modules\DekraProcess\Models\AuftragPartner;
use App\Modules\DekraProcess\Models\BesichtigungOrte;
use App\Modules\DekraProcess\Models\DekraClient;
use App\Modules\DekraProcess\Models\Dienstleistungsobjekt;
use App\Modules\DekraProcess\Models\KundenAuftrag;
use App\Modules\DekraProcess\Models\Quittung;
use App\Modules\DekraProcess\Models\QuittungEmail;
use App\Modules\DekraProcess\Models\QuittungKundenreferenz;
use App\Modules\DekraProcess\Models\QuittungPartner;
use App\Modules\DekraProcess\Models\QuittungPartnerRolle;
use App\Modules\DekraProcess\Models\QuittungStatus;
use App\Modules\DekraProcess\Services\DekraApiService;
use App\Modules\DekraProcess\Services\XmlGeneratorService;
use App\Modules\DekraProcess\Services\XmlParserService;
use App\Modules\UserProfile\Admin\Support\EnsuresAdmin;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every Sanctum-authenticated method here is now gated behind
 * AdminPolicy::manageDekraProcess (via the shared EnsuresAdmin trait) — none
 * of them checked the caller's role at all before this checkpoint, meaning
 * any authenticated user of any type could create DEKRA clients/orders or
 * trigger a real send to the DEKRA API. receiveTerminbestaetigung() is the
 * one method deliberately left alone here: it's the public webhook DEKRA
 * itself calls (no Sanctum token available), gated instead by the
 * VerifyDekraWebhookSignature route middleware.
 */
class DekraController extends Controller
{
    use EnsuresAdmin;

    /**
     * Create a new client with auto-generated 5-digit ID.
     */
    public function createClient(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
        ]);

        $client = DB::transaction(function () use ($validated) {
            // IDs are zero-padded, so lexical descending order is portable across
            // PostgreSQL, MySQL, and SQLite. The old CAST(... AS UNSIGNED) was not
            // valid PostgreSQL syntax.
            $lastClient = DekraClient::query()
                ->orderByDesc('client_id')
                ->lockForUpdate()
                ->first();

            $nextNumber = $lastClient ? (int) $lastClient->client_id + 1 : 1;
            if ($nextNumber > 99999) {
                abort(422, 'No more DEKRA client IDs are available.');
            }

            return DekraClient::create([
                'client_id' => str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT),
                'client_name' => $validated['client_name'],
                'created_at' => Carbon::now(),
            ]);
        });

        return response()->json([
            'ok' => true,
            'data' => $client,
            'message' => 'Client created successfully.',
        ], 201);
    }

    /**
     * Create a Dienstleistungsobjekt (vehicle record).
     */
    public function createDienstleistungsobjekt(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        // Preserve compatibility with the Rust API's original misspelled field.
        if (! $request->has('leasing_nummer') && $request->has('leasing_numer')) {
            $request->merge(['leasing_nummer' => $request->input('leasing_numer')]);
        }

        $validated = $request->validate([
            'client_id' => 'required|string|max:5|exists:clients,client_id',
            'objekt_art' => 'sometimes|string|max:50',
            'amtliches_kennzeichen' => 'required|string|max:50',
            'erstzulassung' => 'required|string|max:10',
            'fahrzeugidentifizierungsnummer' => 'required|string|max:17',
            'hersteller' => 'required|string|max:255',
            'verkaufsbezeichnung' => 'required|string|max:255',
            'leasing_nummer' => 'required|string|max:50',
        ]);

        $objekt = Dienstleistungsobjekt::create([
            ...$validated,
            'objekt_art' => $validated['objekt_art'] ?? 'PKW',
            'objekt_create_date' => Carbon::now(),
        ]);

        return response()->json([
            'ok' => true,
            'data' => $objekt,
            'message' => 'Dienstleistungsobjekt created successfully.',
        ], 201);
    }

    /**
     * Create a BesichtigungOrte (inspection location).
     */
    public function createBesichtigungOrte(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        $validated = $request->validate([
            'orte_name' => 'required|string|max:255',
            'name4' => 'nullable|string|max:255',
            'strasse' => 'required|string|max:255',
            'plz' => 'required|string|max:10',
            'ort' => 'required|string|max:100',
            'rolle' => 'required|string|max:100',
            'is_valid' => 'sometimes|boolean',
        ]);

        $orte = BesichtigungOrte::create([
            ...$validated,
            'is_valid' => $validated['is_valid'] ?? true,
            'orte_create_date' => Carbon::now(),
        ]);

        return response()->json([
            'ok' => true,
            'data' => $orte,
            'message' => 'Besichtigung Ort created successfully.',
        ], 201);
    }

    /**
     * Create a KundenAuftrag (customer order).
     * Auto-generates beauftragungsnummer = "{amtliches_kennzeichen}-{YmdHis}"
     */
    public function createKundenAuftrag(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        // Preserve compatibility with the Rust JSON field name.
        if (! $request->has('bestellung_bestaetigen') && $request->has('bestellung_bestätigen')) {
            $request->merge([
                'bestellung_bestaetigen' => $request->input('bestellung_bestätigen'),
            ]);
        }

        $validated = $request->validate([
            'client_id' => 'required|string|max:5|exists:clients,client_id',
            'objekt_id' => 'required|integer|exists:dienstleistungsobjekt,objekt_id',
            'orte_id' => 'required|integer|exists:besichtigung_orte,orte_id',
            'bestellung_bestaetigen' => 'sometimes|boolean',
            'auftrag_bemerkung' => 'nullable|string',
        ]);

        // Get amtliches_kennzeichen from the referenced Dienstleistungsobjekt
        $objekt = Dienstleistungsobjekt::findOrFail($validated['objekt_id']);
        $kennzeichen = str_replace(' ', '-', $objekt->amtliches_kennzeichen);
        $beauftragungsnummer = $kennzeichen.'-'.Carbon::now()->format('YmdHis');

        $auftrag = KundenAuftrag::create([
            'beauftragungsnummer' => $beauftragungsnummer,
            'client_id' => $validated['client_id'],
            'objekt_id' => $validated['objekt_id'],
            'orte_id' => $validated['orte_id'],
            'auftrag_created_date' => Carbon::now(),
            'bestellung_bestaetigen' => $validated['bestellung_bestaetigen'] ?? false,
            'auftrag_bemerkung' => $validated['auftrag_bemerkung'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'data' => $auftrag,
            'message' => 'Kunden Auftrag created successfully.',
        ], 201);
    }

    /**
     * Create an AnlageListe (attachment).
     */
    public function createAnlageListe(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        $validated = $request->validate([
            'beauftragungsnummer' => 'required|string|max:50|exists:kunden_auftrag,beauftragungsnummer',
            'client_id' => 'required|string|max:5|exists:clients,client_id',
            'beschreibung' => 'required|string',
            'inhalt' => 'required|string',
            'mime_type' => 'required|string|max:100',
            'feile_name' => 'required|string|max:255',
            'feile_typ' => 'required|string|max:50',
        ]);

        $anlage = AnlageListe::create([
            ...$validated,
            'anlage_created_date' => Carbon::now(),
        ]);

        return response()->json([
            'ok' => true,
            'data' => $anlage,
            'message' => 'Anlage created successfully.',
        ], 201);
    }

    /**
     * Create an AuftragPartner.
     */
    public function createPartner(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        $validated = $request->validate([
            'partner_name' => 'required|string|max:255',
            'partner_nummer' => 'required|string|max:50|unique:auftrag_partner,partner_nummer',
            'partner_rolle' => 'required|string|max:100',
            'partner_valid' => 'sometimes|boolean',
        ]);

        $partner = AuftragPartner::create([
            ...$validated,
            'partner_valid' => $validated['partner_valid'] ?? true,
            'partner_create_date' => Carbon::now(),
        ]);

        return response()->json([
            'ok' => true,
            'data' => $partner,
            'message' => 'Partner created successfully.',
        ], 201);
    }

    /**
     * Get full order info by beauftragungsnummer (JOIN query).
     */
    public function getAuftragInfo(Request $request, string $beauftragungsnummer): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        $auftrag = DB::table('kunden_auftrag as ka')
            ->join('dienstleistungsobjekt as dobj', 'ka.objekt_id', '=', 'dobj.objekt_id')
            ->join('besichtigung_orte as bs', 'ka.orte_id', '=', 'bs.orte_id')
            ->join('clients as c', 'ka.client_id', '=', 'c.client_id')
            ->where('ka.beauftragungsnummer', $beauftragungsnummer)
            ->select([
                'ka.beauftragungsnummer',
                'ka.client_id',
                'ka.objekt_id',
                'ka.orte_id',
                'ka.bestellung_bestaetigen',
                'ka.auftrag_bemerkung',
                'dobj.objekt_art',
                'dobj.amtliches_kennzeichen',
                'dobj.erstzulassung',
                'dobj.fahrzeugidentifizierungsnummer',
                'dobj.hersteller',
                'dobj.verkaufsbezeichnung',
                'dobj.leasing_nummer',
                'bs.orte_name',
                'bs.name4',
                'bs.strasse',
                'bs.plz',
                'bs.ort',
                'bs.rolle',
                'bs.is_valid',
                'c.client_name',
            ])
            ->first();

        if (! $auftrag) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => "Auftrag with beauftragungsnummer '{$beauftragungsnummer}' not found.",
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => $auftrag,
            'message' => 'Auftrag info retrieved successfully.',
        ]);
    }

    /**
     * Generate XML, upload to S3, and send to DEKRA API.
     */
    public function generateAndSendAuftrag(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'manageDekraProcess', 'Only admin can access DEKRA process endpoints')) {
            return $denied;
        }

        $validated = $request->validate([
            'beauftragungsnummer' => 'required|string|exists:kunden_auftrag,beauftragungsnummer',
            'partner_id' => 'required|integer|exists:auftrag_partner,partner_id',
            'besichtigung_datum' => 'nullable|date_format:Y-m-d',
            'besichtigung_uhrzeit' => 'nullable|date_format:H:i:s',
        ]);

        // Fetch full auftrag data via JOIN
        $auftragData = DB::table('kunden_auftrag as ka')
            ->join('dienstleistungsobjekt as dobj', 'ka.objekt_id', '=', 'dobj.objekt_id')
            ->join('besichtigung_orte as bs', 'ka.orte_id', '=', 'bs.orte_id')
            ->join('clients as c', 'ka.client_id', '=', 'c.client_id')
            ->where('ka.beauftragungsnummer', $validated['beauftragungsnummer'])
            ->select([
                'ka.beauftragungsnummer',
                'ka.client_id',
                'ka.auftrag_bemerkung',
                'dobj.objekt_art',
                'dobj.amtliches_kennzeichen',
                'dobj.erstzulassung',
                'dobj.fahrzeugidentifizierungsnummer',
                'dobj.hersteller',
                'dobj.verkaufsbezeichnung',
                'dobj.leasing_nummer',
                'bs.orte_name',
                'bs.name4',
                'bs.strasse',
                'bs.plz',
                'bs.ort',
                'bs.rolle',
                'c.client_name',
            ])
            ->first();

        if (! $auftragData) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Auftrag data not found.',
            ], 404);
        }

        // Convert to array and add optional fields
        $auftragArray = (array) $auftragData;
        if (! empty($validated['besichtigung_datum'])) {
            $auftragArray['besichtigung_datum'] = $validated['besichtigung_datum'];
        }
        if (! empty($validated['besichtigung_uhrzeit'])) {
            $auftragArray['besichtigung_uhrzeit'] = $validated['besichtigung_uhrzeit'];
        }

        // Fetch partner
        $partner = AuftragPartner::findOrFail($validated['partner_id']);

        // Fetch anlagen
        $anlagen = AnlageListe::where('beauftragungsnummer', $validated['beauftragungsnummer'])
            ->get()
            ->toArray();

        // Generate XML
        $xmlGenerator = new XmlGeneratorService;
        $xml = $xmlGenerator->generate($auftragArray, $anlagen, $partner->toArray());

        // Upload to S3
        $filename = 'auftrag_'.Str::uuid().'.xml';
        $s3Key = "auftrage/{$validated['beauftragungsnummer']}/{$filename}";

        try {
            $uploaded = Storage::disk('s3')->put($s3Key, $xml, [
                'ContentType' => 'application/xml',
            ]);

            if (! $uploaded) {
                throw new \RuntimeException('The S3 filesystem rejected the upload.');
            }
        } catch (\Throwable $e) {
            Log::error('S3 upload failed', ['error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Failed to upload XML to S3: '.$e->getMessage(),
            ], 500);
        }

        // Send to DEKRA API
        $apiService = new DekraApiService;
        $apiResponse = $apiService->sendAuftrag($xml, $validated['beauftragungsnummer']);

        return response()->json([
            'ok' => $apiResponse['success'],
            'data' => [
                's3_key' => $s3Key,
                'dekra_status_code' => $apiResponse['status_code'],
                'dekra_response' => $apiResponse['body'],
            ],
            'message' => $apiResponse['success']
                ? 'Auftrag sent to DEKRA successfully.'
                : 'Failed to send Auftrag to DEKRA.',
        ], $apiResponse['success'] ? 200 : 502);
    }

    /**
     * Receive and process Terminbestätigung (confirmation XML from DEKRA).
     */
    public function receiveTerminbestaetigung(Request $request): JsonResponse
    {
        $xmlContent = $request->getContent();

        if (empty($xmlContent)) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Empty XML body received.',
            ], 400);
        }

        // Parse XML
        $parser = new XmlParserService;

        try {
            $parsed = $parser->parseQuittung($xmlContent);
        } catch (\Exception $e) {
            Log::error('Failed to parse Quittung XML', ['error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Failed to parse XML: '.$e->getMessage(),
            ], 400);
        }

        // Check for duplicate beauftragungsnummer
        $existing = Quittung::where('beauftragungsnummer', $parsed['beauftragungsnummer'])->first();
        if ($existing) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => "Quittung with beauftragungsnummer '{$parsed['beauftragungsnummer']}' already exists.",
            ], 409);
        }

        // Upload raw XML to S3
        try {
            $s3Key = "auftrage/{$parsed['beauftragungsnummer']}/confirmation.xml";
            $uploaded = Storage::disk('s3')->put($s3Key, $xmlContent, [
                'ContentType' => 'application/xml',
            ]);

            if (! $uploaded) {
                throw new \RuntimeException('The S3 filesystem rejected the upload.');
            }
        } catch (\Throwable $e) {
            Log::error('S3 upload of confirmation XML failed', ['error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Failed to archive confirmation XML.',
            ], 500);
        }

        // Save to database within a transaction
        try {
            DB::transaction(function () use ($parsed) {
                $quittungId = (string) Str::uuid();

                // Insert Quittung
                Quittung::create([
                    'id' => $quittungId,
                    'versandweg' => $parsed['versandweg'],
                    'schema_version' => $parsed['schema_version'],
                    'erstellt_am' => $parsed['erstellt_am'],
                    'amtliches_kennzeichen' => $parsed['amtliches_kennzeichen'],
                    'beauftragungsnummer' => $parsed['beauftragungsnummer'],
                    'sap_auftragsnummer' => $parsed['sap_auftragsnummer'],
                    'vorgangsnummer' => $parsed['vorgangsnummer'],
                ]);

                // Insert Kundenreferenzen
                foreach ($parsed['kundenreferenzen'] as $ref) {
                    QuittungKundenreferenz::create([
                        'id' => (string) Str::uuid(),
                        'quittung_id' => $quittungId,
                        'typ' => $ref['typ'],
                        'nummer' => $ref['nummer'],
                    ]);
                }

                // Insert Partners, Emails, and Roles
                foreach ($parsed['partner'] as $partnerData) {
                    $partnerId = (string) Str::uuid();

                    QuittungPartner::create([
                        'id' => $partnerId,
                        'quittung_id' => $quittungId,
                        'name' => $partnerData['name'],
                        'name2' => $partnerData['name2'],
                        'name4' => $partnerData['name4'],
                        'strasse' => $partnerData['strasse'],
                        'plz' => $partnerData['plz'],
                        'ort' => $partnerData['ort'],
                        'land' => $partnerData['land'],
                        'nummer' => $partnerData['nummer'],
                        'telefonnummer' => $partnerData['telefonnummer'],
                        'faxnummer' => $partnerData['faxnummer'],
                    ]);

                    // Email
                    if (! empty($partnerData['email'])) {
                        QuittungEmail::create([
                            'id' => (string) Str::uuid(),
                            'partner_id' => $partnerId,
                            'bezeichnung' => $partnerData['email'],
                        ]);
                    }

                    // Roles
                    foreach ($partnerData['rollen'] as $rolle) {
                        QuittungPartnerRolle::create([
                            'id' => (string) Str::uuid(),
                            'partner_id' => $partnerId,
                            'rolle' => $rolle,
                        ]);
                    }
                }

                // Insert Status
                if (! empty($parsed['status'])) {
                    QuittungStatus::create([
                        'id' => (string) Str::uuid(),
                        'quittung_id' => $quittungId,
                        'bezeichnung' => $parsed['status']['bezeichnung'],
                        'zusatzinformation' => $parsed['status']['zusatzinformation'],
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Failed to save Quittung to database', ['error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Failed to save confirmation: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'beauftragungsnummer' => $parsed['beauftragungsnummer'],
                'vorgangsnummer' => $parsed['vorgangsnummer'],
            ],
            'message' => 'Terminbestätigung received and saved successfully.',
        ]);
    }
}
