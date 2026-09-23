<?php

namespace App\Modules\PartnerApi\Http\Middleware;

use App\Modules\PartnerApi\Enums\PartnerIdempotencyState;
use App\Modules\PartnerApi\Models\PartnerIdempotencyKey;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Services\PartnerIdempotencyService;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key handling for unsafe methods.
 *
 * `->middleware('partner.idempotent')` makes the header optional;
 * `->middleware('partner.idempotent:required')` makes it mandatory, which is
 * what a create endpoint should use — a partner that retries a timed-out
 * order creation without a key cannot be protected from creating two.
 *
 * Phase 1 exposes no unsafe endpoint. This exists now so the endpoints that
 * arrive in later phases inherit replay protection by adding one word to a
 * route definition, rather than each re-implementing it.
 *
 * Only 2xx responses are recorded. A failed request leaves the key free, so
 * the partner's retry is a real retry rather than a replay of the failure.
 */
class EnforcePartnerIdempotency
{
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __construct(
        private readonly PartnerIdempotencyService $idempotency,
        private readonly PartnerContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        if (! in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return $next($request);
        }

        $header = (string) config('partner_api.idempotency.header', 'Idempotency-Key');
        $key = trim((string) $request->header($header, ''));

        if ($key === '') {
            if ($mode === 'required') {
                return PartnerApiResponse::error(
                    PartnerApiResponse::TYPE_INVALID_REQUEST,
                    'idempotency_key_required',
                    "This endpoint requires an {$header} header — a unique value per logical request.",
                    400,
                );
            }

            return $next($request);
        }

        $maxLength = (int) config('partner_api.idempotency.max_key_length', 255);

        if (mb_strlen($key) > $maxLength) {
            return PartnerApiResponse::error(
                PartnerApiResponse::TYPE_INVALID_REQUEST,
                'idempotency_key_invalid',
                "The {$header} header must be at most {$maxLength} characters.",
                400,
            );
        }

        $endpoint = $request->getMethod().' '.'/'.ltrim($request->path(), '/');
        $result = $this->idempotency->claim(
            $this->context->client(),
            $key,
            $endpoint,
            $this->requestHash($request),
        );

        return match ($result->state) {
            PartnerIdempotencyState::Replay => $this->replay($result->record),
            PartnerIdempotencyState::Conflict => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_CONFLICT,
                'idempotency_key_conflict',
                $result->reason ?? 'This Idempotency-Key was already used for a different request.',
                409,
            ),
            PartnerIdempotencyState::InProgress => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_CONFLICT,
                'idempotency_key_in_progress',
                $result->reason ?? 'The original request for this Idempotency-Key is still being processed.',
                409,
                headers: ['Retry-After' => '1'],
            ),
            PartnerIdempotencyState::Fresh => $this->runAndRecord($request, $next, $result->record),
        };
    }

    private function runAndRecord(Request $request, Closure $next, PartnerIdempotencyKey $record): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->idempotency->release($record);

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $this->idempotency->complete($record, $status, $this->decode($response));
        } else {
            $this->idempotency->release($record);
        }

        return $response;
    }

    private function replay(PartnerIdempotencyKey $record): Response
    {
        $body = $record->response_body ?? [];

        return response()->json($body, $record->response_status ?? 200, [
            'Idempotent-Replay' => 'true',
        ]);
    }

    /**
     * Everything that makes this request distinct: the payload, plus the query
     * string, since two POSTs differing only in a filter are different
     * requests. Keys are sorted so an ordering difference in the partner's
     * JSON serialiser is not mistaken for a changed payload.
     */
    private function requestHash(Request $request): string
    {
        $payload = $request->all();
        $this->sortRecursive($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $array
     */
    private function sortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }

        unset($value);

        if (array_is_list($array)) {
            // A list's order is meaningful — two vehicles in a different
            // order is a different request.
            return;
        }

        ksort($array);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : null;
    }
}
