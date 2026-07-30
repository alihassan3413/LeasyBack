<?php

namespace App\Modules\UserProfile\Admin\Support;

use App\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait EnsuresAdmin
{
    private function adminDenial(Request $request, string $message): ?JsonResponse
    {
        $user = $request->user();
        $type = $user?->user_type;
        $value = $type instanceof UserType ? $type->value : (string) $type;

        if ($user === null || $value !== UserType::Admin->value) {
            return response()->json(['error' => $message], 403);
        }

        return null;
    }

    private function adminId(Request $request): int|string
    {
        return $request->user()->getAuthIdentifier();
    }
}
