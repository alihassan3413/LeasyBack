<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOffer;
use App\Models\LeasybackOrder;
use App\Modules\UserProfile\Offer\Services\OfferService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
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

        // B2B is priced net only (b2b.txt §9): the manual form sends no gross,
        // so the gross columns are optional there and stored as zero. B2C still
        // has to state both.
        $isB2b = TransitionOrderStatus::isB2bOrder($order);
        $grossRule = $isB2b ? 'nullable|numeric|min:0' : 'required|numeric|min:0';

        $validated = $request->validate([
            'repair_cost_net' => 'required|numeric|min:0',
            'repair_cost_gross' => $grossRule,
            'depreciation_value_net' => 'required|numeric|min:0',
            'depreciation_value_gross' => $grossRule,
            'workshop_repair_quote_net' => 'required|numeric|min:0',
            'workshop_repair_quote_gross' => $grossRule,
            'missing_parts_cost_net' => 'required|numeric|min:0',
            'missing_parts_cost_gross' => $grossRule,
            'additional_notes' => 'nullable|string',
        ]);

        if ($isB2b) {
            foreach (['repair_cost_gross', 'depreciation_value_gross', 'workshop_repair_quote_gross', 'missing_parts_cost_gross'] as $grossField) {
                $validated[$grossField] ??= '0';
            }
        }

        try {
            $this->offerService->createOffer($order, $validated, $request->user());
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Angebot konnte nicht erstellt werden.';

            return back()->withErrors(['offer' => $message])->with('error', $message);
        }

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

    /**
     * Accept a published offer for the customer. Distinct from the customer's
     * own offers.select route, which OfferPolicy::select() still restricts to
     * the vehicle's owner — see selectOnBehalf() there for why the two are
     * separate abilities.
     */
    public function select(Request $request, string $offerId): RedirectResponse
    {
        $offer = LeasybackOffer::find($offerId);
        abort_unless($offer !== null, 404);
        abort_unless($request->user()->can('selectOnBehalf', $offer), 403);

        try {
            $result = $this->offerService->selectOffer($offer, $request->user(), onBehalfOfCustomer: true);
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Angebot konnte nicht angenommen werden.';

            return back()->withErrors(['offer' => $message])->with('error', $message);
        }

        // Covers the admin arriving second on a decision the customer already
        // made — the same offer, so nothing to do and nothing to undo.
        return back()->with('success', $result['already_selected']
            ? 'Dieses Angebot war bereits angenommen.'
            : 'Angebot wurde im Auftrag des Kunden angenommen.');
    }

    public function cancel(Request $request, string $offerId): RedirectResponse
    {
        $validated = $request->validate(['cancellation_reason' => 'nullable|string']);

        $offer = LeasybackOffer::find($offerId);
        abort_unless($offer !== null, 404);

        try {
            $this->offerService->cancelOffer($offer, $validated['cancellation_reason'] ?? null, $request->user());
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Angebot konnte nicht storniert werden.';

            return back()->withErrors(['offer' => $message])->with('error', $message);
        }

        return back()->with('success', 'Angebot wurde storniert.');
    }
}
