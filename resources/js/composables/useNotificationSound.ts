import { ref, watch } from 'vue';

const STORAGE_KEY = 'leasyback:notification-sound';
const SOUND_URL = '/sounds/notification.mp3';

const enabled = ref(readStored());

let audio: HTMLAudioElement | null = null;
let unavailable = false;

function readStored(): boolean {
    if (typeof window === 'undefined') {
        return true;
    }

    return window.localStorage.getItem(STORAGE_KEY) !== 'off';
}

watch(enabled, (value) => {
    window.localStorage.setItem(STORAGE_KEY, value ? 'on' : 'off');
});

function play(): void {
    if (!enabled.value || unavailable || typeof window === 'undefined') {
        return;
    }

    if (!audio) {
        audio = new Audio(SOUND_URL);
        audio.volume = 0.4;
        audio.addEventListener('error', () => {
            unavailable = true;
        });
    }

    audio.currentTime = 0;
    void audio.play().catch(() => undefined);
}

export function useNotificationSound() {
    return {
        soundEnabled: enabled,
        toggleSound: () => (enabled.value = !enabled.value),
        playSound: play,
    };
}
