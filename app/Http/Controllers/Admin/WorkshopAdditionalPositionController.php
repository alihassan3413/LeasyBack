<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\WorkshopAdditionalPosition;
use App\Modules\UserProfile\Order\Services\WorkshopAdditionalPositionReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin accepts or rejects damage a workshop reported in addition to the
 * Gutachten. Refusals come back under `additional_position`, which the
 * comparison view on the Werkstattangebote card shows.
 */
class WorkshopAdditionalPositionController extends Controller
{
    public function __construct(private readonly WorkshopAdditionalPositionReviewService $reviews) {}

    public function accept(Request $request, string $positionId): RedirectResponse
    {
        $position = $this->position($positionId);

        try {
            $created = $this->reviews->accept($position, $request->user());
        } catch (ValidationException $e) {
            return $this->refused($e);
        }

        return back()->with('success', sprintf('„%s" wurde als Gutachtenposition übernommen.', $created->component));
    }

    public function reject(Request $request, string $positionId): RedirectResponse
    {
        $position = $this->position($positionId);

        try {
            $this->reviews->reject($position, $request->user());
        } catch (ValidationException $e) {
            return $this->refused($e);
        }

        return back()->with('success', 'Zusätzlicher Schaden wurde abgelehnt.');
    }

    private function position(string $positionId): WorkshopAdditionalPosition
    {
        $position = WorkshopAdditionalPosition::find($positionId);
        abort_if($position === null, 404);

        return $position;
    }

    private function refused(ValidationException $e): RedirectResponse
    {
        $message = collect($e->errors())->flatten()->first() ?? 'Die Aktion war nicht möglich.';

        return back()->withErrors(['additional_position' => $message])->with('error', $message);
    }
}