<?php

namespace App\Modules\PartnerApi\Http\Middleware;

use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses any request that tries to state whose data it is.
 *
 * The company and the acting user come from the token. A payload carrying
 * `b2b_id`, `user_id` or a sibling is either a partner misunderstanding the
 * API or an attempt to reach another company's data, and both are better
 * answered with an error than silently ignored: ignoring it means a partner
 * ships code believing the field works, and finds out when it matters.
 *
 * Applied to the whole route group rather than per-endpoint, so an endpoint
 * added in a later phase is covered by default instead of by remembering.
 * The keys are config, not constants, so a future field is a config edit.
 */
class RejectOwnershipInput
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $rejected */
        $rejected = (array) config('partner_api.rejected_input_keys', []);

        // Query string and body both — a GET filter is just as capable of
        // saying `?b2b_id=…` as a POST body is.
        $present = array_values(array_filter(
            $rejected,
            fn (string $key) => $request->has($key) || $request->query->has($key),
        ));

        if ($present !== []) {
            return PartnerApiResponse::error(
                PartnerApiResponse::TYPE_INVALID_REQUEST,
                'ownership_input_not_allowed',
                'Ownership is determined by your API token and cannot be set on a request.',
                400,
                details: ['rejected_parameters' => $present],
            );
        }

        return $next($request);
    }
}
