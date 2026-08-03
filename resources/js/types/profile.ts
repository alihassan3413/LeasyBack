import type { B2bCompanyData } from '@/types/b2b';

export interface AddressData {
    address_id: string;
    street: string;
    number: string;
    additional_address: string | null;
    zip_code: string;
    city: string;
    country: string;
    longitude: number;
    latitude: number;
}

export interface ContactData {
    contact_id: string;
    salutation: string;
    first_name: string;
    last_name: string;
}

export interface PhoneNumberData {
    phone_id: string;
    international_prefix: string;
    phone_number: string;
}

export interface PreferencesData {
    preference_id: string;
    timezone: string;
    sprache: string;
    benachrichtigungseinstellungen_push: boolean;
    benachrichtigungseinstellungen_email: boolean;
}

/**
 * The company half of "Mein Konto". Null for every account type that has no
 * company at all, which is what makes the page fall back to the personal
 * profile cards. Matches Settings\ProfileController::companyState().
 */
export interface AccountCompanyState {
    /** Null when no company exists yet, or the member may not view it. */
    data: B2bCompanyData | null;
    can_manage: boolean;
    /** True only when the user belongs to no company and may register one. */
    can_register: boolean;
}

/** Matches ProfileService::findForUser()'s response shape. */
export interface UserProfileData {
    user_id: number;
    email: string;
    is_admin: boolean;
    address: AddressData | null;
    contact: ContactData | null;
    phones: PhoneNumberData[];
    preferences: PreferencesData | null;
}
