<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

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

    public function open(Request $request, string $notificationId): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($notificationId)->first();
        $notification?->markAsRead();

        $url = $notification->data['url'] ?? null;

        return redirect($url && str_starts_with($url, '/') ? $url : route('dashboard'));
    }
}
