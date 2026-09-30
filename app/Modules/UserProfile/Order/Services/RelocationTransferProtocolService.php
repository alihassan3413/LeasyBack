<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Überführung's Übergabeprotokoll (transfer protocol).
 *
 * Traffic-light spec, 30 September 2026: saving the protocol — as a link OR as
 * an uploaded PDF, either one is enough — completes the second Admin task and
 * the whole order. The order is set to `completed` in the same step; there is
 * no separate final Admin task.
 *
 * The protocol is saved on the order before the order is closed, and both
 * happen in one transaction, so a failed save never leaves a completed order
 * without its protocol. A repeated save on an already completed order changes
 * nothing and completes nothing a second time.
 */
class RelocationTransferProtocolService
{
    public const DISK = 'documents';

    /** Statuses in which the protocol can be saved: scheduled, or a row from the first relocation version. */
    public const SAVEABLE_STATUSES = ['confirmed', 'vehicle_collected', 'vehicle_returned', 'invoice_processed'];

    public function __construct(private readonly TransitionOrderStatus $transitionOrderStatus) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'url' => ['nullable', 'url:http,https', 'max:2048', 'required_without:file'],
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:20480', 'required_without:url'],
        ];
    }

    public function save(LeasybackOrder $order, User $user, ?string $url, ?UploadedFile $file): LeasybackOrder
    {
        $order = $order->fresh() ?? $order;

        if (! TransitionOrderStatus::isRelocationOrder($order)) {
            throw ValidationException::withMessages([
                'file' => 'Ein Übergabeprotokoll gibt es nur bei einer Überführung.',
            ]);
        }

        // Saving again after completion must not complete anything a second time.
        if ($order->order_status === 'completed') {
            return $order;
        }

        if (! in_array($order->order_status, self::SAVEABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'file' => 'Das Übergabeprotokoll kann erst hinzugefügt werden, wenn die Überführung terminiert ist.',
            ]);
        }

        $url = $url === null || trim($url) === '' ? null : trim($url);

        if ($url === null && $file === null) {
            throw ValidationException::withMessages([
                'url' => 'Bitte geben Sie einen Link an oder laden Sie ein PDF hoch.',
            ]);
        }

        if (! DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->exists()) {
            throw ValidationException::withMessages([
                'file' => 'Für diese Überführung ist noch kein Termin gespeichert.',
            ]);
        }

        // Stored first, outside the transaction; removed again if anything
        // after it fails, so no orphaned file is left behind.
        $path = $file?->storeAs(
            'transfer-protocols/'.$order->vehicle_id,
            Str::uuid().'.pdf',
            self::DISK,
        );

        try {
            return DB::transaction(function () use ($order, $user, $url, $file, $path) {
                DB::table('leasyback_order_logistics')
                    ->where('auftragsnummer', $order->auftragsnummer)
                    ->update([
                        'transfer_protocol_url' => $path === null ? $url : null,
                        'transfer_protocol_path' => $path ?: null,
                        'transfer_protocol_original_name' => $file?->getClientOriginalName(),
                        'transfer_protocol_saved_at' => now(),
                        'transfer_protocol_saved_by_user_id' => $user->id,
                        'updated_by_user_id' => $user->id,
                    ]);

                OrderAuditLog::create([
                    'order_id' => $order->id,
                    'vehicle_id' => $order->vehicle_id,
                    'action' => 'TRANSFER_PROTOCOL_SAVED',
                    'old_values' => null,
                    'new_values' => ['format' => $path ? 'pdf' : 'link'],
                    'changed_by_user_id' => $user->id,
                ]);

                return $this->transitionOrderStatus->__invoke(
                    $order,
                    'completed',
                    'admin',
                    $user->name ?? $user->email,
                    $user->id,
                    request()?->ip(),
                );
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $e;
        }
    }
}