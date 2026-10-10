<?php

namespace Tests\Feature\LegacyImport\Concerns;

use App\Enums\B2bRolePreset;
use App\Models\B2B;
use App\Models\LegacyImportMap;
use App\Models\User;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;

/**
 * Companies and users as legacy:import leaves them: the V2 rows plus the
 * legacy_import_map rows that record where they came from.
 */
trait BuildsLegacyB2bImport
{
    use BuildsB2bCompanies;

    protected int $kunden = 0;

    /** A company as the importer leaves it: the b2b row and its kunde map row. */
    protected function importedCompany(string $name, ?string $kundennummer = null): B2B
    {
        $company = $this->makeCompany($name);
        $this->map('kunde', 'k'.(++$this->kunden), 'imported', 'b2b', $company->b2b_id, array_filter(['kundennummer' => $kundennummer]));

        return $company;
    }

    /** A user as the importer leaves it: account, user map row, membership and its map row. */
    protected function importedUser(B2B $company, string $email, string $name, B2bRolePreset $preset, string $mapStatus = 'imported'): User
    {
        $user = $preset === B2bRolePreset::CompanyAdministrator
            ? $this->makeOwner($company)
            : $this->makeMember($company, $preset->permissions()->toArray(), role: $preset->role()->value);
        $user->forceFill(['email' => $email, 'name' => $name])->save();

        $kunde = LegacyImportMap::where('entity', 'kunde')->where('target_id', $company->b2b_id)->value('legacy_id');
        $this->map('user', $email, $mapStatus, 'users', (string) $user->id);
        $this->map('membership', $email.'|'.$kunde, 'imported', 'user_b2b', $user->id.'|'.$company->b2b_id, ['role' => $preset->role()->value]);

        return $user;
    }

    protected function map(string $entity, string $legacyId, string $status, ?string $table = null, ?string $target = null, ?array $payload = null): void
    {
        LegacyImportMap::create([
            'entity' => $entity, 'legacy_id' => $legacyId, 'status' => $status,
            'target_table' => $table, 'target_id' => $target, 'payload' => $payload, 'batch_id' => 'test-batch',
        ]);
    }
}
