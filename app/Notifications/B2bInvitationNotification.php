<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invitation link to join a B2B company.
 *
 * Sent on-demand to a bare address (`Notification::route('mail', $email)`)
 * because the invitee may not have an account yet — the accept page handles
 * both "log in" and "register first" from the same link.
 */
class B2bInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $companyName,
        private readonly string $acceptUrl,
        private readonly string $invitedByName,
        private readonly string $roleLabel,
        private readonly int $expiresInDays,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Einladung zu {$this->companyName} bei LeasyBack")
            ->greeting('Hallo!')
            ->line("{$this->invitedByName} hat Sie eingeladen, dem Unternehmen \"{$this->companyName}\" bei LeasyBack als {$this->roleLabel} beizutreten.")
            ->action('Einladung annehmen', $this->acceptUrl)
            ->line("Diese Einladung ist {$this->expiresInDays} Tage gültig.")
            ->line('Wenn Sie diese Einladung nicht erwartet haben, können Sie diese E-Mail ignorieren.');
    }
}
