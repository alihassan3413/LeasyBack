<?php

namespace App\Services\Mail;

use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;

class MailRecipientResolver
{
    public function __construct(private readonly VehicleScopeService $vehicleScope) {}

    /**
     * @return array{email: string, name: string}|null
     */
    public function forVehicle(?Vehicle $vehicle): ?array
    {
        if ($vehicle === null) {
            return null;
        }

        $contact = $this->vehicleScope->resolveOwnerContact($vehicle);

        if ($contact !== null && trim((string) $contact['email']) !== '') {
            return [
                'email' => (string) $contact['email'],
                'name' => trim((string) $contact['name']) !== '' ? (string) $contact['name'] : 'Kunde',
            ];
        }

        $linkedUser = $this->vehicleScope->resolveOwnerUsers($vehicle)
            ->first(fn ($user) => trim((string) $user->email) !== '');

        if ($linkedUser === null) {
            return null;
        }

        return [
            'email' => (string) $linkedUser->email,
            'name' => trim((string) $linkedUser->name) !== '' ? (string) $linkedUser->name : 'Kunde',
        ];
    }

    /**
     * @return list<string>
     */
    public function admins(): array
    {
        $recipients = config('mail_notifications.admin_recipients', []);

        if (is_string($recipients)) {
            $recipients = explode(',', $recipients);
        }

        return array_values(array_filter(array_map(
            static fn ($email): string => trim((string) $email),
            (array) $recipients,
        )));
    }
}
