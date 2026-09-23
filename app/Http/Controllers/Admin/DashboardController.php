<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Admin\Services\AdminTaskQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PANELS = ['orders', 'users', 'vehicles'];

    private const PANEL_LIMIT = 10;

    /** Rows in the dashboard's task list; the rest are reached by working through it. */
    private const TASK_LIMIT = 25;

    public function __construct(
        private readonly AdminQueryService $adminQueryService,
        private readonly AdminTaskQueryService $adminTaskQueryService,
    ) {}

    /**
     * Session-authenticated counterpart of the Sanctum API's
     * AdminController::summary() — same AdminQueryService::summary() call,
     * gated by the 'admin' route middleware instead of a per-method Gate
     * check (see routes/admin.php).
     *
     * Only the active panel's list is queried, and each list is a lazy
     * Inertia prop, so switching panels or paging reloads that list alone.
     */
    public function index(Request $request): Response
    {
        $panel = in_array($request->query('panel'), self::PANELS, true) ? $request->query('panel') : 'orders';
        $userType = $request->query('user_type') === 'B2B' ? 'B2B' : 'B2C';
        $taskChannel = in_array($request->query('task_channel'), ['B2B', 'B2C'], true) ? $request->query('task_channel') : null;

        $request->merge([
            'page' => max(1, (int) $request->query('page', 1)),
            'limit' => self::PANEL_LIMIT,
        ]);

        return Inertia::render('Admin/Dashboard', [
            'summary' => $this->adminQueryService->summary(),
            /*
             * Deferred, not merely a closure: a closure prop is still
             * resolved before the first response is sent. OrderTaskHydrator
             * loads every active order in a fixed number of queries, so this
             * is no longer expensive — but it is still work the first paint
             * does not need, and deferring keeps the tiles and panels instant.
             */
            'tasks' => Inertia::defer(fn () => $this->adminTaskQueryService->openTasks(self::TASK_LIMIT, $taskChannel)),
            'filters' => [
                'panel' => $panel,
                'search' => trim((string) $request->query('search', '')),
                'status' => (string) $request->query('status', ''),
                'user_type' => $userType,
                'task_channel' => $taskChannel,
                'page' => (int) $request->input('page'),
            ],
            'orders' => fn () => $panel === 'orders' ? $this->adminQueryService->orders($request) : null,
            'users' => fn () => $panel === 'users'
                ? ($userType === 'B2B' ? $this->adminQueryService->b2bList($request) : $this->adminQueryService->b2cList($request))
                : null,
            'vehicles' => fn () => $panel === 'vehicles' ? $this->adminQueryService->vehicles($request) : null,
        ]);
    }
}
