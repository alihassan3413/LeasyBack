import type { InertiaForm } from '@inertiajs/vue3';

export interface B2bCompanyAddress {
    address_id: string;
    street: string;
    number: string;
    additional_address: string | null;
    zip_code: string;
    city: string;
    country: string;
}

export interface B2bCompanyPhone {
    international_prefix: string;
    phone_number: string;
    is_primary_contact: boolean;
}

export interface B2bCompanyContact {
    contact_id: string;
    salutation: string | null;
    first_name: string;
    last_name: string;
    /** Primary number first — B2BService orders by `is_primary_contact` desc. */
    phone_numbers: B2bCompanyPhone[];
}

/** Matches B2BService::findForUser()'s response shape. */
export interface B2bCompanyData {
    /** The company's UUID — named `b2b` by the service's response contract. */
    b2b: string;
    company_name: string;
    logo_url: string | null;
    logo_path: string | null;
    contact_email: string | null;
    vat_id: string | null;
    service_fee_amount: string | null;
    service_fee_effective_from: string | null;
    created_at: string;
    updated_at: string;
    contact: B2bCompanyContact | null;
    address: B2bCompanyAddress | null;
}

/**
 * Payload shape of the B2B registration form — matches B2bRegistrationRequest.
 * A type alias rather than an interface on purpose: only aliases satisfy
 * Inertia's `Record<string, FormDataConvertible>` constraint on useForm.
 */
export type B2bRegistrationFormData = {
    company_name: string;
    vat_id: string;
    contact_email: string;
    address: {
        street: string;
        number: string;
        additional_address: string;
        zip_code: string;
        city: string;
        country: string;
    };
    contact: {
        salutation: string;
        first_name: string;
        last_name: string;
    };
    phones: { international_prefix: string; phone_number: string }[];
    /** Only set when the user picked a new file this session. */
    logo: File | null;
    /** Clears an already-stored logo without uploading a replacement. */
    remove_logo: boolean;
};

/**
 * The live Inertia form, passed down to the card components so both halves of
 * the page write into one form object and share one submit and one error bag.
 */
export type B2bRegistrationForm = InertiaForm<B2bRegistrationFormData>;

/* ---------------------------------------------------------------------------
 * Membership, permissions and team management
 * ------------------------------------------------------------------------ */

/** Mirrors App\Enums\B2bPermission — the server is the source of truth. */
export type B2bPermissionValue =
    | 'vehicles.view'
    | 'vehicles.create'
    | 'vehicles.update'
    | 'vehicles.documents.upload'
    | 'vehicles.documents.delete'
    | 'orders.create'
    | 'offers.select'
    | 'company.view'
    | 'company.manage'
    | 'members.view'
    | 'members.manage'
    | 'analytics.view';

export type B2bRoleValue = 'owner' | 'member';

export type B2bVehicleScopeValue = 'all' | 'own';

/** One company the signed-in user could act as. */
export interface B2bCompanySummary {
    b2b_id: string;
    company_name: string;
    logo_url: string | null;
    role: B2bRoleValue;
    role_label: string;
}

/** The company the user is currently acting as, plus what they may do in it. */
export interface B2bActiveMembership extends B2bCompanySummary {
    vehicle_scope: B2bVehicleScopeValue;
    permissions: B2bPermissionValue[];
}

/**
 * Shared on every Inertia request for accounts that have a company side —
 * Firmenkunde, and any Privatkunde who accepted a B2B invitation (null
 * otherwise). Used to hide what the server would refuse — never as the
 * authorization itself, which lives in EnsureB2bPermission and
 * VehicleScopeService.
 *
 * `active` is null while a dual-context account is acting on its private side.
 */
export interface B2bSharedState {
    active: B2bActiveMembership | null;
    memberships: B2bCompanySummary[];
    permissions: B2bPermissionValue[];
    /** True for accounts that keep a private area to switch back to. */
    personal_available: boolean;
}

export interface B2bMemberRow {
    user_id: number;
    name: string | null;
    email: string;
    is_active: boolean;
    role: B2bRoleValue;
    role_label: string;
    vehicle_scope: B2bVehicleScopeValue;
    permissions: B2bPermissionValue[];
    joined_at: string | null;
    invited_by_email: string | null;
    vehicle_count: number;
    order_count: number;
}

export interface B2bInvitationRow {
    invitation_id: string;
    email: string;
    role: B2bRoleValue;
    role_label: string;
    permissions: B2bPermissionValue[];
    vehicle_scope: B2bVehicleScopeValue;
    status: 'pending' | 'accepted' | 'revoked' | 'expired';
    expires_at: string;
    created_at: string;
    invited_by_email: string | null;
}

export interface B2bPermissionOption {
    value: B2bPermissionValue;
    label: string;
    description: string;
    /** Permissions auto-enabled alongside this one. */
    requires: B2bPermissionValue[];
}

export interface B2bPermissionGroup {
    group: string;
    permissions: B2bPermissionOption[];
}

export interface B2bMemberAnalyticsRow {
    user_id: number;
    name: string | null;
    email: string;
    role: B2bRoleValue;
    vehicles: number;
    open_orders: number;
    completed_orders: number;
    last_vehicle_at: string | null;
}

/**
 * One bucket of the fleet, counted per vehicle by its latest order's state.
 * `filter` is the dashboard `status` value that isolates the bucket, or null
 * where the filter list has no equivalent.
 */
export interface B2bVehicleState {
    key: 'planned' | 'in_progress' | 'completed' | 'cancelled';
    label: string;
    count: number;
    filter: string | null;
}

export interface B2bAnalytics {
    /** Order-level counts. Not parts of a whole — a vehicle can carry several. */
    totals: {
        vehicles: number;
        open_orders: number;
        completed_orders: number;
    };
    /** Vehicle-level buckets; these do sum to `totals.vehicles`. */
    states: B2bVehicleState[];
    members: B2bMemberAnalyticsRow[];
}

/**
 * Company return statistics (§17), as B2bStatisticsService::summary() builds
 * them. Money arrives as decimal *strings* so no amount is ever rounded by a
 * JavaScript float on the way to the screen; only the derived percentage and
 * the day average, which are already approximations, come through as numbers.
 */
export interface B2bStatisticsOrderTotals {
    active: number;
    completed: number;
    cancelled: number;
    total: number;
}

export interface B2bStatisticsSavings {
    /** Orders with a customer-accepted offer — the only ones a saving is defined for. */
    orders_counted: number;
    vehicles_counted: number;
    appraisal_total_net: string;
    repair_total_net: string;
    saving_total_net: string;
    /** Null when nothing has been accepted yet, so the UI shows a dash rather than 0. */
    average_saving_per_vehicle_net: string | null;
    saving_percentage: string | null;
}

export interface B2bStatusDistributionEntry {
    status: string;
    label: string;
    count: number;
}

export interface B2bMonthlyVolumeEntry {
    /** `YYYY-MM`, for keying. `label` is the display form. */
    month: string;
    label: string;
    count: number;
}

export interface B2bStatistics {
    orders: B2bStatisticsOrderTotals;
    savings: B2bStatisticsSavings;
    processing_time: {
        average_days: number | null;
        measured_orders: number;
    };
    status_distribution: B2bStatusDistributionEntry[];
    monthly_volume: B2bMonthlyVolumeEntry[];
    /** False for a member limited to their own vehicles — the figures are theirs, not the company's. */
    scope: { company_wide: boolean };
}

/** Shape both the invite form and the member editor submit. */
export interface B2bMemberAccessFormData {
    role: B2bRoleValue;
    permissions: B2bPermissionValue[];
    vehicle_scope: B2bVehicleScopeValue;
}
