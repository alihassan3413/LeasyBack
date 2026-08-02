<script setup lang="ts">
import AdminSidebar from '@/components/AdminSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <!--
    h-screen + overflow-hidden on the root makes the sidebar's
    `sticky top-0 h-screen` actually pin correctly.
    The right column scrolls independently inside its own div.
  -->
    <div
        class="flex h-screen gap-4 overflow-hidden p-4 text-[#1a2e2f]"
        style="
            background:
                radial-gradient(900px 500px at 78% -5%, rgba(1, 185, 144, 0.06), transparent 55%),
                radial-gradient(700px 420px at 0% 100%, rgba(16, 57, 59, 0.045), transparent 50%),
                linear-gradient(180deg, #fbfcfb 0%, #f3f6f5 100%);
        "
    >
        <ImpersonationBanner />

        <!-- sidebar stays fixed on the left, never scrolls -->
        <AdminSidebar />

        <!-- right side: each page controls its own scroll -->
        <div class="relative z-10 flex min-w-0 flex-1 flex-col gap-4 overflow-y-auto">
            <AppSidebarHeader :breadcrumbs="breadcrumbs">
                <template v-if="$slots.header" #default><slot name="header" /></template>
            </AppSidebarHeader>

            <!-- Same horizontal rhythm as the header, so page titles in the
                 header slot line up with the cards underneath them. -->
            <div class="flex min-h-0 flex-1 flex-col px-4 pb-4 md:px-6">
                <slot />
            </div>
        </div>
    </div>
</template>
