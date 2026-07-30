<?php

namespace App\Modules\DekraProcess\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DekraApiService
{
    private string $apiUrl;

    private string $username;

    private string $password;

    public function __construct()
    {
        $this->apiUrl = (string) config('services.dekra.api_url');
        $this->username = (string) config('services.dekra.username');
        $this->password = (string) config('services.dekra.password');
    }

    /**
     * Send the generated XML to the DEKRA API.
     *
     * @param  string  $xml  The XML content to send
     * @param  string  $beauftragungsnummer  Used as X-Request-Id header
     * @return array Response with status and body
     */
    public function sendAuftrag(string $xml, string $beauftragungsnummer): array
    {
        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->connectTimeout((int) config('services.dekra.connect_timeout', 10))
                ->timeout((int) config('services.dekra.timeout', 30))
                ->withHeaders([
                    'Content-Type' => 'application/xml;charset=UTF-8',
                    'X-Request-Id' => $beauftragungsnummer,
                    'Accept' => 'application/xml',
                ])
                ->withBody($xml, 'application/xml;charset=UTF-8')
                ->post($this->apiUrl);

            Log::info('DEKRA API response', [
                'beauftragungsnummer' => $beauftragungsnummer,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'body' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('DEKRA API request failed', [
                'beauftragungsnummer' => $beauftragungsnummer,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 500,
                'body' => $e->getMessage(),
            ];
        }
    }
}
