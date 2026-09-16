<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly B2bContext $b2bContext) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->paginate(self::PAGE_SIZE);

        return response()->json([
            'data' => NotificationResource::collection($notifications->items())->resolve(),
            'unread_count' => $user->unreadNotifications()->count(),
            'next_page' => $notifications->hasMorePages() ? $notifications->currentPage() + 1 : null,
        ]);
    }

    public function read(Request $request, string $notificationId): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread_count' => 0]);
    }

    public function destroy(Request $request, string $notificationId): JsonResponse
    {
        $request->user()->notifications()->whereKey($notificationId)->delete();

        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json(['unread_count' => 0]);
    }

    /**
     * Follow a notification to the thing it is about.
     *
     * Two problems this solves, both of which made a click look like it did
     * nothing. Notifications carry a generic '/dashboard' url, so opening one
     * navigated to the page the reader was already on. And a notification
     * about a vehicle in the user's *other* context — their private area
     * while they act as a company, or the reverse — pointed at a record the
     * active scope hides, which is a 404 at best.
     *
     * So the destination is resolved from the notification's subject rather
     * than its stored url, and the context is switched to the one that owns
     * it first. Switching is not an escalation: B2bContext::switchTo() only
     * accepts a company the user is a member of, and the vehicle page
     * re-authorizes through VehiclePolicy afterwards either way.
     */
    public function open(Request $request, string $notificationId): RedirectResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->whereKey($notificationId)->first();
        $notification?->markAsRead();

        $vehicleId = $notification?->data['meta']['vehicle_id'] ?? null;

        if (is_string($vehicleId) && $this->focusVehicleContext($user, $vehicleId)) {
            return redirect()->route('vehicles.show', $vehicleId);
        }

        $url = $notification?->data['url'] ?? null;

        return redirect($url && str_starts_with($url, '/') ? $url : route('dashboard'));
    }

    /**
     * Put the user in the context that owns this vehicle, if it is one of
     * theirs. Returns false when the vehicle is gone or belongs to neither
     * their companies nor their private side — the caller then falls back to
     * the notification's own url rather than sending them somewhere they
     * cannot look.
     */
    private function focusVehicleContext(User $user, string $vehicleId): bool
    {
        $vehicle = Vehicle::query()
            ->select(['vehicle_id', 'vehicle_belongs', 'b2b_id', 'b2c_user_id'])
            ->find($vehicleId);

        if ($vehicle === null) {
            return false;
        }

        if ($vehicle->vehicle_belongs === 'B2B') {
            return $vehicle->b2b_id !== null
                && ($this->b2bContext->activeCompanyId($user) === $vehicle->b2b_id
                    || $this->b2bContext->switchTo($user, $vehicle->b2b_id));
        }

        if ((int) $vehicle->b2c_user_id !== (int) $user->id) {
            return false;
        }

        // Already acting privately, or able to step back to it.
        return ! $this->b2bContext->actsAsCompany($user) || $this->b2bContext->switchToPersonal($user);
    }
}
