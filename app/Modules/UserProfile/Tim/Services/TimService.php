<?php

namespace App\Modules\UserProfile\Tim\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TimService
{
    private function disk(): string
    {
        return (string) config('tim.storage_disk', 's3');
    }

    private function bucket(): string
    {
        return (string) config('tim.storage_bucket', '');
    }


    public function refreshToken(int|string $userId): void
    {
        $xml = $this->sendSoap($this->loginEnvelope());
        $document = $this->parseXml($xml);
        $token = [
            'client_id' => $this->firstText($document, 'ClientId'),
            'session' => $this->firstText($document, 'Session'),
            'username' => $this->firstText($document, 'Username'),
        ];
        if (in_array('', $token, true)) {
            throw new RuntimeException('TIM returned an incomplete login token');
        }
        $this->storeToken($token, $userId);
    }

    /** @return array{status:int,body:array} */
    public function sync(int $bewertungId, int|string $userId): array
    {
        $order = DB::table('leasyback_orders')
            ->whereJsonContains('response_body', $bewertungId)
            ->first(['id as order_id', 'auftragsnummer', 'vehicle_id']);
        if ($order === null) {
            return ['status' => 404, 'body' => [
                'error' => 'bewertung_id not found in leasyback_orders.response_body',
                'bewertung_id' => $bewertungId,
            ]];
        }

        $assessment = DB::table('vehicle_assessments')->where('auftragsnummer', $order->auftragsnummer)->first();
        $documentsProcessed = $assessment !== null
            && DB::table('assessment_documents')->where('assessment_id', $assessment->id)->exists();
        if ($assessment !== null || $documentsProcessed) {
            return ['status' => 404, 'body' => [
                'message' => 'Bewertung already processed. No action taken.',
                'bewertung_id' => $bewertungId,
                'order_id' => $order->order_id,
                'auftragsnummer' => $order->auftragsnummer,
                'vehicle_id' => $order->vehicle_id,
                'assessment_processed' => $assessment !== null,
                'documents_processed' => $documentsProcessed,
            ]];
        }

        $existing = DB::table('tim_bewertung')->where('bewertung_id', $bewertungId)->first(['s3_bucket', 's3_key']);
        if ($existing !== null) {
            return ['status' => 400, 'body' => [
                'message' => 'Bewertung already processed. No action taken.',
                'bewertung_id' => $bewertungId,
                's3_bucket' => $existing->s3_bucket,
                's3_key' => $existing->s3_key,
            ]];
        }

        $token = $this->token();
        if ($token === null) {
            $this->refreshToken($userId);
            $token = $this->token();
        }
        if ($token === null) {
            throw new RuntimeException('TIM token is unavailable');
        }

        $xml = $this->sendSoap($this->assessmentEnvelope($token, $bewertungId));
        $document = $this->parseXml($xml);
        $rawKey = 'tim/bewertung/'.$bewertungId.'/raw-'.Str::uuid().'.xml';
        if (! Storage::disk($this->disk())->put($rawKey, $xml, [
            'visibility' => 'private',
            'ContentType' => 'application/xml',
        ])) {
            throw new RuntimeException('Unable to persist TIM XML');
        }

        try {
            $this->ingestAssessment($document, $bewertungId);
            $summary = $this->summary($document);
            DB::table('tim_bewertung')->updateOrInsert(
                ['bewertung_id' => $bewertungId],
                array_merge($summary, [
                    's3_bucket' => $this->bucket(),
                    's3_key' => $rawKey,
                    'updated_by_user_id' => $userId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        } catch (Throwable $exception) {
            Storage::disk($this->disk())->delete($rawKey);
            throw $exception;
        }

        return ['status' => 200, 'body' => [
            'bewertung_id' => $bewertungId,
            's3_bucket' => $this->bucket(),
            's3_key' => $rawKey,
        ]];
    }

    private function loginEnvelope(): string
    {
        $username = $this->escape((string) config('tim.username'));
        $password = $this->escape((string) config('tim.password_sha1'));

        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tim="https://tim.autoplus-portal.de">
  <soapenv:Body><tim:Login><tim:Benutzername>{$username}</tim:Benutzername><tim:PasswortSHA1>{$password}</tim:PasswortSHA1></tim:Login></soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function assessmentEnvelope(array $token, int $bewertungId): string
    {
        $clientId = $this->escape($token['client_id']);
        $session = $this->escape($token['session']);
        $username = $this->escape($token['username']);

        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tim="https://tim.autoplus-portal.de">
  <soapenv:Body><tim:HoleBewertung><tim:Token><ClientId>{$clientId}</ClientId><Session>{$session}</Session><Username>{$username}</Username></tim:Token><tim:BewertungId>{$bewertungId}</tim:BewertungId></tim:HoleBewertung></soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }


    private function sendSoap(string $envelope): string
    {
        $wsdl = (string) config('tim.wsdl');
        if ($wsdl === '' || filter_var($wsdl, FILTER_VALIDATE_URL) === false || parse_url($wsdl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('TIM endpoint is not safely configured');
        }

        try {
            $response = Http::connectTimeout(max(1, (int) config('tim.connect_timeout_seconds', 10)))
                ->timeout(max(1, (int) config('tim.timeout_seconds', 30)))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->post($wsdl);
        } catch (ConnectionException) {
            throw new RuntimeException('TIM upstream connection failed');
        }

        if (! $response->successful()) {
            throw new RuntimeException('TIM upstream returned a non-success status');
        }
        $body = $response->body();
        if (strlen($body) > max(1024, (int) config('tim.max_soap_bytes', 10 * 1024 * 1024))) {
            throw new RuntimeException('TIM upstream response exceeded the size limit');
        }
        $document = $this->parseXml($body);
        $xpath = new DOMXPath($document);
        if ($xpath->query('//*[local-name()="Fault"]')->length > 0) {
            throw new RuntimeException('TIM upstream returned a SOAP fault');
        }

        return $body;
    }

    private function parseXml(string $xml): DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('Unsafe XML was rejected');
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new RuntimeException('TIM returned malformed XML');
        }

        return $document;
    }

    private function firstText(DOMDocument|DOMElement $node, string $localName): string
    {
        $document = $node instanceof DOMDocument ? $node : $node->ownerDocument;
        $xpath = new DOMXPath($document);
        $context = $node instanceof DOMElement ? $node : null;
        $result = $xpath->query('.//*[local-name()="'.$localName.'"][1]', $context)?->item(0);

        return trim((string) $result?->textContent);
    }

    private function storeToken(array $token, int|string $userId): void
    {
        DB::table('tim_token')->updateOrInsert(['id' => 1], [
            'client_id' => Crypt::encryptString($token['client_id']),
            'session' => Crypt::encryptString($token['session']),
            'username' => Crypt::encryptString($token['username']),
            'updated_by_user_id' => $userId,
            'updated_at' => now(),
        ]);
    }

    private function token(): ?array
    {
        $row = DB::table('tim_token')->where('id', 1)->first(['client_id', 'session', 'username']);
        if ($row === null) {
            return null;
        }

        return [
            'client_id' => $this->decryptOrLegacy($row->client_id),
            'session' => $this->decryptOrLegacy($row->session),
            'username' => $this->decryptOrLegacy($row->username),
        ];
    }

    private function decryptOrLegacy(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }

    private function summary(DOMDocument $document): array
    {
        $date = $this->firstText($document, 'Gutachtendatum');
        $date = $date !== '' ? substr($date, 0, 10) : null;
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = null;
        }
        $kilometers = preg_replace('/[^0-9-]/', '', $this->firstText($document, 'KmStand'));

        return [
            'uid' => $this->nullableText($document, 'Uid'),
            'gutachten_nummer' => $this->nullableText($document, 'Gutachtennummer'),
            'auftragsnummer' => $this->nullableText($document, 'Auftragsnummer'),
            'fin' => $this->nullableText($document, 'FIN'),
            'hersteller' => $this->nullableText($document, 'Hersteller'),
            'modell' => $this->nullableText($document, 'Modell'),
            'farbe' => $this->nullableText($document, 'Farbe'),
            'gutachtendatum' => $date,
            'kilometerstand' => $kilometers !== '' ? (int) $kilometers : null,
            'waehrung' => $this->nullableText($document, 'Waehrung'),
            'kunde' => $this->nullableText($document, 'Kunde'),
            'produkt' => $this->nullableText($document, 'Produkt'),
        ];
    }

    private function nullableText(DOMDocument|DOMElement $node, string $localName): ?string
    {
        $value = $this->firstText($node, $localName);

        return $value === '' ? null : $value;
    }


    private function ingestAssessment(DOMDocument $document, int $bewertungId): void
    {
        $uid = $this->nullableText($document, 'Uid') ?? 'missing-uid-'.Str::uuid();
        $date = $this->nullableText($document, 'Gutachtendatum');
        $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 ? substr($date, 0, 10) : null;
        $values = [
            'gutachtennummer' => $this->nullableText($document, 'Gutachtennummer'),
            'auftragsnummer' => $this->nullableText($document, 'Auftragsnummer'),
            'fin' => $this->nullableText($document, 'FIN'),
            'gutachtendatum' => $date,
        ];
        $assessment = DB::table('vehicle_assessments')->where('uid', $uid)->first();
        if ($assessment === null) {
            $assessmentId = DB::table('vehicle_assessments')->insertGetId(array_merge(
                ['uid' => $uid, 'created_at' => now()],
                $values
            ));
        } else {
            $assessmentId = $assessment->id;
            DB::table('vehicle_assessments')->where('id', $assessmentId)->update(array_filter(
                $values,
                static fn ($value) => $value !== null
            ));
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="Bericht"] | //*[local-name()="AnsichtsFoto"]');
        $count = $nodes?->length ?? 0;
        if ($count > max(1, (int) config('tim.max_documents', 100))) {
            throw new RuntimeException('TIM document count exceeded the configured limit');
        }

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $this->ingestDocument($node, (int) $assessmentId, $bewertungId);
        }
    }

    private function ingestDocument(DOMElement $node, int $assessmentId, int $bewertungId): void
    {
        $url = $this->nullableText($node, 'URL');
        if ($url === null) {
            return;
        }
        $type = $node->localName === 'Bericht' ? 'Bericht' : 'AnsichtsFoto';
        $externalText = $this->nullableText($node, 'ID');
        $externalId = $externalText !== null && ctype_digit($externalText) ? (int) $externalText : null;
        $mime = $this->nullableText($node, 'MIME');
        $format = strtolower($this->nullableText($node, 'Dateiformat') ?? ($type === 'Bericht' ? 'bin' : 'jpg'));
        $extension = preg_replace('/[^a-z0-9]/', '', $format) ?: 'bin';
        $bytes = $this->download($url);
        $this->verifySha1($bytes, $this->nullableText($node, 'InhaltHashSHA1'));

        $key = sprintf(
            'tim/bewertung/%d/%s/%s.%s',
            $bewertungId,
            $type === 'Bericht' ? 'berichte' : 'ansichtsfotos',
            Str::uuid(),
            $extension
        );
        if (! Storage::disk($this->disk())->put($key, $bytes, [
            'visibility' => 'private',
            'ContentType' => $mime ?: 'application/octet-stream',
        ])) {
            throw new RuntimeException('Unable to persist a TIM document');
        }

        $identity = [
            'assessment_id' => $assessmentId,
            'doc_type' => $type,
            'external_id' => $externalId,
        ];
        $record = [
            'title' => $this->nullableText($node, 'Titel'),
            'mime' => $mime,
            'file_format' => $format,
            'sort_order' => ($sort = $this->nullableText($node, 'Reihenfolge')) !== null ? (int) $sort : null,
            'source_url' => $url,
            'source_sha1' => $this->nullableText($node, 'InhaltHashSHA1'),
            'showroom_url' => $this->nullableText($node, 'ShowRoomURL'),
            'caption' => $this->nullableText($node, 'BildUnterschrift'),
            'image_kind' => $type === 'AnsichtsFoto' ? $this->nullableText($node, 'Text') : null,
            's3_bucket' => $this->bucket(),
            's3_key' => $key,
            's3_url' => 's3://'.$this->bucket().'/'.$key,
        ];

        try {
            $existing = DB::table('assessment_documents')->where($identity)->first();
            if ($existing === null) {
                DB::table('assessment_documents')->insert(array_merge($identity, $record, ['created_at' => now()]));
            } else {
                DB::table('assessment_documents')->where('id', $existing->id)->update($record);
                if ($existing->s3_key !== $key) {
                    Storage::disk($this->disk())->delete($existing->s3_key);
                }
            }
        } catch (Throwable $exception) {
            Storage::disk($this->disk())->delete($key);
            throw $exception;
        }
    }

    private function download(string $url): string
    {
        $this->assertAllowedDocumentUrl($url);
        try {
            $response = Http::connectTimeout(max(1, (int) config('tim.connect_timeout_seconds', 10)))
                ->timeout(max(1, (int) config('tim.timeout_seconds', 30)))
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('TIM document download failed');
        }
        if (! $response->successful()) {
            throw new RuntimeException('TIM document host returned a non-success status');
        }

        $maximum = max(1024, (int) config('tim.max_document_bytes', 25 * 1024 * 1024));
        $declared = (int) $response->header('Content-Length', 0);
        if ($declared > $maximum) {
            throw new RuntimeException('TIM document exceeded the size limit');
        }
        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            $bytes .= $chunk;
            if (strlen($bytes) > $maximum) {
                $stream->close();
                throw new RuntimeException('TIM document exceeded the size limit');
            }
        }
        $stream->close();

        return $bytes;
    }


    private function assertAllowedDocumentUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('tim.document_hosts', []));
        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || ! in_array($host, $allowed, true)) {
            throw new RuntimeException('TIM document URL host is not allowlisted');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (($parts['port'] ?? 443) !== 443)) {
            throw new RuntimeException('TIM document URL was rejected');
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        if ($records === []) {
            throw new RuntimeException('TIM document host could not be resolved safely');
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null || filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                throw new RuntimeException('TIM document host resolved to a prohibited address');
            }
        }
    }

    private function verifySha1(string $bytes, ?string $expected): void
    {
        if ($expected === null || trim($expected) === '') {
            return;
        }
        $expected = trim($expected);
        $hex = sha1($bytes);
        $base64 = base64_encode(hex2bin($hex));
        $valid = preg_match('/^[a-f0-9]{40}$/i', $expected) === 1
            ? hash_equals(strtolower($expected), $hex)
            : hash_equals($expected, $base64);
        if (! $valid) {
            throw new RuntimeException('TIM document SHA-1 validation failed');
        }
    }
}
