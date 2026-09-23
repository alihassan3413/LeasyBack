<?php

namespace App\Modules\UserProfile\Order\Jobs;

use App\Modules\UserProfile\Admin\Services\VehicleReportService;
use App\Modules\UserProfile\Order\Contracts\PdfTextExtractor;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\ExtractedImage;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenDamageImageMapper;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenImageExtractor;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenPhotoPages;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractGutachtenImages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DOCUMENT_TYPE = 'GutachtenBild';

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $documentId) {}

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    public function handle(
        GutachtenImageExtractor $extractor,
        VehicleReportService $reports,
        PdfTextExtractor $textExtractor,
        GutachtenPhotoPages $photoPages,
        GutachtenDamageImageMapper $damageMapper,
    ): void {
        $document = VehicleReportDocument::find($this->documentId);

        if ($document === null || ! $extractor->isAvailable()) {
            return;
        }

        $contents = Storage::disk('documents')->exists($document->path)
            ? Storage::disk('documents')->get($document->path)
            : null;

        if ($contents === null || $contents === '') {
            return;
        }

        $textPages = $this->textPages($textExtractor, $document, $contents);
        $allowedPages = $photoPages->detect($textPages);
        $damageNumbers = $damageMapper->map($textPages, $extractor->inspect($contents, $allowedPages));

        try {
            $stored = $extractor->extract($contents, function (ExtractedImage $image) use ($document, $reports, $damageNumbers) {
                $bytes = file_get_contents($image->path);

                if ($bytes === false) {
                    return;
                }

                $reports->storeGeneratedDocument(
                    auftragsnummer: $document->auftragsnummer,
                    vehicleId: $document->vehicle_id,
                    filename: $this->filenameFor($document, $image),
                    contents: $bytes,
                    documentType: self::DOCUMENT_TYPE,
                    documentTitle: $this->titleFor($image, $damageNumbers[$image->index] ?? null),
                    notifyCustomer: false,
                    published: false,
                );
            }, $allowedPages);

            Log::info('Gutachten images extracted', [
                'document_id' => $document->id,
                'auftragsnummer' => $document->auftragsnummer,
                'images' => $stored,
                'damage_pages' => $allowedPages,
                'damage_numbers' => count(array_filter($damageNumbers, fn (?int $number) => $number !== null)),
            ]);
        } catch (AppraisalExtractionException $exception) {
            Log::info('Gutachten image extraction skipped', [
                'document_id' => $document->id,
                'error_code' => $exception->errorCode,
            ]);
        }
    }

    private function textPages(PdfTextExtractor $textExtractor, VehicleReportDocument $document, string $contents): array
    {
        if (! $textExtractor->isAvailable()) {
            return [];
        }

        try {
            return $textExtractor->extract(new AppraisalExtractionInput(
                extractionId: $this->documentId,
                orderId: '',
                auftragsnummer: $document->auftragsnummer,
                vehicleId: $document->vehicle_id,
                documentId: $document->id,
                fileName: basename($document->path),
                contents: $contents,
                sha256: hash('sha256', $contents),
            ));
        } catch (AppraisalExtractionException) {
            return [];
        }
    }

    private function filenameFor(VehicleReportDocument $document, ExtractedImage $image): string
    {
        $page = str_pad((string) ($image->pageNumber ?? 0), 3, '0', STR_PAD_LEFT);
        $index = str_pad((string) $image->index, 3, '0', STR_PAD_LEFT);

        return "gutachten-bilder/{$document->id}/p{$page}-{$index}.{$image->extension()}";
    }

    private function titleFor(ExtractedImage $image, ?int $damageNumber): string
    {
        $title = $image->pageNumber === null
            ? 'Gutachten Bild '.($image->index + 1)
            : "Gutachten Seite {$image->pageNumber}, Bild ".($image->index + 1);

        return $damageNumber === null ? $title : "Beschädigung {$damageNumber}: {$title}";
    }
}
