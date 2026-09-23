<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class SystemNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly NotificationPayload $payload) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload->toArray();
    }

    /**
     * Broadcast inline instead of via the queue.
     *
     * BroadcastChannel wraps this in a BroadcastNotificationCreated event,
     * which is itself ShouldBroadcast — so without this the socket push sits
     * on the queue even when the notification was sent with sendNow(), and
     * the bell only catches up on the next page visit. BroadcastChannel
     * copies this connection onto that event, which is what makes it fire
     * on the current request.
     *
     * Only the socket push is affected: `database` still writes inline as
     * before and WebPush still queues, so a slow push service cannot hold up
     * the request. Every caller reaches this through Notifier, which already
     * swallows and logs a failed dispatch.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->payload->toArray()))->onConnection('sync');
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->payload->title)
            ->body($this->payload->body)
            ->icon('/leasyback-logo.svg')
            ->badge('/leasyback-logo.svg')
            ->tag($this->payload->type->value)
            ->options(['TTL' => 86400])
            ->data([
                'id' => $notification->id,
                'url' => $this->payload->url,
                'type' => $this->payload->type->value,
            ]);

        return $message;
    }

    public function broadcastType(): string
    {
        return 'system.notification';
    }
}
