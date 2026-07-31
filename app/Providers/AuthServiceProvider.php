<?php

namespace App\Providers;

use App\Models\LeasybackOffer;
use App\Models\LeasybackOrder;
use App\Models\LeasybackUserProfile;
use App\Models\UserPreference;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleReportDocument;
use App\Models\Workshop;
use App\Policies\OfferPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProfilePolicy;
use App\Policies\VehicleDocumentPolicy;
use App\Policies\VehiclePolicy;
use App\Policies\VehicleReportDocumentPolicy;
use App\Policies\WorkshopPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Registered explicitly rather than relying on naming-convention
     * auto-discovery, since these models are re-exported from
     * App\Modules\UserProfile\* — explicit mapping avoids any ambiguity.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Vehicle::class => VehiclePolicy::class,
        VehicleDocument::class => VehicleDocumentPolicy::class,
        VehicleReportDocument::class => VehicleReportDocumentPolicy::class,
        Workshop::class => WorkshopPolicy::class,
        LeasybackUserProfile::class => ProfilePolicy::class,
        UserPreference::class => ProfilePolicy::class,
        LeasybackOrder::class => OrderPolicy::class,
        LeasybackOffer::class => OfferPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
