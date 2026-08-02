<script setup lang="ts">
import { computed } from 'vue';
import MdiAccountAlertOutline from '~icons/mdi/account-alert-outline';
import MdiBellOutline from '~icons/mdi/bell-outline';
import MdiCheckDecagram from '~icons/mdi/check-decagram';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';
import MdiProgressClock from '~icons/mdi/progress-clock';
import MdiTagOutline from '~icons/mdi/tag-outline';

const props = defineProps<{ name: string; variant: string }>();

const ICONS: Record<string, unknown> = {
    'progress-clock': MdiProgressClock,
    'check-decagram': MdiCheckDecagram,
    'tag-outline': MdiTagOutline,
    'file-document-outline': MdiFileDocumentOutline,
    'account-alert-outline': MdiAccountAlertOutline,
    'bell-outline': MdiBellOutline,
};

const ACCENT: Record<string, string> = {
    success: '#01B990',
    error: '#E5533D',
    warning: '#EF8450',
    info: '#4FA3A6',
};

const icon = computed(() => ICONS[props.name] ?? MdiBellOutline);
const accent = computed(() => ACCENT[props.variant] ?? ACCENT.info);
</script>

<template>
    <span class="flex size-9 shrink-0 items-center justify-center rounded-[11px] text-white" :style="{ backgroundColor: accent }">
        <component :is="icon" class="text-[18px]" />
    </span>
</template>
