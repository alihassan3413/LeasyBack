<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderMessageResource;
use App\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\OrderMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One endpoint set for both sides of the thread — Admin and customer reach
 * the same routes and OrderPolicy decides what each may touch, rather than
 * an admin/ mirror of these routes that would have to re-derive the same
 * rule. Responses are JSON (not Inertia): the Messages section fetches and
 * appends in place, so a full page visit on every send would be wrong.
 */
class OrderMessageController extends Controller
{
    public function __construct(private readonly OrderMessageService $messages) {}

    public function index(Request $request, string $orderId): JsonResponse
    {
        $order = LeasybackOrder::find($orderId);

        if ($order === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if (! $request->user()->can('viewMessages', $order)) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $page = max(1, (int) $request->query('page', 1));

        return response()->json($this->messages->paginate($order, $request->user(), $page));
    }

    public function store(Request $request, string $orderId): JsonResponse
    {
        $order = LeasybackOrder::find($orderId);

        if ($order === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if (! $request->user()->can('sendMessage', $order)) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = $this->messages->send($order, $request->user(), trim($validated['body']));

        return response()->json([
            'message' => (new OrderMessageResource($message))->resolve(),
            'unread_count' => $this->messages->unreadCount($order, $request->user()),
        ], 201);
    }

    public function read(Request $request, string $orderId): JsonResponse
    {
        $order = LeasybackOrder::find($orderId);

        if ($order === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        if (! $request->user()->can('viewMessages', $order)) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $this->messages->markRead($order, $request->user());

        return response()->json(['unread_count' => 0]);
    }
}
