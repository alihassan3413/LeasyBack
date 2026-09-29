<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Rate limiting on the public workshop routes.
 *
 * These routes are guest-only with a token as their sole credential, so they
 * must stay throttled. What they must not do is share one counter: Laravel's
 * inline `throttle:n,m` keys every route on sha1(domain|ip) and only varies the
 * ceiling it compares against, so browsing a quotation's damage photos used to
 * spend the submission's budget and the workshop got a 429 on the one request
 * that mattered.
 *
 * Each route now has a named limiter, which puts the limiter's name in the key,
 * so the budgets are genuinely separate.
 */
class WorkshopQuotationThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        // The limiter lives in the cache, which the array store keeps for the
        // whole process — one test's requests would otherwise count against
        // the next one's budget.
        RateLimiter::clear('');
        cache()->flush();
    }

    /** The reported bug: photos, then a submission that must still go through. */
    public function test_loading_many_images_does_not_block_the_submission(): void
    {
        [$order, $token, $documentId] = $this->quotationWithImage();

        for ($i = 0; $i < 25; $i++) {
            $this->get(route('workshop.quotations.images.show', [$token, $documentId]))->assertOk();
        }

        $this->submit($token)->assertRedirect();
        $this->assertSame(1, WorkshopQuotation::whereNotNull('submitted_at')->count());
    }

    public function test_reloading_the_page_does_not_block_the_submission(): void
    {
        [$order, $token] = $this->quotationWithImage();

        for ($i = 0; $i < 25; $i++) {
            $this->get(route('workshop.quotations.show', $token))->assertOk();
        }

        $this->submit($token)->assertRedirect();
    }

    /** The PDF shares the page's origin, so it must not spend the budget either. */
    public function test_rendering_the_pdf_does_not_block_the_submission(): void
    {
        [$order, $token] = $this->quotationWithImage();

        for ($i = 0; $i < 15; $i++) {
            $this->get(route('workshop.quotations.pdf', $token))->assertOk();
        }

        $this->submit($token)->assertRedirect();
    }

    /** All three together — the real session a workshop has. */
    public function test_a_full_browsing_session_still_ends_in_a_submission(): void
    {
        [$order, $token, $documentId] = $this->quotationWithImage();

        $this->get(route('workshop.quotations.show', $token))->assertOk();

        for ($i = 0; $i < 20; $i++) {
            $this->get(route('workshop.quotations.images.show', [$token, $documentId]))->assertOk();
        }

        $this->get(route('workshop.quotations.pdf', $token))->assertOk();
        $this->get(route('workshop.quotations.show', $token))->assertOk();

        $this->submit($token)->assertRedirect();
    }

    /**
     * The image budget has to fit a real Gutachten, not a small one.
     *
     * The image route answers `Cache-Control: no-store` deliberately, so the
     * browser refetches every thumbnail on every render. A 40-photo appraisal
     * therefore costs 40 requests per view, and the old ceiling of 120 ran out
     * partway through the third view — which is what a workshop saw as broken
     * images even after each route got its own bucket.
     */
    public function test_an_image_heavy_quotation_survives_repeated_browsing(): void
    {
        [$order, $token] = $this->quotationWithImage(imageCount: 40);
        $ids = AppraisalPosition::where('order_id', $order->id)->value('damage_image_document_ids');

        // Three page views, each refetching every thumbnail.
        for ($view = 0; $view < 3; $view++) {
            foreach ($ids as $id) {
                $this->get(route('workshop.quotations.images.show', [$token, $id]).'?size=thumb')
                    ->assertOk();
            }
        }

        // Then the lightbox on ten of them, which asks for the full image.
        foreach (array_slice($ids, 0, 10) as $id) {
            $this->get(route('workshop.quotations.images.show', [$token, $id]))->assertOk();
        }

        $this->submit($token)->assertRedirect();
    }

    // ------------------------------------------- the limits themselves remain

    /**
     * Separating the buckets must not mean removing the protection: the
     * submission is still refused once its own budget is spent.
     */
    public function test_the_submission_is_still_throttled_on_its_own_budget(): void
    {
        [$order, $token] = $this->quotationWithImage();

        // The first submission closes the link, so every later one is a 404
        // rather than a redirect — what matters here is that a 429 arrives once
        // the budget is gone, not what the earlier answers were.
        $sawThrottle = false;

        for ($i = 0; $i < 15; $i++) {
            if ($this->submit($token)->getStatusCode() === 429) {
                $sawThrottle = true;
                break;
            }
        }

        $this->assertTrue($sawThrottle, 'the submit route stopped being throttled');
    }

    public function test_the_image_route_is_still_throttled(): void
    {
        [$order, $token, $documentId] = $this->quotationWithImage();

        $sawThrottle = false;

        // Past the 600/min ceiling: the budget is large because `no-store`
        // makes every render refetch, but it must still run out.
        for ($i = 0; $i < 700; $i++) {
            if ($this->get(route('workshop.quotations.images.show', [$token, $documentId]))->getStatusCode() === 429) {
                $sawThrottle = true;
                break;
            }
        }

        $this->assertTrue($sawThrottle, 'the image route stopped being throttled');
    }

    public function test_the_page_route_is_still_throttled(): void
    {
        [$order, $token] = $this->quotationWithImage();

        $sawThrottle = false;

        for ($i = 0; $i < 120; $i++) {
            if ($this->get(route('workshop.quotations.show', $token))->getStatusCode() === 429) {
                $sawThrottle = true;
                break;
            }
        }

        $this->assertTrue($sawThrottle, 'the page route stopped being throttled');
    }

    /**
     * The limiter keys on the caller, never on the token in the URL. A token is
     * user input: if it were part of the key, anyone could mint a fresh budget
     * by changing one character.
     */
    public function test_a_different_token_does_not_buy_a_fresh_budget(): void
    {
        [$order, $token] = $this->quotationWithImage();

        $spent = 0;

        while ($spent < 120 && $this->get(route('workshop.quotations.show', $token))->getStatusCode() !== 429) {
            $spent++;
        }

        // A second, unrelated quotation — a different valid token from the same
        // caller must find the budget already spent.
        $other = $this->quotationWithImage();

        $this->get(route('workshop.quotations.show', $other[1]))->assertStatus(429);
    }

    // ----------------------------------------------------------------- helpers

    private function submit(string $token): TestResponse
    {
        $quotation = WorkshopQuotation::where('token_hash', hash('sha256', $token))->first();
        $positionId = $quotation === null
            ? null
            : AppraisalPosition::where('order_id', $quotation->order_id)->value('id');

        return $this->post(route('workshop.quotations.submit', $token), [
            'company_name' => 'Werkstatt GmbH',
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'items' => [['appraisal_position_id' => $positionId, 'amount_net' => '400.00']],
        ]);
    }

    /**
     * @return array{0: LeasybackOrder, 1: string, 2: string}
     */
    private function quotationWithImage(int $imageCount = 1): array
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);

        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image, null, 70);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $documentIds = [];

        for ($i = 0; $i < $imageCount; $i++) {
            $path = 'werkstatt-throttle/'.Str::uuid().'.jpg';
            Storage::disk('documents')->put($path, $bytes);

            $documentIds[] = VehicleReportDocument::create([
                'auftragsnummer' => $order->auftragsnummer,
                'vehicle_id' => $order->vehicle_id,
                'document_type' => 'Schadenbild',
                'document_title' => 'Schadenbild',
                'path' => $path,
                'published' => false,
            ])->id;
        }

        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger hinten',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '500.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
            'damage_image_document_ids' => $documentIds,
        ]);

        $token = Str::random(64);

        WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => hash('sha256', $token),
            'workshop_label' => 'Karosserie '.Str::random(5),
            'show_appraisal_amounts' => true,
            'expires_at' => now()->addDays(14),
        ]);

        return [$order, $token, $documentIds[0]];
    }
}
