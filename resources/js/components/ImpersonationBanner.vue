<script setup lang="ts">
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();

const impersonation = computed(() => page.props.impersonation);
const active = computed(() => impersonation.value?.active === true);
const adminName = computed(() => impersonation.value?.admin_name ?? 'Administrator');
const targetName = computed(() => page.props.auth.user?.name ?? page.props.auth.user?.email ?? 'Kunde');

const stopping = ref(false);

function stop() {
    stopping.value = true;

    router.delete(route('impersonate.destroy'), {
        preserveScroll: false,
        onFinish: () => (stopping.value = false),
    });
}
</script>

<template>
    <div v-if="active" class="pointer-events-none fixed inset-x-0 bottom-4 z-[80] flex justify-center px-4">
        <div
            class="pointer-events-auto flex max-w-full items-center gap-3 rounded-full py-2 pr-2 pl-4"
            style="background: #10393b; box-shadow: 0 12px 32px rgba(16, 57, 59, 0.32)"
        >
            <span class="flex size-2 shrink-0 rounded-full bg-[#ef8450]"></span>

            <p class="min-w-0 truncate text-[12.5px] text-white/70">
                <span class="font-bold text-white">{{ adminName }}</span>
                angemeldet als
                <span class="font-bold text-white">{{ targetName }}</span>
            </p>

            <button
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-full bg-white/12 px-3.5 py-1.5 text-[12px] font-bold text-white transition-colors hover:bg-white/20 disabled:opacity-50"
                :disabled="stopping"
                @click="stop"
            >
                <IconMdiLogoutVariant class="size-3.5" />
                {{ stopping ? 'Wird beendet…' : 'Beenden' }}
            </button>
        </div>
    </div>
</template>
