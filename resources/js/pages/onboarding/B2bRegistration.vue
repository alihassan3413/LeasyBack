<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import B2bAdminCard from '@/components/onboarding/B2bAdminCard.vue';
import B2bCompanyCard from '@/components/onboarding/B2bCompanyCard.vue';
import { Button } from '@/components/ui/button';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import B2bRegistrationLayout from '@/layouts/onboarding/B2bRegistrationLayout.vue';
import type { B2bCompanyData, B2bRegistrationFormData } from '@/types/b2b';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        company: B2bCompanyData | null;
        /** False for a member granted company.view but not company.manage. */
        canManage?: boolean;
    }>(),
    { canManage: true },
);

const page = usePage<SharedData>();

// Already registered → the same form doubles as the edit view, exactly like
// the B2C wizard's steps stay editable when the user navigates back.
const isRegistered = computed(() => !!props.company);

const pageTitle = computed(() => (isRegistered.value ? 'Firmendaten' : 'Firmenkunden - Registrierung'));

function formDataFrom(company: B2bCompanyData | null): B2bRegistrationFormData {
    const address = company?.address;
    const contact = company?.contact;

    return {
        company_name: company?.company_name ?? '',
        vat_id: company?.vat_id ?? '',
        contact_email: company?.contact_email ?? page.props.auth.user?.email ?? '',
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

const form = useForm<B2bRegistrationFormData>(formDataFrom(props.company));

// Re-seeded only when the server record itself changed — deliberately keyed on
// `updated_at` rather than the prop's identity, so a failed validation (which
// re-renders the page with the unchanged record) leaves everything the user
// typed in place instead of silently reverting it.
watch(
    () => props.company?.updated_at,
    () => {
        const seeded = formDataFrom(props.company);

        Object.assign(form, seeded);
        form.defaults(seeded);
    },
);

const showSuccess = ref(false);

function submit() {
    // Captured before the visit: by the time onSuccess runs the fresh page
    // props have landed, so `isRegistered` already reads true either way.
    const wasRegistered = isRegistered.value;

    const options = {
        preserveScroll: true,
        preserveState: true,
        // Always multipart: the logo is a File, and the payload shape must not
        // change depending on whether the user happened to pick one.
        forceFormData: true,
        onSuccess: () => {
            form.logo = null;
            form.remove_logo = false;

            if (!wasRegistered) {
                showSuccess.value = true;
            }
        },
    };

    // PUT can't carry a multipart body reliably, so the update is posted with
    // Laravel's method spoofing — the standard Inertia file-upload workaround.
    if (wasRegistered) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('onboarding.b2b.update'), options);

        return;
    }

    form.transform((data) => data).post(route('onboarding.b2b.store'), options);
}

function goToDashboard() {
    router.visit(route('dashboard'));
}
</script>

<template>
    <Head :title="pageTitle" />

    <B2bRegistrationLayout :title="pageTitle" :back-label="isRegistered ? 'Zurück zum Dashboard' : 'Später fertigstellen'">
        <form novalidate class="space-y-6" :inert="!canManage" @submit.prevent="submit">
            <InputError :message="form.errors.company" />

            <p v-if="!canManage" class="rounded-[8px] bg-white/10 px-4 py-3 text-sm text-white/80">
                Sie können die Firmendaten einsehen, aber nicht ändern. Wenden Sie sich an einen Inhaber Ihres Unternehmens.
            </p>

            <B2bCompanyCard :form="form" :existing-logo-url="company?.logo_url ?? null" />

            <B2bAdminCard :form="form">
                <template v-if="canManage" #actions>
                    <Link
                        :href="route('dashboard')"
                        class="bg-brand-orange hover:bg-brand-orange/90 w-full rounded-[5px] px-8 py-2.5 text-center text-sm font-bold text-white transition sm:w-auto"
                    >
                        Jetzt überspringen
                    </Link>

                    <Button
                        type="submit"
                        :loading="form.processing"
                        class="bg-brand-green hover:bg-brand-green/90 w-full rounded-[5px] px-10 py-2.5 text-sm font-bold text-white shadow-none sm:w-auto"
                    >
                        {{ isRegistered ? 'Speichern' : 'Jetzt Registrieren' }}
                    </Button>
                </template>
            </B2bAdminCard>
        </form>
    </B2bRegistrationLayout>

    <AppModal
        :open="showSuccess"
        title="Vielen Dank!"
        description="Ihre Registrierung war erfolgreich. Sie werden zum Dashboard weitergeleitet."
        :width="620"
        @update:open="(open) => !open && goToDashboard()"
    >
        <div class="flex justify-center px-2">
            <CheckCircle2 class="text-brand-green size-12" aria-hidden="true" />
        </div>

        <template #footer>
            <AppModalButton @click="goToDashboard">Zum Dashboard</AppModalButton>
        </template>
    </AppModal>
</template>
