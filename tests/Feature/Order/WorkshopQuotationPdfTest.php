<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopAdditionalPosition;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotationItem;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationPdf;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The workshop's PDF copy of its quotation (§9).
 *
 * One document for both channels: every rule here is pinned for a B2C order as
 * well as a B2B one, and the B2C/B2B pair deliberately asserts against the same
 * renderer rather than two implementations.
 *
 * Content is asserted by reading the finished PDF back with poppler's pdftotext
 * — already a provisioned dependency for Gutachten extraction — so the
 * assertions cover what a workshop actually sees on paper rather than the
 * Blade template's inputs.
 */
class WorkshopQuotationPdfTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('documents');
    }

    // ---------------------------------------------------------------- access

    public function test_an_open_quotation_renders_a_pdf(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $response = $this->get(route('workshop.quotations.pdf', $token));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_the_pdf_opens_inline_so_the_browser_can_print_it(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->get(route('workshop.quotations.pdf', $token))
            ->assertHeader('Content-Disposition', 'inline; filename="LeasyBack-Werkstattangebot-'.$this->reference.'.pdf"');
    }

    public function test_the_download_flag_returns_an_attachment(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->get(route('workshop.quotations.pdf', $token).'?download=1')
            ->assertHeader('Content-Disposition', 'attachment; filename="LeasyBack-Werkstattangebot-'.$this->reference.'.pdf"');
    }

    /** The filename carries the auftragsnummer the workshop knows, never a UUID. */
    public function test_the_filename_exposes_no_internal_identifier(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);
        $quotation = WorkshopQuotation::firstOrFail();

        $filename = app(WorkshopQuotationPdf::class)->filename($quotation);

        $this->assertSame("LeasyBack-Werkstattangebot-{$order->auftragsnummer}.pdf", $filename);
        $this->assertStringNotContainsString($quotation->id, $filename);
        $this->assertStringNotContainsString($order->id, $filename);
        $this->assertStringNotContainsString((string) $order->vehicle_id, $filename);
        $this->assertStringNotContainsString($token, $filename);
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->b2cOrder();

        $this->get(route('workshop.quotations.pdf', Str::random(64)))->assertNotFound();
    }

    public function test_a_revoked_quotation_cannot_be_rendered(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        WorkshopQuotation::firstOrFail()->update(['revoked_at' => now()]);

        $this->get(route('workshop.quotations.pdf', $token))->assertNotFound();
    }

    public function test_an_expired_quotation_cannot_be_rendered(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        WorkshopQuotation::firstOrFail()->update(['expires_at' => now()->subDay()]);

        $this->get(route('workshop.quotations.pdf', $token))->assertNotFound();
    }

    /**
     * The link closes on submission, so the PDF closes with it. This is the
     * existing workshop-link rule, not a separate one: findOpenByToken()
     * already refuses a submitted quotation for the form and the images.
     */
    public function test_a_submitted_quotation_follows_the_existing_link_rule(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        WorkshopQuotation::firstOrFail()->update(['submitted_at' => now()]);

        $this->get(route('workshop.quotations.pdf', $token))->assertNotFound();
        $this->get(route('workshop.quotations.show', $token))->assertNotFound();
    }

    /** A token is not a quotation id: neither is accepted in place of the other. */
    public function test_a_quotation_id_is_not_accepted_as_a_token(): void
    {
        $this->tokenFor($this->b2cOrder());
        $quotation = WorkshopQuotation::firstOrFail();

        $this->get('/werkstatt/angebot/'.$quotation->id.'/pdf')->assertNotFound();
        $this->get('/werkstatt/angebot/'.str_replace('-', '', $quotation->id).'/pdf')->assertNotFound();
    }

    public function test_one_orders_token_cannot_render_another_orders_quotation(): void
    {
        $mine = $this->b2cOrder();
        $token = $this->tokenFor($mine);

        $theirs = $this->b2cOrder();
        $this->withPosition($theirs, 'Fremdes Bauteil');

        $text = $this->pdfText($token);

        $this->assertStringContainsString($mine->auftragsnummer, $text);
        $this->assertStringNotContainsString($theirs->auftragsnummer, $text);
        $this->assertStringNotContainsString('Fremdes Bauteil', $text);
    }

    // --------------------------------------------------------------- content

    public function test_the_document_carries_its_header_and_order_identity(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $text = $this->pdfText($token);

        $this->assertStringContainsString('Werkstattangebot', $text);
        $this->assertStringContainsString('LeasyBack GmbH', $text);
        $this->assertStringContainsString($order->auftragsnummer, $text);
        $this->assertStringContainsString('Karosserie Nord', $text);
        $this->assertStringContainsString('Seite 1', $text);
    }

    public function test_the_vehicle_block_shows_what_the_workshop_needs(): void
    {
        $token = $this->tokenFor($this->b2cOrder('K-LB 1234', 'WVWZZZ1KZAW000001'));

        $text = $this->pdfText($token);

        $this->assertStringContainsString('Fahrzeug', $text);
        $this->assertStringContainsString('K-LB 1234', $text);
        $this->assertStringContainsString('WVWZZZ1KZAW000001', $text);
        $this->assertStringContainsString('Kennzeichen', $text);
        $this->assertStringContainsString('FIN', $text);
    }

    public function test_the_appraisal_positions_appear_with_german_labels(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $text = $this->pdfText($token);

        $this->assertStringContainsString('Gutachtenpositionen', $text);
        $this->assertStringContainsString('Bauteil', $text);
        $this->assertStringContainsString('Schadenbeschreibung', $text);
        $this->assertStringContainsString('Reparaturweg', $text);
        $this->assertStringContainsString('Werkstatt netto', $text);
        $this->assertStringContainsString('Stoßfänger hinten', $text);
        $this->assertStringContainsString('Kratzer', $text);
    }

    /** §9: a quotation that hides appraisal amounts must hide them here too. */
    public function test_appraisal_amounts_are_hidden_when_the_quotation_hides_them(): void
    {
        $order = $this->b2cOrder();
        $shown = $this->pdfText($this->tokenFor($order, showAmounts: true));

        $this->assertStringContainsString('Gutachten netto', $shown);
        $this->assertStringContainsString('500,00', $shown);

        WorkshopQuotation::query()->delete();
        $hidden = $this->pdfText($this->tokenFor($order, showAmounts: false));

        $this->assertStringNotContainsString('Gutachten netto', $hidden);
        $this->assertStringNotContainsString('500,00', $hidden);
    }

    public function test_money_is_rendered_in_german_notation(): void
    {
        $order = $this->b2cOrder();
        AppraisalPosition::query()->update(['original_amount_net' => '1234567.89']);

        $text = $this->pdfText($this->tokenFor($order));

        $this->assertStringContainsString('1.234.567,89', $text);
        $this->assertStringNotContainsString('1234567.89', $text);
    }

    public function test_an_open_quotation_says_the_prices_are_not_submitted_yet(): void
    {
        $text = $this->pdfText($this->tokenFor($this->b2cOrder()));

        $this->assertStringContainsString('noch nicht eingereicht', $text);
    }

    // ----------------------------------------- priced and workshop-reported

    /**
     * Rendered directly rather than over HTTP: the link closes on submission,
     * so this state is unreachable by token on purpose. The renderer still has
     * to be correct for it, and this is what proves it.
     */
    public function test_submitted_workshop_prices_and_totals_are_rendered(): void
    {
        $order = $this->b2cOrder();
        $this->tokenFor($order);
        $quotation = WorkshopQuotation::firstOrFail();

        WorkshopQuotationItem::create([
            'quotation_id' => $quotation->id,
            'appraisal_position_id' => AppraisalPosition::firstOrFail()->id,
            'amount_net' => '444.44',
            'repair_method' => 'Instandsetzen',
        ]);
        $quotation->update(['submitted_at' => now(), 'total_net' => '444.44']);

        $text = $this->renderedText($quotation->fresh());

        $this->assertStringContainsString('444,44', $text);
        $this->assertStringContainsString('Instandsetzen', $text);
        $this->assertStringContainsString('Werkstattangebot Gesamt netto', $text);
        $this->assertStringContainsString('Eingereicht am', $text);
        $this->assertStringNotContainsString('noch nicht eingereicht', $text);
    }

    public function test_additional_workshop_damage_is_rendered_as_its_own_section(): void
    {
        $quotation = $this->quotationWithAdditionalDamage($this->b2cOrder());

        $text = $this->renderedText($quotation);

        $this->assertStringContainsString('Zusätzliche Schäden', $text);
        $this->assertStringContainsString('von der Werkstatt festgestellt', $text);
        $this->assertStringContainsString('nicht Teil des Gutachtens', $text);
        $this->assertStringContainsString('Tür vorne links', $text);
        $this->assertStringContainsString('Delle und Lackschaden', $text);
        $this->assertStringContainsString('250,00', $text);
    }

    public function test_a_quotation_without_additional_damage_shows_no_such_section(): void
    {
        $text = $this->pdfText($this->tokenFor($this->b2cOrder()));

        $this->assertStringNotContainsString('Zusätzliche Schäden', $text);
        $this->assertStringNotContainsString('von der Werkstatt festgestellt', $text);
    }

    public function test_the_totals_separate_gutachten_positions_from_additional_damage(): void
    {
        $quotation = $this->quotationWithAdditionalDamage($this->b2cOrder());

        $text = $this->renderedText($quotation);

        $this->assertStringContainsString('Zusätzliche Schäden netto', $text);
        $this->assertStringContainsString('Werkstattangebot Gesamt netto', $text);
    }

    /** bcmath, not floats: 0.1 + 0.2 style drift must never reach the page. */
    public function test_totals_are_summed_exactly(): void
    {
        $order = $this->b2cOrder();
        $this->tokenFor($order);
        $quotation = WorkshopQuotation::firstOrFail();

        foreach (['0.10', '0.20'] as $index => $amount) {
            WorkshopAdditionalPosition::create([
                'quotation_id' => $quotation->id,
                'sort_order' => $index,
                'component' => "Bauteil {$index}",
                'damage_description' => 'Schaden',
                'amount_net' => $amount,
            ]);
        }

        $document = app(WorkshopQuotationService::class)->pdfDocument($quotation);

        $this->assertSame('0.30', $document['additional_total_net']);
        $this->assertSame('0.30', $document['grand_total_net']);
        $this->assertStringContainsString('0,30', $this->renderedText($quotation));
    }

    // ----------------------------------------------------------------- images

    public function test_images_attached_to_a_position_are_embedded(): void
    {
        $order = $this->b2cOrder();
        $document = $this->imageDocument($order);
        AppraisalPosition::firstOrFail()->update(['damage_image_document_ids' => [$document->id]]);

        $pdf = $this->pdfBytes($this->tokenFor($order));

        // A PDF carrying an embedded raster image has an image XObject; a
        // document with no pictures at all has none.
        $this->assertStringContainsString('/Image', $pdf);
        $this->assertGreaterThan(20000, strlen($pdf));
    }

    public function test_additional_damage_images_are_embedded(): void
    {
        $order = $this->b2cOrder();
        $quotation = $this->quotationWithAdditionalDamage($order, withImage: true);

        $pdf = app(WorkshopQuotationPdf::class)->render($quotation);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Image', $pdf);
    }

    /**
     * The decisive one: an image id belonging to a different order is never
     * embedded, even when it is written onto this order's position row.
     */
    public function test_another_orders_image_is_never_embedded(): void
    {
        $mine = $this->b2cOrder();
        $theirs = $this->b2cOrder();

        $foreign = $this->imageDocument($theirs);
        AppraisalPosition::where('order_id', $mine->id)->update(['damage_image_document_ids' => [$foreign->id]]);

        $service = app(WorkshopQuotationService::class);
        $this->tokenFor($mine);
        $document = $service->pdfDocument(WorkshopQuotation::firstOrFail());

        $this->assertSame([], $document['positions'][0]['image_paths']);
        $this->assertStringNotContainsString('/Image', $this->pdfBytes($this->tokenFor($mine)));
    }

    public function test_a_quotation_without_images_renders_cleanly(): void
    {
        $pdf = $this->pdfBytes($this->tokenFor($this->b2cOrder()));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Image', $pdf);
    }

    // ------------------------------------------------------ unsent form values

    /**
     * The reported bug: prices typed into the form but not sent printed as a
     * 0,00 € workshop total, because the PDF only read submitted rows.
     */
    public function test_unsent_prices_from_the_form_are_printed_with_their_totals(): void
    {
        $order = $this->b2cOrder();
        $second = $this->withPosition($order, 'Heckklappe');
        $token = $this->tokenFor($order);
        $first = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->firstOrFail();

        $text = $this->draftText($token, [
            'items' => [
                ['appraisal_position_id' => $first->id, 'amount_net' => '410.50', 'repair_method' => 'Smart Repair'],
                ['appraisal_position_id' => $second->id, 'amount_net' => '389', 'not_repairable' => false],
            ],
        ]);

        $this->assertStringContainsString('410,50', $text);
        $this->assertStringContainsString('389,00', $text);
        $this->assertStringContainsString('Smart Repair', $text);
        $this->assertMatchesRegularExpression('/Werkstatt netto \(Gutachtenpositionen\)\s+799,50/', $text);
        $this->assertMatchesRegularExpression('/Werkstattangebot Gesamt netto\s+799,50/', $text);
        $this->assertStringContainsString('Entwurf', $text);
    }

    public function test_printing_unsent_values_stores_nothing(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $this->draftResponse($token, [
            'company_name' => 'Karosserie Nord GmbH',
            'items' => [['appraisal_position_id' => AppraisalPosition::firstOrFail()->id, 'amount_net' => '99.00']],
            'additional_positions' => [['component' => 'Tür', 'damage_description' => 'Delle', 'amount_net' => '50.00']],
        ])->assertOk();

        $quotation = WorkshopQuotation::firstOrFail();
        $this->assertNull($quotation->submitted_at);
        $this->assertNull($quotation->company_name);
        $this->assertSame(0, WorkshopQuotationItem::count());
        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    public function test_unsent_additional_damage_is_printed_and_empty_cards_are_skipped(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $text = $this->draftText($token, [
            'items' => [['appraisal_position_id' => AppraisalPosition::firstOrFail()->id, 'amount_net' => '100.00']],
            'additional_positions' => [
                ['component' => 'Radlauf hinten rechts', 'damage_description' => 'Durchrostung', 'amount_net' => '540.00'],
                ['component' => '', 'damage_description' => '', 'amount_net' => ''],
            ],
        ]);

        $this->assertStringContainsString('Radlauf hinten rechts', $text);
        $this->assertStringContainsString('Z1', $text);
        $this->assertStringNotContainsString('Z2', $text);
        $this->assertMatchesRegularExpression('/Zusätzliche Schäden netto\s+540,00/', $text);
        $this->assertMatchesRegularExpression('/Werkstattangebot Gesamt netto\s+640,00/', $text);
    }

    public function test_a_partly_filled_form_prints_the_prices_it_has(): void
    {
        $order = $this->b2cOrder();
        $this->withPosition($order, 'Heckklappe');
        $token = $this->tokenFor($order);
        $first = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->firstOrFail();

        $text = $this->draftText($token, [
            'items' => [['appraisal_position_id' => $first->id, 'amount_net' => '120.00']],
        ]);

        $this->assertMatchesRegularExpression('/Werkstattangebot Gesamt netto\s+120,00/', $text);
    }

    public function test_a_position_of_another_order_cannot_be_priced_in_a_draft(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);
        $foreign = $this->withPosition($this->b2cOrder(), 'Fremde Position');

        $this->draftResponse($token, [
            'items' => [['appraisal_position_id' => $foreign->id, 'amount_net' => '10.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.appraisal_position_id');
    }

    public function test_a_draft_amount_must_be_a_valid_number(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->draftResponse($token, [
            'items' => [['appraisal_position_id' => AppraisalPosition::firstOrFail()->id, 'amount_net' => 'viel']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.amount_net');
    }

    public function test_a_revoked_quotation_cannot_be_printed_from_a_draft(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        WorkshopQuotation::firstOrFail()->update(['revoked_at' => now()]);

        $this->draftResponse($token, ['items' => []])->assertNotFound();
    }

    /** A submitted answer is the record; a stale draft must never replace it. */
    public function test_a_submitted_quotation_ignores_a_draft(): void
    {
        $order = $this->b2cOrder();
        $this->tokenFor($order);
        $quotation = WorkshopQuotation::firstOrFail();
        $position = AppraisalPosition::firstOrFail();

        WorkshopQuotationItem::create([
            'quotation_id' => $quotation->id,
            'appraisal_position_id' => $position->id,
            'amount_net' => '444.44',
        ]);
        $quotation->update(['submitted_at' => now(), 'total_net' => '444.44']);

        $document = app(WorkshopQuotationService::class)->pdfDocument($quotation->fresh(), [
            'items' => [['appraisal_position_id' => $position->id, 'amount_net' => '1.00']],
        ]);

        $this->assertSame('444.44', $document['workshop_total_net']);
        $this->assertFalse($document['is_draft']);
    }

    // ------------------------------------------------- unsent additional photos

    /**
     * The reported bug: photos picked for additional damage were missing from
     * the downloaded and printed PDF, because the draft request never sent
     * them. They now travel with the draft and are embedded from their bytes.
     */
    public function test_photos_picked_for_unsent_additional_damage_are_embedded(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $response = $this->post(route('workshop.quotations.pdf.draft', $token), [
            'additional_positions' => [[
                'component' => 'Radlauf hinten rechts',
                'damage_description' => 'Durchrostung',
                'amount_net' => '540.00',
                'images' => [UploadedFile::fake()->image('schaden.jpg', 640, 480)],
            ]],
        ], ['Accept' => 'application/pdf, application/json']);

        $response->assertOk();
        $pdf = (string) $response->getContent();

        $this->assertStringContainsString('/Image', $pdf);
        $this->assertStringContainsString('Zusätzlicher Schaden Z1 · Radlauf hinten rechts', $this->toText($pdf));

        // Printed, not stored: no document row, no file, no position.
        $this->assertSame(0, VehicleReportDocument::count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    public function test_each_unsent_photo_prints_under_its_own_additional_damage(): void
    {
        $order = $this->b2cOrder();
        $this->tokenFor($order);
        $quotation = WorkshopQuotation::firstOrFail();
        $first = UploadedFile::fake()->image('a.jpg', 300, 200);
        $second = UploadedFile::fake()->image('b.jpg', 300, 200);
        $third = UploadedFile::fake()->image('c.png', 200, 300);

        $document = app(WorkshopQuotationService::class)->pdfDocument($quotation, [
            'additional_positions' => [
                ['component' => 'Tür', 'amount_net' => '10.00', 'images' => [$first]],
                ['component' => '', 'damage_description' => '', 'amount_net' => ''],
                ['component' => 'Schweller', 'amount_net' => '20.00', 'images' => [$second, $third]],
            ],
        ]);

        $this->assertCount(2, $document['additional_positions']);
        $this->assertSame([$first], $document['additional_positions'][0]['image_uploads']);
        $this->assertSame([$second, $third], $document['additional_positions'][1]['image_uploads']);
    }

    public function test_an_unsent_additional_damage_upload_must_be_an_image(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->post(route('workshop.quotations.pdf.draft', $token), [
            'additional_positions' => [[
                'component' => 'Tür',
                'images' => [UploadedFile::fake()->create('schaden.pdf', 10, 'application/pdf')],
            ]],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('additional_positions.0.images.0');
    }

    /** Gutachten photos still print beside the workshop's unsent ones. */
    public function test_unsent_photos_keep_the_gutachten_position_photos(): void
    {
        $order = $this->b2cOrder();
        AppraisalPosition::firstOrFail()->update(['damage_image_document_ids' => [$this->imageDocument($order)->id]]);
        $token = $this->tokenFor($order);

        $response = $this->post(route('workshop.quotations.pdf.draft', $token), [
            'additional_positions' => [[
                'component' => 'Schweller',
                'amount_net' => '20.00',
                'images' => [UploadedFile::fake()->image('schaden.jpg', 300, 200)],
            ]],
        ], ['Accept' => 'application/json']);

        $text = $this->toText((string) $response->assertOk()->getContent());

        $this->assertStringContainsString('Position 1 ·', $text);
        $this->assertStringContainsString('Zusätzlicher Schaden Z1 · Schweller', $text);
    }

    // ------------------------------------------------------------ image appendix

    public function test_position_photos_are_repeated_large_in_an_appendix(): void
    {
        $order = $this->b2cOrder();
        AppraisalPosition::firstOrFail()->update(['damage_image_document_ids' => [
            $this->imageDocument($order)->id,
            $this->imageDocument($order, 60)->id,
        ]]);

        $text = $this->pdfText($this->tokenFor($order));

        $this->assertStringContainsString('Bildanhang', $text);
        $this->assertStringContainsString('Position 1 · Stoßfänger hinten', $text);
        $this->assertStringContainsString('Bild 2 von 2', $text);
    }

    public function test_additional_damage_photos_are_part_of_the_appendix(): void
    {
        $quotation = $this->quotationWithAdditionalDamage($this->b2cOrder(), withImage: true);

        $text = $this->renderedText($quotation);

        $this->assertStringContainsString('Zusätzlicher Schaden Z1 · Tür vorne links', $text);
    }

    public function test_a_quotation_without_photos_has_no_appendix(): void
    {
        $text = $this->pdfText($this->tokenFor($this->b2cOrder()));

        $this->assertStringNotContainsString('Bildanhang', $text);
    }

    public function test_appendix_photos_are_printed_larger_than_the_thumbnails(): void
    {
        $order = $this->b2cOrder();
        $document = $this->imageDocument($order);
        AppraisalPosition::firstOrFail()->update(['damage_image_document_ids' => [$document->id]]);
        $this->tokenFor($order);

        $method = new \ReflectionMethod(WorkshopQuotationPdf::class, 'viewData');
        $data = $method->invoke(app(WorkshopQuotationPdf::class), WorkshopQuotation::firstOrFail(), null);

        $thumbnail = $data['positions'][0]['images'][0];
        $large = $data['image_appendix'][0]['images'][0];

        $this->assertGreaterThan($thumbnail['width'] * 5, $large['width']);
        $this->assertEqualsWithDelta(
            $thumbnail['width'] / $thumbnail['height'],
            $large['width'] / $large['height'],
            0.05,
            'the large photo keeps its aspect ratio',
        );
    }

    // -------------------------------------------------------- both channels

    public function test_a_b2c_quotation_renders(): void
    {
        $order = $this->b2cOrder();

        $this->assertStringContainsString($order->auftragsnummer, $this->pdfText($this->tokenFor($order)));
    }

    public function test_a_b2b_quotation_renders(): void
    {
        $order = $this->b2bOrder();

        $this->assertStringContainsString($order->auftragsnummer, $this->pdfText($this->tokenFor($order)));
    }

    /**
     * Both channels reach the same renderer and the same data method — there is
     * no channel-specific PDF class, and nothing here branches on the channel.
     */
    public function test_both_channels_use_one_implementation(): void
    {
        $b2c = $this->pdfText($this->tokenFor($this->b2cOrder()));
        WorkshopQuotation::query()->delete();
        $b2b = $this->pdfText($this->tokenFor($this->b2bOrder()));

        foreach ([$b2c, $b2b] as $text) {
            $this->assertStringContainsString('Werkstattangebot', $text);
            $this->assertStringContainsString('Gutachtenpositionen', $text);
        }

        foreach ([
            'App\Modules\UserProfile\Order\Services\B2cWorkshopQuotationPdf',
            'App\Modules\UserProfile\Order\Services\B2bWorkshopQuotationPdf',
        ] as $forbidden) {
            $this->assertFalse(class_exists($forbidden), "{$forbidden} must not exist");
        }
    }

    // ------------------------------------------------------ scale and queries

    public function test_a_long_quotation_renders_across_several_pages(): void
    {
        $order = $this->b2cOrder();

        for ($i = 1; $i <= 40; $i++) {
            $this->withPosition($order, "Bauteil Nummer {$i}");
        }

        $text = $this->pdfText($this->tokenFor($order));

        $this->assertStringContainsString('Bauteil Nummer 1', $text);
        $this->assertStringContainsString('Bauteil Nummer 40', $text);
        $this->assertStringContainsString('Seite 2', $text, 'a 41-position quotation must run past one page');
        $this->assertStringContainsString('Werkstattangebot Gesamt netto', $text);
    }

    /**
     * The document's query cost must not grow with the number of positions,
     * images or reported damages. Asserted as "same for a small quotation as a
     * large one" so it pins the shape rather than a magic number.
     */
    public function test_the_document_does_not_query_per_position_or_image(): void
    {
        $service = app(WorkshopQuotationService::class);

        $small = $this->b2cOrder();
        AppraisalPosition::where('order_id', $small->id)
            ->first()
            ?->update(['damage_image_document_ids' => [$this->imageDocument($small)->id]]);
        $this->tokenFor($small);
        $smallQuotation = WorkshopQuotation::where('order_id', $small->id)->firstOrFail();

        $large = $this->b2cOrder();
        for ($i = 0; $i < 6; $i++) {
            $position = $this->withPosition($large, "Bauteil {$i}");
            $position->update(['damage_image_document_ids' => [
                $this->imageDocument($large)->id,
                $this->imageDocument($large)->id,
            ]]);
        }
        $this->tokenFor($large);
        $largeQuotation = WorkshopQuotation::where('order_id', $large->id)->firstOrFail();

        $costSmall = $this->countQueries(fn () => $service->pdfDocument($smallQuotation));
        $costLarge = $this->countQueries(fn () => $service->pdfDocument($largeQuotation));

        $this->assertSame($costSmall, $costLarge, 'pdfDocument() cost grew with positions or images.');
    }

    // ------------------------------------------------------------ document size

    /**
     * Fonts must be subsetted.
     *
     * dompdf subsets by default, but laravel-dompdf's config ships the option
     * off, which embeds the complete DejaVu Sans and DejaVu Sans Bold files in
     * every document. On a three-page quotation that was roughly 870 KB of font
     * data against about 20 KB of actual photos — a twentyfold blow-up on a
     * document meant to be emailed and printed.
     */
    public function test_embedded_fonts_are_subsetted(): void
    {
        $binary = 'pdffonts';

        if (trim((string) shell_exec(escapeshellcmd($binary).' -v 2>&1')) === '') {
            $this->markTestSkipped('pdffonts is not available on this host.');
        }

        $path = tempnam(sys_get_temp_dir(), 'wqf').'.pdf';
        file_put_contents($path, $this->pdfBytes($this->tokenFor($this->b2cOrder())));

        try {
            $listing = (string) shell_exec(escapeshellcmd($binary).' '.escapeshellarg($path).' 2>/dev/null');
        } finally {
            @unlink($path);
        }

        $rows = array_slice(array_filter(explode("\n", trim($listing))), 2);
        $this->assertNotEmpty($rows, 'the document embeds no fonts at all');

        foreach ($rows as $row) {
            // Columns end with: emb sub uni object ID
            $this->assertMatchesRegularExpression(
                '/\byes\s+yes\s+yes\s+\d+\s+\d+\s*$/',
                $row,
                "an embedded font is not subsetted: {$row}",
            );
        }
    }

    /**
     * A guard on the same thing from the other side, so the size stays sane
     * even if pdffonts is missing: this quotation carries one small photo, and
     * anything approaching a megabyte means whole font files came along.
     */
    public function test_a_small_quotation_produces_a_small_document(): void
    {
        $order = $this->b2cOrder();
        AppraisalPosition::where('order_id', $order->id)
            ->first()
            ?->update(['damage_image_document_ids' => [$this->imageDocument($order)->id]]);

        $bytes = strlen($this->pdfBytes($this->tokenFor($order)));

        $this->assertLessThan(250_000, $bytes, "a one-position quotation rendered {$bytes} bytes.");
    }

    // ------------------------------------------------------- reviewable sample

    /**
     * Writes a sample document to disk for visual review, exercising every
     * section at once: several Gutachten positions, images on more than one of
     * them, submitted workshop prices, a not-repairable position, several
     * workshop-reported damages with their own photos, and enough rows to run
     * past one page.
     *
     * Skipped unless WORKSHOP_PDF_SAMPLE names a directory, so an ordinary test
     * run leaves no artefacts behind:
     *
     *   WORKSHOP_PDF_SAMPLE=storage/app/private/samples \
     *     php artisan test --filter=writes_a_sample_document
     */
    public function test_it_writes_a_sample_document_for_visual_review(): void
    {
        $directory = (string) (env('WORKSHOP_PDF_SAMPLE') ?: '');

        if ($directory === '') {
            $this->markTestSkipped('Set WORKSHOP_PDF_SAMPLE=<dir> to write a sample PDF.');
        }

        $target = str_starts_with($directory, '/') ? $directory : base_path($directory);

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $order = $this->b2cOrder('K-LB 1234', 'WVWZZZ1KZAW000001');
        $this->tokenFor($order);
        $quotation = WorkshopQuotation::where('order_id', $order->id)->firstOrFail();

        AppraisalPosition::where('order_id', $order->id)->delete();

        $catalogue = [
            ['Stoßfänger hinten', 'Tiefe Kratzer über die gesamte Breite, Lack bis auf Grundierung beschädigt', 'Lackieren', '480.00', '455.00', 2],
            ['Heckklappe', 'Delle unterhalb der Griffmulde, ca. 12 cm', 'Ausbeulen und lackieren', '620.00', '590.00', 1],
            ['Seitenwand hinten links', 'Schürfspur mit Lackabtrag', 'Instandsetzen', '310.50', '299.00', 1],
            ['Außenspiegel rechts', 'Spiegelglas gesprungen, Gehäuse gerissen', 'Ersetzen', '245.00', '245.00', 0],
            ['Felge vorne rechts', 'Bordsteinschaden am Felgenhorn', 'Aufbereiten', '180.00', null, 1],
            ['Windschutzscheibe', 'Steinschlag im Sichtfeld des Fahrers', 'Ersetzen', '890.00', null, 0],
            ['Tür hinten rechts', 'Zwei Parkdellen ohne Lackschaden', 'Dellendrücken', '265.00', '240.00', 0],
            ['Frontschürze', 'Haltelaschen gebrochen', 'Ersetzen', '415.00', '398.50', 1],
            ['Kotflügel vorne links', 'Kratzer und leichte Verformung', 'Instandsetzen', '355.00', '340.00', 0],
            ['Dachreling links', 'Abdeckkappen fehlen, Kratzer im Aluminium', 'Aufbereiten', '120.00', '115.00', 0],
            ['Innenraum Kofferraum', 'Ladekantenschutz eingerissen', 'Ersetzen', '95.00', '89.00', 0],
            ['Nebelscheinwerfer rechts', 'Streuscheibe blind', 'Ersetzen', '150.00', '148.00', 0],
        ];

        foreach ($catalogue as $index => [$component, $description, $method, $appraisal, $workshop, $imageCount]) {
            $position = AppraisalPosition::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'sort_order' => $index,
                'component' => $component,
                'damage_description' => $description,
                'repair_method' => $method,
                'original_amount_net' => $appraisal,
                'source' => AppraisalPosition::SOURCE_MANUAL,
                'damage_image_document_ids' => array_map(
                    fn (int $shade) => $this->imageDocument($order, $shade)->id,
                    range(1, $imageCount) === [] ? [] : array_slice([40, 150, 210], 0, $imageCount),
                ),
            ]);

            WorkshopQuotationItem::create([
                'quotation_id' => $quotation->id,
                'appraisal_position_id' => $position->id,
                'amount_net' => $workshop,
                'repair_method' => $method,
                'not_repairable' => $workshop === null,
            ]);
        }

        $additional = [
            ['Radlauf hinten rechts', 'Durchrostung hinter der Radhausschale, im Gutachten nicht erfasst', 'Schweißen und lackieren', '540.00', 2],
            ['Auspuffendrohr', 'Aufhängung gerissen, Endrohr liegt auf', 'Aufhängung ersetzen', '165.00', 1],
            ['Unterfahrschutz', 'Fehlt vollständig', 'Ersetzen', '210.00', 0],
        ];

        foreach ($additional as $index => [$component, $description, $method, $amount, $imageCount]) {
            WorkshopAdditionalPosition::create([
                'quotation_id' => $quotation->id,
                'sort_order' => $index,
                'component' => $component,
                'damage_description' => $description,
                'repair_method' => $method,
                'amount_net' => $amount,
                'damage_image_document_ids' => array_map(
                    fn (int $shade) => $this->imageDocument($order, $shade)->id,
                    array_slice([90, 180], 0, $imageCount),
                ),
            ]);
        }

        $quotation->update([
            'company_name' => 'Karosseriebau Nord GmbH',
            'contact_person' => 'Frau Petra Schneider',
            'contact_email' => 'schneider@karosseriebau-nord.test',
            'earliest_repair_start' => now()->addDays(9),
            'processing_days' => 6,
            'submitted_at' => now(),
            'cannot_repair_for_amount' => true,
            'cannot_repair_note' => 'Felge vorne rechts und Windschutzscheibe sind zu den Gutachtenbeträgen nicht reparabel; hier wurden Austauschteile kalkuliert.',
        ]);

        $pdf = app(WorkshopQuotationPdf::class);
        $bytes = $pdf->render($quotation->fresh());
        $path = rtrim($target, '/').'/'.$pdf->filename($quotation);

        file_put_contents($path, $bytes);
        file_put_contents(preg_replace('/\.pdf$/', '.txt', $path), $this->toText($bytes));

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path));

        fwrite(STDERR, "\n\nSAMPLE PDF: {$path}\n".round(strlen($bytes) / 1024)."\u{00a0}KB\n\n");
    }

    // ----------------------------------------------------------------- helpers

    private string $reference = '';

    private function pdfBytes(string $token): string
    {
        $response = $this->get(route('workshop.quotations.pdf', $token));

        $response->assertOk();

        return (string) $response->getContent();
    }

    /** The finished PDF read back as text with poppler, as a workshop would see it. */
    /**
     * @param  array<string, mixed>  $draft
     */
    private function draftResponse(string $token, array $draft): TestResponse
    {
        return $this->postJson(route('workshop.quotations.pdf.draft', $token), $draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function draftText(string $token, array $draft): string
    {
        $response = $this->draftResponse($token, $draft);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        return $this->toText((string) $response->getContent());
    }

    private function pdfText(string $token): string
    {
        return $this->toText($this->pdfBytes($token));
    }

    private function renderedText(WorkshopQuotation $quotation): string
    {
        return $this->toText(app(WorkshopQuotationPdf::class)->render($quotation));
    }

    private function toText(string $pdf): string
    {
        $binary = (string) config('gutachten.pdftotext.binary', 'pdftotext');

        if (trim((string) shell_exec(escapeshellcmd($binary).' -v 2>&1')) === '') {
            $this->markTestSkipped('pdftotext is not available on this host.');
        }

        $path = tempnam(sys_get_temp_dir(), 'wq').'.pdf';
        file_put_contents($path, $pdf);

        try {
            // -layout keeps table columns side by side, so an amount stays on
            // the same line as the position it belongs to.
            return (string) shell_exec(escapeshellcmd($binary).' -layout '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function imageDocument(LeasybackOrder $order, int $shade = 140): VehicleReportDocument
    {
        $path = 'werkstatt-test/'.Str::uuid().'.jpg';

        // 4:3 and distinctly shaded, so the sample shows real aspect ratios and
        // dompdf cannot fold several photos into one reused XObject.
        $image = imagecreatetruecolor(240, 180);
        imagefilledrectangle($image, 0, 0, 240, 180, imagecolorallocate($image, (int) ($shade / 3), $shade, 120));
        imagefilledrectangle($image, 18, 18, 110, 80, imagecolorallocate($image, 250, 250, 240));
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('documents')->put($path, $bytes);

        return VehicleReportDocument::create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'Schadenbild',
            'document_title' => 'Schadenbild',
            'path' => $path,
            'published' => false,
        ]);
    }

    private function quotationWithAdditionalDamage(LeasybackOrder $order, bool $withImage = false): WorkshopQuotation
    {
        $this->tokenFor($order);
        $quotation = WorkshopQuotation::where('order_id', $order->id)->firstOrFail();

        WorkshopAdditionalPosition::create([
            'quotation_id' => $quotation->id,
            'sort_order' => 0,
            'component' => 'Tür vorne links',
            'damage_description' => 'Delle und Lackschaden',
            'repair_method' => 'Lackieren',
            'amount_net' => '250.00',
            'damage_image_document_ids' => $withImage ? [$this->imageDocument($order)->id] : [],
        ]);

        $quotation->update(['submitted_at' => now()]);

        return $quotation->fresh();
    }

    private function tokenFor(LeasybackOrder $order, bool $showAmounts = true): string
    {
        $token = Str::random(64);
        $this->reference = $order->auftragsnummer;

        WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => hash('sha256', $token),
            'workshop_label' => 'Karosserie Nord',
            'show_appraisal_amounts' => $showAmounts,
            'expires_at' => now()->addDays(14),
        ]);

        return $token;
    }

    private function b2cOrder(?string $plate = null, ?string $vin = null): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
            'license_plate' => $plate ?? 'K-LB '.fake()->unique()->numberBetween(1000, 9999),
            'vin' => $vin ?? strtoupper(Str::random(17)),
            'make' => 'Volkswagen',
            'model' => 'Golf',
            'mileage' => 84500,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);

        $this->withPosition($order);

        return $order;
    }

    private function b2bOrder(): LeasybackOrder
    {
        $order = $this->makeB2bOrder(
            $this->makeB2bVehicle($this->makeCompany(fake()->unique()->company())),
            'inspected',
        );

        $this->withPosition($order);

        return $order;
    }

    private function withPosition(LeasybackOrder $order, string $component = 'Stoßfänger hinten'): AppraisalPosition
    {
        return AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => AppraisalPosition::where('order_id', $order->id)->count(),
            'component' => $component,
            'damage_description' => 'Kratzer',
            'repair_method' => 'Lackieren',
            'original_amount_net' => '500.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);
    }
}
