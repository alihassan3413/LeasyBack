<script setup lang="ts">
import NotificationIcon from '@/components/notifications/NotificationIcon.vue';
import { useNotifications, type AppNotification } from '@/composables/useNotifications';
import { useNotificationSound } from '@/composables/useNotificationSound';
import { useWebPush } from '@/composables/useWebPush';
import { router } from '@inertiajs/vue3';
import MdiBellOffOutline from '~icons/mdi/bell-off-outline';
import MdiBellOutline from '~icons/mdi/bell-outline';
import MdiCheckAll from '~icons/mdi/check-all';
import MdiClose from '~icons/mdi/close';
import MdiVolumeHigh from '~icons/mdi/volume-high';
import MdiVolumeOff from '~icons/mdi/volume-off';

const emit = defineEmits<{ (e: 'close'): void }>();

const { notifications, unreadCount, loading, hasMore, loadMore, markRead, markAllRead, remove, clearAll } = useNotifications();
const { soundEnabled, toggleSound } = useNotificationSound();
const { pushSupported, pushSubscribed, pushBusy, subscribeToPush, unsubscribeFromPush } = useWebPush();

function relativeTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const diff = Date.now() - new Date(value).getTime();
    const minutes = Math.round(diff / 60000);

    if (minutes < 1) {
        return 'gerade eben';
    }

    if (minutes < 60) {
        return `vor ${minutes} Min.`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `vor ${hours} Std.`;
    }

    const days = Math.round(hours / 24);

    return days === 1 ? 'gestern' : `vor ${days} Tagen`;
}

async function open(notification: AppNotification) {
    await markRead(notification.id);

    if (notification.url) {
        emit('close');
        router.visit(notification.url);
    }
}

function togglePush() {
    if (pushSubscribed.value) {
        void unsubscribeFromPush();

        return;
    }

    void subscribeToPush();
}
</script>

<template>
    <div class="w-[380px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-[18px] border border-[#e6eded] bg-white shadow-[0_18px_44px_rgba(16,57,59,0.18)]">
        <div class="flex items-center justify-between px-4 py-3" style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%)">
            <div class="flex items-center gap-2">
                <span class="text-[13.5px] font-bold text-white">Benachrichtigungen</span>
                <span v-if="unreadCount" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-[#01B990] px-1.5 text-[11px] font-bold text-white">
                    {{ unreadCount > 99 ? '99+' : unreadCount }}
                </span>
            </div>

            <div class="flex items-center gap-1">
                <button
                    type="button"
                    :aria-label="soundEnabled ? 'Ton ausschalten' : 'Ton einschalten'"
                    :title="soundEnabled ? 'Ton ausschalten' : 'Ton einschalten'"
                    class="rounded-full p-1.5 text-white/45 transition-colors hover:bg-white/10 hover:text-white"
                    @click="toggleSound"
                >
                    <component :is="soundEnabled ? MdiVolumeHigh : MdiVolumeOff" class="text-[17px]" />
                </button>

                <button
                    v-if="pushSupported"
                    type="button"
                    :disabled="pushBusy"
                    :aria-label="pushSubscribed ? 'Push-Benachrichtigungen deaktivieren' : 'Push-Benachrichtigungen aktivieren'"
                    :title="pushSubscribed ? 'Push-Benachrichtigungen deaktivieren' : 'Push-Benachrichtigungen aktivieren'"
                    class="rounded-full p-1.5 transition-colors hover:bg-white/10 disabled:opacity-40"
                    :class="pushSubscribed ? 'text-[#01B990]' : 'text-white/45 hover:text-white'"
                    @click="togglePush"
                >
                    <component :is="pushSubscribed ? MdiBellOutline : MdiBellOffOutline" class="text-[17px]" />
                </button>

                <button
                    v-if="unreadCount"
                    type="button"
                    aria-label="Alle als gelesen markieren"
                    title="Alle als gelesen markieren"
                    class="rounded-full p-1.5 text-white/45 transition-colors hover:bg-white/10 hover:text-white"
                    @click="markAllRead"
                >
                    <MdiCheckAll class="text-[17px]" />
                </button>
            </div>
        </div>

        <div class="max-h-[380px] overflow-y-auto">
            <p v-if="!notifications.length && !loading" class="px-4 py-10 text-center text-[13px] text-gray-400">
                Keine Benachrichtigungen.
            </p>

            <div
                v-for="notification in notifications"
                :key="notification.id"
                class="group relative flex cursor-pointer items-start gap-3 border-b border-[#f1f5f5] px-4 py-3 transition-colors last:border-b-0 hover:bg-[#f7fafa]"
                :class="notification.read_at ? '' : 'bg-[#01B990]/[0.04]'"
                @click="open(notification)"
            >
                <NotificationIcon :name="notification.icon" :variant="notification.variant" />

                <div class="min-w-0 flex-1">
                    <p class="text-[13px] leading-snug font-bold text-[#10393b]">{{ notification.title }}</p>
                    <p class="mt-0.5 text-[12px] leading-[1.45] text-[#00000080]">{{ notification.body }}</p>
                    <p class="mt-1 text-[11px] text-[#9aacac]">{{ relativeTime(notification.created_at) }}</p>
                </div>

                <span v-if="!notification.read_at" class="mt-1.5 size-2 shrink-0 rounded-full bg-[#01B990]"></span>

                <button
                    type="button"
                    aria-label="Entfernen"
                    class="absolute top-2 right-2 rounded-full p-1 text-[#c3d0d0] opacity-0 transition hover:bg-[#eef3f3] hover:text-[#10393b] group-hover:opacity-100"
                    @click.stop="remove(notification.id)"
                >
                    <MdiClose class="text-[14px]" />
                </button>
            </div>

            <button
                v-if="hasMore"
                type="button"
                class="w-full px-4 py-3 text-center text-[12px] font-semibold text-[#01B990] transition-colors hover:bg-[#f7fafa]"
                :disabled="loading"
                @click="loadMore"
            >
                {{ loading ? 'Lädt…' : 'Ältere anzeigen' }}
            </button>
        </div>

        <div v-if="notifications.length" class="border-t border-[#f1f5f5] px-4 py-2.5 text-right">
            <button type="button" class="text-[12px] font-semibold text-[#9aacac] transition-colors hover:text-[#E5533D]" @click="clearAll">
                Alle löschen
            </button>
        </div>
    </div>
</template>
