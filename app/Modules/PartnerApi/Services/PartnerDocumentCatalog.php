<?php

namespace App\Modules\PartnerApi\Services;

use App\Enums\DocumentType;
use App\Modules\PartnerApi\Data\PartnerDocument;
use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Support\Collection;

/**
 * Every document a partner may see, normalised into one shape.
 *
 * Two tables feed it and they are not alike:
 *
 * - `vehicle_report_documents` — Leasyback-produced reports and invoices
 *   (`gutachten`, `nachgutachten`, `rechnung`, collection and return
 *   paperwork). Drafts exist here, so **`published = true` is mandatory** on
 *   every read: an unpublished row is Admin's working copy and has no partner
 *   visibility at any stage.
 * - `vehicle_documents` — the company's own paperwork on the vehicle
 *   (leasing contract, prior-damage evidence, registration papers). Uploaded
 *   by the company or on its behalf, so it is theirs to read back.
 *
 * `assessment_documents` is deliberately absent. It holds the raw inspection
 * material a report is built *from*, has no published flag and no customer
 * exposure anywhere in the portal, and is therefore not reachable through this
 * API at all — not filtered out downstream, never queried.
 *
 * Scope comes from PartnerResourceLocator and nowhere else: a document is
 * reachable only through an order or a vehicle the token's company owns, so
 * there is one definition of ownership rather than a second one expressed as a
 * `b2b_id` column check on a document table that does not have one.
 */
class PartnerDocumentCatalog
{
    public function __construct(private readonly PartnerResourceLocator $locator) {}

    /**
     * Documents attached to one order: its published reports, plus the
     * paperwork held against the vehicle the order is for.
     *
     * @return Collection<int, PartnerDocument>
     */
    public function forOrder(LeasybackOrder $order): Collection
    {
        $reports = VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)
            ->where('vehicle_id', $order->vehicle_id)
            ->where('published', true)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VehicleReportDocument $document) => self::fromReport($document, $order));

        $vehicleDocuments = VehicleDocument::where('vehicle_id', $order->vehicle_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VehicleDocument $document) => self::fromVehicleDocument($document));

        return $reports
            ->concat($vehicleDocuments)
            ->sortByDesc(fn (PartnerDocument $document) => $document->createdAt?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * One document, resolved *and* authorised in the same lookup.
     *
     * The id is matched against both tables. Both are uuid primary keys, so a
     * collision is not a practical concern, and a single opaque `{document}`
     * segment keeps the partner from having to know which of our two storage
     * tables a file happens to live in — an implementation detail that would
     * otherwise become a public contract.
     *
     * The scope narrowing is the authorisation: the report must hang off an
     * order this token can see, and the vehicle document off a vehicle it can
     * see. Anything else is 404, never 403 — a 403 would confirm the id
     * exists.
     */
    public function findOrFail(string $documentId): PartnerDocument
    {
        $report = VehicleReportDocument::where('id', $documentId)
            ->where('published', true)
            ->whereIn('vehicle_id', $this->locator->vehicleQuery()->select('vehicle_id'))
            ->first();

        if ($report !== null) {
            $order = $this->locator->orderQuery()
                ->where('auftragsnummer', $report->auftragsnummer)
                ->first();

            return self::fromReport($report, $order);
        }

        $document = VehicleDocument::where('document_id', $documentId)
            ->whereIn('vehicle_id', $this->locator->vehicleQuery()->select('vehicle_id'))
            ->first();

        if ($document !== null) {
            return self::fromVehicleDocument($document);
        }

        throw PartnerApiException::notFound(
            'document_not_found',
            'No document with that id exists in the company this token belongs to.',
        );
    }

    /**
     * Static, and public, because PartnerDocumentDownloadLink normalises the
     * same two rows while re-authorising a signed link — at which point there
     * is no bearer token, no PartnerContext and therefore no locator to build
     * a catalog around. One mapping, two entry points.
     */
    public static function fromReport(VehicleReportDocument $document, ?LeasybackOrder $order): PartnerDocument
    {
        $type = strtolower(trim((string) $document->document_type));

        return new PartnerDocument(
            id: $document->id,
            source: PartnerDocument::SOURCE_REPORT,
            type: $type,
            typeLabel: DocumentType::tryFrom($type)?->label() ?? $document->document_title ?? $type,
            title: $document->document_title,
            // Report rows carry no original upload name — the file is ours,
            // generated. A name is derived from the title so a partner writing
            // to disk gets something meaningful with the right extension,
            // rather than the storage key, which never leaves this class.
            filename: self::derivedFilename($document->document_title ?? $type, $document->path),
            path: (string) $document->path,
            contentType: self::contentTypeFor((string) $document->path),
            sizeBytes: null,
            vehicleId: $document->vehicle_id,
            orderId: $order?->id,
            orderReference: $document->auftragsnummer,
            createdAt: $document->created_at,
            updatedAt: $document->updated_at,
        );
    }

    public static function fromVehicleDocument(VehicleDocument $document): PartnerDocument
    {
        $type = strtolower(trim((string) $document->document_type));

        return new PartnerDocument(
            id: $document->document_id,
            source: PartnerDocument::SOURCE_VEHICLE,
            type: $type,
            typeLabel: DocumentType::tryFrom($type)?->label() ?? (string) $document->document_type,
            title: $document->document_type,
            filename: self::safeFilename(
                (string) ($document->original_file_name ?: self::derivedFilename($type, (string) $document->path)),
            ),
            path: (string) $document->path,
            contentType: $document->content_type ?: self::contentTypeFor((string) $document->path),
            sizeBytes: $document->file_size,
            vehicleId: $document->vehicle_id,
            orderId: null,
            orderReference: null,
            createdAt: $document->created_at,
            updatedAt: $document->updated_at,
        );
    }

    private static function derivedFilename(string $stem, ?string $path): string
    {
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) ?: 'pdf';

        return self::safeFilename(trim($stem).'.'.$extension);
    }

    /**
     * A filename is echoed into a `Content-Disposition` header and written to
     * the partner's disk, so directory separators, control characters and
     * leading dots are stripped rather than trusted — a stored
     * `original_file_name` was a partner-supplied string at some point.
     */
    private static function safeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '-', basename($name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name) ?? '';
        $name = ltrim(trim($name), '.');

        return $name === '' ? 'document' : mb_substr($name, 0, 180);
    }

    private static function contentTypeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
