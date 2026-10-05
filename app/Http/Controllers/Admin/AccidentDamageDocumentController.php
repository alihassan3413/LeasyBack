<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Modules\UserProfile\Order\Services\AccidentDamageAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Operations add (and remove) the final accident damage documentation.
 * Admin-only through the route's admin middleware group.
 */
class AccidentDamageDocumentController extends Controller
{
    public function __construct(private readonly AccidentDamageAttachmentService $attachments) {}

    /** POST admin/orders/{orderId}/accident-documents */
    public function store(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::findOrFail($orderId);

        $request->validate(AccidentDamageAttachmentService::finalDocumentRules(), [
            'files.required' => 'Bitte wählen Sie mindestens eine Datei.',
            'files.*.max' => 'Eine Datei ist größer als 20 MB.',
            'files.*.mimes' => 'Erlaubt sind PDF, Bilder (JPG, PNG, WEBP, HEIC) und Word-Dateien.',
        ]);

        $count = $this->attachments->storeFinalDocuments(
            $order,
            $request->user(),
            array_values(array_filter((array) $request->file('files', []))),
        );

        return back()->with('success', $count === 1 ? 'Abschlussdokument gespeichert.' : "{$count} Abschlussdokumente gespeichert.");
    }

    /** DELETE admin/orders/accident-documents/{attachmentId} */
    public function destroy(Request $request, string $attachmentId): RedirectResponse
    {
        $file = OrderAttachment::findOrFail($attachmentId);

        $this->attachments->deleteFinalDocument($file, $request->user());

        return back()->with('success', 'Abschlussdokument entfernt.');
    }
}