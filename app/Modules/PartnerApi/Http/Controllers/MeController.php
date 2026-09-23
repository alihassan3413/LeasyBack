<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group(name: 'Partner API')]
class MeController extends Controller
{
    /**
     * Current integration identity.
     *
     * GET /api/v1/partner/me
     */
    #[Endpoint(
        title: 'Me',
        description: 'Returns the identity behind the calling token: the integration client, its '
            .'environment, the single company whose data the token can reach, and the exact scope '
            .'set granted. Call this first when integrating — it is the authoritative answer to '
            .'"which company am I, and what may I call", and it never varies by request input.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'client' => [
                    'slug' => 'example-partner',
                    'name' => 'Example Partner GmbH',
                    'environment' => 'sandbox',
                ],
                'company' => [
                    'id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                    'name' => 'Musterfirma GmbH',
                ],
                'token' => [
                    'name' => 'initial',
                    'abilities' => ['vehicles.read', 'orders.read', 'timeline.read'],
                    'expires_at' => null,
                    'last_used_at' => '2026-08-06T11:59:58+00:00',
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'The calling identity. `company.id` is the only company this token can ever reach.'
    )]
    #[Response(
        status: 403,
        content: [
            'error' => [
                'type' => 'authorization_error',
                'code' => 'client_inactive',
                'message' => 'This integration client is deactivated. Contact your Leasyback representative.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'The token is valid but the client, its integration account or its company is deactivated.'
    )]
    public function __invoke(PartnerContext $context): JsonResponse
    {
        return PartnerApiResponse::success($context->toArray());
    }
}
