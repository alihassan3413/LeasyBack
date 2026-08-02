self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;

    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'LeasyBack', body: event.data.text() };
    }

    const data = payload.data ?? {};

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'LeasyBack', {
            body: payload.body ?? '',
            icon: payload.icon ?? '/leasyback-logo.svg',
            badge: payload.badge ?? '/leasyback-logo.svg',
            tag: payload.tag,
            renotify: Boolean(payload.tag),
            data: { url: data.url ?? '/dashboard', id: data.id },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(event.notification.data?.url ?? '/dashboard', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate(target);

                    return client.focus();
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
