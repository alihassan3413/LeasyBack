<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import MdiArrowLeft from '~icons/mdi/arrow-left';

withDefaults(defineProps<{ href: string; label?: string }>(), { label: 'Zurück' });
</script>

<template>
    <Link
        :href="href"
        :title="label"
        :aria-label="label"
        class="flex size-9 shrink-0 items-center justify-center rounded-full border border-[#d8e4e3] bg-white text-[#6f8585] transition hover:border-[#01B990] hover:text-[#10393b] focus-visible:ring-2 focus-visible:ring-[#01B990]/30 focus-visible:outline-none active:scale-95"
    >
        <MdiArrowLeft class="text-[18px]" />
    </Link>
</template>
