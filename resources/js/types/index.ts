import type { LucideIcon } from 'lucide-vue-next';
import type { UserType } from './auth';
import type { B2bSharedState } from './b2b';
import type { VehicleImportResult } from './vehicle';

export interface Auth {
    user: User;
    /**
     * Company membership context — populated for Firmenkunde accounts only,
     * null for everyone else. See HandleInertiaRequests::b2bState().
     */
    b2b: B2bSharedState | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface FlashBag {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    /** Shown once after issuing a workshop quotation link (phase 9). */
    workshop_link?: string | null;
    /** Per-row outcome of a bulk vehicle import (phase 15). */
    vehicle_import?: VehicleImportResult | null;
    /** Set once after booking, so the caller knows which order to attach a card to. */
    order_created?: OrderCreatedFlash | null;
}

export interface OrderCreatedFlash {
    order_id: string;
    auftragsnummer: string;
    /** Decided server-side: false for B2B, for Admin acting on a customer's behalf, and when a usable mandate already exists. */
    requires_payment_method: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: FlashBag;
    /** Set only for an admin who has taken over a customer session — never for the customer themselves. */
    impersonation: { active: boolean; admin_name: string | null };
    notifications: { unread_count: number };
    /** Publishable key only. Null when payments are not configured in this environment. */
    stripe: { key: string | null };
    /** Product amounts the UI has to name. Server-held so no copy hardcodes one. */
    payments: { cancellation_fee_cents: number };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    /**
     * Not currently sent by the backend (see HandleInertiaRequests::share())
     * — kept optional so existing avatar-fallback logic (UserInfo.vue) keeps
     * compiling and behaving exactly as it does today (always undefined).
     */
    avatar?: string;
    email_verified_at: string | null;
    user_type: UserType;
}

export type BreadcrumbItemType = BreadcrumbItem;
