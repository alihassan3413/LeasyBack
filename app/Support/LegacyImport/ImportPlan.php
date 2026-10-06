<?php

namespace App\Support\LegacyImport;

/**
 * The decisions that only depend on the export itself, taken once and shared by
 * every step: which companies are relevant, which vehicles survive, who owns
 * which company. No database access, so the same export always yields the
 * same plan.
 */
final class ImportPlan
{
    /** @var array<string, array<string, string>> Kunde rows by legacy id */
    public array $kunden = [];

    /** @var array<string, string> Kunde legacy id => why it is imported */
    public array $relevantKunden = [];

    /** @var array<string, true> */
    public array $referencedVehicleIds = [];

    /**
     * @var array<string, array{action: string, reason: string, winner: string|null}>
     */
    public array $vehicleOutcome = [];

    /** @var array<string, array<string, string>> */
    public array $vehicles = [];

    /** @var array<string, string> Base44 user id => lower-cased e-mail */
    public array $emailByBase44UserId = [];

    /** @var array<string, array<string, string>> user rows by lower-cased e-mail */
    public array $usersByEmail = [];

    /** @var array<string, list<array<string, string>>> active, company-bound users by Kunde id */
    public array $activeUsersByKunde = [];

    /** @var array<string, string> Kunde id => reason, for every Kunde that is not imported */
    public array $irrelevantKunden = [];

    public function __construct(private readonly LegacyExport $export)
    {
        $this->indexKunden();
        $this->indexUsers();
        $this->indexBase44UserIds();
        $this->indexReferencedVehicles();
        $this->decideVehicles();
        $this->decideCompanies();
    }

    /**
     * Owners of a company, by the agreed rule: Base44 admins bound to it; else
     * the active user whose e-mail is the Kunde's contact e-mail; else the
     * earliest active user.
     *
     * @return list<string> lower-cased e-mails
     */
    public function ownersOf(string $kundeId): array
    {
        $users = $this->activeUsersByKunde[$kundeId] ?? [];

        if ($users === []) {
            return [];
        }

        $admins = array_values(array_filter($users, fn (array $u) => $u['role'] === 'admin'));

        if ($admins !== []) {
            return array_map(fn (array $u) => LegacyValue::email($u['email']), $admins);
        }

        $contact = LegacyValue::email($this->kunden[$kundeId]['kontaktperson_email'] ?? null);

        foreach ($users as $user) {
            if ($contact !== null && LegacyValue::email($user['email']) === $contact) {
                return [$contact];
            }
        }

        usort($users, fn (array $a, array $b) => [$a['created_date'], $a['email']] <=> [$b['created_date'], $b['email']]);

        return [LegacyValue::email($users[0]['email'])];
    }

    public function isStaffEmail(?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);

        return in_array($domain, config('legacy_import.staff_email_domains'), true)
            || ($this->usersByEmail[$email]['role'] ?? null) === 'admin';
    }

    private function indexKunden(): void
    {
        foreach ($this->export->rows('kunde') as $row) {
            $this->kunden[$row['id']] = $row;
        }
    }

    private function indexUsers(): void
    {
        foreach ($this->export->rows('users') as $row) {
            $email = LegacyValue::email($row['email']);

            if ($email === null) {
                continue;
            }

            $this->usersByEmail[$email] = $row;

            if ($row['status'] === 'active' && LegacyValue::text($row['kunde_id']) !== null) {
                $this->activeUsersByKunde[$row['kunde_id']][] = $row;
            }
        }
    }

    /**
     * The users export has no id column. Base44 ids are only recoverable from
     * the `created_by_id` / `created_by` pair other exports carry.
     */
    private function indexBase44UserIds(): void
    {
        foreach (['kunde', 'fahrzeug', 'auftrag', 'kommentar', 'historie', 'dateianhang', 'einladung', 'benachrichtigung'] as $dataset) {
            foreach ($this->export->rows($dataset) as $row) {
                $id = LegacyValue::text($row['created_by_id'] ?? null);
                $email = LegacyValue::email($row['created_by'] ?? null);

                if ($id !== null && $email !== null) {
                    $this->emailByBase44UserId[$id] = $email;
                }
            }
        }
    }

    private function indexReferencedVehicles(): void
    {
        foreach ($this->export->rows('auftrag') as $order) {
            foreach (LegacyValue::idList($order['fahrzeug_ids']) as $id) {
                $this->referencedVehicleIds[$id] = true;
            }
        }
    }

    /**
     * Test vehicles are dropped unless an order uses them; duplicate plates are
     * resolved deterministically: order-referenced, then active company, then
     * valid VIN, then newest, then lowest id.
     */
    private function decideVehicles(): void
    {
        $dummy = mb_strtoupper(config('legacy_import.dummy_manufacturer'));
        $byPlate = [];

        foreach ($this->export->rows('fahrzeug') as $row) {
            $id = $row['id'];
            $this->vehicles[$id] = $row;

            if (! isset($this->kunden[$row['kunde_id']])) {
                $this->vehicleOutcome[$id] = ['action' => 'skip', 'reason' => 'orphan_kunde', 'winner' => null];

                continue;
            }

            if (mb_strtoupper(trim($row['hersteller'])) === $dummy && ! isset($this->referencedVehicleIds[$id])) {
                $this->vehicleOutcome[$id] = ['action' => 'skip', 'reason' => 'dummy_vehicle_unreferenced', 'winner' => null];

                continue;
            }

            $plate = LegacyValue::plate($row['kennzeichen']);

            if ($plate === null) {
                $this->vehicleOutcome[$id] = ['action' => 'skip', 'reason' => 'missing_plate', 'winner' => null];

                continue;
            }

            $byPlate[$plate][] = $row;
        }

        foreach ($byPlate as $rows) {
            usort($rows, fn (array $a, array $b) => $this->rank($b) <=> $this->rank($a) ?: strcmp($a['id'], $b['id']));

            $winner = array_shift($rows);
            $this->vehicleOutcome[$winner['id']] = ['action' => 'import', 'reason' => '', 'winner' => null];

            foreach ($rows as $loser) {
                $this->vehicleOutcome[$loser['id']] = ['action' => 'deduplicated', 'reason' => 'duplicate_plate', 'winner' => $winner['id']];
            }
        }
    }

    /**
     * @param  array<string, string>  $vehicle
     * @return array{int, int, int, string}
     */
    private function rank(array $vehicle): array
    {
        return [
            isset($this->referencedVehicleIds[$vehicle['id']]) ? 1 : 0,
            LegacyValue::bool($this->kunden[$vehicle['kunde_id']]['aktiv'] ?? null) === true ? 1 : 0,
            LegacyValue::isValidVin(LegacyValue::vin($vehicle['fahrgestellnummer_vin'])) ? 1 : 0,
            $vehicle['created_date'],
        ];
    }

    private function decideCompanies(): void
    {
        $reasons = [];

        foreach (array_keys($this->activeUsersByKunde) as $kundeId) {
            $reasons[$kundeId][] = 'active_user';
        }

        foreach ($this->export->rows('auftrag') as $order) {
            $reasons[$order['kunde_id']][] = 'order';
        }

        foreach ($this->vehicleOutcome as $id => $outcome) {
            if ($outcome['action'] === 'import') {
                $reasons[$this->vehicles[$id]['kunde_id']][] = 'vehicle';
            }
        }

        if (config('legacy_import.company_relevant_when_invited')) {
            foreach ($this->export->rows('einladung') as $invitation) {
                $reasons[$invitation['kunde_id']][] = 'pending_invitation';
            }
        }

        foreach ($this->kunden as $id => $row) {
            if (isset($reasons[$id])) {
                $this->relevantKunden[$id] = implode('+', array_values(array_unique($reasons[$id])));
            } else {
                $this->irrelevantKunden[$id] = 'no_users_vehicles_or_orders';
            }
        }
    }
}
