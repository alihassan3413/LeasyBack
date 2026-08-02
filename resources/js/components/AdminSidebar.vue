<script setup lang="ts">
import type { SharedData, User } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const collapsed = ref(false);
const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user as User | undefined);

const nav = [
    {
        id: 'dashboard',
        label: 'Dashboard',
        name: 'admin.dashboard',
        icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>`,
    },
    {
        id: 'kunden',
        label: 'Kunden',
        name: 'admin.customers.index',
        icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>`,
    },
    {
        id: 'fahrzeuge',
        label: 'Fahrzeuge',
        name: 'admin.vehicles.index',
        icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v9a2 2 0 01-2 2h-1"/><circle cx="9" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>`,
    },
    {
        id: 'auftraege',
        label: 'Alle Aufträge',
        name: 'admin.orders.index',
        icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/></svg>`,
    },
];

const userName = computed(() => {
    const u = user.value;
    if (!u) return 'Administrator';
    if (u.name) return u.name;
    if (u.email) return u.email;
    return 'Administrator';
});

const userInitials = computed(() => {
    const parts = userName.value.trim().split(' ');
    return parts.length >= 2 ? (parts[0][0] + parts[1][0]).toUpperCase() : userName.value.slice(0, 2).toUpperCase();
});

const userRoleLabel = computed(() => {
    const r = user.value?.user_type ?? 'Admin';
    return (
        (
            {
                Admin: 'Administrator',
                Firmenkunde: 'B2B Nutzer',
                Privatkunde: 'B2C Nutzer',
                Werksatatt: 'Werkstatt',
            } as Record<string, string>
        )[r] ?? r
    );
});

function isActive(name: string) {
    return new URL(route(name), window.location.origin).pathname === page.url.split('?')[0];
}
function navigateTo(name: string) {
    router.visit(route(name));
}
function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <!--
    KEY FIX: overflow-visible on the aside so the collapse toggle
    button (position absolute, -right-3) is NOT clipped.
    Height is controlled by sticky + h-screen.
    Inner scroll is handled by the <nav> having overflow-y-auto.
  -->
    <aside
        class="sticky top-0 z-50 flex h-full shrink-0 flex-col rounded-[22px] py-5 transition-[width] duration-300 ease-out"
        :class="collapsed ? 'w-[78px] px-3' : 'w-[224px] px-4'"
        style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%); box-shadow: 0 8px 30px rgba(16, 57, 59, 0.18); overflow: visible"
    >
        <!-- ── BRAND ── -->
        <div class="mb-6 flex shrink-0 items-center overflow-hidden px-1" :class="collapsed ? 'justify-center' : ''">
            <img src="/leasyback-stacked.png" alt="LeasyBack" class="h-auto shrink-0 object-contain" :class="collapsed ? 'w-full' : 'w-[70%]'" />
        </div>

        <!-- ── COLLAPSE TOGGLE ──
         sits outside overflow:hidden scope because aside is overflow:visible now.
         rounded-full white pill on the right edge.
    -->
        <button
            @click="collapsed = !collapsed"
            class="absolute top-7 -right-3 z-[60] flex h-6 w-6 items-center justify-center rounded-full border border-[#d8e4e3] bg-white text-[#6f8585] transition-all duration-200 hover:scale-110 hover:border-[#01B990] hover:text-[#10393b]"
            style="box-shadow: 0 2px 10px rgba(16, 57, 59, 0.15)"
        >
            <svg
                width="12"
                height="12"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                class="transition-transform duration-300"
                :class="collapsed ? '' : 'rotate-180'"
            >
                <path d="M9 18l6-6-6-6" />
            </svg>
        </button>

        <!-- ── NAV — takes all remaining vertical space, scrolls if needed ── -->
        <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-x-hidden overflow-y-auto pr-0.5">
            <p v-if="!collapsed" class="mt-1 mb-2 shrink-0 px-3 text-[10px] font-bold tracking-[0.13em] text-white/25 uppercase">Menü</p>

            <button
                v-for="item in nav"
                :key="item.id"
                @click="navigateTo(item.name)"
                class="group relative flex h-[44px] w-full shrink-0 items-center gap-3 rounded-[13px] text-left transition-all duration-150"
                :class="[
                    collapsed ? 'justify-center px-0' : 'px-3',
                    isActive(item.name)
                        ? 'bg-[#01B990] font-bold text-[#10393b] shadow-[0_6px_16px_rgba(1,185,144,0.28)]'
                        : 'text-white/55 hover:bg-white/[0.07] hover:text-white',
                ]"
            >
                <span class="flex shrink-0 items-center" v-html="item.icon"></span>

                <transition name="lb-fade">
                    <span
                        v-if="!collapsed"
                        class="flex-1 overflow-hidden text-[13.5px] text-ellipsis whitespace-nowrap"
                        :class="isActive(item.name) ? 'font-bold' : 'font-medium'"
                        >{{ item.label }}</span
                    >
                </transition>

                <!-- active left accent bar -->
                <span v-if="isActive(item.name) && !collapsed" class="absolute top-3 bottom-3 -left-4 w-[3px] rounded-r-full bg-[#01B990]"></span>

                <!-- collapsed tooltip -->
                <span
                    v-if="collapsed"
                    class="pointer-events-none absolute left-[58px] z-[70] -translate-x-2 rounded-lg border border-white/10 bg-[#10393b] px-2.5 py-1.5 text-[12px] font-semibold whitespace-nowrap text-white opacity-0 transition-all duration-150 group-hover:translate-x-0 group-hover:opacity-100"
                    style="box-shadow: 0 4px 12px rgba(16, 57, 59, 0.25)"
                    >{{ item.label }}</span
                >
            </button>
        </nav>

        <!-- ── FOOTER — always pinned at bottom ── -->
        <div class="mt-3 shrink-0 overflow-hidden pt-3" style="border-top: 1px solid rgba(255, 255, 255, 0.08)">
            <!-- Logout -->
            <button
                @click="logout"
                class="group relative mb-1.5 flex w-full items-center gap-3 rounded-[13px] text-white/35 transition-all hover:bg-white/[0.06] hover:text-white/75"
                :class="collapsed ? 'justify-center p-2.5' : 'px-3 py-2'"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0">
                    <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" />
                </svg>
                <transition name="lb-fade">
                    <span v-if="!collapsed" class="text-[13px] font-medium">Abmelden</span>
                </transition>
                <span
                    v-if="collapsed"
                    class="pointer-events-none absolute left-[58px] z-[70] -translate-x-2 rounded-lg border border-white/10 bg-[#10393b] px-2.5 py-1.5 text-[12px] font-semibold whitespace-nowrap text-white opacity-0 transition-all duration-150 group-hover:translate-x-0 group-hover:opacity-100"
                    >Abmelden</span
                >
            </button>

            <!-- User pill -->
            <div
                class="flex cursor-pointer items-center gap-2.5 rounded-[13px] transition-all hover:bg-white/[0.06]"
                :class="collapsed ? 'justify-center p-1.5' : 'p-2'"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] text-[12px] font-extrabold text-white"
                    style="background: linear-gradient(150deg, #01b990, #10393b)"
                >
                    {{ userInitials }}
                </div>
                <transition name="lb-fade">
                    <div v-if="!collapsed" class="min-w-0 flex-1 overflow-hidden">
                        <div class="truncate text-[12.5px] font-bold text-white">{{ userName }}</div>
                        <div class="text-[10.5px] text-white/40">{{ userRoleLabel }}</div>
                    </div>
                </transition>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.lb-fade-enter-active,
.lb-fade-leave-active {
    transition: opacity 0.18s ease;
}
.lb-fade-enter-from,
.lb-fade-leave-to {
    opacity: 0;
}
</style>
