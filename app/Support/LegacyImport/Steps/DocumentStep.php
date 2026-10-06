<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dateianhang and comment attachments → order files.
 *
 * Files are copied from their Base44 URL into the `documents` disk. For a
 * Gutachten or Unfallschaden they become leasyback_order_attachments (staff
 * uploads as `final_document`, everyone else's as `customer_upload`), the table
 * those services read; for every other order they become vehicle_report_documents
 * (type `sonstiges`, published, so the customer sees them where they saw them in
 * Base44). Rows are written
 * directly: VehicleReportService would notify the customer and queue
 * extraction jobs. The link back to the original comment or upload lives in
 * legacy_import_map.payload. A failed download leaves no map row, so the next
 * run retries it. In a dry run nothing is fetched.
 */
final class DocumentStep extends AbstractStep
{
    /** Services whose files live in leasyback_order_attachments. */
    private const ATTACHMENT_SERVICES = ['gutachten', 'unfallschaden'];

    public function name(): string
    {
        return 'documents';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->export->rows('dateianhang') as $row) {
            $this->handleUpload($context, $row);
        }

        foreach ($context->export->rows('kommentar') as $comment) {
            foreach ($this->attachments($comment) as $index => $attachment) {
                $this->handleCommentAttachment($context, $comment, $index, $attachment);
            }
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function handleUpload(ImportContext $context, array $row): void
    {
        $hash = LegacyValue::hash($row);

        if ($this->seenBefore($context, 'dokument', $row['id'], $hash)) {
            return;
        }

        if (str_starts_with($row['auftrag_id'], 'FAHRZEUG_IMPORT_')) {
            $context->map->record('dokument', $row['id'], 'archived', payload: ['reason' => 'vehicle_import_spreadsheet', 'row' => $row], hash: $hash);
            $context->report->add('dokument', $row['id'], 'archived', 'vehicle_import_spreadsheet');

            return;
        }

        $order = $context->map->find('auftrag', $row['auftrag_id']);

        if ($order === null || $order->status !== 'imported') {
            $reason = $order?->status === 'archived' ? 'parent_order_archived' : 'parent_order_not_imported';
            $context->map->record('dokument', $row['id'], 'skipped', payload: ['reason' => $reason], hash: $hash);
            $context->report->add('dokument', $row['id'], 'skipped', $reason);

            return;
        }

        $this->copy($context, 'dokument', $row['id'], $order, [
            'url' => $row['speicherort'],
            'name' => $row['dateiname'],
            'size' => ctype_digit($row['dateigroesse']) ? (int) $row['dateigroesse'] : null,
            'created' => $row['created_date'],
            'uploader' => $context->userIdForBase44Id($row['hochgeladen_von_nutzer_id']),
            'mime' => LegacyValue::text($row['dateityp']),
            'staff' => $context->plan->isStaffEmail($context->plan->emailByBase44UserId[LegacyValue::text($row['hochgeladen_von_nutzer_id'])] ?? null),
            'meta' => ['source' => 'dateianhang'],
        ], $hash);
    }

    /**
     * @param  array<string, string>  $comment
     * @param  array{name: string, url: string}  $attachment
     */
    private function handleCommentAttachment(ImportContext $context, array $comment, int $index, array $attachment): void
    {
        $legacyId = $comment['id'].'#'.($index + 1);
        $hash = LegacyValue::hash([$comment['id'], $attachment]);

        if ($this->seenBefore($context, 'dokument', $legacyId, $hash)) {
            return;
        }

        $order = $context->map->find('auftrag', $comment['auftrag_id']);
        $message = $context->map->find('kommentar', $comment['id']);

        if ($order === null || $order->status !== 'imported' || $message === null || $message->status !== 'imported') {
            $reason = match (true) {
                $order?->status === 'archived' => 'parent_order_archived',
                $message?->status === 'skipped' => 'comment_not_imported',
                default => 'parent_order_not_imported',
            };
            $context->map->record('dokument', $legacyId, 'skipped', payload: ['reason' => $reason, 'comment_legacy_id' => $comment['id']], hash: $hash);
            $context->report->add('dokument', $legacyId, 'skipped', $reason);

            return;
        }

        $this->copy($context, 'dokument', $legacyId, $order, [
            'url' => $attachment['url'],
            'name' => $attachment['name'],
            'size' => null,
            'created' => $comment['created_date'],
            'uploader' => $context->userIdForEmail($comment['erstellt_von_email']),
            'mime' => null,
            'staff' => $context->plan->isStaffEmail(LegacyValue::email($comment['erstellt_von_email'])),
            'meta' => ['source' => 'kommentar', 'comment_legacy_id' => $comment['id'], 'message_id' => $message->target_id],
        ], $hash);
    }

    /**
     * @param  array<string, string>  $comment
     * @return list<array{name: string, url: string}>
     */
    private function attachments(array $comment): array
    {
        $items = LegacyValue::json($comment['anhaenge']);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_array($item) && isset($item['url']) ? ['name' => (string) ($item['name'] ?? basename((string) $item['url'])), 'url' => (string) $item['url']] : null,
            $items,
        )));
    }

    /**
     * @param  array{url: string, name: string, size: int|null, created: string, uploader: int|null, mime: string|null, staff: bool, meta: array<string, mixed>}  $file
     */
    private function copy(ImportContext $context, string $entity, string $legacyId, mixed $order, array $file, string $hash): void
    {
        if (! $this->hostAllowed($file['url'])) {
            $context->map->record($entity, $legacyId, 'skipped', payload: ['reason' => 'host_not_allowed', 'url' => $file['url']], hash: $hash);
            $context->report->add($entity, $legacyId, 'skipped', 'host_not_allowed');

            return;
        }

        if ($context->options->dryRun) {
            $context->report->add($entity, $legacyId, 'planned', 'download_skipped_in_dry_run');

            return;
        }

        try {
            $response = Http::timeout(config('legacy_import.document_timeout_seconds'))
                ->withOptions(['allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['https'],
                    'on_redirect' => function ($request, $response, $uri) {
                        if (! $this->hostAllowed((string) $uri)) {
                            throw new \RuntimeException('redirect_to_host_not_allowed');
                        }
                    },
                ]])
                ->retry(config('legacy_import.document_retries'), config('legacy_import.document_retry_sleep_ms'), throw: false)
                ->get($file['url']);
        } catch (Throwable $e) {
            $context->report->add($entity, $legacyId, 'failed', 'download_error', $e::class);

            return;
        }

        $body = $response->successful() ? $response->body() : null;

        if ($body === null || $body === '') {
            $context->report->add($entity, $legacyId, 'failed', 'download_failed', 'HTTP '.$response->status());

            return;
        }

        if (strlen($body) > config('legacy_import.document_max_bytes')) {
            $context->report->add($entity, $legacyId, 'failed', 'file_too_large');

            return;
        }

        $payload = $order->payload;
        $disk = Storage::disk('documents');
        $asAttachment = in_array($payload['service_type'] ?? null, self::ATTACHMENT_SERVICES, true);
        $mime = $file['mime'] ?? (LegacyValue::text(strtok((string) $response->header('Content-Type'), ';')) ?: null);
        $kind = $file['staff'] ? 'final_document' : 'customer_upload';

        $path = $asAttachment
            ? $this->attachmentPath($payload['service_type'], $order->target_id, $kind, $file['name'])
            : $this->freePath($disk, $payload['auftragsnummer'], $payload['vehicle_id'], $file['name']);

        if ($disk->put($path, $body) !== true) {
            $context->report->add($entity, $legacyId, 'failed', 'store_failed');

            return;
        }

        try {
            DB::transaction(function () use ($context, $entity, $legacyId, $file, $payload, $order, $path, $body, $hash, $asAttachment, $kind, $mime) {
                $createdAt = LegacyValue::timestamp($file['created']) ?? now()->format('Y-m-d H:i:s');
                $documentId = $this->uuid();

                if ($asAttachment) {
                    DB::table('leasyback_order_attachments')->insert([
                        'id' => $documentId,
                        'order_id' => $order->target_id,
                        'auftragsnummer' => $payload['auftragsnummer'],
                        'kind' => $kind,
                        'original_name' => $file['name'],
                        'path' => $path,
                        'mime_type' => $mime,
                        'size' => strlen($body),
                        'uploaded_by_user_id' => $file['uploader'],
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                } else {
                    DB::table('vehicle_report_documents')->insert([
                        'id' => $documentId,
                        'auftragsnummer' => $payload['auftragsnummer'],
                        'vehicle_id' => $payload['vehicle_id'],
                        'document_type' => 'sonstiges',
                        'document_title' => $file['name'],
                        'path' => $path,
                        'published' => true,
                        'created_by_user_id' => $file['uploader'],
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                }

                $context->map->record($entity, $legacyId, 'imported', $asAttachment ? 'leasyback_order_attachments' : 'vehicle_report_documents', $documentId, $this->filled([
                    'path' => $path,
                    'kind' => $asAttachment ? $kind : null,
                    'original_url' => $file['url'],
                    'original_name' => $file['name'],
                    'sha256' => hash('sha256', $body),
                    'size' => strlen($body),
                ] + $file['meta']), $hash);
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        $context->report->add($entity, $legacyId, 'imported', '', $path);

        if ($file['size'] !== null && $file['size'] !== strlen($body)) {
            $context->report->add($entity, $legacyId, 'warning', 'size_differs_from_source', $file['size'].' vs '.strlen($body));
        }
    }

    private function hostAllowed(string $url): bool
    {
        $parts = parse_url($url);
        $host = mb_strtolower($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return false;
        }

        foreach (config('legacy_import.document_hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The layout AccidentDamageAttachmentService uses: one folder per service
     * and order, final documents in a `final` subfolder, a uuid as file name.
     */
    private function attachmentPath(string $service, string $orderId, string $kind, string $name): string
    {
        $folder = $service === 'gutachten' ? 'appraisal' : 'accident-damage';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');

        return $folder.'/'.$orderId.($kind === 'final_document' ? '/final' : '').'/'.Str::uuid().'.'.$extension;
    }

    private function freePath(mixed $disk, string $auftragsnummer, string $vehicleId, string $name): string
    {
        $safe = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename(str_replace('\\', '/', $name))) ?: 'datei';
        $stem = pathinfo($safe, PATHINFO_FILENAME);
        $extension = pathinfo($safe, PATHINFO_EXTENSION);

        for ($n = 1; ; $n++) {
            $candidate = 'vehicle-reports/'.$auftragsnummer.'/'.$stem.($n > 1 ? '-'.$n : '').($extension !== '' ? '.'.$extension : '');

            $taken = $disk->exists($candidate)
                || DB::table('vehicle_report_documents')->where('vehicle_id', $vehicleId)->where('auftragsnummer', $auftragsnummer)->where('path', $candidate)->exists();

            if (! $taken) {
                return $candidate;
            }
        }
    }
}
