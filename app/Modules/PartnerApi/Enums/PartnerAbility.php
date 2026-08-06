<?php

namespace App\Modules\PartnerApi\Enums;

/**
 * Every distinct thing a partner integration token can be granted.
 *
 * This is the API's own scope vocabulary and is deliberately separate from
 * B2bPermission: a B2B permission answers "may this human do it in the
 * portal", an ability answers "did we sell this partner that endpoint". A
 * request must pass both — the token's ability gates the route, and the
 * integration user's company permissions still gate the underlying service,
 * so a mis-scoped token can never exceed what the company itself may do.
 *
 * Phase 1 defines the vocabulary; the endpoints it gates arrive in later
 * phases. Declaring them now means a partner's credential is provisioned
 * once with its final scope set rather than re-issued per feature.
 */
enum PartnerAbility: string
{
    case ReadVehicles = 'vehicles.read';
    case WriteVehicles = 'vehicles.write';

    case ReadOrders = 'orders.read';
    case WriteOrders = 'orders.write';

    case ReadTimeline = 'timeline.read';

    case ReadDocuments = 'documents.read';
    case WriteDocuments = 'documents.write';

    case ReadOffers = 'offers.read';
    case AcceptOffers = 'offers.accept';

    case ReadWebhooks = 'webhooks.read';
    case ManageWebhooks = 'webhooks.manage';

    /**
     * Grants every ability, including ones added after the token was issued.
     * Never the default: `partner:provision` writes out the explicit list so
     * a stored scope set is auditable.
     */
    public const WILDCARD = '*';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Read-only scope set — the safe starting point for a new partner.
     *
     * @return array<string>
     */
    public static function readOnlyValues(): array
    {
        return array_values(array_map(
            fn (self $ability) => $ability->value,
            array_filter(self::cases(), fn (self $ability) => $ability->isReadOnly()),
        ));
    }

    public function isReadOnly(): bool
    {
        return match ($this) {
            self::ReadVehicles, self::ReadOrders, self::ReadTimeline,
            self::ReadDocuments, self::ReadOffers, self::ReadWebhooks => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReadVehicles => 'Read fleet vehicles',
            self::WriteVehicles => 'Create and update vehicles',
            self::ReadOrders => 'Read orders',
            self::WriteOrders => 'Create and update orders',
            self::ReadTimeline => 'Read order timeline events',
            self::ReadDocuments => 'Download documents',
            self::WriteDocuments => 'Upload documents',
            self::ReadOffers => 'Read offers',
            self::AcceptOffers => 'Accept or reject offers',
            self::ReadWebhooks => 'Read webhook subscriptions and deliveries',
            self::ManageWebhooks => 'Create, update and delete webhook subscriptions',
        };
    }

    /** Grouping used by the provisioning command's summary output. */
    public function group(): string
    {
        return match ($this) {
            self::ReadVehicles, self::WriteVehicles => 'Vehicles',
            self::ReadOrders, self::WriteOrders, self::ReadTimeline => 'Orders',
            self::ReadDocuments, self::WriteDocuments => 'Documents',
            self::ReadOffers, self::AcceptOffers => 'Offers',
            self::ReadWebhooks, self::ManageWebhooks => 'Webhooks',
        };
    }
}
