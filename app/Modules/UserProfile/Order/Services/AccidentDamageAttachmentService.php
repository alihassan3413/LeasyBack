<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Support\PortalTimestamp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The files of an Unfallschaden (Accident Damage brief), and the final report
 * of a Gutachten (Vehicle Condition Appraisal brief), which is kept the same way:
 *
 * - the customer's supporting files from the report form, and
 * - the final accident damage documentation operations add.
 *
 * Operations always see both. A customer sees their own files, and the final
 * documentation once the order is completed ("Make final accident damage
 * documentation available in the order when the service is completed").
 */
class AccidentDamageAttachmentService
{
    private const APPRAISAL_SERVICE_TYPE = 'gutachten';

    /** Upload rules for the final documentation (same 20 MB limit as the form). */
    public static function finalDocumentRules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.OrderService::MAX_ATTACHMENTS],
            'files.*' => ['file', 'max:'.OrderService::MAX_ATTACHMENT_KB, 'mimes:pdf,jpg,jpeg,png,webp,heic,heif,doc,docx'],
        ];
    }

    /**
     * Files grouped by order id, each with a short-lived signed link.
     *
     * @param  array<int, string>  $orderIds
     * @param  array<string, string>  $orderStatuses  order id => status; final documents are only
     *                                                 included for completed orders unless $forOperations
     * @return array<string, list<array<string, mixed>>>
     */
    public function forOrders(array $orderIds, array $orderStatuses = [], bool $forOperations = false): array
    {
        if ($orderIds === []) {
            return [];
        }

        return OrderAttachment::whereIn('order_id', $orderIds)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (OrderAttachment $file) => $forOperations
                || $file->kind === OrderAttachment::KIND_CUSTOMER_UPLOAD
                || ($orderStatuses[$file->order_id] ?? null) === 'completed')
            ->groupBy('order_id')
            ->map(fn ($files) => $files->map(fn (OrderAttachment $file) => self::present($file))->values()->all())
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(OrderAttachment $file): array
    {
        try {
            $url = Storage::disk(OrderAttachment::DISK)->temporaryUrl($file->path, now()->addMinutes(30));
        } catch (Throwable) {
            $url = null;
        }

        return [
            'id' => $file->id,
            'kind' => $file->kind,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'url' => $url,
            'created_at' => PortalTimestamp::iso($file->created_at),
        ];
    }

    /**
     * Operations add final documentation. Allowed while the order is open or
     * completed; the order's status is not changed here — completing it is a
     * separate step (brief: operations "update the three statuses").
     *
     * @param  list<UploadedFile>  $files
     */
    public function storeFinalDocuments(LeasybackOrder $order, User $user, array $files): int
    {
        if (! TransitionOrderStatus::isAccidentDamageOrder($order) && ! self::isAppraisal($order)) {
            throw ValidationException::withMessages(['files' => 'Abschlussdokumente gibt es nur bei einem Unfallschaden oder einem Gutachten.']);
        }

        if (in_array($order->order_status, ['cancelled', 'discarded'], true)) {
            throw ValidationException::withMessages(['files' => 'Zu einem stornierten Auftrag können keine Dokumente hinzugefügt werden.']);
        }

        $stored = [];

        try {
            DB::transaction(function () use ($order, $user, $files, &$stored) {
                foreach ($files as $file) {
                    $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
                    $path = $file->storeAs(self::folder($order).'/'.$order->id.'/final', Str::uuid().'.'.$extension, OrderAttachment::DISK);

                    if ($path === false) {
                        throw new RuntimeException('A final document could not be stored.');
                    }

                    $stored[] = $path;

                    OrderAttachment::create([
                        'order_id' => $order->id,
                        'auftragsnummer' => $order->auftragsnummer,
                        'kind' => OrderAttachment::KIND_FINAL_DOCUMENT,
                        'original_name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'mime_type' => $file->getClientMimeType(),
                        'size' => (int) $file->getSize(),
                        'uploaded_by_user_id' => $user->id,
                    ]);
                }

                OrderAuditLog::create([
                    'order_id' => $order->id,
                    'vehicle_id' => $order->vehicle_id,
                    'action' => self::isAppraisal($order) ? 'APPRAISAL_REPORT_ADDED' : 'ACCIDENT_FINAL_DOCUMENTS_ADDED',
                    'old_values' => null,
                    'new_values' => ['count' => count($files)],
                    'changed_by_user_id' => $user->id,
                ]);
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk(OrderAttachment::DISK)->delete($path);
            }

            throw $e;
        }

        return count($files);
    }

    private static function isAppraisal(LeasybackOrder $order): bool
    {
        return $order->service_type === self::APPRAISAL_SERVICE_TYPE;
    }

    /** One folder per service, so the two kinds of final document stay apart on disk. */
    private static function folder(LeasybackOrder $order): string
    {
        return self::isAppraisal($order) ? 'appraisal' : 'accident-damage';
    }

    /** Removes a final document. Customer uploads are the customer's record and stay. */
    public function deleteFinalDocument(OrderAttachment $file, User $user): void
    {
        if ($file->kind !== OrderAttachment::KIND_FINAL_DOCUMENT) {
            throw ValidationException::withMessages(['files' => 'Vom Kunden hochgeladene Dateien können nicht gelöscht werden.']);
        }

        Storage::disk(OrderAttachment::DISK)->delete($file->path);
        $file->delete();

        $order = LeasybackOrder::find($file->order_id);

        OrderAuditLog::create([
            'order_id' => $file->order_id,
            'vehicle_id' => $order?->vehicle_id,
            'action' => $order !== null && self::isAppraisal($order) ? 'APPRAISAL_REPORT_REMOVED' : 'ACCIDENT_FINAL_DOCUMENT_REMOVED',
            'old_values' => ['original_name' => $file->original_name],
            'new_values' => null,
            'changed_by_user_id' => $user->id,
        ]);
    }
}