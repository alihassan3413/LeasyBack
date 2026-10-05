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
        backend rule (AppServiceProvider::passwordRules(): at least 12
        characters, upper and lower case, at least one digit). Never show a
        requirement the server doesn't enforce, and never omit one it does;
        see docs/AUTH_FRONTEND_IMPLEMENTATION_PLAN.md §14.3.
    -->
    <p :id="id" :class="cn('text-muted-foreground text-xs', props.class)">
        Mindestens 12 Zeichen, mit Groß- und Kleinbuchstaben und mindestens einer Zahl.
    </p>
</template>
