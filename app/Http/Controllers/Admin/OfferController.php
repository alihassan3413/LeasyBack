<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOffer;
use App\Models\LeasybackOrder;
use App\Modules\UserProfile\Offer\Services\OfferService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offerService) {}

    public function store(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $validated = $request->validate([
            'repair_cost_net' => 'required|numeric|min:0',
            'repair_cost_gross' => 'required|numeric|min:0',
            'depreciation_value_net' => 'required|numeric|min:0',
            'depreciation_value_gross' => 'required|numeric|min:0',
            'workshop_repair_quote_net' => 'required|numeric|min:0',
            'workshop_repair_quote_gross' => 'required|numeric|min:0',
            'missing_parts_cost_net' => 'required|numeric|min:0',
            'missing_parts_cost_gross' => 'required|numeric|min:0',
            'additional_notes' => 'nullable|string',
        ]);

        $this->offerService->createOffer($order, $validated, $request->user());

        return back()->with('success', 'Angebot wurde erstellt.');
    }

    public function publish(Request $request, string $offerId): RedirectResponse
    {
        $offer = LeasybackOffer::find($offerId);
        abort_unless($offer !== null, 404);

        try {
            $this->offerService->publishOffer($offer, $request->user());
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Angebot konnte nicht veröffentlicht werden.';

            return back()->withErrors(['offer' => $message])->with('error', $message);
        }

        return back()->with('success', 'Angebot wurde veröffentlicht.');
    }

    public function cancel(Request $request, string $offerId): RedirectResponse
    {
        $validated = $request->validate(['cancellation_reason' => 'nullable|string']);

        $offer = LeasybackOffer::find($offerId);
        abort_unless($offer !== null, 404);

        $this->offerService->cancelOffer($offer, $validated['cancellation_reason'] ?? null, $request->user());

        return back()->with('success', 'Angebot wurde storniert.');
    }
}
