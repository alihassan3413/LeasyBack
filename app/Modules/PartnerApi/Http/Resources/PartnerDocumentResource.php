<?php

namespace App\Modules\PartnerApi\Http\Resources;

use App\Modules\PartnerApi\Data\PartnerDocument;

/**
 * A document as a partner sees it.
 *
 * Not a JsonResource: the input is a PartnerDocument value object rather than
 * a model, and the point of that object is that its `$path` — the storage key
 * — is never read here. There is no `$model->toArray()` anywhere near this
 * class, so a new column on `vehicle_documents` cannot appear in a partner
 * response by simply existing.
 *
 * `download_url` is the API endpoint that mints a short-lived link, not the
 * link itself. Minting one costs a signature and stamps an expiry, and a
 * document listing that pre-minted a URL per row would hand out links that
 * expire while the partner is still reading the page.
 */
final class PartnerDocumentResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PartnerDocument $document): array
    {
        return [
            'id' => $document->id,
            // Which of our two stores it came from: `report` is Leasyback
            // paperwork (appraisals, invoices, collection and return files),
            // `vehicle` is the company's own document on the vehicle.
            'source' => $document->source,
            'type' => $document->type,
            'type_label' => $document->typeLabel,
            'title' => $document->title,
            'filename' => $document->filename,
            'content_type' => $document->contentType,
            'size_bytes' => $document->sizeBytes,
            'vehicle' => ['id' => $document->vehicleId],
            'order' => $document->orderId === null && $document->orderReference === null
                ? null
                : ['id' => $document->orderId, 'reference' => $document->orderReference],
            'created_at' => $document->createdAt?->toIso8601String(),
            'updated_at' => $document->updatedAt?->toIso8601String(),
            'download_url' => route('partner.v1.documents.download', $document->id),
        ];
    }
}
