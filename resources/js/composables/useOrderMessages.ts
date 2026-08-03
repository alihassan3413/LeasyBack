import { echo } from '@/echo';
import { http } from '@/lib/http';
import type { OrderMessage, OrderMessagePage } from '@/types/message';
import { computed, onBeforeUnmount, ref, type Ref } from 'vue';

interface SendResponse {
    message: OrderMessage;
    unread_count: number;
}

interface BroadcastPayload {
    message: OrderMessage;
}

/**
 * One order's thread. Unlike useNotifications — a single app-wide store
 * behind the bell — this is instantiated per Messages section, because the
 * thread belongs to the order being viewed and must be torn down with it.
 */
export function useOrderMessages(orderId: string, currentUserId: Ref<number | null>) {
    const messages = ref<OrderMessage[]>([]);
    const unreadCount = ref(0);
    const loading = ref(false);
    const loaded = ref(false);
    const sending = ref(false);
    const nextPage = ref<number | null>(null);

    let channelName: string | null = null;

    /** Guards against the sender seeing their own message twice: once from the
     *  POST response, once from the broadcast that follows it. */
    function exists(id: string): boolean {
        return messages.value.some((message) => message.id === id);
    }

    function append(message: OrderMessage): boolean {
        if (exists(message.id)) {
            return false;
        }

        messages.value = [...messages.value, message];

        return true;
    }

    /** The API pages from the newest end; the thread reads oldest-first. */
    async function fetchPage(page = 1): Promise<void> {
        if (loading.value) {
            return;
        }

        loading.value = true;

        try {
            const data = await http.get<OrderMessagePage>(`/orders/${orderId}/messages`, { page });
            const ordered = [...data.data].reverse();

            messages.value = page === 1 ? ordered : [...ordered, ...messages.value];
            unreadCount.value = data.unread_count;
            nextPage.value = data.next_page;
            loaded.value = true;
        } finally {
            loading.value = false;
        }
    }

    async function loadOlder(): Promise<void> {
        if (nextPage.value) {
            await fetchPage(nextPage.value);
        }
    }

    async function send(body: string): Promise<void> {
        const trimmed = body.trim();

        if (!trimmed || sending.value) {
            return;
        }

        sending.value = true;

        try {
            const data = await http.post<SendResponse>(`/orders/${orderId}/messages`, { body: trimmed });

            append(data.message);
            unreadCount.value = data.unread_count;
        } finally {
            sending.value = false;
        }
    }

    async function markRead(): Promise<void> {
        if (unreadCount.value === 0) {
            return;
        }

        unreadCount.value = 0;

        const data = await http.post<{ unread_count: number }>(`/orders/${orderId}/messages/read`);
        unreadCount.value = data.unread_count;
    }

    function listen(): void {
        if (!echo || channelName !== null) {
            return;
        }

        channelName = `orders.${orderId}.messages`;

        echo.private(channelName).listen('.order.message.sent', (payload: BroadcastPayload) => {
            if (!append(payload.message)) {
                return;
            }

            if (payload.message.sender_id !== currentUserId.value) {
                unreadCount.value += 1;
            }
        });
    }

    function stopListening(): void {
        if (echo && channelName !== null) {
            echo.leave(channelName);
            channelName = null;
        }
    }

    onBeforeUnmount(stopListening);

    return {
        messages,
        unreadCount,
        loading,
        loaded,
        sending,
        hasOlder: computed(() => nextPage.value !== null),
        load: fetchPage,
        loadOlder,
        send,
        markRead,
        listen,
        stopListening,
    };
}
