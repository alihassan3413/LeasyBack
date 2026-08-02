import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

export const echo: Echo<'reverb'> | null = key
    ? new Echo({
          broadcaster: 'reverb',
          key,
          wsHost: import.meta.env.VITE_REVERB_HOST as string,
          wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
          wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
          forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
          enabledTransports: ['ws', 'wss'],
      })
    : null;

if (echo) {
    window.Echo = echo;
}
