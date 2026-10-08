<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Modules\UserProfile\Admin\Services\VehicleReportService;
use App\Modules\UserProfile\Order\Services\B2bBillingService;
use App\Modules\UserProfile\Order\Services\B2bLexwareDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderBillingController extends Controller
{
    public function __construct(
        private readonly B2bBillingService $b2bBillingService,
        private readonly B2bLexwareDraftService $lexwareDraftService,
        private readonly VehicleReportService $documents,
    ) {}

    /**
     * Records the internal billing state of a B2B order. B2B only — the same
     * 404-on-persisted-vehicle-type rule the other B2B order endpoints use.
     */
    public function update(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        abort_unless($vehicle !== null && $vehicle->vehicle_belongs === 'B2B', 404);

        $validated = $request->validate([
            ...B2bBillingService::rules($this->b2bBillingService->allowedDocumentIds($order)),
            // Publishing the invoice and closing the billing are one action to
            // the admin — "the company has its invoice" — so they travel in one
            // request rather than leaving the two states to drift apart.
            'publish_invoice_document' => ['nullable', 'boolean'],
        ]);

        $this->b2bBillingService->update($order, $vehicle, $request->user(), $validated);

        if (($validated['publish_invoice_document'] ?? false) && ($validated['invoice_document_id'] ?? null) !== null) {
            $this->documents->publish($validated['invoice_document_id'], true, $request->user());
        }

        return back()->with('success', 'Abrechnung wurde gespeichert.');
    }

    /**
     * b2b.txt §13: creates the invoice in Lexware, finalized, and files its PDF
     * with the order's documents (unpublished) — the same as the B2C invoice.
     */
    public function lexwareDraft(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $validated = $request->validate(B2bLexwareDraftService::rules());

        try {
            $invoice = $this->lexwareDraftService->create($order, $request->user(), $validated);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        $number = $invoice->voucher_number ? ' '.$invoice->voucher_number : '';

        // The invoice exists either way; only its PDF may still be missing.
        if ($invoice->document_id === null) {
            return back()->with('error', "Die Rechnung{$number} wurde in Lexware erstellt, das PDF konnte aber noch nicht abgerufen werden. Bitte „Rechnung aus Lexware abrufen\u{201C} erneut versuchen.");
        }

        return back()->with('success', "Die Rechnung{$number} wurde in Lexware erstellt und abgelegt. Veröffentlichen Sie sie, damit das Unternehmen sie sieht.");
    }

    /**
     * Fetches the PDF of a Lexware invoice that has none yet (an older draft
     * finalized in Lexware, or a PDF that could not be fetched at creation) and
     * files it with the order's documents, still unpublished.
     */
    public function lexwareFinalize(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        try {
            $this->lexwareDraftService->finalize($order, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Die Rechnung wurde abgerufen und abgelegt. Veröffentlichen Sie sie, damit das Unternehmen sie sieht.');
    }
}
