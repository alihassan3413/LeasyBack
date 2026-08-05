<?php

namespace App\Enums;

enum NotificationType: string
{
    case OrderStatusChanged = 'order.status_changed';
    case OrderApproved = 'order.approved';
    case OfferPublished = 'offer.published';
    case CustomerActionRequired = 'customer.action_required';
    case ReportPublished = 'report.published';
    case DocumentPublished = 'document.published';
    case AccountStatusChanged = 'account.status_changed';
    case MessageReceived = 'message.received';
    case Generic = 'generic';

    public function variant(): string
    {
        return match ($this) {
            self::OrderStatusChanged, self::MessageReceived, self::Generic => 'info',
            self::OrderApproved, self::OfferPublished, self::ReportPublished, self::DocumentPublished => 'success',
            self::AccountStatusChanged, self::CustomerActionRequired => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OrderStatusChanged => 'progress-clock',
            self::OrderApproved => 'check-decagram',
            self::OfferPublished => 'tag-outline',
            self::ReportPublished, self::DocumentPublished => 'file-document-outline',
            self::AccountStatusChanged => 'account-alert-outline',
            self::CustomerActionRequired => 'alert-circle-outline',
            self::MessageReceived => 'message-text-outline',
            self::Generic => 'bell-outline',
        };
    }
}
