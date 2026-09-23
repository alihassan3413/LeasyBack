import { companyFormData } from '@/lib/company';
import type { B2bCompanyData, B2bRegistrationFormData } from '@/types/b2b';
import { useForm } from '@inertiajs/vue3';
import { ref, watch, type Ref } from 'vue';

/**
 * Shared plumbing for the company cards on "Mein Konto".
 *
 * Each card shows one slice of the company — master data, address, contact
 * person — but they all edit the same record through one endpoint, so each
 * gets its own form seeded with the complete payload and submits all of it.
 */
export function useCompanyCard(company: Ref<B2bCompanyData | null>) {
    const form = useForm<B2bRegistrationFormData>(companyFormData(company.value));
    const isEditing = ref(false);

    // Re-seeded only when the stored record itself changed — keyed on
    // `updated_at` rather than the prop's identity, so a failed validation
    // (which re-renders the page with the unchanged record) leaves everything
    // the user typed in place instead of silently reverting it.
    watch(
        () => company.value?.updated_at,
        () => {
            const seeded = companyFormData(company.value);

            Object.assign(form, seeded);
            form.defaults(seeded);
        },
    );

    function startEditing(): void {
        form.clearErrors();
        isEditing.value = true;
    }

    function cancelEditing(): void {
        form.reset();
        form.clearErrors();
        isEditing.value = false;
    }

    function submit(): void {
        // PUT can't carry a multipart body reliably, so the update is posted
        // with Laravel's method spoofing — the standard Inertia file-upload
        // workaround, matching what the registration form does.
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('company.update'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.logo = null;
                form.remove_logo = false;
                isEditing.value = false;
            },
        });
    }

    return { form, isEditing, startEditing, cancelEditing, submit };
}
