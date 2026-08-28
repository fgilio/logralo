// Logralo service worker — online-only by design.
//
// It exists for two reasons: Chrome on Android only offers the install prompt
// to pages controlled by a worker that has a fetch handler, and a Web Push
// message can only be drawn from inside one. Nothing is cached, so a deploy is
// live the moment it lands and no stale HTML, Livewire payload or photo can
// ever be served.

// What a push draws when its payload never arrived or will not parse. Showing
// nothing is not an option: a push that draws no notification is one Safari
// can drop the subscription over.
const FALLBACK = {
    title: "Logralo",
    body: "Pasó algo en el grupo.",
    icon: "/icons/icon-192.png",
};

self.addEventListener("install", () => {
    // Never sit in "waiting" behind an older worker.
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        (async () => {
            // Nothing is cached now, but drop anything an older version left.
            const names = await caches.keys();
            await Promise.all(names.map((name) => caches.delete(name)));

            await self.clients.claim();
        })(),
    );
});

self.addEventListener("fetch", (event) => {
    // Livewire updates and uploads are POSTs; leave them entirely alone.
    if (event.request.method !== "GET") {
        return;
    }

    // Passing the request straight through is what makes this count as a real
    // fetch handler without introducing a cache.
    event.respondWith(fetch(event.request));
});

self.addEventListener("push", (event) => {
    let payload = FALLBACK;

    if (event.data) {
        try {
            payload = { ...FALLBACK, ...event.data.json() };
        } catch {
            payload = { ...FALLBACK, body: event.data.text() };
        }
    }

    event.waitUntil(
        self.registration.showNotification(payload.title, {
            body: payload.body,
            icon: payload.icon,
            tag: payload.tag,
            data: payload.data,
        }),
    );
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    const target = new URL(
        event.notification.data?.url ?? "/",
        self.location.origin,
    );

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            // Logralo is one screen, so a tab that is already open is where
            // the tap belongs. Opening a second copy of the same page is the
            // thing that makes a PWA feel like a browser again.
            const open = windows.find((client) =>
                client.url.startsWith(self.location.origin),
            );

            if (open) {
                await open.focus();

                return;
            }

            await self.clients.openWindow(target.href);
        })(),
    );
});
