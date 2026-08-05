<?php

namespace App\Http\Controllers\Workshop;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workshop's side of §9. Guests only — a workshop has no account.
 *
 * The token in the URL is the sole credential, so both actions are throttled,
 * and an unusable token (unknown, expired, revoked or already submitted) is
 * always answered with the same generic 404 page rather than a message that
 * would reveal which of those it was.
 */
class QuotationSubmissionController extends Controller
{
    public function __construct(private readonly WorkshopQuotationService $workshopQuotationService) {}

    public function show(string $token): Response
    {
        $quotation = $this->workshopQuotationService->findOpenByToken($token);

        abort_if($quotation === null, 404);

        return Inertia::render('Workshop/Quotation', [
            'token' => $token,
            'quotation' => $this->workshopQuotationService->publicPayload($quotation),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $quotation = $this->workshopQuotationService->findOpenByToken($token);

        abort_if($quotation === null, 404);

        $positionIds = AppraisalPosition::where('order_id', $quotation->order_id)->pluck('id')->all();

        $validated = $request->validate(WorkshopQuotationService::submissionRules($positionIds));

        $this->workshopQuotationService->submit($quotation, $validated);

        return redirect()->route('workshop.quotations.thanks');
    }

    public function thanks(): Response
    {
        return Inertia::render('Workshop/QuotationThanks');
    }
}
