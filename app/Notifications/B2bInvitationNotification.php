<?php

namespace App\Notifications;

use App\Enums\B2bVehicleScope;
use Carbon\CarbonInterface;
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
 *
 * Rendered through `emails.layout`, the same table-based, client-safe shell
 * every other production email uses, rather than Laravel's default markdown
 * notification theme — an invitation is a customer-facing LeasyBack email and
 * has no business looking like a different product. A plain-text alternative
 * part ships alongside it.
 */
class B2bInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $permissionLabels
     */
    public function __construct(
        private readonly string $companyName,
        private readonly string $acceptUrl,
        private readonly string $invitedByName,
        private readonly string $roleLabel,
        private readonly int $expiresInDays,
        private readonly string $invitedEmail,
        private readonly CarbonInterface $expiresAt,
        private readonly B2bVehicleScope $vehicleScope,
        private readonly array $permissionLabels = [],
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
            ->subject("Einladung zu {$this->companyName} bei ".config('mail.from.name'))
            ->view('emails.b2b.invitation', $this->payload())
            ->text('emails.b2b.invitation-text', $this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'companyName' => $this->companyName,
            'acceptUrl' => $this->acceptUrl,
            'invitedByName' => $this->invitedByName,
            'invitedEmail' => $this->invitedEmail,
            'roleLabel' => $this->roleLabel,
            'expiresInDays' => $this->expiresInDays,
            'expiresAtLabel' => $this->expiresAt->translatedFormat('d.m.Y'),
            'vehicleScopeLabel' => $this->vehicleScope === B2bVehicleScope::Own
                ? 'Nur selbst angelegte Fahrzeuge'
                : 'Alle Fahrzeuge des Unternehmens',
            'permissionLabels' => $this->permissionLabels,
        ];
    }
}
