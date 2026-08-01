<?php

namespace App\Modules\UserProfile\B2B\Http\Controllers;

use App\Modules\UserProfile\B2B\Http\Requests\B2BRequest;
use App\Modules\UserProfile\B2B\Services\B2BService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class B2BController extends Controller
{
    public function __construct(private readonly B2BService $b2bService) {}

    /**
     * POST /b2b/create
     */
    public function store(B2BRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Firmenkunde') {
            return response()->json(['error' => 'Access denied: insufficient privileges not b2b user'], 403);
        }

        try {
            $b2b = $this->b2bService->create($user, $request->validated());
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json($b2b);
    }

    /**
     * GET /b2b/user_id/{id}
     *
     * Fixed IDOR: this used to trust the client-supplied {id} directly with
     * no check it was the caller's own — any Firmenkunde could read any
     * other user's company/contact/address by guessing their user id. Now
     * always resolves the authenticated user's own company; the {id} in the
     * URL is ignored for the lookup (kept only for the route's existing
     * shape, no legitimate case exists for looking up another user's B2B
     * company through this endpoint).
     */
    public function showByUser(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value !== 'Firmenkunde') {
            return response()->json(['error' => 'Access denied: not b2b user'], 403);
        }

        $company = $this->b2bService->findForUser($user->id);
        if (! $company) {
            return response()->json('No company found for this user', 404);
        }

        return response()->json($company);
    }

    /**
     * PATCH /b2b/{id}
     *
     * Fixed the role-acceptance bug: this used to accept any user_b2b role
     * (owner or member) as sufficient to update the company; now delegates
     * to B2BService::update(), which requires role === 'owner'.
     */
    public function update(B2BRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Firmenkunde') {
            return response()->json(['error' => 'Access denied: insufficient privileges'], 403);
        }

        try {
            $result = $this->b2bService->update($user, $id, $request->validated());
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json($result);
    }
}
