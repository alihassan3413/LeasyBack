<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Label from '@/components/ui/label/Label.vue';
import { computed, useId } from 'vue';

interface Props {
    /** Explicit id for the control. Auto-generated (SSR-safe) when omitted. */
    id?: string;
    label?: string;
    required?: boolean;
    hint?: string;
    error?: string;
}

const props = defineProps<Props>();

const autoId = useId();
const fieldId = computed(() => props.id ?? autoId);
const hintId = computed(() => `${fieldId.value}-hint`);
const errorId = computed(() => `${fieldId.value}-error`);

const describedBy = computed(() => {
    const ids = [props.hint ? hintId.value : null, props.error ? errorId.value : null].filter(Boolean);

    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div class="grid gap-2">
        <Label v-if="label" :for="fieldId">
            {{ label }}
            <span v-if="required" aria-hidden="true" class="text-destructive"> *</span>
            <span v-if="required" class="sr-only"> (erforderlich)</span>
        </Label>

        <!--
            Structural only: the control itself is provided by the caller so
            FormField works uniformly with Input, PasswordInput, Checkbox, or
            anything else — it never dictates control markup. The scoped slot
            hands back the id/aria wiring so the caller's control stays
            correctly associated with this field's label, hint, and error.
        -->
        <slot :id="fieldId" :described-by="describedBy" :invalid="!!error" />

        <p v-if="hint" :id="hintId" class="text-muted-foreground text-sm">
            {{ hint }}
        </p>

        <InputError :id="errorId" :message="error" />
    </div>
</template>
