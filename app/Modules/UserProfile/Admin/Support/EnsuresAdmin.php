<?php

namespace App\Modules\UserProfile\Admin\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared by every controller method gated behind an AdminPolicy ability
 * (see app/Policies/AdminPolicy.php) — checks the ability via the Gate
 * (the single source of truth for "is this user an Admin") and, on denial,
 * returns the same {"error": "..."} JSON 403 shape every one of these
 * endpoints already returned before this checkpoint, so no live response
 * body changes even though the underlying check is now centralized.
 */
trait EnsuresAdmin
{
    private function ensureAdmin(Request $request, string $ability, string $message): ?JsonResponse
    {
        if ($request->user()?->can($ability)) {
            return null;
        }

        return response()->json(['error' => $message], 403);
    }
}
