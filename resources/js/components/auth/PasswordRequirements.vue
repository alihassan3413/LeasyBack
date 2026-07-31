<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    /** Pass through so a page can wire it into the password field's aria-describedby. */
    id?: string;
}>();
</script>

<template>
    <!--
        Non-authoritative guidance only — must always match the actual
        backend rule (Auth\RegisterRequest / Auth\NewPasswordController:
        `min:8`, nothing else). Never show a requirement the server doesn't
        enforce; see docs/AUTH_FRONTEND_IMPLEMENTATION_PLAN.md §14.3.
    -->
    <p :id="id" :class="cn('text-muted-foreground text-xs', props.class)">Mindestens 8 Zeichen.</p>
</template>
