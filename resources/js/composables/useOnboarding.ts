import { ref } from 'vue';

const STORAGE_PREFIX = 'leasyback_b2c_onboarding_v1_';

export function onboardingStorageKey(userKey: string): string {
    return `${STORAGE_PREFIX}${userKey}`;
}

export function useOnboarding(getUserKey: () => string | undefined) {
    const isOpen = ref(false);

    function hasSeen(): boolean {
        const key = getUserKey();

        if (!key) {
            return true;
        }

        try {
            return localStorage.getItem(onboardingStorageKey(key)) === '1';
        } catch {
            return true;
        }
    }

    function markSeen(): void {
        const key = getUserKey();

        if (!key) {
            return;
        }

        try {
            localStorage.setItem(onboardingStorageKey(key), '1');
        } catch {
            // Storage unavailable (private mode / quota) — the popup may show again next visit.
        }
    }

    function maybeShow(): void {
        if (!hasSeen()) {
            isOpen.value = true;
        }
    }

    function open(): void {
        isOpen.value = true;
    }

    function dismiss(): void {
        markSeen();
        isOpen.value = false;
    }

    return { isOpen, maybeShow, open, dismiss };
}
