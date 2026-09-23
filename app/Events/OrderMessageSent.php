<?php

namespace App\Events;

use App\Models\OrderMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live delivery of one message to everyone currently viewing the order.
 *
 * Deliberately separate from the SystemNotification the recipients also
 * receive: the notification is the "you have something waiting" signal for
 * a user who is elsewhere in the app, this event is what appends the bubble
 * to an already-open thread. The sender receives this one (their own client
 * reconciles it against the message it just posted) but not the
 * notification — see OrderMessageService::notifyRecipients().
 *
 * Broadcast inline rather than queued: a bubble that appears whenever a
 * worker next picks up the job is not a live thread. The cost is one Reverb
 * HTTP call on the send request.
 */
class OrderMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly OrderMessage $message) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->message->order_id.'.messages')];
    }

    public function broadcastAs(): string
    {
        return 'order.message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'order_id' => $this->message->order_id,
                'sender_id' => $this->message->sender_id,
                'sender_name' => $this->message->sender_name,
                'sender_is_admin' => $this->message->sender_is_admin,
                'body' => $this->message->body,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
