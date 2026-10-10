<?php

namespace App\Support\LegacyImport;

use App\Enums\B2bRole;
use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Models\LegacyImportMap;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Applies the client's review of the Base44 company users.
 *
 * - remove: the account's memberships are deleted and the account is
 *   deactivated, never deleted, so the vehicles, orders, status history and
 *   documents it created keep their creator. Unused activation tokens go.
 * - assign: the user's one company and role. Memberships of other companies
 *   are removed (a move). An existing account changes only its memberships
 *   and the company it opens in — never its password, MFA, e-mail, activation
 *   state or active flag. A user the import held back gets an account by the
 *   import's own rules (unusable password, activation mail later).
 * - expect-admin: the company's only Company Administrator once everything
 *   is applied; anything else blocks.
 * - activate-company: switch a company back on.
 *
 * Map rows record each decision (`removed`, or `imported` under this
 * correction's batch), which keeps a later legacy:import from restoring the
 * old state: the importer never re-decides a legacy id it has decided.
 *
 * Every step looks at the current state first; a step already in place is
 * reported as done and not repeated. Any state the client's decision did not
 * account for blocks the whole run instead of being guessed at.
 */
final class LegacyB2bUserCorrection
{
    public const PENDING = 'ausstehend';

    public const DONE = 'bereits erledigt';

    public const CHECKED = 'geprüft';

    public const BLOCKED = 'BLOCKIERT';

    /** Reasons the importer held a user back for that a client decision may resolve. */
    private const ASSIGNABLE_SKIPS = ['active_user_without_company_manual_review', 'company_not_imported', 'orphan_kunde'];

    /** @var list<array{subject: string, action: string, state: string, run: Closure|null}> */
    private array $steps = [];

    /** @var list<array<string, mixed>> */
    private array $audit = [];

    /** @var array<string, object> Kunde id => b2b row (b2b_id, company_name, is_active) */
    private array $companies = [];

    /** @var array<string, array<string, true>> b2b_id => e-mails of its active administrators, as they will be */
    private array $admins = [];

    /**
     * @param  list<string>  $remove  e-mails
     * @param  list<array{email: string, kunde: string, preset: B2bRolePreset}>  $assign
     * @param  array<string, string>  $expectAdmin  Kunde id => e-mail
     * @param  list<string>  $activate  Kunde ids
     */
    public function __construct(
        private readonly array $remove,
        private readonly array $assign,
        private readonly array $expectAdmin,
        private readonly array $activate,
        private readonly string $batchId,
    ) {}

    /**
     * @return list<array{subject: string, action: string, state: string, run: Closure|null}>
     */
    public function plan(): array
    {
        $this->steps = [];
        $this->admins = [];

        if (! $this->resolveCompanies()) {
            return $this->steps;
        }

        foreach ($this->remove as $email) {
            $this->planRemoval($email);
        }

        foreach ($this->assign as $assignment) {
            $this->planAssignment($assignment['email'], $assignment['kunde'], $assignment['preset']);
        }

        foreach ($this->activate as $kunde) {
            $company = $this->companies[$kunde];
            $this->step($company->company_name, 'Unternehmen aktiv schalten', $company->is_active ? self::DONE : self::PENDING, fn () => $this->activateCompany($company));
        }

        $this->checkAdministrators();

        return $this->steps;
    }

    public function isBlocked(): bool
    {
        return collect($this->steps)->contains('state', self::BLOCKED);
    }

    /**
     * Plans again inside the transaction (the state may have moved since the
     * preview) and runs every pending step.
     *
     * @return list<array<string, mixed>> audit entries, empty when everything was already done
     */
    public function apply(): array
    {
        return DB::transaction(function () {
            $this->audit = [];
            $this->plan();

            if ($this->isBlocked()) {
                throw new \RuntimeException('Correction blocked; nothing was changed.');
            }

            foreach ($this->steps as $step) {
                if ($step['state'] === self::PENDING && $step['run'] !== null) {
                    ($step['run'])();
                }
            }

            return $this->audit;
        });
    }

    private function resolveCompanies(): bool
    {
        $kunden = array_unique([...array_column($this->assign, 'kunde'), ...array_keys($this->expectAdmin), ...$this->activate]);
        $this->companies = [];

        foreach ($kunden as $kunde) {
            $target = LegacyImportMap::where('entity', 'kunde')->where('legacy_id', $kunde)->whereIn('status', ['imported', 'linked'])->value('target_id');
            $company = $target ? DB::table('b2b')->where('b2b_id', $target)->first(['b2b_id', 'company_name', 'is_active']) : null;

            if ($company === null) {
                $this->step($kunde, 'Base44-Unternehmen ist in V2 nicht vorhanden', self::BLOCKED);

                continue;
            }

            $this->companies[$kunde] = $company;
            $this->admins[$company->b2b_id] ??= $this->currentAdmins($company->b2b_id);
        }

        return ! $this->isBlocked();
    }

    private function planRemoval(string $email): void
    {
        $map = LegacyImportMap::where('entity', 'user')->where('legacy_id', $email)->first();

        if ($map === null) {
            $this->step($email, 'Kein Base44-Benutzer mit dieser E-Mail', self::BLOCKED);

            return;
        }

        if (in_array($email, array_column($this->assign, 'email'), true)) {
            $this->step($email, 'Steht sowohl auf der Entfernen- als auch auf der Zuordnen-Liste', self::BLOCKED);

            return;
        }

        if ($map->status === 'linked') {
            $this->step($email, 'Bestehendes V2-Konto (nicht vom Import angelegt) – bitte manuell klären', self::BLOCKED);

            return;
        }

        $user = $this->mappedUser($map);

        if ($user === false) {
            $this->step($email, 'Import-Zuordnung zeigt auf ein anderes Konto als diese E-Mail', self::BLOCKED);

            return;
        }

        if ($user !== null && $user->user_type !== UserType::Firmenkunde->value) {
            $this->step($email, "V2-Konto ist kein Firmenkunde ({$user->user_type}) – wird nicht angefasst", self::BLOCKED);

            return;
        }

        if ($user === null) {
            $this->step($email, 'Kein V2-Konto vorhanden (vom Import nicht angelegt) – bleibt ausgeschlossen', $map->status === 'removed' ? self::DONE : self::PENDING,
                fn () => $this->markRemoved($email, $map, null, collect()));

            return;
        }

        $memberships = $this->memberships((int) $user->id);

        foreach ($memberships as $membership) {
            $this->step($email, "Mitgliedschaft entfernen: {$membership->company_name} ({$this->roleName($membership)})", self::PENDING);
            unset($this->admins[$membership->b2b_id][$email]);
        }

        $done = $map->status === 'removed' && ! $user->is_active && $memberships->isEmpty();
        $this->step($email, 'Konto deaktivieren, keine Aktivierungs-E-Mail (bleibt Ersteller seiner Fahrzeuge/Aufträge/Dokumente)', $done ? self::DONE : self::PENDING,
            fn () => $this->markRemoved($email, $map, $user, $memberships));
    }

    private function markRemoved(string $email, LegacyImportMap $map, ?object $user, Collection $memberships): void
    {
        if ($user !== null) {
            DB::table('user_b2b')->where('user_id', $user->id)->delete();
            DB::table('users')->where('id', $user->id)->update(['is_active' => false, 'active_b2b_id' => null, 'updated_at' => now()]);
            DB::table('legacy_activation_tokens')->whereRaw('LOWER(email) = ?', [$email])->delete();
        }

        $correction = $this->correction('removed', 'Entfernt (vom Kunden freigegeben)', ['previous_status' => $map->status, 'was_active' => $user ? (bool) $user->is_active : null, 'memberships' => $memberships->map(fn ($m) => (array) $m)->all()]);
        $map->forceFill(['status' => 'removed', 'payload' => [...($map->payload ?? []), 'client_correction' => $correction]])->save();
        LegacyImportMap::where('entity', 'membership')->where('legacy_id', 'like', $email.'|%')->update(['status' => 'removed']);

        $this->audit[] = ['email' => $email, 'user_id' => $user?->id, ...$correction];
    }

    private function planAssignment(string $email, string $kunde, B2bRolePreset $preset): void
    {
        $company = $this->companies[$kunde];
        $map = LegacyImportMap::where('entity', 'user')->where('legacy_id', $email)->first();

        if ($map === null || $map->status === 'removed') {
            $this->step($email, $map ? 'Benutzer ist als entfernt vermerkt' : 'Kein Base44-Benutzer mit dieser E-Mail', self::BLOCKED);

            return;
        }

        $user = $this->mappedUser($map);
        $existing = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first(['id']);

        if ($user === false || ($map->status === 'skipped' && $existing !== null)) {
            $this->step($email, 'V2-Konto passt nicht zur Import-Zuordnung – bitte manuell klären', self::BLOCKED);

            return;
        }

        if ($map->status === 'skipped' && ! in_array($map->payload['reason'] ?? null, self::ASSIGNABLE_SKIPS, true)) {
            $this->step($email, 'Vom Import aus anderem Grund zurückgehalten ('.($map->payload['reason'] ?? '?').')', self::BLOCKED);

            return;
        }

        if ($map->status !== 'skipped' && $user === null) {
            $this->step($email, 'V2-Konto fehlt', self::BLOCKED);

            return;
        }

        if ($user !== null && ($user->user_type !== UserType::Firmenkunde->value || ! $user->is_active)) {
            $this->step($email, $user->is_active ? "V2-Konto ist kein Firmenkunde ({$user->user_type})" : 'V2-Konto ist deaktiviert', self::BLOCKED);

            return;
        }

        if ($user === null) {
            $this->step($email, 'V2-Konto anlegen wie beim Import (ohne Passwort; Aktivierungs-E-Mail später)', self::PENDING, fn () => $this->createAccount($email, $map, $company->b2b_id));
        } else {
            $this->step($email, 'Bestehendes Konto bleibt unverändert (Passwort, MFA, Aktivierung, E-Mail)', self::CHECKED);
        }

        $others = $user ? $this->memberships((int) $user->id)->where('b2b_id', '!=', $company->b2b_id) : collect();

        foreach ($others as $membership) {
            $this->step($email, "Mitgliedschaft entfernen: {$membership->company_name} ({$this->roleName($membership)})", self::PENDING);
            unset($this->admins[$membership->b2b_id][$email]);
        }

        $current = $user ? DB::table('user_b2b')->where('user_id', $user->id)->where('b2b_id', $company->b2b_id)->first(['role', 'permissions', 'status']) : null;
        $inPlace = $current !== null && $current->status === 'active'
            && B2bRolePreset::match(B2bRole::from($current->role), B2bPermissionSet::fromRaw(json_decode((string) $current->permissions, true) ?? [])) === $preset
            && $others->isEmpty();

        $this->step($email, "{$preset->label()} von {$company->company_name}", $inPlace ? self::DONE : self::PENDING,
            fn () => $this->assignMembership($email, $kunde, $company, $preset, $others));

        if ($preset === B2bRolePreset::CompanyAdministrator) {
            $this->admins[$company->b2b_id][$email] = true;
        } else {
            unset($this->admins[$company->b2b_id][$email]);
        }
    }

    private function createAccount(string $email, LegacyImportMap $map, string $b2bId): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
            'user_type' => UserType::Firmenkunde->value,
            'is_active' => true,
            'email_verified_at' => now(),
            'active_b2b_id' => $b2bId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `imported` under this correction's batch: the activation mail picks it up, and legacy:rollback <batch> undoes it.
        $correction = $this->correction('account_created', 'Konto angelegt (vom Kunden freigegeben)', ['previous_status' => $map->status, 'previous_reason' => $map->payload['reason'] ?? null]);
        $map->forceFill(['status' => 'imported', 'target_table' => 'users', 'target_id' => (string) $userId, 'batch_id' => $this->batchId, 'payload' => [...($map->payload ?? []), 'client_correction' => $correction]])->save();

        $this->audit[] = ['email' => $email, 'user_id' => $userId, ...$correction];
    }

    /**
     * Only user_b2b and the company the account opens in change; nothing on
     * the account that logs in.
     */
    private function assignMembership(string $email, string $kunde, object $company, B2bRolePreset $preset, Collection $others): void
    {
        $userId = (int) DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');
        $before = DB::table('user_b2b')->where('user_id', $userId)->where('b2b_id', $company->b2b_id)->first(['role', 'permissions', 'status']);
        $values = ['role' => $preset->role()->value, 'permissions' => json_encode($preset->permissions()->toArray()), 'vehicle_scope' => 'all', 'status' => 'active', 'updated_at' => now()];

        foreach ($others as $membership) {
            DB::table('user_b2b')->where('user_id', $userId)->where('b2b_id', $membership->b2b_id)->delete();
        }

        LegacyImportMap::where('entity', 'membership')->where('legacy_id', 'like', $email.'|%')->where('legacy_id', '!=', $email.'|'.$kunde)->update(['status' => 'removed']);

        if ($before === null) {
            DB::table('user_b2b')->insert([...$values, 'user_id' => $userId, 'b2b_id' => $company->b2b_id, 'joined_at' => now(), 'created_at' => now()]);
        } else {
            DB::table('user_b2b')->where('user_id', $userId)->where('b2b_id', $company->b2b_id)->update($values);
        }

        DB::table('users')->where('id', $userId)->where(fn ($q) => $q->whereNull('active_b2b_id')->orWhere('active_b2b_id', '!=', $company->b2b_id))->update(['active_b2b_id' => $company->b2b_id]);

        $note = $others->isEmpty()
            ? "{$company->company_name} zugeordnet als {$preset->label()}"
            : 'von '.$others->pluck('company_name')->implode(', ')." zu {$company->company_name} verschoben, {$preset->label()}";
        $correction = $this->correction('assigned', 'Freigegebene Korrektur: '.$note, ['b2b_id' => $company->b2b_id, 'role' => $preset->value, 'before' => $before ? (array) $before : null, 'removed_memberships' => $others->map(fn ($m) => (array) $m)->values()->all()]);

        LegacyImportMap::updateOrCreate(
            ['entity' => 'membership', 'legacy_id' => $email.'|'.$kunde],
            ['status' => 'imported', 'target_table' => 'user_b2b', 'target_id' => $userId.'|'.$company->b2b_id, 'batch_id' => $this->batchId, 'payload' => ['role' => $preset->role()->value, 'client_correction' => $correction]],
        );

        $userMap = LegacyImportMap::where('entity', 'user')->where('legacy_id', $email)->first();
        $userMap->forceFill(['payload' => [...($userMap->payload ?? []), 'client_correction' => $userMap->payload['client_correction'] ?? $correction]])->save();

        $this->audit[] = ['email' => $email, 'user_id' => $userId, ...$correction];
    }

    private function activateCompany(object $company): void
    {
        DB::table('b2b')->where('b2b_id', $company->b2b_id)->update(['is_active' => true, 'updated_at' => now()]);

        $this->audit[] = ['company' => $company->company_name, 'b2b_id' => $company->b2b_id, ...$this->correction('company_activated', 'Unternehmen aktiv geschaltet', ['was_active' => (bool) $company->is_active])];
    }

    /**
     * Every company this run touches must end with exactly one administrator,
     * and the one the client named where they named one.
     */
    private function checkAdministrators(): void
    {
        $names = DB::table('b2b')->whereIn('b2b_id', array_keys($this->admins))->pluck('company_name', 'b2b_id');

        foreach ($this->admins as $b2bId => $emails) {
            $emails = array_keys($emails);
            $kunde = array_search($b2bId, array_map(fn ($c) => $c->b2b_id, $this->companies), true);
            $expected = $kunde !== false ? ($this->expectAdmin[$kunde] ?? null) : null;
            $name = $names[$b2bId] ?? $b2bId;

            if (count($emails) !== 1) {
                $this->step($name, count($emails) === 0 ? 'Hätte danach keinen Unternehmensadministrator' : 'Hätte danach '.count($emails).' Unternehmensadministratoren: '.implode(', ', $emails), self::BLOCKED);
            } elseif ($expected !== null && $emails[0] !== $expected) {
                $this->step($name, "Einziger Administrator wäre {$emails[0]}, erwartet {$expected}", self::BLOCKED);
            } else {
                $this->step($name, "Danach genau ein Unternehmensadministrator: {$emails[0]}", self::CHECKED);
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function currentAdmins(string $b2bId): array
    {
        return DB::table('user_b2b as ub')->join('users as u', 'u.id', '=', 'ub.user_id')
            ->where('ub.b2b_id', $b2bId)->where('ub.role', B2bRole::Owner->value)->where('ub.status', 'active')
            ->pluck('u.email')->mapWithKeys(fn ($email) => [Str::lower($email) => true])->all();
    }

    /** Memberships also mark their companies for the administrator check. */
    private function memberships(int $userId): Collection
    {
        $memberships = DB::table('user_b2b as ub')->join('b2b as b', 'b.b2b_id', '=', 'ub.b2b_id')->where('ub.user_id', $userId)
            ->get(['ub.b2b_id', 'b.company_name', 'ub.role', 'ub.permissions', 'ub.vehicle_scope', 'ub.status', 'ub.joined_at']);

        foreach ($memberships as $membership) {
            $this->admins[$membership->b2b_id] ??= $this->currentAdmins($membership->b2b_id);
        }

        return $memberships;
    }

    /**
     * The account a user map row points at; false when it points at an
     * account with a different e-mail.
     */
    private function mappedUser(LegacyImportMap $map): object|false|null
    {
        if ($map->target_table !== 'users') {
            return null;
        }

        $user = DB::table('users')->where('id', $map->target_id)->first(['id', 'email', 'user_type', 'is_active']);

        return $user !== null && Str::lower($user->email) !== $map->legacy_id ? false : $user;
    }

    private function roleName(object $membership): string
    {
        return $membership->role === B2bRole::Owner->value ? 'Unternehmensadministrator' : 'Mitglied';
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function correction(string $action, string $note, array $details = []): array
    {
        return ['action' => $action, 'note' => $note, 'batch' => $this->batchId, 'at' => now()->toIso8601String(), ...$details];
    }

    private function step(string $subject, string $action, string $state, ?Closure $run = null): void
    {
        $this->steps[] = compact('subject', 'action', 'state', 'run');
    }
}
