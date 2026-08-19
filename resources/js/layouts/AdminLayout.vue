<script setup lang="ts">
import AdminSidebar from '@/components/AdminSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import type { BreadcrumbItemType } from '@/types';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

/**
 * Below `md` the sidebar is an off-canvas drawer instead of a column: at
 * 224px + gutters it used to eat two thirds of a phone screen, which is what
 * squeezed the admin tables into an unreadable strip.
 */
const mobileNavOpen = ref(false);

/** A drawer left open across an Inertia visit would cover the new page. */
const stopNavigation = router.on('navigate', () => {
    mobileNavOpen.value = false;
});

/** The drawer overlays the page, so the page behind it must not scroll. */
watch(mobileNavOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    stopNavigation();
});
</script>

<template>
    <!--
    h-dvh + overflow-hidden on the root makes the sidebar's
    `sticky top-0 h-full` actually pin correctly. dvh rather than vh so mobile
    Chrome's collapsing URL bar cannot push the shell out of the viewport.
    The right column scrolls independently inside its own div.
  -->
    <div
        class="flex h-dvh gap-0 overflow-hidden p-0 text-[#1a2e2f] md:gap-4 md:p-4"
        style="
            background:
                radial-gradient(900px 500px at 78% -5%, rgba(1, 185, 144, 0.06), transparent 55%),
                radial-gradient(700px 420px at 0% 100%, rgba(16, 57, 59, 0.045), transparent 50%), linear-gradient(180deg, #fbfcfb 0%, #f3f6f5 100%);
        "
    >
        <ImpersonationBanner />

        <!-- Drawer scrim — mobile only; the sidebar is a normal column from md up. -->
        <div v-if="mobileNavOpen" class="fixed inset-0 z-[65] bg-[#10393b]/45 md:hidden" aria-hidden="true" @click="mobileNavOpen = false"></div>

        <!-- sidebar stays fixed on the left, never scrolls -->
        <AdminSidebar :open="mobileNavOpen" @close="mobileNavOpen = false" />

        <!-- right side: each page controls its own scroll -->
        <div class="relative z-10 flex min-w-0 flex-1 flex-col gap-4 overflow-x-hidden overflow-y-auto">
            <AppSidebarHeader :breadcrumbs="breadcrumbs">
                <template #leading>
                    <button
                        type="button"
                        class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-[11px] border border-[#e6eded] bg-white text-[#10393b] transition-colors hover:border-[#01B990] md:hidden"
                        aria-label="Menü öffnen"
                        :aria-expanded="mobileNavOpen"
                        @click="mobileNavOpen = true"
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <path d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                </template>

                <template v-if="$slots.header" #default><slot name="header" /></template>
            </AppSidebarHeader>

            <!-- Same horizontal rhythm as the header, so page titles in the
                 header slot line up with the cards underneath them. -->
            <div class="flex min-h-0 flex-1 flex-col px-3 pb-4 sm:px-4 md:px-6">
                <slot />
            </div>
        </div>
    </div>
</template>
