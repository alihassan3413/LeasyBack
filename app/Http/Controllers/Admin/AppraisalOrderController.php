<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Modules\UserProfile\Order\Services\AccidentDamageAttachmentService;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Operations' side of a Gutachten (Vehicle Condition Appraisal brief):
 * confirm or adjust the appointment, coordinate the inspection site and the
 * transport, and add the final appraisal report — which completes the order.
 * Admin-only through the route's admin middleware group.
 */
class AppraisalOrderController extends Controller
{
    /** The fields of OrderCollectionService::adminRules() this form sends. */
    private const SCHEDULE_FIELDS = [
        'confirmed_collection_date',
        'confirmed_time_from',
        'confirmed_time_to',
        'inspection_site_name',
        'inspection_site_address',
        'transport_confirmed',
        'internal_note',
    ];

    public function __construct(
        private readonly OrderCollectionService $collections,
        private readonly AccidentDamageAttachmentService $attachments,
        private readonly TransitionOrderStatus $transitionOrderStatus,
    ) {}

    /**
     * PATCH admin/orders/{orderId}/appraisal/schedule
     *
     * Saving a date together with a full time window is what schedules the
     * order — OrderCollectionService does that, exactly as for an Überführung.
     */
    public function schedule(Request $request, string $orderId): RedirectResponse
    {
        $order = $this->appraisalOrder($orderId);
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->firstOrFail();

        $validated = $request->validate(Arr::only(OrderCollectionService::adminRules(), self::SCHEDULE_FIELDS));

        $this->collections->updateByAdmin($order, $vehicle, $request->user(), $validated);

        return back()->with('success', 'Termin und Prüfstelle gespeichert.');
    }

    /**
     * POST admin/orders/{orderId}/appraisal/report
     *
     * The report completes a scheduled order. On an order that is already
     * completed it is simply added, so a corrected report can follow.
     */
    public function storeReport(Request $request, string $orderId): RedirectResponse
    {
        $order = $this->appraisalOrder($orderId);

        $request->validate(AccidentDamageAttachmentService::finalDocumentRules(), [
            'files.required' => 'Bitte wählen Sie mindestens eine Datei.',
            'files.*.max' => 'Eine Datei ist größer als 20 MB.',
            'files.*.mimes' => 'Erlaubt sind PDF, Bilder (JPG, PNG, WEBP, HEIC) und Word-Dateien.',
        ]);

        if (! in_array($order->order_status, ['confirmed', 'completed'], true)) {
            throw ValidationException::withMessages([
                'files' => 'Das Gutachten kann erst hinzugefügt werden, wenn der Termin bestätigt ist.',
            ]);
        }

        $user = $request->user();

        $this->attachments->storeFinalDocuments(
            $order,
            $user,
            array_values(array_filter((array) $request->file('files', []))),
        );

        if ($order->order_status !== 'confirmed') {
            return back()->with('success', 'Gutachten gespeichert.');
        }

        ($this->transitionOrderStatus)($order, 'completed', 'admin', $user->name ?? $user->email, $user->id, $request->ip());

        return back()->with('success', 'Gutachten gespeichert. Der Auftrag ist abgeschlossen.');
    }

    /**
     * DELETE admin/orders/appraisal/report/{attachmentId}
     *
     * A completed order always keeps a report: the last one can only be
     * removed after its replacement has been uploaded.
     */
    public function destroyReport(Request $request, string $attachmentId): RedirectResponse
    {
        $file = OrderAttachment::findOrFail($attachmentId);
        $order = $this->appraisalOrder((string) $file->order_id);

        $reports = OrderAttachment::where('order_id', $order->id)
            ->where('kind', OrderAttachment::KIND_FINAL_DOCUMENT)
            ->count();

        if ($order->order_status === 'completed' && $file->kind === OrderAttachment::KIND_FINAL_DOCUMENT && $reports <= 1) {
            throw ValidationException::withMessages([
                'files' => 'Ein abgeschlossener Auftrag braucht ein Gutachten. Laden Sie zuerst das neue Gutachten hoch und entfernen Sie danach das alte.',
            ]);
        }

        $this->attachments->deleteFinalDocument($file, $request->user());

        return back()->with('success', 'Gutachten entfernt.');
    }

    private function appraisalOrder(string $orderId): LeasybackOrder
    {
        $order = LeasybackOrder::findOrFail($orderId);

        abort_unless(TransitionOrderStatus::isAppraisalOrder($order), 404);

        return $order;
    }
}
