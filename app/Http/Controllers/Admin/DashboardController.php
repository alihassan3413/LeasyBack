<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly AdminQueryService $adminQueryService) {}

    /**
     * Session-authenticated counterpart of the Sanctum API's
     * AdminController::summary() — same AdminQueryService::summary() call,
     * gated by the 'admin' route middleware instead of a per-method Gate
     * check (see routes/admin.php).
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'summary' => $this->adminQueryService->summary(),
        ]);
    }
}
