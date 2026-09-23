<?php

namespace Tests\Feature\Order;

use App\Enums\DocumentType;
use App\Modules\UserProfile\Order\Services\OrderTaskResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The task action contract: every actionable task must carry a primary action
 * that resolves to a real workflow, already configured for that task.
 *
 * Driven directly against the resolver rather than through the admin page,
 * because the contract is what the resolver emits — the Vue registry only maps
 * `key` to a component.
 */
class OrderTaskActionContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides = []): array
    {
        return [
            'id' => '11111111-1111-1111-1111-111111111111',
            'vehicle_belongs' => 'B2C',
            'order_status' => 'inspected',
            'status_updates' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    private function nextTask(array $order): ?array
    {
        return app(OrderTaskResolver::class)->forOrderDetail($order)['next'];
    }

    private function publishedReport(string $type): array
    {
        return ['document_type' => $type, 'published' => true, 'created_at' => '2026-08-01T10:00:00+00:00'];
    }

    // ---- the shape every action must have ---------------------------------

    /**
     * Walks both channels across every status and asserts the contract holds
     * for whatever task surfaces, so a new or edited definition cannot ship a
     * malformed action.
     *
     * @param  array<string, mixed>  $order
     */
    #[DataProvider('everyChannelAndStatus')]
    public function test_every_surfaced_task_action_satisfies_the_contract(array $order): void
    {
        $task = $this->nextTask($order);

        if ($task === null || $task['action'] === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $action = $task['action'];

        $this->assertArrayHasKey('type', $action);
        $this->assertArrayHasKey('key', $action);
        $this->assertArrayHasKey('label', $action);
        $this->assertContains($action['type'], [
            OrderTaskResolver::ACTION_REQUEST,
            OrderTaskResolver::ACTION_MODAL,
            OrderTaskResolver::ACTION_INLINE,
        ], "task {$task['key']} has an unknown action type");

        $this->assertNotSame('', trim((string) $action['label']), "task {$task['key']} has an empty label");
        $this->assertNotSame('', trim((string) $action['key']), "task {$task['key']} has an empty action key");

        if ($action['type'] === OrderTaskResolver::ACTION_REQUEST) {
            $this->assertNotNull($action['url'], "request task {$task['key']} has no url");
            $this->assertContains($action['method'], ['post', 'patch']);
        } else {
            // A modal or inline action is carried out in the browser, so a url
            // would be a second, contradictory way to perform the same task.
            $this->assertNull($action['url'], "{$action['type']} task {$task['key']} must not carry a url");
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function everyChannelAndStatus(): array
    {
        $b2c = ['order_requested', 'order_placed', 'confirmed', 'inspected', 'workshop_commissioned', 'workshop', 'reworkshop', 'reinspection', 'delivered', 'completed'];
        $b2b = ['order_requested', 'order_placed', 'confirmed', 'vehicle_collected', 'inspected', 'workshop_commissioned', 'workshop', 'repair_completed', 'reinspection', 'vehicle_returned', 'invoice_processed', 'completed'];

        $cases = [];

        foreach (['B2C' => $b2c, 'B2B' => $b2b] as $channel => $statuses) {
            foreach ($statuses as $status) {
                $cases["{$channel} {$status}"] = [[
                    'id' => '11111111-1111-1111-1111-111111111111',
                    'vehicle_belongs' => $channel,
                    'order_status' => $status,
                    'status_updates' => [],
                ]];
            }
        }

        return $cases;
    }

    // ---- the presets that actually matter ---------------------------------

    /**
     * The regression behind this work: filing the follow-up report as a
     * Gutachten leaves the task open with no visible cause, so the task must
     * open the uploader already set to `nachgutachten`.
     */
    public function test_upload_final_appraisal_opens_the_uploader_preset_to_nachgutachten(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'reinspection',
            'report_documents' => [$this->publishedReport(DocumentType::Gutachten->value)],
        ]));

        $this->assertSame('upload_final_appraisal', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_MODAL, $task['action']['type']);
        $this->assertSame(OrderTaskResolver::UI_UPLOAD_REPORT, $task['action']['key']);
        $this->assertSame(DocumentType::Nachgutachten->value, $task['action']['payload']['document_type']);
        $this->assertSame('Nachgutachten hochladen', $task['action']['label']);
    }

    /**
     * The title travels with the preset so the modal cannot say "Gutachten
     * hochladen" over a Nachgutachten form.
     */
    public function test_the_upload_preset_carries_a_matching_modal_title(): void
    {
        $final = $this->nextTask($this->order([
            'order_status' => 'reinspection',
            'report_documents' => [$this->publishedReport(DocumentType::Gutachten->value)],
        ]));

        $initial = $this->nextTask($this->order(['order_status' => 'confirmed']));

        $this->assertSame('Nachgutachten hochladen', $final['action']['payload']['title']);
        $this->assertStringContainsString('Erstgutachten', $initial['action']['payload']['title']);
        $this->assertNotSame($initial['action']['payload']['title'], $final['action']['payload']['title']);
    }

    public function test_upload_initial_appraisal_opens_the_uploader_preset_to_gutachten(): void
    {
        $task = $this->nextTask($this->order(['order_status' => 'confirmed']));

        $this->assertSame('upload_initial_appraisal', $task['key']);
        $this->assertSame(OrderTaskResolver::UI_UPLOAD_REPORT, $task['action']['key']);
        $this->assertSame(DocumentType::Gutachten->value, $task['action']['payload']['document_type']);
    }

    public function test_the_two_upload_tasks_never_share_a_preset(): void
    {
        $initial = $this->nextTask($this->order(['order_status' => 'confirmed']));
        $final = $this->nextTask($this->order([
            'order_status' => 'reinspection',
            'report_documents' => [$this->publishedReport(DocumentType::Gutachten->value)],
        ]));

        $this->assertNotSame(
            $initial['action']['payload']['document_type'],
            $final['action']['payload']['document_type'],
        );
    }

    public function test_capture_repair_positions_focuses_the_positions_form(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'inspected',
            'report_documents' => [$this->publishedReport(DocumentType::Gutachten->value)],
        ]));

        $this->assertSame('capture_repair_positions', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_INLINE, $task['action']['type']);
        $this->assertSame(OrderTaskResolver::SECTION_POSITIONS, $task['action']['key']);
    }

    public function test_request_workshop_quotations_focuses_the_offers_section(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'inspected',
            'report_documents' => [$this->publishedReport(DocumentType::Gutachten->value)],
            'appraisal_positions' => [['id' => 'p1']],
        ]));

        $this->assertSame('request_workshop_quotations', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_INLINE, $task['action']['type']);
        $this->assertSame(OrderTaskResolver::SECTION_OFFERS, $task['action']['key']);
    }

    public function test_create_customer_offer_opens_the_offer_modal(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'inspected',
            'report_documents' => [$this->publishedReport(DocumentType::Gutachten->value)],
            'appraisal_positions' => [['id' => 'p1']],
            'workshop_quotations' => [['status' => 'submitted', 'total_net' => '600.00']],
        ]));

        $this->assertSame('create_customer_offer', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_MODAL, $task['action']['type']);
        $this->assertSame(OrderTaskResolver::UI_CREATE_OFFER, $task['action']['key']);
    }

    // ---- request tasks keep working exactly as before ----------------------

    public function test_evaluate_reinspection_still_posts_the_status_transition(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'reinspection',
            'report_documents' => [
                $this->publishedReport(DocumentType::Gutachten->value),
                $this->publishedReport(DocumentType::Nachgutachten->value),
            ],
        ]));

        $this->assertSame('evaluate_reinspection', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_REQUEST, $task['action']['type']);
        $this->assertSame('patch', $task['action']['method']);
        $this->assertSame('delivered', $task['action']['payload']['status']);
    }

    public function test_confirm_pickup_still_posts_the_completion_transition(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'delivered',
            'repair_payment' => ['status' => 'paid', 'blocks_pickup' => false],
            // A paid repair owes the customer an invoice, and that step comes
            // first; with it satisfied the handover is what remains.
            'report_documents' => [$this->publishedReport(DocumentType::Rechnung->value)],
        ]));

        $this->assertSame('confirm_pickup', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_REQUEST, $task['action']['type']);
        $this->assertSame('completed', $task['action']['payload']['status']);
    }

    public function test_provide_invoice_opens_the_upload_modal(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'delivered',
            'repair_payment' => ['status' => 'paid', 'blocks_pickup' => false],
        ]));

        $this->assertSame('provide_invoice', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_MODAL, $task['action']['type']);
        $this->assertSame(OrderTaskResolver::UI_UPLOAD_REPORT, $task['action']['key']);
        $this->assertSame(DocumentType::Rechnung->value, $task['action']['payload']['document_type']);
    }

    // ---- informational tasks stay actionless ------------------------------

    /**
     * An outstanding repair payment is the customer's move and there is no
     * admin-side payment UI, so offering a button would be offering nothing.
     */
    public function test_await_repair_payment_is_informational(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'delivered',
            'repair_payment' => ['status' => 'requires_manual_collection', 'blocks_pickup' => true],
        ]));

        $this->assertSame('await_repair_payment', $task['key']);
        $this->assertNull($task['action']);
        $this->assertSame('customer', $task['actor']);
    }

    public function test_awaiting_a_customer_decision_is_informational(): void
    {
        $task = $this->nextTask($this->order([
            'order_status' => 'inspected',
            'report_documents' => [$this->publishedReport(DocumentType::Gutachten->value)],
            'appraisal_positions' => [['id' => 'p1']],
            'offers' => [['offer_id' => 'o1', 'offer_status' => 'published', 'published_at' => '2026-08-01T10:00:00+00:00']],
        ]));

        $this->assertSame('await_customer_decision', $task['key']);
        $this->assertNull($task['action']);
    }

    // ---- B2B keeps its own tree -------------------------------------------

    public function test_b2b_upload_tasks_carry_the_same_presets(): void
    {
        $initial = $this->nextTask($this->order([
            'vehicle_belongs' => 'B2B',
            'order_status' => 'vehicle_collected',
        ]));

        $this->assertSame('upload_initial_appraisal', $initial['key']);
        $this->assertSame(DocumentType::Gutachten->value, $initial['action']['payload']['document_type']);
    }

    public function test_b2b_confirm_collection_focuses_its_own_section(): void
    {
        $task = $this->nextTask($this->order([
            'vehicle_belongs' => 'B2B',
            'order_status' => 'confirmed',
        ]));

        $this->assertSame('confirm_collection', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_INLINE, $task['action']['type']);
        $this->assertSame(OrderTaskResolver::SECTION_COLLECTION, $task['action']['key']);
    }

    public function test_b2b_prepare_invoice_focuses_the_billing_section(): void
    {
        $task = $this->nextTask($this->order([
            'vehicle_belongs' => 'B2B',
            'order_status' => 'vehicle_returned',
        ]));

        $this->assertSame('prepare_invoice', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTION_INLINE, $task['action']['type']);
        $this->assertSame(OrderTaskResolver::SECTION_BILLING, $task['action']['key']);
    }

    /**
     * The two trees stay distinct: B2C has no collection step, B2B has no
     * repair-payment step.
     */
    public function test_the_channels_do_not_leak_each_others_tasks(): void
    {
        $b2cKeys = [];
        $b2bKeys = [];

        foreach (['order_placed', 'confirmed', 'inspected', 'reinspection', 'delivered'] as $status) {
            $b2cKeys[] = $this->nextTask($this->order(['order_status' => $status]))['key'] ?? null;
        }

        foreach (['order_placed', 'confirmed', 'vehicle_collected', 'reinspection', 'vehicle_returned'] as $status) {
            $b2bKeys[] = $this->nextTask($this->order(['vehicle_belongs' => 'B2B', 'order_status' => $status]))['key'] ?? null;
        }

        $this->assertNotContains('confirm_collection', $b2cKeys);
        $this->assertNotContains('await_repair_payment', $b2bKeys);
        $this->assertContains('confirm_collection', $b2bKeys);
    }
}
