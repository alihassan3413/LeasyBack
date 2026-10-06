<?php

namespace App\Support\LegacyImport\Steps;

use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Base44 users → users + user_b2b.
 *
 * Only active, company-bound users are created. Nobody is mailed and nobody
 * gets a usable password: each account holds an unguessable hash and is
 * activated later through the normal password reset. Staff admins and invited
 * users are reported, never created; an existing V2 account is never altered.
 */
final class UserStep extends AbstractStep
{
    public function name(): string
    {
        return 'users';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->plan->usersByEmail as $email => $row) {
            $hash = LegacyValue::hash($row);

            if ($this->seenBefore($context, 'user', $email, $hash)) {
                continue;
            }

            $reason = $this->rejection($context, $email, $row);

            if ($reason !== null) {
                $context->map->record('user', $email, 'skipped', payload: ['reason' => $reason, 'role' => $row['role'], 'status' => $row['status']], hash: $hash);
                $context->report->add('user', $email, $this->isReview($reason) ? 'review' : 'skipped', $reason);

                continue;
            }

            DB::transaction(fn () => $this->import($context, $email, $row, $hash));
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rejection(ImportContext $context, string $email, array $row): ?string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid_email';
        }

        if ($row['status'] !== 'active') {
            return 'invited_not_activated';
        }

        $kundeId = LegacyValue::text($row['kunde_id']);

        if ($kundeId === null) {
            return $row['role'] === 'admin' ? 'staff_admin_manual_review' : 'active_user_without_company_manual_review';
        }

        if (! isset($context->plan->kunden[$kundeId])) {
            return 'orphan_kunde';
        }

        return $context->map->target('kunde', $kundeId) === null ? 'company_not_imported' : null;
    }

    private function isReview(string $reason): bool
    {
        return str_ends_with($reason, 'manual_review');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function import(ImportContext $context, string $email, array $row, string $hash): void
    {
        $kundeId = $row['kunde_id'];
        $b2bId = $context->map->target('kunde', $kundeId);
        $createdAt = LegacyValue::timestamp($row['created_date']) ?? now()->format('Y-m-d H:i:s');
        $updatedAt = LegacyValue::timestamp($row['updated_date']) ?? $createdAt;

        $existing = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first(['id', 'user_type', 'active_b2b_id']);

        if ($existing !== null && $existing->user_type !== UserType::Firmenkunde->value) {
            $reason = 'existing_v2_account_type_'.Str::slug((string) $existing->user_type, '_');
            $context->map->record('user', $email, 'skipped', payload: ['reason' => $reason], hash: $hash);
            $context->report->add('user', $email, 'review', $reason);

            return;
        }

        if ($existing === null) {
            $userId = (int) DB::table('users')->insertGetId([
                'name' => LegacyValue::text($row['anzeigename']) ?? LegacyValue::text($row['full_name']) ?? Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'user_type' => UserType::Firmenkunde->value,
                'is_active' => true,
                'email_verified_at' => now(),
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
            $context->map->record('user', $email, 'imported', 'users', (string) $userId, $this->archivedBooks($row), $hash);
            $context->report->add('user', $email, 'imported', 'unusable_password_set');
        } else {
            $userId = (int) $existing->id;
            $context->map->record('user', $email, 'linked', 'users', (string) $userId, $this->archivedBooks($row), $hash);
            $context->report->add('user', $email, 'linked', 'existing_v2_account');
        }

        $context->rememberUser($email, $userId);

        $this->attachMembership($context, $email, $kundeId, $userId, $b2bId, $createdAt);

        DB::table('users')->where('id', $userId)->whereNull('active_b2b_id')->update(['active_b2b_id' => $b2bId]);
    }

    /**
     * V2 keeps billing addresses and cost centres per company, not per user, so
     * a user's own saved lists are preserved here rather than merged into the
     * company's.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function archivedBooks(array $row): ?array
    {
        $books = $this->filled([
            'archived_billing_addresses' => LegacyValue::json($row['gespeicherte_rechnungsadressen']),
            'archived_cost_centres' => LegacyValue::json($row['gespeicherte_kostenstellen']),
        ]);

        return $books === [] ? null : $books;
    }

    private function attachMembership(ImportContext $context, string $email, string $kundeId, int $userId, string $b2bId, string $joinedAt): void
    {
        $legacyId = $email.'|'.$kundeId;

        if (DB::table('user_b2b')->where('user_id', $userId)->where('b2b_id', $b2bId)->exists()) {
            $context->map->record('membership', $legacyId, 'linked', 'user_b2b', $userId.'|'.$b2bId);
            $context->report->add('membership', $legacyId, 'linked', 'membership_already_present');

            return;
        }

        $isOwner = in_array($email, $context->plan->ownersOf($kundeId), true);
        $preset = $isOwner ? B2bRolePreset::CompanyAdministrator : B2bRolePreset::StandardUser;

        DB::table('user_b2b')->insert([
            'user_id' => $userId,
            'b2b_id' => $b2bId,
            'role' => $preset->role()->value,
            'permissions' => $this->json($preset->permissions()->toArray()),
            'vehicle_scope' => 'all',
            'status' => 'active',
            'joined_at' => $joinedAt,
            'created_at' => $joinedAt,
            'updated_at' => $joinedAt,
        ]);

        $context->map->record('membership', $legacyId, 'imported', 'user_b2b', $userId.'|'.$b2bId, ['role' => $preset->role()->value]);
        $context->report->add('membership', $legacyId, 'imported', $isOwner ? 'owner' : 'member');
    }
}
