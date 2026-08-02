<?php

namespace App\Notifications;

use App\Enums\NotificationType;

class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function make(
        NotificationType $type,
        string $title,
        string $body,
        ?string $url = null,
        array $meta = [],
    ): self {
        return new self($type, $title, $body, $url, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'variant' => $this->type->variant(),
            'icon' => $this->type->icon(),
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'meta' => $this->meta,
        ];
    }
}
