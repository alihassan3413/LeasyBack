<script setup lang="ts">
import ErrorAction from '@/components/errors/ErrorAction.vue';
import { resolveErrorCopy } from '@/lib/errorPages';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft, RotateCw } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    status: number;
    maintenanceMessage?: string | null;
}>();

const page = usePage<SharedData>();

const copy = computed(() => resolveErrorCopy(props.status));

const user = computed(() => page.props.auth?.user ?? null);

const home = computed(() => {
    if (!user.value) {
        return { href: route('home'), label: 'Zur Startseite' };
    }

    return {
        href: user.value.user_type === 'Admin' ? route('admin.dashboard') : route('dashboard'),
        label: 'Zum Dashboard',
    };
});

const description = computed(() => (props.status === 503 && props.maintenanceMessage ? props.maintenanceMessage : copy.value.description));

function reload() {
    window.location.reload();
}

function goBack() {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    window.location.assign(home.value.href);
}
</script>

<template>
    <div>
        <div class="flex items-center gap-4 sm:gap-5">
            <span class="text-brand-teal text-5xl font-bold tracking-[-0.03em] tabular-nums sm:text-6xl">{{ status }}</span>
            <span
                class="border-brand-green-gray/70 text-brand-teal/70 border-l pl-4 text-[11px] font-bold tracking-[0.16em] uppercase sm:pl-5 sm:text-xs"
            >
                {{ copy.eyebrow }}
            </span>
        </div>

        <h1 class="text-brand-teal mt-8 text-3xl leading-[1.15] font-bold tracking-[-0.02em] text-balance sm:mt-10 sm:text-[2.6rem] sm:leading-[1.1]">
            {{ copy.title }}
        </h1>

        <p class="text-brand-black/70 mt-5 max-w-xl text-base leading-relaxed sm:text-[1.0625rem]">
            {{ description }}
        </p>

        <div class="mt-10 flex flex-col gap-3 sm:mt-12 sm:flex-row sm:flex-wrap sm:items-center">
            <ErrorAction v-if="copy.requiresSignIn" :href="route('login')" variant="primary">Anmelden</ErrorAction>

            <ErrorAction :href="home.href" :variant="copy.requiresSignIn ? 'secondary' : 'primary'">
                {{ home.label }}
            </ErrorAction>

            <ErrorAction v-if="copy.retryable" @click="reload">
                <RotateCw class="h-4 w-4" aria-hidden="true" />
                {{ copy.retryLabel ?? 'Erneut versuchen' }}
            </ErrorAction>
        </div>

        <div class="border-brand-green-gray/50 mt-10 flex flex-col gap-3 border-t pt-6 sm:mt-12 sm:flex-row sm:items-center sm:justify-between">
            <button
                type="button"
                class="text-brand-teal hover:text-brand-teal/70 focus-visible:ring-brand-teal inline-flex items-center gap-2 self-start rounded-[5px] text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none motion-reduce:transition-none"
                @click="goBack"
            >
                <ArrowLeft class="h-4 w-4" aria-hidden="true" />
                Zurück zur vorherigen Seite
            </button>

            <p v-if="copy.offerSupport" class="text-brand-black/60 text-sm">
                Problem bleibt bestehen?
                <a
                    href="mailto:hallo@leasyback.de"
                    class="text-brand-teal hover:text-brand-teal/70 focus-visible:ring-brand-teal rounded-[5px] font-medium underline underline-offset-2 focus-visible:ring-2 focus-visible:outline-none"
                >
                    Support kontaktieren
                </a>
            </p>
        </div>
    </div>
</template>
