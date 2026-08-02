<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * Touching the session is enough to slide its expiry — the response body
     * only tells the client how long it has left so the idle timer and the
     * server stay in agreement.
     */
    public function keepAlive(Request $request): JsonResponse
    {
        $request->session()->put('last_seen_at', now()->toIso8601String());

        return response()->json(['lifetime' => (int) config('session.lifetime')]);
    }
}
