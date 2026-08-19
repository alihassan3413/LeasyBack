<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Mail\Workshop\WorkshopQuotationRequestedMail;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotationItem;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Workshop quotations (§9). A workshop never gets a portal account: Admin
 * issues a time-limited, revocable link per workshop, and the workshop prices
 * the order's existing appraisal positions through it.
 *
 * The link's secret follows the `b2b_invitations` precedent exactly — the
 * plaintext token is returned once at creation and never stored; only its
 * sha256 hash is persisted, and lookup failure modes are indistinguishable so
 * a caller cannot tell "unknown" from "expired" from "revoked".
 *
 * Both channels. A quotation is scoped to one order and reaches nothing but
 * that order's positions, and "what would this repair cost" is the same
 * question whoever the car belongs to — the workshop is never told, and has no
 * way to ask. The public submission route was already channel-blind; the Admin
 * side is no longer guarded on the vehicle being B2B either.
 *
 * The step after this one — turning a submitted quotation into a customer offer
 * (RepairOfferService) — is shared too. The one thing that genuinely differs by
 * channel is whether that offer shows a gross price, and OfferPricingPolicy owns
 * it; nothing about collecting the quote itself does.
 */
class WorkshopQuotationService
{
    public const DEFAULT_TTL_DAYS = 14;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return array<string, mixed>
     */
    public static function inviteRules(): array
    {
        return [
            'workshop_label' => ['required', 'string', 'max:255'],
            'invited_email' => ['nullable', 'email', 'max:255'],
            'show_appraisal_amounts' => ['nullable', 'boolean'],
            'ttl_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    /**
     * The workshop's own submission. Amounts are net and per position; there is
     * deliberately no gross field to fill in.
     *
     * @param  array<int, string>  $positionIds
     * @return array<string, mixed>
     */
    public static function submissionRules(array $positionIds): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'earliest_repair_start' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'processing_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'cannot_repair_for_amount' => ['nullable', 'boolean'],
            'cannot_repair_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['present', 'array', 'max:200'],
            'items.*.appraisal_position_id' => ['required', 'uuid', 'in:'.implode(',', $positionIds)],
            'items.*.amount_net' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'items.*.repair_method' => ['nullable', 'string', 'max:255'],
            'items.*.not_repairable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Issue one workshop's link. Every invitation is independent: a second
     * workshop on the same order gets its own token, its own expiry and its own
     * items, and neither can reach the other through anything this service
     * exposes.
     *
     * The plaintext token exists only inside this method, and the email is the
     * one place it is allowed to travel to. It is sent *after* the row is
     * written, so a mail failure can never leave an admin holding a link to an
     * invitation that does not exist — and never rolls one back either: the
     * invitation is valid whether or not the message arrived, and the URL is
     * still returned for the admin to copy.
     *
     * `notified` distinguishes the three outcomes the UI has to tell apart:
     * true (sent), false (an address was given and the send failed), and null
     * (no address was given, so nothing was expected).
     *
     * @param  array<string, mixed>  $validated
     * @return array{quotation: WorkshopQuotation, token: string, url: string, notified: bool|null}
     */
    public function invite(LeasybackOrder $order, User $user, array $validated): array
    {
        $token = Str::random(64);
        $ttlDays = (int) ($validated['ttl_days'] ?? self::DEFAULT_TTL_DAYS);

        $quotation = WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => $this->hash($token),
            'workshop_label' => trim((string) $validated['workshop_label']),
            'invited_email' => $this->trimToNull($validated['invited_email'] ?? null),
            'show_appraisal_amounts' => (bool) ($validated['show_appraisal_amounts'] ?? true),
            'expires_at' => now()->addDays($ttlDays),
            'created_by_user_id' => $user->id,
        ]);

        $url = route('workshop.quotations.show', ['token' => $token]);

        $this->auditInvitation($order, $quotation, $user);

        return [
            'quotation' => $quotation,
            'token' => $token,
            'url' => $url,
            'notified' => $this->sendInvitation($order, $quotation, $url),
        ];
    }

    /**
     * Best-effort, exactly as the commissioning mail is: one invite request
     * produces one send, a failure is logged and reported rather than thrown,
     * and the invitation stands either way.
     */
    private function sendInvitation(LeasybackOrder $order, WorkshopQuotation $quotation, string $url): ?bool
    {
        if (blank($quotation->invited_email)) {
            return null;
        }

        try {
            Mail::to($quotation->invited_email)->send($this->invitationMail($order, $quotation, $url));
        } catch (\Throwable $e) {
            Log::error('Workshop quotation invitation email failed', [
                'auftragsnummer' => $order->auftragsnummer,
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function invitationMail(LeasybackOrder $order, WorkshopQuotation $quotation, string $url): WorkshopQuotationRequestedMail
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        $positions = $this->positionsFor($order->id);

        return new WorkshopQuotationRequestedMail(
            workshopLabel: $quotation->workshop_label,
            orderReference: (string) $order->auftragsnummer,
            vehicleLabel: trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) ?: 'Fahrzeug',
            licensePlate: $vehicle?->license_plate,
            vin: $vehicle?->vin,
            firstRegistration: $vehicle?->first_registration_date?->format('d.m.Y'),
            mileage: $vehicle?->mileage,
            positionCount: $positions->count(),
            // The same flag that decides whether the portal shows the workshop
            // what is budgeted decides it here. With it off, the email carries
            // no amount at all rather than a number the link would then hide.
            requestedTotalNet: $quotation->show_appraisal_amounts
                ? $positions->reduce(fn (string $total, AppraisalPosition $position) => bcadd($total, $position->effectiveAmountNet(), 2), '0')
                : null,
            expiresOn: $quotation->expires_at->format('d.m.Y'),
            quotationUrl: $url,
        );
    }

    /**
     * Who was asked, and by whom. Never the token, the URL or the hash — this
     * row is read by anyone with order access, and a credential in it would
     * outlive every protection the token model provides.
     */
    private function auditInvitation(LeasybackOrder $order, WorkshopQuotation $quotation, User $user): void
    {
        OrderAuditLog::create([
            'order_id' => $order->id,
            'vehicle_id' => $order->vehicle_id,
            'action' => 'WORKSHOP_QUOTATION_INVITED',
            'old_values' => null,
            'new_values' => [
                'quotation_id' => $quotation->id,
                'workshop_label' => $quotation->workshop_label,
                'invited_email' => $quotation->invited_email,
                'expires_at' => $quotation->expires_at?->toISOString(),
            ],
            'changed_by_user_id' => $user->id,
        ]);
    }

    public function revoke(WorkshopQuotation $quotation, User $user): void
    {
        if ($quotation->isRevoked()) {
            return;
        }

        $quotation->update(['revoked_at' => now(), 'revoked_by_user_id' => $user->id]);
    }

    /**
     * Resolve a quotation by its plaintext token. Returns null for anything
     * unusable — unknown, revoked, expired or already submitted — so the
     * public endpoint cannot be used to probe which tokens exist.
     */
    public function findOpenByToken(string $token): ?WorkshopQuotation
    {
        $quotation = WorkshopQuotation::where('token_hash', $this->hash($token))->first();

        return $quotation !== null && $quotation->isOpenForSubmission() ? $quotation : null;
    }

    /**
     * The workshop's own submission.
     *
     * Everything that is not a real first submission has already been turned
     * away before the announcement below: an unknown, revoked or expired token
     * never resolves at the controller, a validation failure never reaches this
     * method, and a replay is refused by the locked re-read inside the
     * transaction. So the notification sits after the commit and needs no
     * conditions of its own — reaching that line *is* the condition.
     *
     * @param  array<string, mixed>  $validated
     */
    public function submit(WorkshopQuotation $quotation, array $validated): void
    {
        if (! $quotation->isOpenForSubmission()) {
            $this->fail(410, 'Dieser Link ist nicht mehr gültig.');
        }

        DB::transaction(function () use ($quotation, $validated) {
            $locked = WorkshopQuotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpenForSubmission()) {
                $this->fail(410, 'Dieser Link ist nicht mehr gültig.');
            }

            WorkshopQuotationItem::where('quotation_id', $locked->id)->delete();

            $total = '0';

            foreach ($validated['items'] ?? [] as $item) {
                $notRepairable = (bool) ($item['not_repairable'] ?? false);
                $amount = $notRepairable ? null : $this->amountOrNull($item['amount_net'] ?? null);

                WorkshopQuotationItem::create([
                    'quotation_id' => $locked->id,
                    'appraisal_position_id' => $item['appraisal_position_id'],
                    'amount_net' => $amount,
                    'repair_method' => $this->trimToNull($item['repair_method'] ?? null),
                    'not_repairable' => $notRepairable,
                ]);

                if ($amount !== null) {
                    $total = bcadd($total, $amount, 2);
                }
            }

            $locked->update([
                'company_name' => trim((string) $validated['company_name']),
                'contact_person' => trim((string) $validated['contact_person']),
                'contact_email' => trim((string) $validated['contact_email']),
                'contact_phone' => $this->trimToNull($validated['contact_phone'] ?? null),
                'earliest_repair_start' => $this->trimToNull($validated['earliest_repair_start'] ?? null),
                'processing_days' => $validated['processing_days'] ?? null,
                'cannot_repair_for_amount' => (bool) ($validated['cannot_repair_for_amount'] ?? false),
                'cannot_repair_note' => $this->trimToNull($validated['cannot_repair_note'] ?? null),
                'total_net' => $total,
                'submitted_at' => now(),
            ]);
        });

        $this->announceSubmission($quotation->fresh());
    }

    /**
     * Tell Admin a quotation arrived, so nobody has to reopen the order to find
     * out. Reuses the existing Notifier — database row, Reverb broadcast and
     * web push in one call — rather than adding a second way to reach an admin.
     *
     * `sendNow` rather than `send`: the value of this one is that it appears
     * while the admin is still looking at the order, and the workshop's request
     * is already finished and committed by the time it runs.
     */
    private function announceSubmission(WorkshopQuotation $quotation): void
    {
        $order = LeasybackOrder::whereKey($quotation->order_id)->first();

        if ($order === null) {
            return;
        }

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        $workshop = $quotation->company_name ?: $quotation->workshop_label;
        $total = $quotation->total_net === null
            ? null
            : number_format((float) $quotation->total_net, 2, ',', '.').' € netto';

        $this->auditSubmission($order, $quotation);

        $this->notifier->sendNow(
            $this->admins(),
            NotificationPayload::make(
                NotificationType::WorkshopQuotationReceived,
                'Werkstattangebot eingegangen',
                trim(sprintf(
                    '%s hat ein Angebot für %s abgegeben%s.',
                    $workshop,
                    $vehicle?->license_plate ?? $order->auftragsnummer,
                    $total === null ? '' : ': '.$total,
                )),
                route('admin.orders.show', $order->id, false),
                // No token, no hash, no link to the workshop's own form — this
                // payload is stored and broadcast, and the credential belongs
                // to exactly one email.
                [
                    'order_id' => $order->id,
                    'auftragsnummer' => $order->auftragsnummer,
                    'quotation_id' => $quotation->id,
                    'workshop' => $workshop,
                    'total_net' => $quotation->total_net === null ? null : (string) $quotation->total_net,
                    'submitted_at' => $quotation->submitted_at?->toISOString(),
                ],
            ),
        );
    }

    private function auditSubmission(LeasybackOrder $order, WorkshopQuotation $quotation): void
    {
        OrderAuditLog::create([
            'order_id' => $order->id,
            'vehicle_id' => $order->vehicle_id,
            'action' => 'WORKSHOP_QUOTATION_SUBMITTED',
            'old_values' => null,
            'new_values' => [
                'quotation_id' => $quotation->id,
                'workshop_label' => $quotation->workshop_label,
                'company_name' => $quotation->company_name,
                'total_net' => $quotation->total_net === null ? null : (string) $quotation->total_net,
                'submitted_at' => $quotation->submitted_at?->toISOString(),
            ],
            // A guest with a link, not a user. The actor is the workshop, and
            // the workshop has no account by design.
            'changed_by_user_id' => null,
        ]);
    }

    /**
     * Mirrors OrderMessageService::admins() — the same audience, resolved the
     * same way, because "which humans staff this system" is one question.
     *
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return User::where('user_type', UserType::Admin->value)
            ->where('is_active', true)
            ->get();
    }

    /**
     * What the workshop sees behind the link. Deliberately minimal: the
     * vehicle it will repair and the positions to price. No customer identity,
     * no internal notes, no order status, no other quotation — in particular no
     * sibling workshop's prices, which is what makes inviting three workshops
     * to the same order a real comparison rather than an auction.
     *
     * Built from `$quotation` alone. Nothing here is read from the request, so
     * a tampered id in a payload cannot widen what comes back.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(WorkshopQuotation $quotation): array
    {
        $order = LeasybackOrder::whereKey($quotation->order_id)->first();
        $vehicle = $order === null ? null : Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        $showAmounts = $quotation->show_appraisal_amounts;

        return [
            'workshop_label' => $quotation->workshop_label,
            'expires_at' => $quotation->expires_at?->toISOString(),
            'shows_appraisal_amounts' => $showAmounts,
            'vehicle' => $vehicle === null ? null : [
                'license_plate' => $vehicle->license_plate,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'vin' => $vehicle->vin,
                'first_registration_date' => $vehicle->first_registration_date?->toDateString(),
                'mileage' => $vehicle->mileage,
            ],
            'positions' => $this->positionsFor($quotation->order_id)
                ->map(fn (AppraisalPosition $position) => [
                    'id' => $position->id,
                    'component' => $position->component,
                    'damage_description' => $position->damage_description,
                    'repair_method' => $position->repair_method,
                    'requested_amount_net' => $showAmounts ? $position->effectiveAmountNet() : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Admin's comparison view: every quotation for the order, each expandable
     * to a per-position appraisal-vs-workshop comparison. Submitted quotations
     * stay listed after one has been presented or accepted (§9).
     *
     * Admin-only, and the one place sibling prices sit side by side — which is
     * exactly why no workshop-facing method returns anything shaped like this.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forOrder(string $orderId): array
    {
        $positions = $this->positionsFor($orderId)->keyBy('id');

        return WorkshopQuotation::where('order_id', $orderId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (WorkshopQuotation $quotation) use ($positions) {
                $items = WorkshopQuotationItem::where('quotation_id', $quotation->id)
                    ->get()
                    ->keyBy('appraisal_position_id');

                $comparison = $positions->map(function (AppraisalPosition $position) use ($items) {
                    $item = $items->get($position->id);
                    $appraisal = $position->effectiveAmountNet();
                    $workshop = $item?->amount_net;

                    return [
                        'appraisal_position_id' => $position->id,
                        'component' => $position->component,
                        'appraisal_amount_net' => $appraisal,
                        'workshop_amount_net' => $workshop === null ? null : (string) $workshop,
                        'difference_net' => $workshop === null ? null : bcsub($appraisal, (string) $workshop, 2),
                        'repair_method' => $item?->repair_method ?? $position->repair_method,
                        'not_repairable' => (bool) ($item?->not_repairable ?? false),
                    ];
                })->values()->all();

                return [
                    'id' => $quotation->id,
                    'workshop_label' => $quotation->workshop_label,
                    'invited_email' => $quotation->invited_email,
                    'status' => $quotation->status(),
                    'shows_appraisal_amounts' => $quotation->show_appraisal_amounts,
                    'expires_at' => $quotation->expires_at?->toISOString(),
                    'submitted_at' => $quotation->submitted_at?->toISOString(),
                    'revoked_at' => $quotation->revoked_at?->toISOString(),
                    'company_name' => $quotation->company_name,
                    'contact_person' => $quotation->contact_person,
                    'contact_email' => $quotation->contact_email,
                    'contact_phone' => $quotation->contact_phone,
                    'earliest_repair_start' => $quotation->earliest_repair_start?->toDateString(),
                    'processing_days' => $quotation->processing_days,
                    'total_net' => $quotation->total_net === null ? null : (string) $quotation->total_net,
                    'cannot_repair_for_amount' => $quotation->cannot_repair_for_amount,
                    'cannot_repair_note' => $quotation->cannot_repair_note,
                    'appraisal_total_net' => $this->appraisalTotal($comparison),
                    'comparison' => $comparison,
                ];
            })
            ->all();
    }

    /**
     * @return Collection<int, AppraisalPosition>
     */
    private function positionsFor(string $orderId)
    {
        return AppraisalPosition::where('order_id', $orderId)->orderBy('sort_order')->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $comparison
     */
    private function appraisalTotal(array $comparison): string
    {
        $total = '0';

        foreach ($comparison as $row) {
            $total = bcadd($total, (string) $row['appraisal_amount_net'], 2);
        }

        return $total;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function amountOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function trimToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        return trim($value) === '' ? null : trim($value);
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
