<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Events\OrderMessageSent;
use App\Http\Resources\OrderMessageResource;
use App\Models\LeasybackOrder;
use App\Models\OrderMessage;
use App\Models\OrderMessageRead;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrderMessageService
{
    private const PAGE_SIZE = 20;

    private const PREVIEW_LENGTH = 120;

    public function __construct(
        private readonly VehicleScopeService $vehicleScope,
        private readonly Notifier $notifier,
    ) {}

    /**
     * One page of the thread, newest first — the same shape and paging
     * contract NotificationController::index() returns, so the frontend
     * reuses its "append the next page" pattern unchanged. The caller
     * reverses for display; paging from the newest end is what lets an old
     * thread open instantly without loading its whole history.
     *
     * @return array{data: list<array<string, mixed>>, unread_count: int, next_page: int|null}
     */
    public function paginate(LeasybackOrder $order, User $user, int $page = 1): array
    {
        $messages = OrderMessage::where('order_id', $order->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE, ['*'], 'page', $page);

        return [
            'data' => OrderMessageResource::collection($messages->items())->resolve(),
            'unread_count' => $this->unreadCount($order, $user),
            'next_page' => $messages->hasMorePages() ? $messages->currentPage() + 1 : null,
        ];
    }

    /**
     * Messages that arrived after this user last opened the thread, not
     * counting their own — a sender is never unread to themselves.
     */
    public function unreadCount(LeasybackOrder $order, User $user): int
    {
        $lastReadAt = OrderMessageRead::where('order_id', $order->id)
            ->where('user_id', $user->id)
            ->value('last_read_at');

        return OrderMessage::where('order_id', $order->id)
            ->where(fn ($query) => $query->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
            ->when($lastReadAt !== null, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->count();
    }

    public function markRead(LeasybackOrder $order, User $user): void
    {
        OrderMessageRead::updateOrCreate(
            ['order_id' => $order->id, 'user_id' => $user->id],
            ['last_read_at' => now()],
        );
    }

    public function send(LeasybackOrder $order, User $user, string $body): OrderMessage
    {
        $message = OrderMessage::create([
            'order_id' => $order->id,
            'sender_id' => $user->id,
            'sender_name' => $user->name ?: $user->email,
            'sender_is_admin' => $user->isAdmin(),
            'body' => $body,
        ]);

        // Sending is also reading: the thread the sender is looking at is by
        // definition caught up, so their own badge must not light up for the
        // messages already on screen.
        $this->markRead($order, $user);

        OrderMessageSent::dispatch($message);

        $this->notifyRecipients($order, $message, $user);

        return $message;
    }

    /**
     * The other side of the thread, never the sender: Admin writes to the
     * order's customer(s), a customer writes to Admin. Each group gets the
     * URL of the surface it can actually open — customers have no admin
     * area, admins have no customer vehicle page.
     */
    private function notifyRecipients(LeasybackOrder $order, OrderMessage $message, User $sender): void
    {
        $vehicle = $order->vehicle;

        if ($vehicle === null) {
            return;
        }

        $recipients = $sender->isAdmin()
            ? $this->vehicleScope->resolveOwnerUsers($vehicle)
            : $this->admins();

        $recipients = $recipients->reject(fn (User $user) => $user->id === $sender->id)->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $url = $sender->isAdmin()
            ? route('vehicles.show', $vehicle->vehicle_id, false)
            : route('admin.orders.show', $order->id, false);

        $this->notifier->send(
            $recipients,
            NotificationPayload::make(
                NotificationType::MessageReceived,
                'Neue Nachricht',
                sprintf('%s: %s', $message->sender_name, Str::limit($message->body, self::PREVIEW_LENGTH)),
                $url,
                [
                    'order_id' => $order->id,
                    'auftragsnummer' => $order->auftragsnummer,
                    'message_id' => $message->id,
                ],
            ),
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return User::where('user_type', UserType::Admin->value)
            ->where('is_active', true)
            ->get();
    }
}
