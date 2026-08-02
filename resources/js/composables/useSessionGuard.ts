import { http } from '@/lib/http';
import { router } from '@inertiajs/vue3';
import { useThrottleFn } from '@vueuse/core';
import { computed, ref } from 'vue';

export type LogoutReason = 'manual' | 'inactivity' | 'session-expired';

const IDLE_BEFORE_WARNING_MS = 5 * 60 * 1000;
const WARNING_COUNTDOWN_MS = 60 * 1000;
const TICK_MS = 500;
const PERSIST_THROTTLE_MS = 1000;

const LAST_ACTIVITY_KEY = 'leasyback:last-activity';
const LOGOUT_EVENT_KEY = 'leasyback:logout-event';

const DIRECT_EVENTS = ['click', 'keydown', 'touchstart', 'pointerdown'] as const;
const THROTTLED_EVENTS = ['mousemove', 'scroll', 'wheel'] as const;

const lastActivity = ref(Date.now());
const now = ref(Date.now());
const warningVisible = ref(false);

const overlay = ref<{ visible: boolean; reason: LogoutReason; phase: 'working' | 'done' }>({
    visible: false,
    reason: 'manual',
    phase: 'working',
});

const msUntilLogout = computed(() => {
    const deadline = lastActivity.value + IDLE_BEFORE_WARNING_MS + WARNING_COUNTDOWN_MS;

    return Math.max(0, deadline - now.value);
});

const secondsRemaining = computed(() => Math.ceil(msUntilLogout.value / 1000));
const countdownProgress = computed(() => Math.min(1, Math.max(0, msUntilLogout.value / WARNING_COUNTDOWN_MS)));

let running = false;
let ticker: ReturnType<typeof setInterval> | undefined;
let lastPersistedAt = 0;

function persist(timestamp: number): void {
    if (Date.now() - lastPersistedAt < PERSIST_THROTTLE_MS) {
        return;
    }

    lastPersistedAt = Date.now();

    try {
        localStorage.setItem(LAST_ACTIVITY_KEY, String(timestamp));
    } catch {
        // Storage-restricted context — cross-tab sync degrades, this tab still works.
    }
}

function recordActivity(): void {
    if (warningVisible.value || overlay.value.visible) {
        return;
    }

    const timestamp = Date.now();
    lastActivity.value = timestamp;
    persist(timestamp);
}

const throttledActivity = useThrottleFn(recordActivity, 1000, true, true);

function tick(): void {
    now.value = Date.now();

    if (overlay.value.visible) {
        return;
    }

    const idleFor = now.value - lastActivity.value;

    if (idleFor >= IDLE_BEFORE_WARNING_MS + WARNING_COUNTDOWN_MS) {
        warningVisible.value = false;
        void logout('inactivity');

        return;
    }

    warningVisible.value = idleFor >= IDLE_BEFORE_WARNING_MS;
}

function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') {
        tick();
    }
}

function onStorage(event: StorageEvent): void {
    if (event.key === LAST_ACTIVITY_KEY && event.newValue) {
        const timestamp = Number(event.newValue);

        if (!Number.isNaN(timestamp) && timestamp > lastActivity.value) {
            lastActivity.value = timestamp;
            warningVisible.value = false;
        }

        return;
    }

    if (event.key === LOGOUT_EVENT_KEY && event.newValue && !overlay.value.visible) {
        showOverlay('session-expired');
        window.setTimeout(() => window.location.assign('/'), 1200);
    }
}

function showOverlay(reason: LogoutReason): void {
    overlay.value = { visible: true, reason, phase: 'working' };
}

async function logout(reason: LogoutReason = 'manual'): Promise<void> {
    if (overlay.value.visible) {
        return;
    }

    warningVisible.value = false;
    showOverlay(reason);

    try {
        localStorage.setItem(LOGOUT_EVENT_KEY, String(Date.now()));
        localStorage.removeItem(LAST_ACTIVITY_KEY);
    } catch {
        // Cross-tab broadcast is best-effort.
    }

    stop();

    router.post(
        route('logout'),
        {},
        {
            onFinish: () => {
                overlay.value = { ...overlay.value, phase: 'done' };
                window.setTimeout(() => {
                    overlay.value = { visible: false, reason: 'manual', phase: 'working' };
                }, 1100);
            },
        },
    );
}

async function staySignedIn(): Promise<void> {
    warningVisible.value = false;
    const timestamp = Date.now();
    lastActivity.value = timestamp;
    now.value = timestamp;
    lastPersistedAt = 0;
    persist(timestamp);

    try {
        await http.post(route('session.keep-alive'));
    } catch {
        // A failed ping just means the next real request will surface the expiry.
    }
}

function start(): void {
    if (running) {
        return;
    }

    running = true;
    lastActivity.value = Date.now();
    now.value = lastActivity.value;
    warningVisible.value = false;

    DIRECT_EVENTS.forEach((event) => window.addEventListener(event, recordActivity, { passive: true }));
    THROTTLED_EVENTS.forEach((event) => window.addEventListener(event, throttledActivity, { passive: true }));
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('storage', onStorage);

    ticker = setInterval(tick, TICK_MS);
}

function stop(): void {
    if (!running) {
        return;
    }

    running = false;

    DIRECT_EVENTS.forEach((event) => window.removeEventListener(event, recordActivity));
    THROTTLED_EVENTS.forEach((event) => window.removeEventListener(event, throttledActivity));
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener('storage', onStorage);

    if (ticker !== undefined) {
        clearInterval(ticker);
        ticker = undefined;
    }

    warningVisible.value = false;
}

export function useSessionGuard() {
    return {
        warningVisible,
        secondsRemaining,
        countdownProgress,
        overlay,
        start,
        stop,
        recordActivity,
        staySignedIn,
        logout,
        showOverlay,
    };
}
