<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Eye, EyeOff } from 'lucide-vue-next';
import { ref, useAttrs } from 'vue';

defineOptions({
    inheritAttrs: false,
});

defineProps<{
    modelValue?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

// Everything else (id, name, autocomplete, required, placeholder, autofocus,
// tabindex, aria-invalid, aria-describedby, ...) is forwarded to the
// underlying Input untouched — this component only ever adds the
// show/hide behavior on top.
const attrs = useAttrs();

const visible = ref(false);
</script>

<template>
    <div class="relative">
        <Input
            v-bind="attrs"
            :type="visible ? 'text' : 'password'"
            :model-value="modelValue"
            class="pr-11"
            @update:model-value="(value) => emit('update:modelValue', String(value))"
        />

        <button
            type="button"
            class="absolute inset-y-0 right-0 flex h-full w-11 items-center justify-center rounded-full text-muted-foreground transition hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            :aria-label="visible ? 'Passwort verbergen' : 'Passwort anzeigen'"
            :aria-pressed="visible"
            @click="visible = !visible"
        >
            <EyeOff v-if="visible" class="size-4" aria-hidden="true" />
            <Eye v-else class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
