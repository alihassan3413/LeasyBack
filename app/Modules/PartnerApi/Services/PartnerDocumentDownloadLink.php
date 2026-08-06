<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Data\PartnerDocument;
use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The one way document bytes leave this API.
 *
 * The `documents` disk is private and stays private: nothing here returns a
 * storage URL, and no response anywhere in this module carries a storage path.
 * A partner asks for a download, gets a **signed, short-lived link back into
 * this API**, and the bytes are streamed by us from the private disk.
 *
 * Why a signed link rather than streaming from the bearer-authenticated
 * endpoint directly: a download is the one call a partner is likely to hand to
 * something that is not their integration — a browser, a document pipeline, a
 * user clicking through. Handing that a bearer token means handing it every
 * endpoint the token has; handing it a signature means handing it one file,
 * for thirty minutes.
 *
 * Why not `Storage::temporaryUrl()`, which the portal uses: on the local
 * driver that URL is served by a framework route with no notion of who this
 * partner is, and on S3 it is a presigned URL that bypasses us entirely. Both
 * would be valid after the client is deactivated or the vehicle leaves the
 * company. This link is re-authorised on every fetch — see verify() — so
 * revocation takes effect immediately rather than in thirty minutes.
 */
class PartnerDocumentDownloadLink
{
    /**
     * Matches the portal's own document links (VehicleService passes 1800 to
     * generateSignedUrl). Long enough to survive a slow pipeline, short enough
     * that a leaked link is not an ongoing exposure.
     */
    public const TTL_SECONDS = 1800;

    public function __construct(private readonly PartnerContext $context) {}

    /**
     * @return array{url: string, expires_at: string, expires_in_seconds: int}
     */
    public function mint(PartnerDocument $document): array
    {
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        return [
            'url' => URL::temporarySignedRoute(
                'partner.v1.documents.content',
                $expiresAt,
                // The client is part of the signed payload, so a link is
                // bound to the integration that asked for it and cannot be
                // replayed against another company's document by editing the
                // id — that would invalidate the signature.
                ['document' => $document->id, 'client' => $this->context->client()->id],
            ),
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => self::TTL_SECONDS,
        ];
    }

    /**
     * Re-check a link at fetch time.
     *
     * This runs with no bearer token and therefore no PartnerContext, so every
     * question is asked again from scratch against the signed client id: is
     * the signature intact and unexpired, is the client still active, and does
     * the document still belong to a B2B vehicle in that client's company. A
     * link minted before a client was deactivated stops working here rather
     * than at its expiry.
     */
    public function verify(Request $request, string $documentId, string $clientId): PartnerDocument
    {
        if (! $request->hasValidSignature()) {
            $expires = (int) $request->query('expires', '0');

            throw $expires > 0 && $expires < now()->getTimestamp()
                ? PartnerApiException::forbidden(
                    'download_link_expired',
                    'This download link has expired. Request a new one from the download endpoint.',
                )
                : PartnerApiException::forbidden(
                    'download_link_invalid',
                    'This download link is not valid.',
                );
        }

        $client = PartnerIntegrationClient::where('id', $clientId)
            ->where('is_active', true)
            ->first();

        if ($client === null) {
            throw $this->notFound();
        }

        // The company narrowing is repeated rather than borrowed from
        // PartnerResourceLocator: the locator scopes through the *acting
        // user*, and there is no authenticated user on this route. The
        // question here is narrower and answerable without one — is this
        // document's vehicle a B2B vehicle of the signed client's company.
        $vehicleIds = Vehicle::query()
            ->where('vehicle_belongs', 'B2B')
            ->where('b2b_id', $client->b2b_id)
            ->select('vehicle_id');

        $report = VehicleReportDocument::where('id', $documentId)
            ->where('published', true)
            ->whereIn('vehicle_id', $vehicleIds)
            ->first();

        if ($report !== null) {
            return PartnerDocumentCatalog::fromReport($report, null);
        }

        $document = VehicleDocument::where('document_id', $documentId)
            ->whereIn('vehicle_id', $vehicleIds)
            ->first();

        if ($document === null) {
            throw $this->notFound();
        }

        return PartnerDocumentCatalog::fromVehicleDocument($document);
    }

    private function notFound(): PartnerApiException
    {
        return PartnerApiException::notFound(
            'document_not_found',
            'No document with that id exists in the company this link belongs to.',
        );
    }
}
