<script setup lang="ts">
import { resolveErrorCopy } from '@/lib/errorPages';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    statuses: number[];
    fallbackStatus: number;
}>();

const rows = computed(() =>
    [...props.statuses, props.fallbackStatus].map((status) => ({
        status,
        eyebrow: resolveErrorCopy(status).eyebrow,
        title: resolveErrorCopy(status).title,
        isFallback: status === props.fallbackStatus,
        previewUrl: route('dev.errors.show', { status }),
        abortUrl: route('dev.errors.abort', { status }),
    })),
);
</script>

<template>
    <Head title="Fehlerseiten-Vorschau" />

    <div class="text-brand-black min-h-svh bg-[#fbfcfb] px-6 py-12 sm:px-10">
        <div class="mx-auto max-w-3xl">
            <img src="/leasyback-logo-dark.svg" alt="LeasyBack" class="h-7 w-auto" />

            <h1 class="text-brand-teal mt-8 text-3xl font-bold tracking-[-0.02em]">Fehlerseiten</h1>
            <p class="text-brand-black/70 mt-3 text-sm leading-relaxed">
                „Vorschau“ rendert die Seite direkt mit Status 200. „Auslösen“ läuft durch die echte Exception-Behandlung und zeigt die Fehlerseite
                nur bei
                <code class="text-brand-teal">APP_DEBUG=false</code>.
            </p>

            <ul class="mt-10 space-y-2">
                <li
                    v-for="row in rows"
                    :key="row.status"
                    class="border-brand-green-gray/50 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[10px] border bg-white px-5 py-4"
                >
                    <span class="text-brand-teal w-12 text-lg font-bold tabular-nums">{{ row.status }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{ row.title }}</span>
                        <span class="text-brand-black/55 block text-xs">{{ row.eyebrow }}{{ row.isFallback ? ' · Fallback' : '' }}</span>
                    </span>
                    <a :href="row.previewUrl" class="text-brand-teal text-sm font-medium underline underline-offset-2">Vorschau</a>
                    <a :href="row.abortUrl" class="text-brand-black/60 text-sm underline underline-offset-2">Auslösen</a>
                </li>
            </ul>
        </div>
    </div>
</template>
