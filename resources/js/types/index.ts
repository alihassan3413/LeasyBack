import type { LucideIcon } from 'lucide-vue-next';
import type { UserType } from './auth';

export interface Auth {
    user: User;
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

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
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
