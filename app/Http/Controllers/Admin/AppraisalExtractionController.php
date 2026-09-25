<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentType;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\AppraisalExtractionService;
use App\Modules\UserProfile\Order\Services\AppraisalPositionService;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppraisalExtractionController extends Controller
{
    use HandlesServiceValidationErrors;

    private const EXTRACTABLE_DOCUMENT_TYPES = [
        DocumentType::Gutachten->value,
        DocumentType::Nachgutachten->value,
    ];

    public function __construct(
        private readonly AppraisalExtractionService $appraisalExtractions,
        private readonly AppraisalPositionService $appraisalPositions,
    ) {}

    public function store(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $validated = $request->validate([
            'document_id' => [
                'required',
                'uuid',
                Rule::exists('vehicle_report_documents', 'id')
                    ->where('auftragsnummer', $order->auftragsnummer)
                    ->where('vehicle_id', $order->vehicle_id)
                    ->whereIn('document_type', self::EXTRACTABLE_DOCUMENT_TYPES),
            ],
        ], [
            'document_id.exists' => 'Nur Gutachten dieses Auftrags können ausgelesen werden.',
        ]);

        $document = VehicleReportDocument::whereKey($validated['document_id'])->firstOrFail();

        return $this->withServiceErrorHandling('document_id', function () use ($order, $document, $request) {
            $this->appraisalExtractions->start($order, $document, $request->user());
        }) ?? back()->with('success', 'Das Gutachten wird ausgelesen. Der Vorschlag erscheint, sobald die Auslese abgeschlossen ist.');
    }

    public function apply(Request $request, string $extractionId): RedirectResponse
    {
        $extraction = AppraisalExtraction::find($extractionId);
        abort_unless($extraction !== null, 404);

        $order = LeasybackOrder::whereKey($extraction->order_id)->first();
        abort_unless($order !== null, 404);

        $validated = $request->validate(
            AppraisalExtractionService::applyRules($this->appraisalPositions->allowedDocumentIds($order)),
        );

        $created = 0;

        $denied = $this->withServiceErrorHandling('positions', function () use ($extraction, $request, $validated, &$created) {
            $created = $this->appraisalExtractions->apply($extraction, $request->user(), $validated);
        });

        if ($denied) {
            return $denied;
        }

        return back()->with('success', $created === 1
            ? '1 Gutachtenposition wurde übernommen.'
            : "{$created} Gutachtenpositionen wurden übernommen.");
    }
}
