import { useNotificationSound } from '@/composables/useNotificationSound';
import { useToast, type ToastVariant } from '@/composables/useToast';
import { echo } from '@/echo';
import { http } from '@/lib/http';
import { computed, ref } from 'vue';

export interface AppNotification {
    id: string;
    type: string;
    variant: ToastVariant;
    icon: string;
    title: string;
    body: string;
    url: string | null;
    meta: Record<string, unknown>;
    read_at: string | null;
    created_at: string | null;
}

interface UnreadResponse {
    unread_count: number;
}

interface NotificationsResponse {
    data: AppNotification[];
    unread_count: number;
    next_page: number | null;
}

interface BroadcastPayload {
    id: string;
    type?: string;
    variant?: ToastVariant;
    icon?: string;
    title?: string;
    body?: string;
    url?: string | null;
    meta?: Record<string, unknown>;
}

const items = ref<AppNotification[]>([]);
const unreadCount = ref(0);
const loading = ref(false);
const loaded = ref(false);
const nextPage = ref<number | null>(null);

let boundUserId: number | null = null;

/** Returns false when the notification was already known, so a redelivered
 *  broadcast can't inflate the badge or re-fire the toast/sound. */
function upsert(notification: AppNotification): boolean {
    if (items.value.some((item) => item.id === notification.id)) {
        return false;
    }

    items.value = [notification, ...items.value];

    return true;
}

async function fetchPage(page = 1): Promise<void> {
    if (loading.value) {
        return;
    }

    loading.value = true;

    try {
        const data = await http.get<NotificationsResponse>('/notifications', { page });

        items.value = page === 1 ? data.data : [...items.value, ...data.data];
        unreadCount.value = data.unread_count;
        nextPage.value = data.next_page;
        loaded.value = true;
    } finally {
        loading.value = false;
    }
}

async function loadMore(): Promise<void> {
    if (nextPage.value) {
        await fetchPage(nextPage.value);
    }
}

async function markRead(id: string): Promise<void> {
    const target = items.value.find((item) => item.id === id);

    if (!target || target.read_at) {
        return;
    }

    target.read_at = new Date().toISOString();
    unreadCount.value = Math.max(0, unreadCount.value - 1);

    const data = await http.post<UnreadResponse>(`/notifications/${id}/read`);
    unreadCount.value = data.unread_count;
}

async function markAllRead(): Promise<void> {
    const stamp = new Date().toISOString();
    items.value = items.value.map((item) => (item.read_at ? item : { ...item, read_at: stamp }));
    unreadCount.value = 0;

    await http.post('/notifications/read-all');
}

async function remove(id: string): Promise<void> {
    items.value = items.value.filter((item) => item.id !== id);

    const data = await http.delete<UnreadResponse>(`/notifications/${id}`);
    unreadCount.value = data.unread_count;
}

async function clearAll(): Promise<void> {
    items.value = [];
    unreadCount.value = 0;

    await http.delete('/notifications');
}

function showBrowserNotification(notification: AppNotification): void {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return;
    }

    if (Notification.permission !== 'granted' || document.visibilityState === 'visible') {
        return;
    }

    const native = new Notification(notification.title, {
        body: notification.body,
        icon: '/leasyback-logo.svg',
        tag: notification.type,
    });

    native.onclick = () => {
        window.focus();

        if (notification.url) {
            window.location.href = notification.url;
        }
    };
}

/**
 * Bound once per user for the app's lifetime. The bell remounts on every
 * Inertia navigation (each page renders its own layout), so tearing the
 * channel down per component would either drop it entirely or, on a
 * re-bind, stack a second callback onto the same channel.
 */
function listen(userId: number): void {
    if (!echo || boundUserId === userId) {
        return;
    }

    if (boundUserId !== null) {
        echo.leave(`App.Models.User.${boundUserId}`);
    }

    boundUserId = userId;

    echo.private(`App.Models.User.${userId}`).notification((payload: BroadcastPayload) => {
        const notification: AppNotification = {
            id: payload.id,
            type: payload.type ?? 'generic',
            variant: payload.variant ?? 'info',
            icon: payload.icon ?? 'bell-outline',
            title: payload.title ?? '',
            body: payload.body ?? '',
            url: payload.url ?? null,
            meta: payload.meta ?? {},
            read_at: null,
            created_at: new Date().toISOString(),
        };

        if (!upsert(notification)) {
            return;
        }

        unreadCount.value += 1;

        useToast().toast(notification.variant, notification.title, { description: notification.body });
        useNotificationSound().playSound();
        showBrowserNotification(notification);
    });
}

function stopListening(): void {
    if (echo && boundUserId !== null) {
        echo.leave(`App.Models.User.${boundUserId}`);
        boundUserId = null;
    }
}

export function useNotifications() {
    return {
        notifications: items,
        unreadCount,
        loading,
        loaded,
        hasMore: computed(() => nextPage.value !== null),
        setUnreadCount: (value: number) => (unreadCount.value = value),
        fetchNotifications: fetchPage,
        loadMore,
        markRead,
        markAllRead,
        remove,
        clearAll,
        listen,
        stopListening,
    };
}
