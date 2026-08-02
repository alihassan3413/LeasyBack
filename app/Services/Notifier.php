<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\NotificationPayload;
use App\Notifications\SystemNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class Notifier
{
    /**
     * @param  User|iterable<User>|null  $recipients
     */
    public function send(User|iterable|null $recipients, NotificationPayload $payload): void
    {
        $users = $this->normalize($recipients);

        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::send($users, new SystemNotification($payload));
        } catch (\Throwable $e) {
            Log::error('Notification dispatch failed', [
                'type' => $payload->type->value,
                'recipients' => $users->pluck('id')->all(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  User|iterable<User>|null  $recipients
     * @return Collection<int, User>
     */
    private function normalize(User|iterable|null $recipients): Collection
    {
        if ($recipients === null) {
            return collect();
        }

        $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);

        return $users->filter(fn ($user) => $user instanceof User)->unique('id')->values();
    }
}
