<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopAdditionalPosition;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
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
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Damage the workshop found that the Gutachten does not list.
 *
 * It is reported through the same token, the same form and the same service as
 * the prices, and lands in its own table — never in b2b_appraisal_positions,
 * which stays the appraisal. The quotation flow is channel-blind, so every rule
 * here is pinned for a B2C order as well as a B2B one.
 */
class WorkshopAdditionalPositionTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('documents');
    }

    // ------------------------------------------------------------ both channels

    public function test_a_b2c_workshop_can_report_additional_damage(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [$this->additional()])->assertRedirect();

        $position = WorkshopAdditionalPosition::firstOrFail();

        $this->assertSame('Tür vorne links', $position->component);
        $this->assertSame('Delle und Lackschaden', $position->damage_description);
        $this->assertSame('Lackieren', $position->repair_method);
        $this->assertSame('250.00', (string) $position->amount_net);
    }

    public function test_a_b2b_workshop_can_report_additional_damage(): void
    {
        $token = $this->tokenFor($this->b2bOrder());

        $this->submit($token, [$this->additional()])->assertRedirect();

        $this->assertSame('Tür vorne links', WorkshopAdditionalPosition::firstOrFail()->component);
    }

    public function test_both_channels_persist_into_one_shared_table_through_one_service(): void
    {
        $this->submit($this->tokenFor($this->b2cOrder()), [$this->additional()])->assertRedirect();
        $this->submit($this->tokenFor($this->b2bOrder()), [$this->additional()])->assertRedirect();

        $this->assertSame('workshop_additional_positions', (new WorkshopAdditionalPosition)->getTable());
        $this->assertSame(2, WorkshopAdditionalPosition::count());
    }

    public function test_each_channels_images_are_stored_against_its_own_order(): void
    {
        $b2cOrder = $this->b2cOrder();
        $b2bOrder = $this->b2bOrder();

        $this->submit($this->tokenFor($b2cOrder), [$this->additional(images: [$this->image()])])->assertRedirect();
        $this->submit($this->tokenFor($b2bOrder), [$this->additional(images: [$this->image()])])->assertRedirect();

        [$b2c, $b2b] = WorkshopAdditionalPosition::orderBy('created_at')->get()->all();

        foreach ([[$b2c, $b2cOrder], [$b2b, $b2bOrder]] as [$position, $order]) {
            $document = VehicleReportDocument::findOrFail($position->damage_image_document_ids[0]);

            $this->assertSame($order->auftragsnummer, $document->auftragsnummer);
            $this->assertSame($order->vehicle_id, $document->vehicle_id);
        }
    }

    /**
     * No workshop token reaches a workshop-uploaded image, not even the one
     * that uploaded it: the submission that writes them is also what closes
     * the link. Admin reads them through the admin image route instead.
     */
    public function test_no_workshop_token_can_reach_a_workshop_uploaded_image(): void
    {
        $order = $this->b2cOrder();
        $reporter = $this->tokenFor($order);
        $sibling = $this->tokenFor($order);

        $this->submit($reporter, [$this->additional(images: [$this->image()])])->assertRedirect();

        $documentId = WorkshopAdditionalPosition::firstOrFail()->damage_image_document_ids[0];

        $this->get(route('workshop.quotations.images.show', [$reporter, $documentId]))->assertNotFound();
        $this->get(route('workshop.quotations.images.show', [$sibling, $documentId]))->assertNotFound();
    }

    public function test_an_admin_can_see_the_reported_images(): void
    {
        $this->submit($this->tokenFor($this->b2cOrder()), [$this->additional(images: [$this->image()])])->assertRedirect();

        $documentId = WorkshopAdditionalPosition::firstOrFail()->damage_image_document_ids[0];
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->get(route('admin.vehicles.reports.image', $documentId))->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.vehicles.reports.image', ['documentId' => $documentId, 'size' => 'thumb']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_a_gutachten_image_stays_reachable_through_an_open_link(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);
        $path = "vehicle-reports/{$order->auftragsnummer}/gutachten-bild.jpg";

        Storage::disk('documents')->put($path, $this->image()->getContent());

        $document = VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'path' => $path,
        ]);

        AppraisalPosition::where('order_id', $order->id)->firstOrFail()
            ->update(['damage_image_document_ids' => [$document->id]]);

        $this->get(route('workshop.quotations.images.show', [$token, $document->id]))->assertOk();
    }

    // ------------------------------------------------------------------ content

    public function test_several_additional_positions_are_kept_in_order(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [
            $this->additional(component: 'Tür vorne links'),
            $this->additional(component: 'Kotflügel hinten rechts'),
            $this->additional(component: 'Schweller links'),
        ])->assertRedirect();

        $this->assertSame(
            ['Tür vorne links', 'Kotflügel hinten rechts', 'Schweller links'],
            WorkshopAdditionalPosition::orderBy('sort_order')->pluck('component')->all(),
        );
    }

    public function test_several_images_are_attached_to_one_position(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [$this->additional(images: [$this->image(), $this->image(), $this->image()])])->assertRedirect();

        $position = WorkshopAdditionalPosition::firstOrFail();

        $this->assertCount(3, $position->damage_image_document_ids);

        foreach ($position->damage_image_document_ids as $documentId) {
            $document = VehicleReportDocument::findOrFail($documentId);

            $this->assertSame(WorkshopQuotationService::ADDITIONAL_IMAGE_DOCUMENT_TYPE, $document->document_type);
            $this->assertFalse($document->published);
            $this->assertNull($document->created_by_user_id);
            Storage::disk('documents')->assertExists($document->path);
        }
    }

    public function test_a_decimal_price_is_stored_exactly(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [$this->additional(amount: '1234.56')])->assertRedirect();

        $this->assertSame('1234.56', (string) WorkshopAdditionalPosition::firstOrFail()->amount_net);
    }

    public function test_an_additional_position_counts_toward_the_quotation_total(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $this->submit($token, [$this->additional(amount: '250.00')], itemAmount: '400.00')->assertRedirect();

        $this->assertSame('650.00', (string) WorkshopQuotation::firstOrFail()->total_net);
    }

    public function test_an_additional_position_alone_satisfies_the_pricing_requirement(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [$this->additional(amount: '250.00')], itemAmount: null)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('250.00', (string) WorkshopQuotation::firstOrFail()->total_net);
    }

    // --------------------------------------------------------------- validation

    public function test_component_description_and_price_are_required(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [['component' => '', 'damage_description' => '', 'repair_method' => '', 'amount_net' => '']])
            ->assertSessionHasErrors([
                'additional_positions.0.component',
                'additional_positions.0.damage_description',
                'additional_positions.0.amount_net',
            ]);

        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    public function test_a_price_of_zero_is_refused(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [$this->additional(amount: '0')])->assertSessionHasErrors('additional_positions.0.amount_net');
    }

    public function test_more_than_five_images_are_refused(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        $images = array_map(fn () => $this->image(), range(1, WorkshopQuotationService::MAX_ADDITIONAL_IMAGES + 1));

        $this->submit($token, [$this->additional(images: $images)])->assertSessionHasErrors('additional_positions.0.images');

        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    public function test_an_image_over_ten_megabytes_is_refused(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        $oversized = UploadedFile::fake()->image('gross.jpg', 800, 600)
            ->size(WorkshopQuotationService::MAX_ADDITIONAL_IMAGE_KILOBYTES + 1);

        $this->submit($token, [$this->additional(images: [$oversized])])->assertSessionHasErrors('additional_positions.0.images.0');
    }

    /**
     * A real UploadedFile, not the testing fake: the fake reports its mime
     * type from the filename, so it could not tell a disguised file from a
     * photo and would prove nothing about content validation.
     */
    public function test_a_non_image_is_refused_even_with_an_image_extension(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $path = tempnam(sys_get_temp_dir(), 'disguised');
        file_put_contents($path, '<?php echo "not an image";');
        $disguised = new UploadedFile($path, 'schaden.jpg', 'image/jpeg', null, true);

        $this->submit($token, [$this->additional(images: [$disguised])])->assertSessionHasErrors('additional_positions.0.images.0');

        $this->assertSame(0, WorkshopAdditionalPosition::count());
        $this->assertSame(0, VehicleReportDocument::where('document_type', WorkshopQuotationService::ADDITIONAL_IMAGE_DOCUMENT_TYPE)->count());
    }

    public function test_a_pdf_is_refused(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [$this->additional(images: [UploadedFile::fake()->create('schaden.pdf', 20, 'application/pdf')])])
            ->assertSessionHasErrors('additional_positions.0.images.0');
    }

    // ------------------------------------------------- total image count (PHP)

    /**
     * The invariant the whole total-image cap rests on.
     *
     * PHP keeps the first `max_file_uploads` files of a multipart body and
     * discards the rest before any application code runs — with no
     * UPLOAD_ERR_* marker, and with every text field still arriving intact, so
     * a request carrying one image too many is indistinguishable from a legal
     * one. Holding the application cap strictly below that directive is what
     * turns the loss into a refusal: a truncated body always arrives holding
     * max_file_uploads images, which is over the cap.
     *
     * If this fails, the feature can silently drop a workshop's photos.
     */
    public function test_the_total_image_cap_stays_below_php_max_file_uploads(): void
    {
        $phpLimit = (int) ini_get('max_file_uploads');

        $this->assertGreaterThan(0, $phpLimit, 'max_file_uploads must be readable to reason about truncation.');
        $this->assertLessThan(
            $phpLimit,
            WorkshopQuotationService::MAX_ADDITIONAL_IMAGES_TOTAL,
            "MAX_ADDITIONAL_IMAGES_TOTAL must stay below max_file_uploads ({$phpLimit}), or PHP drops images silently.",
        );
    }

    public function test_exactly_the_total_image_allowance_is_accepted(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        $total = WorkshopQuotationService::MAX_ADDITIONAL_IMAGES_TOTAL;

        $this->submit($token, $this->spreadImages($total))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $total,
            VehicleReportDocument::where('document_type', WorkshopQuotationService::ADDITIONAL_IMAGE_DOCUMENT_TYPE)->count(),
        );
    }

    /**
     * One image past the allowance is refused with a message rather than
     * quietly stored short. This is the case PHP would otherwise swallow.
     */
    public function test_one_image_over_the_total_allowance_is_refused(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, $this->spreadImages(WorkshopQuotationService::MAX_ADDITIONAL_IMAGES_TOTAL + 1))
            ->assertSessionHasErrors('additional_positions');

        $this->assertSame(0, WorkshopAdditionalPosition::count());
        $this->assertSame(
            0,
            VehicleReportDocument::where('document_type', WorkshopQuotationService::ADDITIONAL_IMAGE_DOCUMENT_TYPE)->count(),
        );
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertNull(WorkshopQuotation::firstOrFail()->submitted_at);
    }

    public function test_the_total_image_allowance_is_enforced_on_a_b2b_order(): void
    {
        $token = $this->tokenFor($this->b2bOrder());

        $this->submit($token, $this->spreadImages(WorkshopQuotationService::MAX_ADDITIONAL_IMAGES_TOTAL + 1))
            ->assertSessionHasErrors('additional_positions');

        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    /**
     * The count is refused ahead of the byte budget, so a body PHP truncated to
     * twenty small images is caught even though it is nowhere near 40 MB.
     */
    public function test_the_count_is_refused_even_when_the_size_budget_is_untouched(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, $this->spreadImages(WorkshopQuotationService::MAX_ADDITIONAL_IMAGES_TOTAL + 1, kilobytes: 4))
            ->assertSessionHasErrors('additional_positions');

        $this->assertStringContainsString(
            'Schadenbilder möglich',
            (string) session('errors')->getBag('default')->first('additional_positions'),
        );
    }

    /** The limits the workshop's form is told about are the ones enforced. */
    public function test_the_payload_publishes_the_image_limits_it_enforces(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->get(route('workshop.quotations.show', $token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('quotation.max_additional_images', WorkshopQuotationService::MAX_ADDITIONAL_IMAGES)
                ->where('quotation.max_additional_images_total', WorkshopQuotationService::MAX_ADDITIONAL_IMAGES_TOTAL)
                ->missing('quotation.max_additional_positions'));
    }

    // ----------------------------------------------------- admin read query cost

    /**
     * forOrder() used to run one items query and one additional-positions query
     * inside its per-quotation map, so an order with ten workshops cost
     * twenty-one round trips. Both are loaded once and grouped now.
     *
     * Asserted as "same cost for one workshop as for five" rather than against
     * a hard number, so the test pins the absence of per-quotation growth
     * without breaking every time an unrelated query is added.
     */
    public function test_for_order_does_not_query_per_quotation(): void
    {
        $service = app(WorkshopQuotationService::class);

        $one = $this->orderWithSubmittedQuotations(1);
        $five = $this->orderWithSubmittedQuotations(5);

        $costForOne = $this->countQueries(fn () => $service->forOrder($one->id));
        $costForFive = $this->countQueries(fn () => $service->forOrder($five->id));

        $this->assertSame($costForOne, $costForFive, 'forOrder() cost grew with the number of quotations.');
    }

    /** Guards the test above from passing because the payload came back empty. */
    public function test_for_order_still_returns_every_quotations_additional_positions(): void
    {
        $order = $this->orderWithSubmittedQuotations(3);

        $quotations = app(WorkshopQuotationService::class)->forOrder($order->id);

        $this->assertCount(3, $quotations);

        foreach ($quotations as $quotation) {
            $this->assertCount(1, $quotation['additional_positions']);
            $this->assertCount(1, $quotation['additional_positions'][0]['images']);
            $this->assertSame('250.00', $quotation['additional_positions'][0]['amount_net']);
            $this->assertNotSame([], $quotation['comparison']);
        }
    }

    // --------------------------------------------------------- total upload size

    public function test_a_submission_below_the_total_budget_succeeds(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [
            $this->additional(images: [$this->sized(4096), $this->sized(4096)]),
            $this->additional(images: [$this->sized(4096)]),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, WorkshopAdditionalPosition::count());
    }

    public function test_a_submission_at_exactly_the_total_budget_succeeds(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        $perImage = intdiv(WorkshopQuotationService::MAX_ADDITIONAL_UPLOAD_KILOBYTES, 4);

        $this->submit($token, array_map(
            fn () => $this->additional(images: [$this->sized($perImage)]),
            range(1, 4),
        ))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(4, WorkshopAdditionalPosition::count());
    }

    public function test_one_kilobyte_over_the_total_budget_is_rejected(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        $perImage = intdiv(WorkshopQuotationService::MAX_ADDITIONAL_UPLOAD_KILOBYTES, 4);

        $positions = array_map(fn () => $this->additional(images: [$this->sized($perImage)]), range(1, 4));
        $positions[] = $this->additional(images: [$this->sized(1)]);

        $this->submit($token, $positions)->assertSessionHasErrors('additional_positions');

        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    public function test_a_submission_well_over_the_total_budget_is_rejected(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $positions = array_map(
            fn () => $this->additional(images: [$this->sized(WorkshopQuotationService::MAX_ADDITIONAL_IMAGE_KILOBYTES)]),
            range(1, 6),
        );

        $this->submit($token, $positions)->assertSessionHasErrors('additional_positions');
    }

    public function test_an_over_budget_submission_stores_no_files_or_rows(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $positions = array_map(
            fn () => $this->additional(images: [$this->sized(WorkshopQuotationService::MAX_ADDITIONAL_IMAGE_KILOBYTES)]),
            range(1, 6),
        );

        $this->submit($token, $positions)->assertSessionHasErrors('additional_positions');

        $this->assertSame(0, WorkshopAdditionalPosition::count());
        $this->assertSame(0, VehicleReportDocument::where('document_type', WorkshopQuotationService::ADDITIONAL_IMAGE_DOCUMENT_TYPE)->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertNull(WorkshopQuotation::firstOrFail()->submitted_at);
    }

    public function test_the_budget_is_enforced_identically_on_a_b2b_order(): void
    {
        $token = $this->tokenFor($this->b2bOrder());

        $positions = array_map(
            fn () => $this->additional(images: [$this->sized(WorkshopQuotationService::MAX_ADDITIONAL_IMAGE_KILOBYTES)]),
            range(1, 6),
        );

        $this->submit($token, $positions)->assertSessionHasErrors('additional_positions');
        $this->assertSame(0, WorkshopAdditionalPosition::count());

        $this->submit($token, [$this->additional(images: [$this->sized(4096)])])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, WorkshopAdditionalPosition::count());
    }

    public function test_the_per_image_limit_still_applies_below_the_total_budget(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        $oversized = $this->sized(WorkshopQuotationService::MAX_ADDITIONAL_IMAGE_KILOBYTES + 1);

        $this->submit($token, [$this->additional(images: [$oversized])])
            ->assertSessionHasErrors('additional_positions.0.images.0');

        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    // ------------------------------------------------------------- side effects

    public function test_the_gutachten_positions_are_left_untouched(): void
    {
        $order = $this->b2cOrder();
        $before = AppraisalPosition::where('order_id', $order->id)->get()->toArray();

        $this->submit($this->tokenFor($order), [$this->additional(images: [$this->image()])])->assertRedirect();

        $this->assertSame($before, AppraisalPosition::where('order_id', $order->id)->get()->toArray());
    }

    public function test_an_existing_gutachten_image_assignment_is_left_untouched(): void
    {
        $order = $this->b2cOrder();
        $position = AppraisalPosition::where('order_id', $order->id)->firstOrFail();
        $position->update(['damage_image_document_ids' => ['11111111-1111-1111-1111-111111111111']]);

        $this->submit($this->tokenFor($order), [$this->additional(images: [$this->image()])])->assertRedirect();

        $this->assertSame(['11111111-1111-1111-1111-111111111111'], $position->fresh()->damage_image_document_ids);
    }

    // ------------------------------------------------------------------ locking

    public function test_a_submitted_quotation_cannot_report_more_damage(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        $this->submit($token, [$this->additional()])->assertRedirect();

        $this->submit($token, [$this->additional(component: 'Zweiter Versuch')])->assertNotFound();

        $this->assertSame(1, WorkshopAdditionalPosition::count());
        $this->assertSame('Tür vorne links', WorkshopAdditionalPosition::firstOrFail()->component);
    }

    public function test_a_revoked_quotation_cannot_report_damage(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        WorkshopQuotation::query()->update(['revoked_at' => now()]);

        $this->submit($token, [$this->additional()])->assertNotFound();

        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    public function test_an_expired_quotation_cannot_report_damage(): void
    {
        $token = $this->tokenFor($this->b2cOrder());
        WorkshopQuotation::query()->update(['expires_at' => now()->subDay()]);

        $this->submit($token, [$this->additional()])->assertNotFound();

        $this->assertSame(0, WorkshopAdditionalPosition::count());
    }

    public function test_a_second_post_cannot_add_a_duplicate(): void
    {
        $token = $this->tokenFor($this->b2cOrder());

        $this->submit($token, [$this->additional(images: [$this->image()])])->assertRedirect();
        $this->submit($token, [$this->additional(images: [$this->image()])])->assertNotFound();

        $this->assertSame(1, WorkshopAdditionalPosition::count());
        $this->assertSame(1, VehicleReportDocument::where('document_type', WorkshopQuotationService::ADDITIONAL_IMAGE_DOCUMENT_TYPE)->count());
    }

    public function test_a_failed_submission_leaves_no_document_row_or_file_behind(): void
    {
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        // Accepted by validation, refused by the locked re-read inside the
        // transaction — the images are already on disk by then.
        WorkshopQuotation::query()->update(['revoked_at' => now()]);

        $this->submit($token, [$this->additional(images: [$this->image(), $this->image()])])->assertNotFound();

        $this->assertSame(0, WorkshopAdditionalPosition::count());
        $this->assertSame(0, VehicleReportDocument::where('document_type', WorkshopQuotationService::ADDITIONAL_IMAGE_DOCUMENT_TYPE)->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<int, UploadedFile>  $images
     * @return array<string, mixed>
     */
    private function additional(
        string $component = 'Tür vorne links',
        string $amount = '250.00',
        array $images = [],
    ): array {
        return [
            'component' => $component,
            'damage_description' => 'Delle und Lackschaden',
            'repair_method' => 'Lackieren',
            'amount_net' => $amount,
            'images' => $images,
        ];
    }

    /**
     * `$count` images spread over as many positions as the per-position cap
     * requires, so a total-count rule can be reached without tripping the
     * per-position one first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function spreadImages(int $count, int $kilobytes = 64): array
    {
        $positions = [];

        for ($remaining = $count; $remaining > 0; $remaining -= WorkshopQuotationService::MAX_ADDITIONAL_IMAGES) {
            $take = min($remaining, WorkshopQuotationService::MAX_ADDITIONAL_IMAGES);

            $positions[] = $this->additional(
                component: 'Bauteil '.count($positions),
                images: array_map(fn () => $this->sized($kilobytes), range(1, $take)),
            );
        }

        return $positions;
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image(Str::uuid().'.jpg', 1200, 900);
    }

    /** A real image whose reported size is fixed, so a budget can be hit exactly. */
    private function sized(int $kilobytes): UploadedFile
    {
        return UploadedFile::fake()->image(Str::uuid().'.jpg', 1200, 900)->size($kilobytes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $additional
     */
    private function submit(string $token, array $additional, ?string $itemAmount = '400.00'): TestResponse
    {
        $quotation = WorkshopQuotation::where('token_hash', hash('sha256', $token))->first();
        $positionId = $quotation === null
            ? null
            : AppraisalPosition::where('order_id', $quotation->order_id)->orderBy('sort_order')->value('id');

        return $this->post(route('workshop.quotations.submit', $token), [
            'company_name' => 'Werkstatt GmbH',
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'items' => [['appraisal_position_id' => $positionId, 'amount_net' => $itemAmount]],
            'additional_positions' => $additional,
        ]);
    }

    /**
     * One order carrying `$count` submitted quotations, each with a priced item
     * and one additional position holding an image.
     */
    private function orderWithSubmittedQuotations(int $count): LeasybackOrder
    {
        $order = $this->b2cOrder();

        for ($i = 0; $i < $count; $i++) {
            $this->submit(
                $this->tokenFor($order),
                [$this->additional(component: "Bauteil {$i}", images: [$this->image()])],
            )->assertRedirect();
        }

        return $order;
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

    private function tokenFor(LeasybackOrder $order): string
    {
        $token = Str::random(64);

        WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => hash('sha256', $token),
            'workshop_label' => 'Karosserie '.Str::random(5),
            'show_appraisal_amounts' => true,
            'expires_at' => now()->addDays(14),
        ]);

        return $token;
    }

    private function b2cOrder(): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        return $this->withPosition(LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]));
    }

    private function b2bOrder(): LeasybackOrder
    {
        return $this->withPosition($this->makeB2bOrder(
            $this->makeB2bVehicle($this->makeCompany(fake()->unique()->company())),
            'inspected',
        ));
    }

    private function withPosition(LeasybackOrder $order): LeasybackOrder
    {
        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger hinten',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '500.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        return $order;
    }
}
