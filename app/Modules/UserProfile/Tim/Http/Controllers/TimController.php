<?php

namespace App\Modules\UserProfile\Tim\Http\Controllers;

use App\Models\TimBewertung;
use App\Models\TimToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TimController extends Controller
{
    /**
     * POST /tim/appraisal/login/refresh
     */
    public function refreshLogin(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can access vehicles'], 403);
        }

        $username = config('services.tim.username');
        $password = config('services.tim.password');
        $wsdl = config('services.tim.wsdl');

        $soapXml = $this->loginEnvelope($username, $password);

        $response = Http::withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
            ->withBody($soapXml, 'text/xml')
            ->post($wsdl);

        if (!$response->successful()) {
            return response()->json(['error' => 'login_failed_or_unexpected_response'], 502);
        }

        $xml = $response->body();
        $tags = $this->extractTags($xml, ['ClientId', 'Session', 'Username']);

        $clientId = $tags['ClientId'] ?? '';
        $session = $tags['Session'] ?? '';
        $timUsername = $tags['Username'] ?? '';

        if (empty($clientId) || empty($session) || empty($timUsername)) {
            return response()->json(['error' => 'login_failed_or_unexpected_response'], 502);
        }

        TimToken::upsertToken($clientId, $session, $timUsername, $user->id);

        return response()->json(null, 200);
    }

    /**
     * POST /tim/appraisal/xml/sync/{bewertungId}
     */
    public function sync(Request $request, int $bewertungId): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can access vehicles'], 403);
        }

        // Check if already processed
        $existing = TimBewertung::find($bewertungId);
        if ($existing) {
            return response()->json([
                'message' => 'Bewertung already processed. No action taken.',
                'bewertung_id' => $bewertungId,
                's3_bucket' => $existing->s3_bucket,
                's3_key' => $existing->s3_key,
            ], 400);
        }

        // Get token
        $token = TimToken::current();
        if (!$token) {
            return response()->json(['error' => 'No TIM token available. Please login first.'], 400);
        }

        $wsdl = config('services.tim.wsdl');
        $soapXml = $this->holeBewertungEnvelope($token->client_id, $token->session, $token->username, $bewertungId);

        $response = Http::withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
            ->timeout(60)
            ->withBody($soapXml, 'text/xml')
            ->post($wsdl);

        if (!$response->successful()) {
            return response()->json(['error' => 'TIM request failed'], 502);
        }

        $respXml = $response->body();

        // Save XML to S3
        $key = "tim/bewertung/{$bewertungId}/" . now()->format('Ymd\THis\Z') . '.xml';
        $bucket = config('filesystems.disks.s3.bucket');

        Storage::disk('s3')->put($key, $respXml, ['ContentType' => 'application/xml']);

        // Parse summary fields
        $tags = $this->extractTags($respXml, ['Uid', 'Gutachtennummer', 'Auftragsnummer', 'FIN', 'Modell', 'Farbe', 'Kunde', 'Produkt', 'Waehrung', 'KmStand', 'Gutachtendatum']);

        TimBewertung::create([
            'bewertung_id' => $bewertungId,
            'uid' => $tags['Uid'] ?? null,
            'gutachten_nummer' => $tags['Gutachtennummer'] ?? null,
            'auftragsnummer' => $tags['Auftragsnummer'] ?? null,
            'fin' => $tags['FIN'] ?? null,
            'modell' => $tags['Modell'] ?? null,
            'farbe' => $tags['Farbe'] ?? null,
            'kunde' => $tags['Kunde'] ?? null,
            'produkt' => $tags['Produkt'] ?? null,
            'waehrung' => $tags['Waehrung'] ?? null,
            'kilometerstand' => isset($tags['KmStand']) ? (int) $tags['KmStand'] : null,
            'gutachtendatum' => isset($tags['Gutachtendatum']) ? substr($tags['Gutachtendatum'], 0, 10) : null,
            's3_bucket' => $bucket,
            's3_key' => $key,
            'updated_by_user_id' => $user->id,
        ]);

        return response()->json([
            'bewertung_id' => $bewertungId,
            's3_bucket' => $bucket,
            's3_key' => $key,
        ]);
    }

    /**
     * GET /tim/appraisal/xml/{bewertungId}
     */
    public function xml(Request $request, int $bewertungId): mixed
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can access vehicles'], 403);
        }

        $bewertung = TimBewertung::find($bewertungId);
        if (!$bewertung) {
            return response()->json(['error' => 'bewertung_id not_found'], 404);
        }

        $content = Storage::disk('s3')->get($bewertung->s3_key);
        return response($content, 200)->header('Content-Type', 'application/xml');
    }

    /**
     * GET /tim/appraisal/docs/{auftragsnummer}
     */
    public function documents(Request $request, string $auftragsnummer): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can access vehicles'], 403);
        }

        $docs = DB::table('vehicle_assessments as va')
            ->join('assessment_documents as ad', 'ad.assessment_id', '=', 'va.id')
            ->where('va.auftragsnummer', $auftragsnummer)
            ->select('va.id as assessment_id', 'ad.external_id', 'ad.title', 'ad.s3_bucket', 'ad.s3_key')
            ->orderBy('ad.doc_type')
            ->orderBy('ad.sort_order')
            ->get();

        $result = $docs->map(function ($doc) {
            $signedUrl = Storage::disk('s3')->temporaryUrl($doc->s3_key, now()->addMinutes(15));
            return [
                'assessment_id' => $doc->assessment_id,
                'external_id' => $doc->external_id,
                'title' => $doc->title,
                'signed_url' => $signedUrl,
            ];
        });

        return response()->json($result);
    }

    private function loginEnvelope(string $username, string $password): string
    {
        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tim="https://tim.autoplus-portal.de">
  <soapenv:Body>
    <tim:Login>
      <tim:Benutzername>{$username}</tim:Benutzername>
      <tim:PasswortSHA1>{$password}</tim:PasswortSHA1>
    </tim:Login>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function holeBewertungEnvelope(string $clientId, string $session, string $username, int $bewertungId): string
    {
        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tim="https://tim.autoplus-portal.de">
  <soapenv:Body>
    <tim:HoleBewertung>
      <tim:Token>
        <ClientId>{$clientId}</ClientId>
        <Session>{$session}</Session>
        <Username>{$username}</Username>
      </tim:Token>
      <tim:BewertungId>{$bewertungId}</tim:BewertungId>
    </tim:HoleBewertung>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function extractTags(string $xml, array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            if (preg_match("/<{$tag}[^>]*>([^<]+)<\/{$tag}>/", $xml, $m)) {
                $result[$tag] = html_entity_decode($m[1]);
            }
        }
        return $result;
    }
}
