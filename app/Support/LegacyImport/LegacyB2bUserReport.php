<?php

namespace App\Support\LegacyImport;

use App\Enums\B2bRole;
use App\Enums\B2bRolePreset;
use App\Models\LegacyImportMap;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Base44 company users as they stand in V2, one row per user and company,
 * for the client to check the migration: who belongs to which company, in
 * which role, and which mappings need a second look.
 *
 * Read-only. Every fact comes from what the importer recorded
 * (legacy_import_map) and the live V2 tables; nothing is re-derived from the
 * export. No passwords, tokens or internal ids beyond the B2B-ID.
 */
final class LegacyB2bUserReport
{
    public const HEADINGS = [
        'Unternehmen',
        'B2B-ID',
        'Vorname',
        'Nachname',
        'E-Mail',
        'Rolle',
        'Administrator',
        'Benutzerstatus',
        'Base44-Zuordnung/Quelle',
        'Prüfung/Hinweis',
    ];

    /** Users the importer held back although they belong to a company: the client must decide. */
    private const HELD_BACK = [
        'active_user_without_company_manual_review' => 'Aktiver Base44-Benutzer ohne Unternehmen',
        'orphan_kunde' => 'Base44-Unternehmen fehlt im Export',
        'company_not_imported' => 'Base44-Unternehmen wurde nicht übernommen',
    ];

    /**
     * @return list<array<string, string>> keyed by HEADINGS, sorted by company, administrator first
     */
    public function rows(): array
    {
        $userMaps = LegacyImportMap::where('entity', 'user')->get();
        $mapped = $userMaps->whereIn('status', ['imported', 'linked'])->filter(fn ($map) => $map->target_table === 'users');
        $userIds = $mapped->pluck('target_id')->map(fn ($id) => (int) $id)->all();

        $users = DB::table('users')->whereIn('id', $userIds)->get(['id', 'name', 'email', 'is_active'])->keyBy('id');
        $memberships = DB::table('user_b2b as ub')
            ->join('b2b as b', 'b.b2b_id', '=', 'ub.b2b_id')
            ->whereIn('ub.user_id', $userIds)
            ->get(['ub.user_id', 'ub.b2b_id', 'ub.role', 'ub.permissions', 'ub.vehicle_scope', 'ub.status', 'b.company_name', 'b.is_active as company_active'])
            ->groupBy('user_id');

        $companies = $memberships->flatten(1)->pluck('b2b_id')->unique()->values();
        $adminCounts = DB::table('user_b2b')->whereIn('b2b_id', $companies)->where('role', B2bRole::Owner->value)->where('status', 'active')
            ->groupBy('b2b_id')->selectRaw('b2b_id, count(*) as n')->pluck('n', 'b2b_id');

        $kunden = LegacyImportMap::where('entity', 'kunde')->whereIn('status', ['imported', 'linked'])->get()->keyBy('legacy_id');
        $membershipMaps = LegacyImportMap::where('entity', 'membership')->whereIn('status', ['imported', 'linked'])->get();
        $activation = Schema::hasTable('legacy_activation_mails')
            ? DB::table('legacy_activation_mails')->whereIn('user_id', $userIds)->get(['user_id', 'status', 'activated_at'])->keyBy('user_id')
            : collect();
        $vehiclesElsewhere = $this->vehicleCompaniesByCreator($userIds);

        $rows = [];

        foreach ($mapped as $map) {
            $user = $users->get((int) $map->target_id);

            if ($user === null) {
                $rows[] = $this->row(email: $map->legacy_id, status: 'Konto nicht mehr vorhanden', source: 'Base44-Benutzer, V2-Konto gelöscht', flags: ['Benutzerkonto fehlt in V2']);

                continue;
            }

            // Where Base44 put this user: email|kunde id → the company that Kunde became.
            $base44 = $membershipMaps->filter(fn ($m) => str_starts_with($m->legacy_id, $map->legacy_id.'|'))
                ->mapWithKeys(function ($m) use ($kunden) {
                    $kunde = $kunden->get(substr($m->legacy_id, strpos($m->legacy_id, '|') + 1));

                    return $kunde ? [$kunde->target_id => ['kundennummer' => $kunde->payload['kundennummer'] ?? null, 'role' => $m->payload['role'] ?? null, 'correction' => $m->payload['client_correction']['note'] ?? null]] : [];
                });

            $own = $memberships->get($user->id, collect());
            $elsewhere = collect($vehiclesElsewhere[$user->id] ?? [])->diff($own->pluck('b2b_id'))->count();
            $status = $this->userStatus($user, $map->status, $activation->get($user->id));
            [$first, $last] = $this->name($user);

            $userFlags = [];

            if (! $user->is_active) {
                $userFlags[] = 'Benutzerkonto deaktiviert';
            }

            if ($activation->get($user->id)?->status === 'failed') {
                $userFlags[] = 'Aktivierungs-E-Mail fehlgeschlagen';
            }

            if ($own->count() > 1) {
                $userFlags[] = 'Mitglied in '.$own->count().' Unternehmen';
            }

            // A user the client reviewed and placed is no longer an open question.
            if ($elsewhere > 0 && ! isset($map->payload['client_correction'])) {
                $userFlags[] = "Hat Fahrzeuge für {$elsewhere} andere(s) Unternehmen angelegt – evtl. interner Mitarbeiter oder Dienstleister, Zuordnung prüfen";
            }

            if ($own->isEmpty()) {
                $rows[] = $this->row(first: $first, last: $last, email: $user->email, status: $status, source: $this->source($map->status, null), flags: ['Kein Unternehmen zugeordnet', ...$userFlags]);

                continue;
            }

            foreach ($own as $membership) {
                $preset = B2bRolePreset::match(...$this->roleAndPermissions($membership));
                $isAdmin = $preset === B2bRolePreset::CompanyAdministrator;
                $origin = $base44->get($membership->b2b_id);
                $flags = [];

                if ($origin === null) {
                    $flags[] = 'Unternehmen stammt nicht aus der Base44-Zuordnung (in V2 hinzugefügt)';
                } elseif ($origin['correction'] === null && $origin['role'] !== null && $origin['role'] !== $membership->role) {
                    $flags[] = 'Rolle seit dem Import geändert';
                }

                if ($preset === null) {
                    $flags[] = 'Individuelle Rechte – keiner Standardrolle zugeordnet';
                }

                $admins = (int) ($adminCounts[$membership->b2b_id] ?? 0);

                if ($admins === 0) {
                    $flags[] = 'Unternehmen hat keinen Administrator';
                } elseif ($admins > 1) {
                    $flags[] = "Unternehmen hat {$admins} Administratoren";
                }

                if ($membership->status !== 'active') {
                    $flags[] = 'Mitgliedschaft nicht aktiv ('.$membership->status.')';
                }

                if (! $membership->company_active) {
                    $flags[] = 'Unternehmen deaktiviert';
                }

                $rows[] = $this->row(
                    company: (string) $membership->company_name,
                    b2bId: (string) $membership->b2b_id,
                    first: $first,
                    last: $last,
                    email: $user->email,
                    role: $this->roleLabel($preset),
                    admin: $isAdmin,
                    status: $status,
                    source: $this->source($map->status, $origin),
                    flags: [...$flags, ...$userFlags],
                    note: $origin['correction'] ?? null,
                );
            }
        }

        foreach ($userMaps->where('status', 'skipped') as $map) {
            $reason = (string) ($map->payload['reason'] ?? '');
            $why = self::HELD_BACK[$reason] ?? (str_starts_with($reason, 'existing_v2_account_type_')
                ? 'E-Mail gehört bereits zu einem anderen V2-Konto ('.substr($reason, strlen('existing_v2_account_type_')).')'
                : null);

            if ($why !== null) {
                $rows[] = $this->row(email: $map->legacy_id, status: 'Nicht übernommen', source: "Base44-Benutzer, nicht übernommen: {$why}", flags: ['Kein Unternehmen zugeordnet', 'Manuelle Prüfung erforderlich']);
            }
        }

        foreach ($userMaps->where('status', 'removed') as $map) {
            $correction = $map->payload['client_correction'] ?? [];
            $rows[] = $this->row(email: $map->legacy_id, status: 'Entfernt', source: 'Base44-Benutzer', note: 'Freigegebene Korrektur: entfernt'.(isset($correction['at']) ? ' am '.substr((string) $correction['at'], 0, 10) : '').', keine Unternehmenszugehörigkeit, kein Login');
        }

        return $this->sorted($rows);
    }

    /** Whether a row needs a look: a flag, not just the note of an approved correction. */
    public static function needsReview(array $row): bool
    {
        return $row['Prüfung/Hinweis'] !== 'OK' && ! str_starts_with($row['Prüfung/Hinweis'], 'Freigegebene Korrektur');
    }

    /**
     * Companies each user created vehicles in (vehicles.created_by_user_id,
     * filled from Base44's created_by by the importer).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function vehicleCompaniesByCreator(array $userIds): array
    {
        return DB::table('vehicles')->whereIn('created_by_user_id', $userIds)->whereNotNull('b2b_id')
            ->distinct()->get(['created_by_user_id', 'b2b_id'])
            ->groupBy('created_by_user_id')
            ->map(fn (Collection $rows) => $rows->pluck('b2b_id')->all())
            ->all();
    }

    /**
     * @return array{0: B2bRole, 1: B2bPermissionSet}
     */
    private function roleAndPermissions(object $membership): array
    {
        $resolved = B2bMembership::fromRow($membership);

        return [$resolved->role, $resolved->permissions];
    }

    private function roleLabel(?B2bRolePreset $preset): string
    {
        return match ($preset) {
            B2bRolePreset::CompanyAdministrator => 'Unternehmensadministrator',
            B2bRolePreset::StandardUser => 'Standardbenutzer',
            B2bRolePreset::ReadOnly => 'Nur Lesen',
            null => 'Individuelle Rechte',
        };
    }

    private function userStatus(object $user, string $mapStatus, ?object $activation): string
    {
        return match (true) {
            ! $user->is_active => 'Deaktiviert',
            $activation?->activated_at !== null => 'Aktiv – Konto aktiviert',
            $mapStatus === 'linked' => 'Aktiv – bestehendes V2-Konto',
            $activation?->status === 'sent' => 'Aktivierungs-E-Mail versendet, noch nicht aktiviert',
            $activation?->status === 'queued' => 'Aktivierungs-E-Mail wird versendet',
            $activation?->status === 'failed' => 'Aktivierungs-E-Mail fehlgeschlagen',
            default => 'Angelegt, noch nicht aktiviert',
        };
    }

    /**
     * @param  array{kundennummer: string|null, role: string|null}|null  $origin
     */
    private function source(string $mapStatus, ?array $origin): string
    {
        $account = $mapStatus === 'linked' ? 'mit bestehendem V2-Konto verknüpft' : 'Konto neu angelegt';

        if ($origin === null) {
            return "Base44-Benutzer, {$account}";
        }

        $kunde = $origin['kundennummer'] !== null ? " (Kundennr. {$origin['kundennummer']})" : '';

        return "Base44-Unternehmen{$kunde}, {$account}";
    }

    /**
     * The importer falls back to the e-mail's local part when Base44 had no
     * name; that is shown as "no name", not split into a made-up one.
     *
     * @return array{0: string, 1: string}
     */
    private function name(object $user): array
    {
        $name = trim((string) $user->name);

        if ($name === '' || mb_strtolower($name) === mb_strtolower(strstr((string) $user->email, '@', true) ?: '')) {
            return ['', ''];
        }

        $parts = LegacyValue::splitName($name);

        return [(string) $parts['first'], (string) $parts['last']];
    }

    /**
     * @param  list<string>  $flags
     * @return array<string, string>
     */
    private function row(
        string $company = '',
        string $b2bId = '',
        string $first = '',
        string $last = '',
        string $email = '',
        string $role = '',
        bool $admin = false,
        string $status = '',
        string $source = '',
        array $flags = [],
        ?string $note = null,
    ): array {
        return array_combine(self::HEADINGS, [
            $company,
            $b2bId,
            $first,
            $last,
            $email,
            $role,
            $admin ? 'Ja' : 'Nein',
            $status,
            $source,
            // Flags first, so a row with an open question never reads as an approved correction.
            implode('; ', array_filter([...array_unique($flags), $note])) ?: 'OK',
        ]);
    }

    /**
     * Company by company (rows without one last), the administrator first.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    private function sorted(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [
            $a['Unternehmen'] === '', mb_strtolower($a['Unternehmen']), $a['B2B-ID'], $a['Administrator'] !== 'Ja', mb_strtolower($a['Nachname']), mb_strtolower($a['Vorname']), $a['E-Mail'],
        ] <=> [
            $b['Unternehmen'] === '', mb_strtolower($b['Unternehmen']), $b['B2B-ID'], $b['Administrator'] !== 'Ja', mb_strtolower($b['Nachname']), mb_strtolower($b['Vorname']), $b['E-Mail'],
        ]);

        return $rows;
    }
}
