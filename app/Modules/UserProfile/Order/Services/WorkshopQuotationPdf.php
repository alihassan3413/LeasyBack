<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Vehicle\Services\DamageImageThumbnailService;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Renders one workshop quotation as a German business document.
 *
 * Only rendering lives here. The quotation's data — and every authorisation
 * decision about it — comes from WorkshopQuotationService::pdfDocument(), so
 * this class never queries, never resolves an id, and cannot widen what a
 * workshop is allowed to see.
 *
 * Both channels use this one renderer. Nothing below reads the order's channel,
 * because a repair estimate is the same document whoever the car belongs to.
 */
class WorkshopQuotationPdf
{
    /**
     * Printed width of one damage photo in CSS px (dompdf maps 96px to an inch),
     * chosen so two sit side by side inside the description column. The height
     * follows from each photo's own ratio.
     */
    private const IMAGE_WIDTH_PX = 84;

    /**
     * The largest box one photo may take in the "Bildanhang" at the end, in CSS
     * px: 166mm wide by 95mm high, which fits two landscape photos with their
     * captions on every A4 page, the first one under the heading included
     * (dompdf measures the rows a little generously, so there is headroom). A
     * photo is scaled to fit inside, keeping its ratio.
     */
    private const DETAIL_MAX_WIDTH_PX = 627;

    private const DETAIL_MAX_HEIGHT_PX = 359;

    public function __construct(
        private readonly WorkshopQuotationService $quotations,
        private readonly DamageImageThumbnailService $images,
    ) {}

    /**
     * The rendered PDF as a byte string.
     *
     * Font subsetting is switched on explicitly. dompdf enables it by default,
     * but laravel-dompdf's own config ships it off, which embeds the complete
     * DejaVu Sans and DejaVu Sans Bold files in every document — roughly 870 KB
     * of font data on a three-page quotation whose photos are only about 20 KB.
     * Subsetting keeps the full Unicode coverage the workshop's own text may
     * need while embedding only the glyphs actually used.
     */
    /**
     * @param  array<string, mixed>|null  $draft  unsent form values, see WorkshopQuotationService::pdfDocument()
     */
    public function render(WorkshopQuotation $quotation, ?array $draft = null): string
    {
        return Pdf::loadView('pdf.workshop-quotation', $this->viewData($quotation, $draft))
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('a4')
            ->output();
    }

    /**
     * `LeasyBack-Werkstattangebot-AUF-50410681.pdf` — the auftragsnummer the
     * workshop already knows, never the quotation's UUID. Reduced to characters
     * that survive a Content-Disposition header on any platform.
     */
    public function filename(WorkshopQuotation $quotation): string
    {
        $reference = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $quotation->auftragsnummer) ?: 'Angebot';

        return 'LeasyBack-Werkstattangebot-'.trim($reference, '-').'.pdf';
    }

    /**
     * Formats the document for print: German dates and amounts, images as
     * embedded JPEG data URIs, and the company block from the branding config
     * the emails already use.
     *
     * @param  array<string, mixed>|null  $draft
     * @return array<string, mixed>
     */
    private function viewData(WorkshopQuotation $quotation, ?array $draft): array
    {
        $document = $this->quotations->pdfDocument($quotation, $draft);

        return [
            ...$document,
            'printed_at' => Carbon::now()->format('d.m.Y'),
            'submitted_at' => $this->date($document['submitted_at']),
            'expires_at' => $this->date($document['expires_at']),
            'earliest_repair_start' => $this->date($document['earliest_repair_start']),
            'is_submitted' => $document['submitted_at'] !== null,
            'vehicle' => $document['vehicle'] === null ? null : [
                ...$document['vehicle'],
                'first_registration' => $this->date($document['vehicle']['first_registration_date']),
                'mileage' => $document['vehicle']['mileage'] === null
                    ? null
                    : number_format((float) $document['vehicle']['mileage'], 0, ',', '.').' km',
            ],
            'positions' => array_map(fn (array $position) => [
                ...$position,
                'appraisal_amount_net' => $this->euro($position['appraisal_amount_net']),
                'workshop_amount_net' => $position['workshop_amount_net'] === null
                    ? null
                    : $this->euro($position['workshop_amount_net']),
                'images' => $this->embed($position['image_paths']),
            ], $document['positions']),
            'additional_positions' => array_map(fn (array $position) => [
                ...$position,
                'amount_net' => $this->euro($position['amount_net']),
                'images' => $this->embed($position['image_paths']),
            ], $document['additional_positions']),
            'image_appendix' => $this->imageAppendix($document),
            'appraisal_total_net' => $this->euro($document['appraisal_total_net']),
            'workshop_total_net' => $this->euro($document['workshop_total_net']),
            'additional_total_net' => $this->euro($document['additional_total_net']),
            'grand_total_net' => $this->euro($document['grand_total_net']),
            'logo' => $this->logo(),
            'company' => [
                'name' => (string) config('mail_notifications.branding.company_name'),
                'address' => (string) config('mail_notifications.branding.company_address'),
                'website' => (string) config('mail_notifications.branding.website_url'),
                'email' => (string) config('mail_notifications.support.email'),
                'phone' => (string) config('mail_notifications.support.phone'),
            ],
        ];
    }

    /**
     * Each authorised image as a data URI plus the millimetre box it occupies,
     * skipping any that cannot be encoded so one unreadable photo costs a
     * thumbnail rather than the whole document.
     *
     * The height is derived from the image's own pixels, so the printed box
     * always matches the source's aspect ratio — nothing is stretched — and
     * dompdf gets both dimensions, which is what keeps a tall photo from
     * overlapping the row above it.
     *
     * @param  array<int, string>  $paths
     * @return array<int, array{src: string, width: int, height: int}>
     */
    private function embed(array $paths): array
    {
        $images = [];

        foreach ($paths as $path) {
            $image = $this->images->pdfJpegDataUri($path);

            if ($image === null) {
                continue;
            }

            $images[] = [
                'src' => $image['src'],
                'width' => self::IMAGE_WIDTH_PX,
                'height' => (int) round(self::IMAGE_WIDTH_PX * $image['height'] / max($image['width'], 1)),
            ];
        }

        return $images;
    }

    /**
     * The photos again at the end of the document, large enough to judge the
     * damage — one block per position that has any, Gutachten positions first,
     * then the workshop's own additional damage. Built from the same
     * authorised paths as the thumbnails, so it can never show more.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, array{label: string, component: string, images: array<int, array{src: string, width: int, height: int}>}>
     */
    private function imageAppendix(array $document): array
    {
        $sections = [];

        foreach ([['positions', 'Position '], ['additional_positions', 'Zusätzlicher Schaden Z']] as [$key, $prefix]) {
            foreach ($document[$key] as $position) {
                $images = $this->embedDetail($position['image_paths']);

                if ($images !== []) {
                    $sections[] = [
                        'label' => $prefix.$position['number'],
                        'component' => (string) $position['component'],
                        'images' => $images,
                    ];
                }
            }
        }

        return $sections;
    }

    /**
     * Like embed(), but from the original upload and scaled to fit the large
     * appendix box, so a portrait photo does not run off the page.
     *
     * @param  array<int, string>  $paths
     * @return array<int, array{src: string, width: int, height: int}>
     */
    private function embedDetail(array $paths): array
    {
        $images = [];

        foreach ($paths as $path) {
            $image = $this->images->pdfJpegDataUri($path, DamageImageThumbnailService::PDF_DETAIL_WIDTH);

            if ($image === null) {
                continue;
            }

            $scale = min(
                self::DETAIL_MAX_WIDTH_PX / max($image['width'], 1),
                self::DETAIL_MAX_HEIGHT_PX / max($image['height'], 1),
            );

            $images[] = [
                'src' => $image['src'],
                'width' => max((int) round($image['width'] * $scale), 1),
                'height' => max((int) round($image['height'] * $scale), 1),
            ];
        }

        return $images;
    }

    /**
     * The logo, inlined because dompdf runs with remote fetching disabled.
     *
     * Deliberately the dark-variant mark rather than the one the notification
     * emails use: `branding.logo_asset` is drawn in white and green for the
     * dark hero band at the top of an email, so on white paper half of the
     * wordmark disappears. This is the same logo in the ink-on-white variant.
     * The branding asset is still the fallback, so a host missing the SVG
     * prints something rather than nothing.
     */
    private function logo(): ?string
    {
        $candidates = [
            'leasyback-logo-dark.svg' => 'image/svg+xml',
            (string) config('mail_notifications.branding.logo_asset') => 'image/png',
        ];

        foreach ($candidates as $file => $mime) {
            $path = public_path($file);

            if ($file !== '' && is_file($path) && is_readable($path)) {
                return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }

    /**
     * German decimal notation, done on the string itself.
     *
     * Deliberately not number_format(): that would route the amount through a
     * float on its way to the page. The value arrives as a bcmath decimal and
     * leaves as text, so no money in this document is ever a float — not even
     * for display.
     */
    private function euro(?string $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '00');

        $grouped = strrev(implode('.', str_split(strrev($whole === '' ? '0' : $whole), 3)));

        return ($negative ? '-' : '').$grouped.','.substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('d.m.Y') : null;
    }
}
