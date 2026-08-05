<?php

namespace App\Services\Mail;

use App\Mail\Orders\AppointmentConfirmedMail;
use App\Mail\Orders\AppointmentRequestedMail;
use App\Mail\Orders\FinalInspectionCompletedMail;
use App\Mail\Orders\InitialInspectionCompletedMail;
use App\Mail\Orders\OfferApprovalReminderMail;
use App\Mail\Orders\OrderCompletedMail;
use App\Mail\Orders\OrderCreatedAdminMail;
use App\Mail\Orders\OrderCreatedCustomerMail;
use App\Mail\Orders\OrderEventMail;
use App\Mail\Orders\OrderStatusUpdatedMail;
use App\Mail\Orders\RepairApprovalConfirmedMail;
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
        // B2B-only terminal (§18 "order completed"). `delivered` remains the
        // B2C terminal above, so this entry cannot affect a B2C order.
        'completed' => OrderCompletedMail::class,
    ];

    public function __construct(
        private readonly OrderEmailDataFactory $dataFactory,
        private readonly MailRecipientResolver $recipients,
    ) {}

    public function orderCreated(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $vehicle ??= $order->vehicle;

        $this->sendToAdmins(
            $order,
            new OrderCreatedAdminMail($this->dataFactory->forAdmin($order, $vehicle)),
        );

        $customerMailable = $order->order_status === 'order_requested'
            ? AppointmentRequestedMail::class
            : OrderCreatedCustomerMail::class;

        $this->sendToCustomer($order, $vehicle, $customerMailable);
    }

    public function statusUpdated(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $vehicle ??= $order->vehicle;

        $mailable = self::STATUS_MAILABLES[(string) $order->order_status] ?? OrderStatusUpdatedMail::class;

        $this->sendToCustomer($order, $vehicle, $mailable);
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
     * The §18 "customer action required" reminder. Only ever sent by
     * SendB2bOfferReminders, which owns the 24 h spacing and the stop
     * conditions — nothing else may call this, or the spacing is meaningless.
     */
    public function offerApprovalReminder(LeasybackOffer $offer): void
    {
        $this->sendOfferMail($offer, OfferApprovalReminderMail::class);
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
