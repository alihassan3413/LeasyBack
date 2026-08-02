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

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload->toArray());
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
