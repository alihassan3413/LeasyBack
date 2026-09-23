<?php

namespace App\Http\Controllers\Dev;

use App\Exceptions\ErrorPageRenderer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class ErrorPreviewController extends Controller
{
    /**
     * @var list<int>
     */
    private const STATUSES = [400, 401, 403, 404, 405, 408, 419, 422, 429, 500, 502, 503, 504];

    /**
     * Fallback page preview — any status without dedicated copy.
     */
    private const FALLBACK_STATUS = 418;

    public function index(): InertiaResponse
    {
        $this->guard();

        return Inertia::render('Errors/Preview', [
            'statuses' => self::STATUSES,
            'fallbackStatus' => self::FALLBACK_STATUS,
        ]);
    }

    /**
     * Renders an error page with a 200 so it stays previewable while
     * APP_DEBUG is on.
     */
    public function show(Request $request, int $status): Response
    {
        $this->guard();

        return ErrorPageRenderer::page($status, $request);
    }

    /**
     * Throws for real, exercising the full exception -> response pipeline.
     * Requires APP_DEBUG=false to show the branded page.
     */
    public function abort(int $status): never
    {
        $this->guard();

        abort($status);
    }

    private function guard(): void
    {
        abort_unless(app()->environment('local'), 404);
    }
}
