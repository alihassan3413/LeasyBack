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
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: FlashBag;
    /** Set only for an admin who has taken over a customer session — never for the customer themselves. */
    impersonation: { active: boolean; admin_name: string | null };
    notifications: { unread_count: number };
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
