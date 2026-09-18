<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Data\LexwareContactRequest;
use App\Modules\UserProfile\Payment\Data\LexwareFile;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceRequest;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceResult;
use App\Modules\UserProfile\Payment\Data\LexwarePersonContactRequest;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LexwareClient implements LexwareGateway
{
    private const ENABLED_MODES = ['test', 'live'];

    private readonly string $baseUrl;

    private readonly string $apiKey;

    public function __construct()
    {
        $mode = trim((string) config('services.lexware.mode', 'disabled'));

        if (! in_array($mode, self::ENABLED_MODES, true)) {
            throw LexwareGatewayException::notConfigured(
                'LEXWARE_INTEGRATION_MODE is not enabled — refusing to construct a Lexware client.',
            );
        }

        $apiKey = trim((string) config('services.lexware.api_key'));

        if ($apiKey === '') {
            throw LexwareGatewayException::notConfigured(
                'LEXWARE_API_KEY is not configured — refusing to construct a Lexware client.',
            );
        }

        $baseUrl = rtrim(trim((string) config('services.lexware.base_url')), '/');

        if (! str_starts_with($baseUrl, 'https://')) {
            throw LexwareGatewayException::notConfigured(
                'LEXWARE_API_BASE_URL must be an https URL — refusing to construct a Lexware client.',
            );
        }

        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
    }

    public function createInvoice(LexwareInvoiceRequest $invoice): LexwareInvoiceResult
    {
        return LexwareInvoiceResult::fromResponse(
            $this->send('post', '/v1/invoices', $invoice->toPayload()),
        );
    }

    public function createFinalizedInvoice(LexwareInvoiceRequest $invoice): LexwareInvoiceResult
    {
        return LexwareInvoiceResult::fromResponse(
            $this->send('post', '/v1/invoices?finalize=true', $invoice->toPayload()),
        );
    }

    public function retrieveInvoice(string $invoiceId): LexwareInvoiceResult
    {
        return LexwareInvoiceResult::fromResponse(
            $this->send('get', '/v1/invoices/'.rawurlencode($invoiceId)),
        );
    }

    public function requireFinalizedInvoice(string $invoiceId): LexwareInvoiceResult
    {
        $result = LexwareInvoiceResult::fromResponse(
            $this->send('get', '/v1/invoices/'.rawurlencode($invoiceId)),
        );

        // Lexware vouchers are immutable through the API: `finalize` exists
        // only as a flag when *creating* one, and there is no endpoint that
        // promotes an existing draft (an earlier attempt to PUT one answered
        // 404). Accounting finalizes it in Lexware's own UI — which is where
        // §13 has them reviewing it anyway — and this reports what it found.
        if ($result->isDraft()) {
            throw LexwareGatewayException::notFinalized(
                sprintf('Lexware voucher %s is still a draft.', $invoiceId),
            );
        }

        return $result;
    }

    public function downloadInvoiceFile(string $invoiceId): LexwareFile
    {
        // Two steps, because that is what Lexware exposes: the voucher renders
        // a document and hands back its file id, and the file is fetched from
        // the files resource. (`/v1/invoices/{id}/file` is not an endpoint —
        // it was the reason invoice PDFs never arrived.)
        $path = '/v1/invoices/'.rawurlencode($invoiceId).'/document';
        $response = $this->perform(fn () => $this->request()->get($path));

        // Lexware's answer for "this voucher is a draft" — guidance, not a
        // fault, and the admin needs to hear the difference.
        if ($response->status() === 406) {
            throw LexwareGatewayException::notFinalized(
                sprintf('Lexware has no document for voucher %s — it is still a draft.', $invoiceId),
                (array) ($response->json() ?? []),
            );
        }

        if ($response->failed()) {
            throw LexwareGatewayException::apiError(
                sprintf('Lexware rejected GET %s with HTTP %d.', $path, $response->status()),
                $response->status(),
                (array) ($response->json() ?? []),
            );
        }

        $fileId = trim((string) ($response->json('documentFileId') ?? ''));

        if ($fileId === '') {
            throw LexwareGatewayException::apiError(
                sprintf('Lexware returned no documentFileId for voucher %s.', $invoiceId),
                $response->status(),
                (array) ($response->json() ?? []),
            );
        }

        return $this->downloadFile($fileId);
    }

    private function downloadFile(string $fileId): LexwareFile
    {
        $path = '/v1/files/'.rawurlencode($fileId);
        $response = $this->perform(fn () => $this->request()->accept('application/pdf')->get($path));

        if ($response->failed()) {
            throw LexwareGatewayException::apiError(
                sprintf('Lexware rejected GET %s with HTTP %d.', $path, $response->status()),
                $response->status(),
                (array) ($response->json() ?? []),
            );
        }

        $contents = $response->body();

        if ($contents === '') {
            throw LexwareGatewayException::apiError('Lexware returned an empty invoice file.', $response->status());
        }

        return new LexwareFile(
            contents: $contents,
            mimeType: $response->header('Content-Type') ?: 'application/pdf',
            filename: $this->filename($response->header('Content-Disposition')),
        );
    }

    public function createCompanyContact(LexwareContactRequest $contact): string
    {
        return $this->contactId($this->send('post', '/v1/contacts', $contact->toPayload()));
    }

    public function createPersonContact(LexwarePersonContactRequest $contact): string
    {
        return $this->contactId($this->send('post', '/v1/contacts', $contact->toPayload()));
    }

    public function listCustomerContacts(int $page = 0, int $size = 250): array
    {
        $body = $this->send('get', '/v1/contacts', [
            'page' => $page,
            'size' => $size,
            'customer' => 'true',
        ]);

        return array_values(array_map(
            fn (mixed $contact) => (array) $contact,
            (array) ($body['content'] ?? []),
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function contactId(array $body): string
    {
        $id = (string) ($body['id'] ?? '');

        if ($id === '') {
            throw LexwareGatewayException::apiError('Lexware returned a contact without an id.', null, $body);
        }

        return $id;
    }

    private function filename(?string $disposition): ?string
    {
        if ($disposition === null || preg_match('/filename="?([^";]+)"?/i', $disposition, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        try {
            $response = $this->perform(fn () => $this->request()->{$method}($path, $payload));
        } catch (ConnectionException $exception) {
            throw LexwareGatewayException::transportError(
                'Lexware could not be reached: '.$exception->getMessage(),
                $exception,
            );
        }

        return $this->body($response, strtoupper($method).' '.$path);
    }

    /**
     * Sends one request, waiting out a single throttle.
     *
     * Lexware allows roughly two requests a second per organization, and an
     * invoice step fires several back to back — voucher, document, file — so a
     * 429 is a moment to pause rather than a reason to fail an invoice the
     * admin is standing in front of.
     *
     * @param  callable(): Response  $send
     */
    private function perform(callable $send): Response
    {
        $response = $send();

        if ($response->status() === 429) {
            usleep(1_100_000);
            $response = $send();
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.lexware.connect_timeout', 10))
            ->timeout((int) config('services.lexware.timeout', 12));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response, string $request = 'the request'): array
    {
        $body = (array) ($response->json() ?? []);

        if ($response->failed()) {
            throw LexwareGatewayException::apiError(
                sprintf('Lexware rejected %s with HTTP %d.', $request, $response->status()),
                $response->status(),
                $body,
            );
        }

        return $body;
    }
}
