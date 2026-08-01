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
use App\Policies\AdminPolicy;
use App\Policies\OfferPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProfilePolicy;
use App\Policies\VehicleDocumentPolicy;
use App\Policies\VehiclePolicy;
use App\Policies\VehicleReportDocumentPolicy;
use App\Policies\WorkshopPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

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

        // AdminPolicy isn't bound to a model (Gate::policy() requires one),
        // so each ability is registered as its own named Gate instead. Names
        // are deliberately prefixed/distinct from every model Policy's own
        // ability names (view/viewAny/create/update/...) — a raw Gate::define
        // with a colliding name would silently take priority over a model
        // Policy's method of the same name for every $user->can(name, Model)
        // call app-wide, not just these four call sites.
        Gate::define('viewDashboardSummary', [AdminPolicy::class, 'viewDashboardSummary']);
        Gate::define('viewAdminListings', [AdminPolicy::class, 'viewAdminListings']);
        Gate::define('updateCustomerStatus', [AdminPolicy::class, 'updateCustomerStatus']);
        Gate::define('syncAppraisal', [AdminPolicy::class, 'syncAppraisal']);
    }
}
