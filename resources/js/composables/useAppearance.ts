import { onMounted, ref } from 'vue';

type Appearance = 'light' | 'dark' | 'system';

// Dark mode is disabled app-wide for now (light only) — see
// docs/AUTH_FRONTEND_IMPLEMENTATION_PLAN.md. This is the single point of
// control: re-enabling later means restoring the system/dark branches below,
// nothing else needs to change (AppearanceTabs.vue, localStorage, etc. are
// all left intact, just inert while this is disabled).
export function updateTheme() {
    document.documentElement.classList.remove('dark');
}

export function initializeTheme() {
    updateTheme('light');
}

export function useAppearance() {
    const appearance = ref<Appearance>('system');

    onMounted(() => {
        initializeTheme();

        const savedAppearance = localStorage.getItem('appearance') as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;
        localStorage.setItem('appearance', value);
        updateTheme(value);
    }

    return {
        appearance,
        updateAppearance,
    };
}
