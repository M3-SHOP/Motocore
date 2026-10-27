
const CACHE_NAME = "motocore-v1";

const APP_FILES = [
    "/manifest.json",
    "/assets/css/style.css"
];

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(APP_FILES);
        })
    );

    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );

    self.clients.claim();
});

self.addEventListener("fetch", (event) => {
    const request = event.request;

    if (request.method !== "GET") {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Não guardar páginas PHP nem respostas da base de dados em cache.
    if (url.pathname.endsWith(".php") || url.pathname === "/") {
        return;
    }

    event.respondWith(
        fetch(request).catch(() => caches.match(request))
    );
});
