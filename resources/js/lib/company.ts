import type { B2bCompanyData, B2bRegistrationFormData } from '@/types/b2b';

/**
 * Seeds a complete company payload from a stored record.
 *
 * Every card on "Mein Konto" edits one slice of the company but submits the
 * whole record: B2bRegistrationRequest validates the complete form, so the
 * fields a card doesn't show still have to travel along unchanged rather than
 * arriving empty and clearing what the user entered during onboarding.
 */
export function companyFormData(company: B2bCompanyData | null, fallbackEmail = ''): B2bRegistrationFormData {
    const address = company?.address;
    const contact = company?.contact;

    return {
        company_name: company?.company_name ?? '',
        vat_id: company?.vat_id ?? '',
        contact_email: company?.contact_email ?? fallbackEmail,
        address: {
            street: address?.street ?? '',
            number: address?.number ?? '',
            additional_address: address?.additional_address ?? '',
            zip_code: address?.zip_code ?? '',
            city: address?.city ?? '',
            country: address?.country ?? 'Deutschland',
        },
        contact: {
            salutation: contact?.salutation ?? '',
            first_name: contact?.first_name ?? '',
            last_name: contact?.last_name ?? '',
        },
        phones: contact?.phone_numbers?.length
            ? contact.phone_numbers.map((phone) => ({
                  international_prefix: phone.international_prefix,
                  phone_number: phone.phone_number,
              }))
            : [{ international_prefix: '+49', phone_number: '' }],
        // The stored logo is rendered from `company.logo_url`; only a fresh
        // pick or an explicit removal is ever submitted.
        logo: null,
        remove_logo: false,
    };
}
