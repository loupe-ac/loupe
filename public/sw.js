// Loupe service worker. It exists for web push only: no fetch handler, no cache.

async function closeStale(openTags) {
    if (!Array.isArray(openTags)) {
        return;
    }
    const shown = await self.registration.getNotifications();
    for (const notification of shown) {
        if (!openTags.includes(notification.tag)) {
            notification.close();
        }
    }
}

function sameOriginUrl(value) {
    if (typeof value !== 'string') {
        return null;
    }
    try {
        const url = new URL(value, self.location.origin);
        return url.origin === self.location.origin ? url.href : null;
    } catch {
        return null;
    }
}

self.addEventListener('push', (event) => {
    if (event.notification) {
        // Declarative push: the browser shows the notification itself.
        event.waitUntil(closeStale(event.notification.data?.openTags));
        return;
    }

    let payload;
    try {
        payload = event.data?.json();
    } catch {
        return;
    }
    const notification = payload?.notification;
    if (!notification?.title) {
        return;
    }

    event.waitUntil(
        closeStale(notification.data?.openTags).then(() =>
            self.registration.showNotification(notification.title, {
                body: notification.body,
                tag: notification.tag,
                icon: notification.icon,
                data: notification.data,
            }),
        ),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = sameOriginUrl(event.notification.data?.url) ?? self.location.origin + '/';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => {
                const open = windows.find((client) => sameOriginUrl(client.url));
                if (open) {
                    return open.focus().then((client) => (client ?? open).navigate(target));
                }
                return self.clients.openWindow(target);
            }),
    );
});
