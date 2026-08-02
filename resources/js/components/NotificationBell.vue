<script setup lang="ts">
import { computed } from 'vue';
import MdiBellOutline from '~icons/mdi/bell-outline';

const props = withDefaults(defineProps<{ count?: number }>(), { count: 0 });

const badge = computed(() => (props.count > 99 ? '99+' : String(props.count)));
</script>

<template>
    <button
        type="button"
        aria-label="Benachrichtigungen"
        title="Benachrichtigungen"
        class="group relative flex size-10 shrink-0 items-center justify-center rounded-full text-white/85 transition-all duration-200 hover:text-white focus-visible:ring-2 focus-visible:ring-[#01B990]/40 focus-visible:outline-none active:scale-95"
        style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%); box-shadow: 0 4px 12px rgba(16, 57, 59, 0.18)"
    >
        <MdiBellOutline class="text-[18px] transition-transform duration-200 group-hover:-rotate-12" />

        <span
            v-if="count > 0"
            class="absolute -top-1 -right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-[#01B990] px-1 text-[10px] leading-none font-bold text-white ring-2 ring-white"
        >
            {{ badge }}
        </span>
    </button>
</template>
