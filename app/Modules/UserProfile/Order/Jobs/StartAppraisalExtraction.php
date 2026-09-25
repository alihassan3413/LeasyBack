<?php

namespace App\Modules\UserProfile\Order\Jobs;

use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\AppraisalExtractionService;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartAppraisalExtraction implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $documentId,
        public readonly ?int $requestedByUserId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    public function handle(AppraisalExtractionService $extractions): void
    {
        $document = VehicleReportDocument::find($this->documentId);

        if ($document === null) {
            return;
        }

        $order = LeasybackOrder::where('auftragsnummer', $document->auftragsnummer)
            ->where('vehicle_id', $document->vehicle_id)
            ->orderByDesc('created_at')
            ->first();

        if ($order === null) {
            return;
        }

        try {
            $extractions->start($order, $document, $this->user());
        } catch (HttpResponseException $exception) {
            Log::info('Automatic appraisal extraction was not started', [
                'document_id' => $this->documentId,
                'order_id' => $order->id,
                'status' => $exception->getResponse()->getStatusCode(),
            ]);
        }
    }

    private function user(): ?User
    {
        return $this->requestedByUserId === null ? null : User::find($this->requestedByUserId);
    }
}
