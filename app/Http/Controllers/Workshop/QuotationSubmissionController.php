<?php

namespace App\Http\Controllers\Workshop;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationPdf;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'quotation' => $this->workshopQuotationService->publicPayload($quotation, $token),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $quotation = $this->workshopQuotationService->findOpenByToken($token);

        abort_if($quotation === null, 404);

        $positionIds = AppraisalPosition::where('order_id', $quotation->order_id)->pluck('id')->all();

        $validated = $request->validate(
            WorkshopQuotationService::submissionRules($positionIds),
            WorkshopQuotationService::submissionMessages(),
            WorkshopQuotationService::submissionAttributes(),
        );

        $this->workshopQuotationService->submit($quotation, $validated);

        return redirect()->route('workshop.quotations.thanks');
    }

    public function image(Request $request, string $token, string $documentId): StreamedResponse
    {
        $quotation = $this->workshopQuotationService->findOpenByToken($token);

        abort_if($quotation === null, 404);

        $image = $this->workshopQuotationService->damageImage($quotation, $documentId, $request->query('size') === 'thumb');

        abort_if($image === null, 404);

        return Storage::disk('documents')->response($image['path'], "schadenbild-{$documentId}", [
            'Content-Type' => $image['content_type'],
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The quotation as a PDF business document.
     *
     * Resolved through findOpenByToken() like every other action here, so an
     * unknown, revoked, expired or already-submitted token gets the same 404
     * page as the form itself. No order, vehicle or quotation id is accepted
     * from the request.
     *
     * `inline` rather than an attachment: the browser opens it in a tab, which
     * is what makes "Drucken" work without a second print-only document. The
     * download button asks for the same URL with ?download=1.
     */
    public function pdf(Request $request, string $token, WorkshopQuotationPdf $pdf): HttpResponse
    {
        $quotation = $this->workshopQuotationService->findOpenByToken($token);

        abort_if($quotation === null, 404);

        return $this->pdfResponse($request, $pdf, $quotation, null);
    }

    /**
     * The same document, printed from what the form currently holds rather
     * than from what has been submitted — so a workshop can print or save its
     * prices before sending them. Nothing in the request is stored.
     */
    public function pdfDraft(Request $request, string $token, WorkshopQuotationPdf $pdf): HttpResponse
    {
        $quotation = $this->workshopQuotationService->findOpenByToken($token);

        abort_if($quotation === null, 404);

        $positionIds = AppraisalPosition::where('order_id', $quotation->order_id)->pluck('id')->all();

        $draft = $request->validate(
            WorkshopQuotationService::draftRules($positionIds),
            WorkshopQuotationService::submissionMessages(),
            WorkshopQuotationService::submissionAttributes(),
        );

        return $this->pdfResponse($request, $pdf, $quotation, $draft);
    }

    /**
     * @param  array<string, mixed>|null  $draft
     */
    private function pdfResponse(Request $request, WorkshopQuotationPdf $pdf, WorkshopQuotation $quotation, ?array $draft): HttpResponse
    {
        $filename = $pdf->filename($quotation);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf->render($quotation, $draft), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function thanks(): Response
    {
        return Inertia::render('Workshop/QuotationThanks');
    }
}
