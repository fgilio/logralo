// Logralo service worker — online-only by design.
//
// It exists for exactly one reason: Chrome on Android only offers the install
// prompt to pages controlled by a worker that has a fetch handler. Nothing is
// cached, so a deploy is live the moment it lands and no stale HTML, Livewire
// payload or photo can ever be served.

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
