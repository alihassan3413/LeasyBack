import { http } from '@/lib/http';
import { ref } from 'vue';

const VAPID_PUBLIC_KEY = import.meta.env.VITE_VAPID_PUBLIC_KEY as string | undefined;

const supported =
    typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && Boolean(VAPID_PUBLIC_KEY);

const permission = ref<NotificationPermission>(
    typeof window !== 'undefined' && 'Notification' in window ? Notification.permission : 'denied',
);
const subscribed = ref(false);
const busy = ref(false);

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(normalized);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (!supported) {
        return null;
    }

    return navigator.serviceWorker.register('/sw.js', { scope: '/' });
}

async function syncState(): Promise<void> {
    const worker = await registration();
    subscribed.value = Boolean(await worker?.pushManager.getSubscription());
}

async function subscribe(): Promise<boolean> {
    if (!supported || busy.value) {
        return false;
    }

    busy.value = true;

    try {
        permission.value = await Notification.requestPermission();

        if (permission.value !== 'granted') {
            return false;
        }

        const worker = await navigator.serviceWorker.ready.catch(() => null);
        const target = worker ?? (await registration());

        if (!target) {
            return false;
        }

        const existing = await target.pushManager.getSubscription();
        const subscription =
            existing ??
            (await target.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY as string),
            }));

        const json = subscription.toJSON();

        await http.post('/push-subscriptions', {
            endpoint: subscription.endpoint,
            keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
        });

        subscribed.value = true;

        return true;
    } catch {
        return false;
    } finally {
        busy.value = false;
    }
}

async function unsubscribe(): Promise<void> {
    if (!supported || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const worker = await registration();
        const subscription = await worker?.pushManager.getSubscription();

        if (!subscription) {
            subscribed.value = false;

            return;
        }

        await http.delete('/push-subscriptions', { endpoint: subscription.endpoint });
        await subscription.unsubscribe();
        subscribed.value = false;
    } catch {
        // keep the previous state; the caller re-reads `subscribed`
    } finally {
        busy.value = false;
    }
}

export function useWebPush() {
    return {
        pushSupported: supported,
        pushPermission: permission,
        pushSubscribed: subscribed,
        pushBusy: busy,
        registerServiceWorker: registration,
        syncPushState: syncState,
        subscribeToPush: subscribe,
        unsubscribeFromPush: unsubscribe,
    };
}
