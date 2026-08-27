<?php

namespace App\Enums;

enum NotificationType: string
{
    case OrderStatusChanged = 'order.status_changed';
    case OrderApproved = 'order.approved';
    case OfferPublished = 'offer.published';
    case OfferAccepted = 'offer.accepted';
    case OfferRejected = 'offer.rejected';
    case WorkshopQuotationReceived = 'workshop.quotation_received';
    case CustomerActionRequired = 'customer.action_required';
    case ReportPublished = 'report.published';
    case DocumentPublished = 'document.published';
    case AccountStatusChanged = 'account.status_changed';
    case MessageReceived = 'message.received';
    case PaymentActionRequired = 'payment.action_required';
    case VehicleReadyForPickup = 'vehicle.ready_for_pickup';
    case Generic = 'generic';

    public function variant(): string
    {
        return match ($this) {
            self::OrderStatusChanged, self::MessageReceived, self::Generic => 'info',
            self::OrderApproved, self::OfferPublished, self::OfferAccepted,
            self::WorkshopQuotationReceived, self::ReportPublished, self::DocumentPublished,
            self::VehicleReadyForPickup => 'success',
            self::OfferRejected => 'error',
            self::AccountStatusChanged, self::CustomerActionRequired,
            self::PaymentActionRequired => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OrderStatusChanged => 'progress-clock',
            self::OrderApproved => 'check-decagram',
            self::OfferPublished => 'tag-outline',
            self::OfferAccepted => 'check-decagram',
            self::OfferRejected => 'close-circle-outline',
            self::WorkshopQuotationReceived => 'wrench-outline',
            self::ReportPublished, self::DocumentPublished => 'file-document-outline',
            self::AccountStatusChanged => 'account-alert-outline',
            self::CustomerActionRequired => 'alert-circle-outline',
            self::MessageReceived => 'message-text-outline',
            self::PaymentActionRequired => 'credit-card-outline',
            self::VehicleReadyForPickup => 'car-key',
            self::Generic => 'bell-outline',
        };
    }
}
