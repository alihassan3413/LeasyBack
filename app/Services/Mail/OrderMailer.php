<?php

namespace App\Services\Mail;

use App\Mail\Orders\AccidentDamageCompletedMail;
use App\Mail\Orders\AccidentDamageRequestedMail;
use App\Mail\Orders\AccidentDamageScheduledMail;
use App\Mail\Orders\AppointmentConfirmedMail;
use App\Mail\Orders\AppraisalCompletedMail;
use App\Mail\Orders\AppraisalRequestedMail;
use App\Mail\Orders\AppraisalScheduledMail;
use App\Mail\Orders\AppointmentRequestedMail;
use App\Mail\Orders\B2bCollectionRequestedMail;
use App\Mail\Orders\B2bCollectionRescheduledMail;
use App\Mail\Orders\B2bCollectionScheduledMail;
use App\Mail\Orders\B2bFinalInspectionCompletedMail;
use App\Mail\Orders\B2bVehicleCollectedMail;
use App\Mail\Orders\B2bVehicleReturnedMail;
use App\Mail\Orders\FinalInspectionCompletedMail;
use App\Mail\Orders\InitialInspectionCompletedMail;
use App\Mail\Orders\OfferApprovalReminderMail;
use App\Mail\Orders\OrderCompletedMail;
use App\Mail\Orders\OrderCreatedAdminMail;
use App\Mail\Orders\OrderCreatedCustomerMail;
use App\Mail\Orders\OrderEventMail;
use App\Mail\Orders\OrderStatusUpdatedMail;
use App\Mail\Orders\RelocationCompletedMail;
use App\Mail\Orders\RelocationDeliveredMail;
use App\Mail\Orders\RelocationRequestedMail;
use App\Mail\Orders\RelocationScheduledMail;
use App\Mail\Orders\RelocationVehicleCollectedMail;
use App\Mail\Orders\RepairApprovalConfirmedMail;
use App\Mail\Orders\RepairInvoiceAvailableMail;
use App\Mail\Orders\RepairPaymentReceivedMail;
use App\Mail\Orders\RepairQuotationAvailableMail;
use App\Mail\Orders\VehicleInRepairMail;
use App\Mail\Orders\VehicleReadyForPickupMail;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderMailer
{
    /** `leasyback_orders.service_type` of an Überführung. */
    private const SERVICE_RELOCATION = 'ueberfuehrung';

    /** `leasyback_orders.service_type` of an Unfallschaden. */
    private const SERVICE_ACCIDENT_DAMAGE = 'unfallschaden';

    /** `leasyback_orders.service_type` of a Vehicle Condition Appraisal. */
    private const SERVICE_APPRAISAL = 'gutachten';

    /**
     * @var array<string, class-string<OrderEventMail>>
     */
    private const STATUS_MAILABLES = [
        'order_requested' => AppointmentRequestedMail::class,
        'confirmed' => AppointmentConfirmedMail::class,
        'inspected' => InitialInspectionCompletedMail::class,
        'workshop' => VehicleInRepairMail::class,
        'reworkshop' => VehicleInRepairMail::class,
        'reinspection' => FinalInspectionCompletedMail::class,
        'delivered' => VehicleReadyForPickupMail::class,
        // The successful terminal of both channels. For B2B it lands after
        // billing; for B2C it lands when the customer has collected the car,
        // which is the point at which nothing is pending from anyone.
        'completed' => OrderCompletedMail::class,
    ];

    /**
     * The B2B return process (b2b.txt §1): LeasyBack collects the vehicle, so
     * nothing here may tell a fleet customer to bring a car to a station or
     * to pick it up. Statuses without an entry fall back to the generic
     * status update.
     *
     * @var array<string, class-string<OrderEventMail>>
     */
    private const B2B_STATUS_MAILABLES = [
        'order_requested' => B2bCollectionRequestedMail::class,
        'confirmed' => B2bCollectionScheduledMail::class,
        'vehicle_collected' => B2bVehicleCollectedMail::class,
        'inspected' => InitialInspectionCompletedMail::class,
        'workshop' => VehicleInRepairMail::class,
        'reinspection' => B2bFinalInspectionCompletedMail::class,
        'vehicle_returned' => B2bVehicleReturnedMail::class,
        'completed' => OrderCompletedMail::class,
    ];

    /**
     * The Überführung (B2B vehicle relocation). Its own wording throughout:
     * a relocated car is delivered to another site, not returned to a
     * leasing company, and there is no inspection or repair to mention.
     * Statuses without an entry fall back to the generic status update, as
     * in the other two maps.
     *
     * @var array<string, class-string<OrderEventMail>>
     */
    private const RELOCATION_STATUS_MAILABLES = [
        'order_requested' => RelocationRequestedMail::class,
        'confirmed' => RelocationScheduledMail::class,
        'vehicle_collected' => RelocationVehicleCollectedMail::class,
        'vehicle_returned' => RelocationDeliveredMail::class,
        'completed' => RelocationCompletedMail::class,
    ];

    /**
     * The Unfallschaden: REQUESTED → SCHEDULED → COMPLETED (Accident Damage
     * brief). Statuses without an entry fall back to the generic update.
     *
     * @var array<string, class-string<OrderEventMail>>
     */
    private const ACCIDENT_DAMAGE_STATUS_MAILABLES = [
        'order_requested' => AccidentDamageRequestedMail::class,
        'confirmed' => AccidentDamageScheduledMail::class,
        'completed' => AccidentDamageCompletedMail::class,
    ];

    /**
     * The Vehicle Condition Appraisal: REQUESTED → SCHEDULED → COMPLETED.
     *
     * @var array<string, class-string<OrderEventMail>>
     */
    private const APPRAISAL_STATUS_MAILABLES = [
        'order_requested' => AppraisalRequestedMail::class,
        'confirmed' => AppraisalScheduledMail::class,
        'completed' => AppraisalCompletedMail::class,
    ];

    public function __construct(
        private readonly OrderEmailDataFactory $dataFactory,
        private readonly MailRecipientResolver $recipients,
        private readonly EmailUrlBuilder $urls,
    ) {}

    public function orderCreated(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $vehicle ??= $order->vehicle;

        $this->sendToAdmins(
            $order,
            new OrderCreatedAdminMail($this->dataFactory->forAdmin($order, $vehicle)),
        );

        $customerMailable = match (true) {
            self::isRelocation($order) => RelocationRequestedMail::class,
            self::isAccidentDamage($order) => AccidentDamageRequestedMail::class,
            self::isAppraisal($order) => AppraisalRequestedMail::class,
            $vehicle?->vehicle_belongs === 'B2B' => B2bCollectionRequestedMail::class,
            $order->order_status === 'order_requested' => AppointmentRequestedMail::class,
            default => OrderCreatedCustomerMail::class,
        };

        $this->sendToCustomer($order, $vehicle, $customerMailable);
    }

    public function statusUpdated(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $vehicle ??= $order->vehicle;

        $mailables = match (true) {
            self::isRelocation($order) => self::RELOCATION_STATUS_MAILABLES,
            self::isAccidentDamage($order) => self::ACCIDENT_DAMAGE_STATUS_MAILABLES,
            self::isAppraisal($order) => self::APPRAISAL_STATUS_MAILABLES,
            $vehicle?->vehicle_belongs === 'B2B' => self::B2B_STATUS_MAILABLES,
            default => self::STATUS_MAILABLES,
        };
        $mailable = $mailables[(string) $order->order_status] ?? OrderStatusUpdatedMail::class;

        $this->sendToCustomer($order, $vehicle, $mailable);
    }

    /**
     * §18 "Appointment confirmed": a confirmed collection date that moves
     * afterwards is news the customer has to act on. The first confirmation
     * is announced by the `confirmed` status mail itself.
     *
     * Also used for an Überführung — its pickup date is a collection date.
     */
    public function collectionRescheduled(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $this->sendToCustomer($order, $vehicle ?? $order->vehicle, B2bCollectionRescheduledMail::class);
    }

    public function repairQuotationAvailable(LeasybackOffer $offer): void
    {
        $this->sendOfferMail($offer, RepairQuotationAvailableMail::class);
    }

    public function repairApprovalConfirmed(LeasybackOffer $offer): void
    {
        $this->sendOfferMail($offer, RepairApprovalConfirmedMail::class);
    }

    /**
     * Sent when the repair payment settles, not when the order reaches
     * `delivered` — a B2C car is only collectable once it is paid for, so
     * PaymentService owns this mail and `delivered` is suppressed in
     * TransitionOrderStatus to keep it to one sender.
     */
    public function vehicleReadyForPickup(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $this->sendToCustomer($order, $vehicle ?? $order->vehicle, VehicleReadyForPickupMail::class);
    }

    public function repairInvoiceAvailable(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        string $paymentUrl,
        ?string $invoiceNumber,
    ): void {
        $vehicle ??= $order->vehicle;
        $recipient = $this->recipients->forVehicle($vehicle);

        if ($recipient === null) {
            Log::warning('Could not resolve a customer email recipient — skipping billing email', [
                'auftragsnummer' => $order->auftragsnummer,
                'vehicle_id' => $vehicle?->vehicle_id,
            ]);

            return;
        }

        $data = $this->dataFactory->forRepairInvoice(
            $order,
            $vehicle,
            $recipient['name'],
            $paymentUrl,
            $invoiceNumber,
        );

        $this->dispatch($recipient['email'], new RepairInvoiceAvailableMail($data), [
            'auftragsnummer' => $order->auftragsnummer,
            'mailable' => RepairInvoiceAvailableMail::class,
        ]);
    }

    public function repairPaymentReceived(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        ?string $invoiceNumber,
    ): void {
        $vehicle ??= $order->vehicle;
        $recipient = $this->recipients->forVehicle($vehicle);

        if ($recipient === null) {
            Log::warning('Could not resolve a customer email recipient — skipping pickup authorisation', [
                'auftragsnummer' => $order->auftragsnummer,
                'vehicle_id' => $vehicle?->vehicle_id,
            ]);

            return;
        }

        $data = $this->dataFactory->forRepairInvoice(
            $order,
            $vehicle,
            $recipient['name'],
            $this->urls->customerVehicleUrl($vehicle),
            $invoiceNumber,
        );

        $this->dispatch($recipient['email'], new RepairPaymentReceivedMail($data), [
            'auftragsnummer' => $order->auftragsnummer,
            'mailable' => RepairPaymentReceivedMail::class,
        ]);
    }

    /**
     * The §18 "customer action required" reminder. Only ever sent by
     * SendB2bOfferReminders, which owns the 24 h spacing and the stop
     * conditions — nothing else may call this, or the spacing is meaningless.
     */
    public function offerApprovalReminder(LeasybackOffer $offer): void
    {
        $this->sendOfferMail($offer, OfferApprovalReminderMail::class);
    }

    /**
     * Read from the order's own `service_type`, never from the caller.
     */
    private static function isRelocation(LeasybackOrder $order): bool
    {
        return $order->service_type === self::SERVICE_RELOCATION;
    }

    /**
     * Read from the order's own `service_type`, never from the caller.
     */
    private static function isAccidentDamage(LeasybackOrder $order): bool
    {
        return $order->service_type === self::SERVICE_ACCIDENT_DAMAGE;
    }

    /**
     * Read from the order's own `service_type`, never from the caller.
     */
    private static function isAppraisal(LeasybackOrder $order): bool
    {
        return $order->service_type === self::SERVICE_APPRAISAL;
    }

    /**
     * @param  class-string<OrderEventMail>  $mailable
     */
    private function sendOfferMail(LeasybackOffer $offer, string $mailable): void
    {
        $order = $offer->order;

        if ($order === null) {
            Log::warning('Offer has no related order — skipping customer email', [
                'offer_id' => $offer->offer_id,
                'mailable' => $mailable,
            ]);

            return;
        }

        $this->sendToCustomer($order, $order->vehicle, $mailable, $offer);
    }

    /**
     * @param  class-string<OrderEventMail>  $mailable
     */
    private function sendToCustomer(
        LeasybackOrder $order,
        ?Vehicle $vehicle,
        string $mailable,
        ?LeasybackOffer $offer = null,
    ): void {
        $recipient = $this->recipients->forVehicle($vehicle);

        if ($recipient === null) {
            Log::warning('Could not resolve a customer email recipient — skipping email', [
                'auftragsnummer' => $order->auftragsnummer,
                'vehicle_id' => $vehicle?->vehicle_id,
                'mailable' => $mailable,
            ]);

            return;
        }

        $data = $this->dataFactory->forCustomer($order, $vehicle, $recipient['name'], $offer);

        $this->dispatch($recipient['email'], new $mailable($data), [
            'auftragsnummer' => $order->auftragsnummer,
            'mailable' => $mailable,
        ]);
    }

    private function sendToAdmins(LeasybackOrder $order, OrderEventMail $mail): void
    {
        $admins = $this->recipients->admins();

        if ($admins === []) {
            Log::warning('No admin notification recipients configured — skipping internal email', [
                'auftragsnummer' => $order->auftragsnummer,
                'mailable' => $mail::class,
            ]);

            return;
        }

        $this->dispatch($admins, $mail, [
            'auftragsnummer' => $order->auftragsnummer,
            'mailable' => $mail::class,
        ]);
    }

    /**
     * @param  string|list<string>  $to
     * @param  array<string, mixed>  $context
     */
    private function dispatch(string|array $to, OrderEventMail $mail, array $context): void
    {
        try {
            Mail::to($to)->queue($mail);
        } catch (\Throwable $e) {
            Log::error('Email dispatch failed', $context + ['error' => $e->getMessage()]);
        }
    }
}