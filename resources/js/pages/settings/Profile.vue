<script setup lang="ts">
import AccountDetailCard from '@/components/account/AccountDetailCard.vue';
import CompanyAddressCard from '@/components/account/CompanyAddressCard.vue';
import CompanyContactCard from '@/components/account/CompanyContactCard.vue';
import CompanyMasterDataCard from '@/components/account/CompanyMasterDataCard.vue';
import CompanyNoticeCard from '@/components/account/CompanyNoticeCard.vue';
import ContactPersonCard from '@/components/account/ContactPersonCard.vue';
import DeleteAccountCard from '@/components/account/DeleteAccountCard.vue';
import ManagePasswordCard from '@/components/account/ManagePasswordCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { AccountCompanyState, UserProfileData } from '@/types/profile';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    mustVerifyEmail: boolean;
    status?: string;
    profile: UserProfileData | null;
    /** Null whenever the user is not acting as a company — see companyState(). */
    company: AccountCompanyState | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mein Konto', href: '/settings/profile' }];

const page = usePage<SharedData>();

const email = computed(() => props.profile?.email ?? page.props.auth.user?.email ?? '');

/**
 * While acting as a company there is no personal profile to show: the address,
 * contact person and phone numbers are the company's, entered once during
 * registration. Falls back to the personal cards otherwise — which is also
 * what a dual-context account sees on its private side.
 */
const isCompanyAccount = computed(() => props.company !== null);
const companyData = computed(() => props.company?.data ?? null);

const contact = computed(() => (isCompanyAccount.value ? (companyData.value?.contact ?? null) : (props.profile?.contact ?? null)));

const fullName = computed(() => {
    // The company's own name identifies a company account better than whoever
    // happens to administer it.
    if (isCompanyAccount.value) {
        return companyData.value?.company_name ?? '';
    }

    if (!contact.value) {
        return '';
    }

    return [contact.value.first_name, contact.value.last_name].filter(Boolean).join(' ');
});

const initials = computed(() => {
    if (isCompanyAccount.value) {
        return (companyData.value?.company_name ?? '').trim().slice(0, 2).toUpperCase() || '•';
    }

    if (!contact.value) {
        return '•';
    }

    return ((contact.value.first_name?.[0] ?? '') + (contact.value.last_name?.[0] ?? '')).toUpperCase() || '•';
});

const sections = computed(() => {
    // With no company to show, the single notice card is the whole company
    // half of the page — there is nothing for the other two anchors to reach.
    const companySections = companyData.value
        ? [
              { id: 'firmendaten', label: 'Firmendaten' },
              { id: 'kontodaten', label: 'Kontodaten' },
              { id: 'ansprechpartner', label: 'Ansprechpartner' },
          ]
        : [{ id: 'firmendaten', label: 'Firmendaten' }];

    const personalSections = [
        { id: 'kontodaten', label: 'Kontodaten' },
        { id: 'ansprechpartner', label: 'Ansprechpartner' },
    ];

    return [
        ...(isCompanyAccount.value ? companySections : personalSections),
        { id: 'passwort', label: 'Passwort' },
        { id: 'konto-loeschen', label: 'Konto löschen' },
    ];
});

const activeSection = ref(isCompanyAccount.value ? 'firmendaten' : 'kontodaten');

function scrollTo(id: string) {
    activeSection.value = id;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <Head title="Mein Konto" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-screen bg-[#F5F7F7]">
            <div class="mx-auto max-w-[1240px] px-4 py-6 sm:px-6 sm:py-10">
                <header class="mb-6 sm:mb-8">
                    <div class="mb-1.5 flex items-center gap-2">
                        <span class="bg-brand-green h-px w-4"></span>
                        <p class="text-brand-green text-[11px] font-bold tracking-[0.2em] uppercase">Einstellungen</p>
                    </div>
                    <h1 class="text-[22px] leading-tight font-bold text-[#10393B] sm:text-[26px]">Mein Konto</h1>
                </header>

                <div class="mb-6 lg:hidden">
                    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
                        <div class="h-[60px] bg-linear-to-br from-[#10393B] via-[#155254] to-[#1e6568] sm:h-[80px]" />
                        <div class="-mt-8 flex flex-col items-center px-4 pb-4 sm:-mt-10 sm:px-5 sm:pb-6">
                            <div
                                class="bg-brand-green flex size-[64px] items-center justify-center overflow-hidden rounded-full border-[3px] border-white text-lg font-bold text-white shadow-lg sm:size-[76px] sm:text-xl"
                            >
                                <span>{{ initials }}</span>
                            </div>
                            <p class="mt-3 max-w-full truncate text-[14px] font-bold text-[#10393B] sm:text-[15px]">
                                {{ fullName || '—' }}
                            </p>
                            <p class="max-w-full truncate text-[11px] text-[#7A9699] sm:text-[12px]">
                                {{ email }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-start gap-6 lg:gap-8">
                    <aside class="sticky top-8 hidden w-[264px] shrink-0 lg:block">
                        <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
                            <div class="h-[80px] bg-linear-to-br from-[#10393B] via-[#155254] to-[#1e6568]" />
                            <div class="-mt-10 flex flex-col items-center px-5 pb-6">
                                <div
                                    class="bg-brand-green flex size-[76px] items-center justify-center overflow-hidden rounded-full border-[3px] border-white text-xl font-bold text-white shadow-lg"
                                >
                                    <span>{{ initials }}</span>
                                </div>
                                <p class="mt-3 max-w-full truncate text-[15px] font-bold text-[#10393B]">
                                    {{ fullName || '—' }}
                                </p>
                                <p class="max-w-full truncate text-[12px] text-[#7A9699]">
                                    {{ email }}
                                </p>
                            </div>
                        </div>

                        <nav class="mt-3 space-y-0.5">
                            <button
                                v-for="section in sections"
                                :key="section.id"
                                type="button"
                                class="group flex w-full items-center rounded-lg py-2.5 text-left text-sm font-medium transition-all"
                                :class="
                                    activeSection === section.id
                                        ? 'border-brand-green border-l-2 bg-white pr-3.5 pl-[calc(0.875rem-2px)] text-[#10393B] shadow-sm'
                                        : 'px-3.5 text-[#6B8587] hover:bg-white/80 hover:text-[#10393B]'
                                "
                                @click="scrollTo(section.id)"
                            >
                                {{ section.label }}
                            </button>
                        </nav>
                    </aside>

                    <main class="min-w-0 flex-1 space-y-4 scroll-smooth sm:space-y-5">
                        <template v-if="isCompanyAccount">
                            <template v-if="company?.data">
                                <section id="firmendaten" class="scroll-mt-6 sm:scroll-mt-8">
                                    <CompanyMasterDataCard :company="company.data" :can-manage="company.can_manage" />
                                </section>

                                <section id="kontodaten" class="scroll-mt-6 sm:scroll-mt-8">
                                    <CompanyAddressCard :company="company.data" :email="email" :can-manage="company.can_manage" />
                                </section>

                                <section id="ansprechpartner" class="scroll-mt-6 sm:scroll-mt-8">
                                    <CompanyContactCard :company="company.data" :can-manage="company.can_manage" />
                                </section>
                            </template>

                            <section v-else id="firmendaten" class="scroll-mt-6 sm:scroll-mt-8">
                                <CompanyNoticeCard :can-register="company?.can_register ?? false" />
                            </section>
                        </template>

                        <template v-else>
                            <section id="kontodaten" class="scroll-mt-6 sm:scroll-mt-8">
                                <AccountDetailCard :profile="profile" :email="email" />
                            </section>

                            <section id="ansprechpartner" class="scroll-mt-6 sm:scroll-mt-8">
                                <ContactPersonCard :profile="profile" />
                            </section>
                        </template>

                        <section id="passwort" class="scroll-mt-6 sm:scroll-mt-8">
                            <ManagePasswordCard :email="email" />
                        </section>

                        <section id="konto-loeschen" class="scroll-mt-6 sm:scroll-mt-8">
                            <DeleteAccountCard />
                        </section>
                    </main>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
