<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PartnerApi\Data\PartnerDocument;
use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use App\Modules\PartnerApi\Http\Resources\PartnerDocumentResource;
use App\Modules\PartnerApi\Services\PartnerDocumentCatalog;
use App\Modules\PartnerApi\Services\PartnerDocumentDownloadLink;
use App\Modules\PartnerApi\Services\PartnerResourceLocator;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents on an order, and the bytes behind them.
 *
 * Storage is private on both drivers and stays that way: no response here
 * carries a storage path, and no partner-visible URL points at the disk. The
 * download is a two-step — ask this API for a link, fetch the link — for the
 * reasons in PartnerDocumentDownloadLink.
 *
 * Nothing unpublished is reachable. A `vehicle_report_documents` row with
 * `published = false` is Admin's draft and is filtered in the catalog's
 * queries, not after the fact, so it cannot be listed, shown, linked or
 * streamed.
 */
#[Group(name: 'Partner API')]
class DocumentController extends Controller
{
    public function __construct(
        private readonly PartnerResourceLocator $locator,
        private readonly PartnerDocumentCatalog $catalog,
        private readonly PartnerDocumentDownloadLink $links,
    ) {}

    /**
     * List an order's documents.
     */
    #[Endpoint(
        title: 'List order documents',
        description: 'Every document a partner may see for one order: Leasyback’s published reports '
            .'and invoices for the order (`source: report`) and the company’s own paperwork on the '
            .'vehicle (`source: vehicle`), newest first. Unpublished drafts and internal inspection '
            .'material are not included at any stage. '
            .'Filter with `type` (for example `gutachten`, `nachgutachten`, `rechnung`) or `source`.'
    )]
    #[QueryParam('type', 'string', 'Restrict to one document type.', required: false, example: 'gutachten')]
    #[QueryParam('source', 'string', '`report` or `vehicle`.', required: false, example: 'report')]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'documents' => [[
                    'id' => 'ba3d7b0e-1a55-4a09-9a4f-4c2f1d8b1c11',
                    'source' => 'report',
                    'type' => 'gutachten',
                    'type_label' => 'Gutachten',
                    'title' => 'Erstgutachten',
                    'filename' => 'Erstgutachten.pdf',
                    'content_type' => 'application/pdf',
                    'size_bytes' => null,
                    'vehicle' => ['id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f'],
                    'order' => [
                        'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                        'reference' => 'BXY123260806',
                    ],
                    'created_at' => '2026-08-06T09:14:02+00:00',
                    'updated_at' => '2026-08-06T09:14:02+00:00',
                    'download_url' => 'https://app.example/api/v1/partner/documents/ba3d7b0e-1a55-4a09-9a4f-4c2f1d8b1c11/download',
                ]],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function forOrder(Request $request, string $order): JsonResponse
    {
        $found = $this->locator->findOrderOrFail($order);

        $documents = $this->catalog->forOrder($found)
            ->when(
                trim((string) $request->query('type', '')) !== '',
                fn ($all) => $all->filter(fn (PartnerDocument $document) => $document->type === strtolower(trim((string) $request->query('type')))),
            )
            ->when(
                trim((string) $request->query('source', '')) !== '',
                fn ($all) => $all->filter(fn (PartnerDocument $document) => $document->source === strtolower(trim((string) $request->query('source')))),
            );

        return PartnerApiResponse::success([
            'documents' => $documents
                ->map(fn (PartnerDocument $document) => PartnerDocumentResource::toArray($document))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Retrieve one document's metadata.
     */
    #[Endpoint(
        title: 'Get a document',
        description: 'Metadata only. The bytes come from `GET /documents/{document}/download`.'
    )]
    #[Response(
        status: 404,
        content: [
            'error' => [
                'type' => 'not_found',
                'code' => 'document_not_found',
                'message' => 'No document with that id exists in the company this token belongs to.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function show(Request $request, string $document): JsonResponse
    {
        $found = $this->catalog->findOrFail($document);

        return PartnerApiResponse::success([
            'document' => [
                ...PartnerDocumentResource::toArray($found),
                // Resolved from the private disk here but not in the listing:
                // it is one stat call for one document, where a listing would
                // make it one per row — a per-object HEAD request once the
                // disk is S3.
                'size_bytes' => $found->sizeBytes ?? $this->fileSize($found),
            ],
        ]);
    }

    /**
     * Mint a short-lived download link.
     */
    #[Endpoint(
        title: 'Request a document download',
        description: 'Returns a signed URL valid for 30 minutes, not the bytes. The URL streams the '
            .'file from private storage through this API and is bound to the integration client that '
            .'requested it; it carries no storage path and cannot be pointed at another document. '
            .'It is re-authorised on every fetch, so deactivating a client invalidates outstanding '
            .'links immediately. An expired link answers 403 `download_link_expired`.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'document' => [
                    'id' => 'ba3d7b0e-1a55-4a09-9a4f-4c2f1d8b1c11',
                    'filename' => 'Erstgutachten.pdf',
                    'content_type' => 'application/pdf',
                ],
                'download' => [
                    'url' => 'https://app.example/api/v1/partner/documents/ba3d7b0e-1a55-4a09-9a4f-4c2f1d8b1c11/content?client=…&expires=…&signature=…',
                    'expires_at' => '2026-08-06T09:44:02+00:00',
                    'expires_in_seconds' => 1800,
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function download(Request $request, string $document): JsonResponse
    {
        $found = $this->catalog->findOrFail($document);

        return PartnerApiResponse::success([
            'document' => [
                'id' => $found->id,
                'filename' => $found->filename,
                'content_type' => $found->contentType,
                'size_bytes' => $found->sizeBytes ?? $this->fileSize($found),
            ],
            'download' => $this->links->mint($found),
        ]);
    }

    /**
     * Stream the bytes behind a signed link.
     *
     * The only route in this module that runs without a bearer token — the
     * signature is the credential, and it is re-checked against the client and
     * the document's current ownership before a single byte is read.
     */
    #[Endpoint(
        title: 'Download a document',
        description: 'Streams the file. Reached only through the signed URL returned by '
            .'`GET /documents/{document}/download`; there is nothing to construct by hand.',
        authenticated: false
    )]
    public function content(Request $request, string $document): StreamedResponse
    {
        $found = $this->links->verify($request, $document, (string) $request->query('client', ''));
        $disk = Storage::disk('documents');

        if (! $disk->exists($found->path)) {
            // The row survived its file. A partner cannot act on the
            // difference between that and a bad id, and telling them apart
            // would confirm the id exists.
            throw PartnerApiException::notFound(
                'document_not_found',
                'No document with that id exists in the company this link belongs to.',
            );
        }

        return $disk->download($found->path, $found->filename, [
            'Content-Type' => $found->contentType,
            // A document is one company's paperwork behind a signed link;
            // nothing in front of it should keep a copy.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function fileSize(PartnerDocument $document): ?int
    {
        $disk = Storage::disk('documents');

        return $disk->exists($document->path) ? $disk->size($document->path) : null;
    }
}
