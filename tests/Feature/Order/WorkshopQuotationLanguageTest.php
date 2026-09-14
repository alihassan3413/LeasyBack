<?php

namespace Tests\Feature\Order;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The workshop's form is entirely German, so its validation must be too — an
 * English framework default is the one thing that breaks that page.
 */
class WorkshopQuotationLanguageTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    public function test_a_missing_contact_person_is_reported_in_german(): void
    {
        Mail::fake();

        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'order_status' => 'inspected']);

        $invite = app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => 'Karosserie Meier GmbH']);

        $response = $this->post(route('workshop.quotations.submit', $invite['token']), [
            'company_name' => 'Karosserie Meier GmbH',
            'contact_person' => '',
            'contact_email' => 'jens@werkstatt.test',
            'items' => [],
        ]);

        $errors = session('errors')->getBag('default');

        $this->assertSame('Ansprechpartner muss ausgefüllt werden.', $errors->first('contact_person'));
        $this->assertStringNotContainsString('field is required', $errors->first('contact_person'));
    }

    public function test_an_invalid_email_is_reported_in_german(): void
    {
        Mail::fake();

        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'order_status' => 'inspected']);

        $invite = app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => 'Karosserie Meier GmbH']);

        $this->post(route('workshop.quotations.submit', $invite['token']), [
            'company_name' => 'Karosserie Meier GmbH',
            'contact_person' => 'Jens Meier',
            'contact_email' => 'keine-mail',
            'items' => [],
        ]);

        $this->assertSame(
            'E-Mail muss eine gültige E-Mail-Adresse sein.',
            session('errors')->getBag('default')->first('contact_email'),
        );
    }
}
