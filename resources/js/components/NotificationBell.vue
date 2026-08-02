<script setup lang="ts">
import NotificationPanel from '@/components/notifications/NotificationPanel.vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useNotifications } from '@/composables/useNotifications';
import { useWebPush } from '@/composables/useWebPush';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import MdiBellOutline from '~icons/mdi/bell-outline';

const page = usePage<SharedData>();
const { unreadCount, setUnreadCount, fetchNotifications, listen } = useNotifications();
const { syncPushState } = useWebPush();

const open = ref(false);

const badge = computed(() => (unreadCount.value > 99 ? '99+' : String(unreadCount.value)));
const userId = computed(() => page.props.auth?.user?.id ?? null);

// The server count is authoritative on every navigation: it self-heals a
// badge drifted by a duplicate broadcast or a read in another tab.
watch(
    () => page.props.notifications?.unread_count,
    (value) => {
        if (typeof value === 'number') {
            setUnreadCount(value);
        }
    },
    { immediate: true },
);

watch(
    userId,
    (id) => {
        if (id) {
            listen(id);
        }
    },
    { immediate: true },
);

watch(open, (isOpen) => {
    if (isOpen) {
        void fetchNotifications(1);
    }
});

onMounted(() => void syncPushState());
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                aria-label="Benachrichtigungen"
                title="Benachrichtigungen"
                class="group relative flex size-10 shrink-0 items-center justify-center rounded-full text-white/85 transition-all duration-200 hover:text-white focus-visible:ring-2 focus-visible:ring-[#01B990]/40 focus-visible:outline-none active:scale-95"
                style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%); box-shadow: 0 4px 12px rgba(16, 57, 59, 0.18)"
            >
                <MdiBellOutline class="text-[18px] transition-transform duration-200 group-hover:-rotate-12" />

                <span
                    v-if="unreadCount > 0"
                    class="absolute -top-1 -right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-[#01B990] px-1 text-[10px] leading-none font-bold text-white ring-2 ring-white"
                >
                    {{ badge }}
                </span>
            </button>
        </PopoverTrigger>

        <PopoverContent align="end" :side-offset="10" class="w-auto border-none bg-transparent p-0 shadow-none">
            <NotificationPanel @close="open = false" />
        </PopoverContent>
    </Popover>
</template>
