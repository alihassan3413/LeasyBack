<script setup lang="ts">
/**
 * One-time company registration for a Firmenkunde. Once the company exists the
 * server redirects this route to "Mein Konto", which is where the record is
 * reviewed and edited from then on — so this page only ever renders the empty
 * form and never doubles as an edit view.
 */
import InputError from '@/components/InputError.vue';
import B2bAdminCard from '@/components/onboarding/B2bAdminCard.vue';
import B2bCompanyCard from '@/components/onboarding/B2bCompanyCard.vue';
import { Button } from '@/components/ui/button';
import B2bRegistrationLayout from '@/layouts/onboarding/B2bRegistrationLayout.vue';
import { companyFormData } from '@/lib/company';
import type { SharedData } from '@/types';
import type { B2bRegistrationFormData } from '@/types/b2b';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage<SharedData>();

const form = useForm<B2bRegistrationFormData>(companyFormData(null, page.props.auth.user?.email ?? ''));

function submit() {
    // Always multipart: the logo is a File, and the payload shape must not
    // change depending on whether the user happened to pick one.
    form.post(route('onboarding.b2b.store'), { preserveScroll: true, forceFormData: true });
}
</script>

<template>
    <Head title="Firmenkunden - Registrierung" />

    <B2bRegistrationLayout title="Firmenkunden - Registrierung" back-label="Später fertigstellen">
        <form novalidate class="space-y-6" @submit.prevent="submit">
            <InputError :message="form.errors.company" />

            <B2bCompanyCard :form="form" />

            <B2bAdminCard :form="form">
                <template #actions>
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
                        Jetzt Registrieren
                    </Button>
                </template>
            </B2bAdminCard>
        </form>
    </B2bRegistrationLayout>
</template>
