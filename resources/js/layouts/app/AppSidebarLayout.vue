<script setup lang="ts">
import AppSidebar from '@/components/AppSidebar.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import type { BreadcrumbItemType, SharedData, User } from '@/types';
import type { UserType } from '@/types/auth';
import type { B2bPermissionValue } from '@/types/b2b';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { useSessionGuard } from '@/composables/useSessionGuard';
import { router, usePage } from '@inertiajs/vue3';
import { computed, type Component } from 'vue';
import MdiAccountGroupOutline from '~icons/mdi/account-group-outline';
import MdiAccountOutline from '~icons/mdi/account-outline';
import MdiDomain from '~icons/mdi/domain';
import MdiLogout from '~icons/mdi/logout';
import MdiViewDashboardOutline from '~icons/mdi/view-dashboard-outline';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user as User | undefined);

interface NavItem {
    label: string;
    icon: Component;
    name: string;
    aliases?: string[];
    /** Company permission required to see this entry (Firmenkunde only). */
    permission?: B2bPermissionValue;
}

const navByRole: Record<UserType, NavItem[]> = {
    Privatkunde: [
        { label: 'Mein Dashboard', icon: MdiViewDashboardOutline, name: 'dashboard' },
        { label: 'Mein Konto', icon: MdiAccountOutline, name: 'profile.edit' },
    ],
    Firmenkunde: [
        { label: 'Mein Dashboard', icon: MdiViewDashboardOutline, name: 'dashboard', permission: 'vehicles.view' },
        { label: 'Firmendaten', icon: MdiDomain, name: 'onboarding.b2b.show', permission: 'company.view' },
        { label: 'Team', icon: MdiAccountGroupOutline, name: 'b2b.members.index', permission: 'members.view' },
        { label: 'Mein Konto', icon: MdiAccountOutline, name: 'profile.edit' },
    ],
    Werksatatt: [{ label: 'Mein Konto', icon: MdiAccountOutline, name: 'profile.edit' }],
    Admin: [],
};

const { can } = useB2bPermissions();

// Mirrors AppSidebar: hide what the server would refuse. `can()` is true for
// every non-Firmenkunde account, so other roles are unaffected.
const navItems = computed<NavItem[]>(() => {
    const role = user.value?.user_type;

    if (!role) {
        return [];
    }

    return navByRole[role].filter((item) => !item.permission || can(item.permission));
});

function isActive(name: string) {
    return new URL(route(name), window.location.origin).pathname === page.url.split('?')[0];
}

function navigateTo(name: string) {
    router.visit(route(name));
}

const handleLogout = () => {
    void useSessionGuard().logout('manual');
};
</script>

<template>
    <div class="flex h-screen flex-col overflow-hidden md:gap-4 md:p-4">
        <ImpersonationBanner />
        <!-- Body: sidebar + main for desktop -->
        <div class="relative hidden flex-1 overflow-hidden md:flex">
            <AppSidebar />

            <!-- Main content -->
            <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-white">
                <AppSidebarHeader :breadcrumbs="breadcrumbs">
                    <template v-if="$slots.header" #default><slot name="header" /></template>
                </AppSidebarHeader>

                <div class="flex-1 overflow-y-auto p-6">
                    <slot />
                </div>
            </main>
        </div>

        <!-- Mobile view -->
        <div class="relative flex flex-1 flex-col overflow-hidden md:hidden">
            <!-- Main content -->
            <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-white">
                <AppSidebarHeader :breadcrumbs="breadcrumbs">
                    <template v-if="$slots.header" #default><slot name="header" /></template>
                </AppSidebarHeader>

                <div class="flex-1 overflow-y-auto p-4">
                    <slot />
                </div>
            </main>

            <!-- Bottom tab bar for mobile -->
            <nav
                class="z-50 flex shrink-0 items-center justify-around px-2 py-3"
                style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%); box-shadow: 0 -8px 30px rgba(16, 57, 59, 0.18)"
            >
                <button
                    v-for="item in navItems"
                    :key="item.name"
                    @click="navigateTo(item.name)"
                    class="flex flex-col items-center gap-1 rounded-lg px-2 py-1 transition-all"
                    :class="isActive(item.name) ? 'text-[#01B990]' : 'text-white/55 hover:text-white'"
                >
                    <component :is="item.icon" :style="{ width: '24px', height: '24px' }" />
                </button>
                <button
                    @click="handleLogout"
                    class="flex flex-col items-center gap-1 rounded-lg px-2 py-1 text-white/55 transition-all hover:text-white"
                >
                    <MdiLogout :style="{ width: '24px', height: '24px' }" />
                </button>
            </nav>
        </div>
    </div>
</template>
