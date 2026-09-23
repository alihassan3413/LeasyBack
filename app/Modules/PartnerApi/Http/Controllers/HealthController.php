<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group(
    name: 'Partner API',
    description: "Machine-to-machine integration API for partner systems, served under `/api/v1/partner`.\n\n"
        .'Authenticated with a long-lived bearer token issued per partner and per environment '
        .'(`sandbox` / `production`). There is no login endpoint: the token is provisioned out of band, '
        .'shown once, and sent as `Authorization: Bearer {token}` on every request.'."\n\n"
        .'The company whose data a request may reach is derived from the token alone. Company and '
        .'ownership fields are never accepted as request input.'
)]
class HealthController extends Controller
{
    /**
     * Health check.
     *
     * GET /api/v1/partner/health
     */
    #[Endpoint(
        title: 'Health',
        description: 'Liveness probe for the Partner API. Authenticated, so a partner can use it as a '
            .'credential smoke test during integration: a 200 means the token is valid, active and '
            .'in the expected environment.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'status' => 'ok',
                'service' => 'leasyback-partner-api',
                'version' => 'v1',
                'environment' => 'sandbox',
                'time' => '2026-08-06T12:00:00+00:00',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'The API is reachable and the token is valid.'
    )]
    #[Response(
        status: 401,
        content: [
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_token',
                'message' => 'The provided API token is not valid.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'Missing, unknown, revoked or expired token. See `code` for which.'
    )]
    public function __invoke(PartnerContext $context): JsonResponse
    {
        return PartnerApiResponse::success([
            'status' => 'ok',
            'service' => 'leasyback-partner-api',
            'version' => 'v1',
            'environment' => $context->environment()->value,
            'time' => now()->toIso8601String(),
        ]);
    }
}
