<?php

namespace App\Modules\UserProfile\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyStripeWebhookSignature;
use App\Modules\UserProfile\Payment\Services\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    public function __construct(private readonly StripeWebhookService $webhooks) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = (array) $request->attributes->get(VerifyStripeWebhookSignature::EVENT_ATTRIBUTE, []);

        try {
            $this->webhooks->handle($event);
        } catch (\Throwable $e) {
            // Answering 5xx would make Stripe redeliver indefinitely. Record it
            // and acknowledge; payments:reconcile is what recovers state.
            Log::error('Stripe webhook handling failed.', [
                'event_id' => $event['id'] ?? null,
                'type' => $event['type'] ?? null,
                'exception' => $e->getMessage(),
            ]);
        }

        return response()->json(['received' => true]);
    }
}
