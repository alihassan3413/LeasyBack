<script setup lang="ts">
/**
 * Parallel to AppSidebar.vue (the B2C dashboard's sidebar), not a variant of
 * it — reuses every underlying primitive (NavFooter, NavUser, ui/sidebar,
 * AppLogo) but has its own nav and color scheme, matching
 * leasyback_web's src/components/admin/AdminSidebar.vue: same four items in
 * the same order (Dashboard, Kunden, Fahrzeuge, Alle Aufträge) and the same
 * dark teal→green brand palette. Only Dashboard and Kunden are real routes
 * so far — Fahrzeuge/Alle Aufträge are Checkpoints 10/11's job; shown here
 * as disabled placeholders (the intended IA, honestly not-yet-built)
 * rather than broken links or a fabricated "coming soon" page.
 */
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { BookOpen, Car, ClipboardList, Folder, LayoutGrid, Users } from 'lucide-vue-next';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
        icon: LayoutGrid,
    },
    {
        title: 'Kunden',
        href: route('admin.customers.index'),
        icon: Users,
    },
];

const upcomingNavItems: { title: string; icon: typeof Car }[] = [
    { title: 'Fahrzeuge', icon: Car },
    { title: 'Alle Aufträge', icon: ClipboardList },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" class="admin-sidebar-theme">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('admin.dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />

            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Demnächst</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in upcomingNavItems" :key="item.title">
                        <SidebarMenuButton disabled class="opacity-50">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

<style scoped>
/*
 * Brand palette ported from leasyback_web's AdminSidebar.vue (teal→green
 * gradient, brand-green active state). Scoped to this component's own
 * rendered [data-sidebar="sidebar"] node via :deep() so it never leaks into
 * the B2C AppSidebar, which keeps the app's generic --sidebar-* theme —
 * this reskins the shared ui/sidebar primitives locally rather than
 * hand-rolling bespoke markup, so collapse/expand, the mobile drawer, and
 * every other primitive's behavior is untouched.
 */
.admin-sidebar-theme :deep([data-sidebar='sidebar']) {
    background: linear-gradient(180deg, var(--brand-teal) 0%, #0d3133 100%);
    --sidebar-foreground: white;
    --sidebar-accent: var(--brand-green);
    --sidebar-accent-foreground: var(--brand-teal);
}
</style>
