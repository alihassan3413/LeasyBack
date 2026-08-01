<script setup lang="ts">
/**
 * Matches leasyback_web's src/components/dashboard/AppSidebar.vue: same nav
 * items (Mein Dashboard, Mein Konto) and the same dark teal→green brand
 * palette as AdminSidebar.vue, reskinning the shared ui/sidebar primitives
 * locally rather than hand-rolling bespoke markup.
 */
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarHeader, SidebarFooter, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { LayoutGrid, UserRound } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Mein Dashboard',
        href: route('dashboard'),
        icon: LayoutGrid,
    },
    {
        title: 'Mein Konto',
        href: route('profile.edit'),
        icon: UserRound,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" class="app-sidebar-theme">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

<style scoped>
/* Brand palette ported from leasyback_web's AppSidebar.vue — kept identical
 * to AdminSidebar.vue's .admin-sidebar-theme override. */
.app-sidebar-theme :deep([data-sidebar='sidebar']) {
    background: linear-gradient(180deg, var(--brand-teal) 0%, #0d3133 100%);
    --sidebar-foreground: white;
    --sidebar-accent: var(--brand-green);
    --sidebar-accent-foreground: var(--brand-teal);
}
</style>
