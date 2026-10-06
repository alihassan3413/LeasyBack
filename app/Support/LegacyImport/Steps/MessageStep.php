<?php

namespace App\Support\LegacyImport\Steps;

use App\Models\LegacyImportMap;
use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auftragskommentar → order_messages (the shared customer/staff thread).
 *
 * Inserted directly: OrderMessageService would broadcast and notify. Read
 * markers are written so the migrated history does not show up as unread.
 */
final class MessageStep extends AbstractStep
{
    public function name(): string
    {
        return 'messages';
    }

    public function run(ImportContext $context): void
    {
        /** @var array<string, true> $touchedOrders */
        $touchedOrders = [];

        foreach ($context->export->rows('kommentar') as $row) {
            $hash = LegacyValue::hash($row);

            if ($this->seenBefore($context, 'kommentar', $row['id'], $hash)) {
                continue;
            }

            $order = $context->map->find('auftrag', $row['auftrag_id']);

            if (LegacyValue::bool($row['geloescht']) === true) {
                $this->drop($context, $row['id'], 'deleted_in_source', $hash);
            } elseif ($order === null || $order->status !== 'imported') {
                $this->drop($context, $row['id'], $order?->status === 'archived' ? 'parent_order_archived' : 'parent_order_not_imported', $hash);
            } else {
                DB::transaction(fn () => $this->import($context, $row, (string) $order->target_id, $hash));
                $touchedOrders[(string) $order->target_id] = true;
            }
        }

        $this->markAsRead($context, array_keys($touchedOrders));
    }

    private function drop(ImportContext $context, string $id, string $reason, string $hash): void
    {
        $context->map->record('kommentar', $id, 'skipped', payload: ['reason' => $reason], hash: $hash);
        $context->report->add('kommentar', $id, 'skipped', $reason);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function import(ImportContext $context, array $row, string $orderId, string $hash): void
    {
        $email = LegacyValue::email($row['erstellt_von_email']);
        $createdAt = LegacyValue::timestamp($row['created_date']) ?? now()->format('Y-m-d H:i:s');
        $senderId = $context->userIdForEmail($email);
        $messageId = $this->uuid();

        DB::table('order_messages')->insert([
            'id' => $messageId,
            'order_id' => $orderId,
            'sender_id' => $senderId,
            'sender_name' => $this->displayName($context, $email),
            'sender_is_admin' => $context->plan->isStaffEmail($email),
            'body' => $row['text'],
            'created_at' => $createdAt,
            'updated_at' => LegacyValue::timestamp($row['updated_date']) ?? $createdAt,
        ]);

        $context->map->record('kommentar', $row['id'], 'imported', 'order_messages', $messageId, null, $hash);
        $context->report->add('kommentar', $row['id'], 'imported', $senderId === null ? 'author_not_in_v2' : '');
    }

    private function displayName(ImportContext $context, ?string $email): string
    {
        if ($email === null) {
            return 'Unbekannt';
        }

        $user = $context->plan->usersByEmail[$email] ?? null;
        $name = LegacyValue::text($user['anzeigename'] ?? null);

        if ($name === null && LegacyValue::text($user['full_name'] ?? null) !== null && LegacyValue::email($user['full_name']) !== Str::before($email, '@')) {
            $name = $user['full_name'];
        }

        return $name ?? Str::of(Str::before($email, '@'))->replace(['.', '_', '-'], ' ')->title()->toString();
    }

    /**
     * Everyone who could see the thread — the company's imported users and the
     * existing admins — starts with it read.
     *
     * @param  list<string>  $orderIds
     */
    private function markAsRead(ImportContext $context, array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        $importedUsers = LegacyImportMap::query()
            ->where('entity', 'user')->whereIn('status', ['imported', 'linked'])
            ->pluck('target_id')->map(fn ($id) => (int) $id)->all();

        $admins = DB::table('users')->where('user_type', 'Admin')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($orderIds as $orderId) {
            $b2bId = DB::table('leasyback_orders as o')->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')->where('o.id', $orderId)->value('v.b2b_id');
            $members = $b2bId === null ? [] : DB::table('user_b2b')->where('b2b_id', $b2bId)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $readers = array_unique([...array_intersect($members, $importedUsers), ...$admins]);
            $lastMessage = DB::table('order_messages')->where('order_id', $orderId)->max('created_at');

            foreach ($readers as $userId) {
                DB::table('order_message_reads')->insertOrIgnore([
                    'id' => $this->uuid(),
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'last_read_at' => $lastMessage,
                    'created_at' => $lastMessage,
                    'updated_at' => $lastMessage,
                ]);
            }
        }
    }
}
