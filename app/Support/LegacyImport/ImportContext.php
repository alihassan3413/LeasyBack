<?php

namespace App\Support\LegacyImport;

use Illuminate\Support\Facades\DB;

/**
 * What every step needs: the export, the shared plan, the id map and the
 * report, plus the e-mail → V2 user lookup used wherever Base44 names a person.
 */
final class ImportContext
{
    public readonly ImportPlan $plan;

    public readonly IdMap $map;

    public readonly ImportReport $report;

    /** @var array<string, int|null> */
    private array $userIds = [];

    public function __construct(
        public readonly LegacyExport $export,
        public readonly ImportOptions $options,
    ) {
        $this->plan = new ImportPlan($export);
        $this->map = new IdMap($options->batchId);
        $this->report = new ImportReport;
    }

    /** The V2 user for a Base44 e-mail: an imported/linked account, else an existing one. */
    public function userIdForEmail(?string $email): ?int
    {
        $email = LegacyValue::email($email);

        if ($email === null) {
            return null;
        }

        if (! array_key_exists($email, $this->userIds)) {
            $mapped = $this->map->target('user', $email);

            $this->userIds[$email] = $mapped !== null
                ? (int) $mapped
                : DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');
        }

        return $this->userIds[$email];
    }

    public function rememberUser(string $email, int $id): void
    {
        $this->userIds[$email] = $id;
    }

    /** The V2 user a Base44 user id (as found in created_by_id & co.) stands for. */
    public function userIdForBase44Id(?string $base44Id): ?int
    {
        $base44Id = LegacyValue::text($base44Id);

        return $base44Id === null ? null : $this->userIdForEmail($this->plan->emailByBase44UserId[$base44Id] ?? null);
    }
}
