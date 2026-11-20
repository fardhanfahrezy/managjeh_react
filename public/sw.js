/* eslint-disable no-restricted-globals */
const CACHE_VERSION = 'v1';
const STATIC_CACHE = `managjeh-static-${CACHE_VERSION}`;
const SHELL_CACHE = `managjeh-shell-${CACHE_VERSION}`;
const OFFLINE_SHELL_URL = '/offline-shell';

const PRECACHE_STATIC_URLS = [
    '/manifest.webmanifest',
    '/favicon.ico',
    '/favicon.svg',
    '/apple-touch-icon.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon.svg',
];

const DENY_LIST_PREFIXES = [
    '/login',
    '/logout',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/two-factor',
    '/user/',
    '/passkeys',
    '/settings/security',
    '/settings/whatsapp',
    '/settings/profile/export',
    '/ai-chat/messages',
    '/ai-chat/conversations',
    '/ai-chat/audits',
    '/ai-planner/generate',
    '/ai-planner/reminders',
    '/sync/',
    '/up',
];

const OFFLINE_APP_SHELL_PREFIXES = [
    '/dashboard',
    '/tasks',
    '/projects',
    '/calendar',
    '/ai-planner',
    '/ai-chat',
    '/offline-shell',
];

const CORE_MANIFEST_ENTRIES = [
    'resources/css/app.css',
    'resources/js/app.tsx',
    'resources/js/pages/offline-shell.tsx',
    'resources/js/pages/dashboard.tsx',
    'resources/js/pages/tasks/index.tsx',
    'resources/js/pages/projects/index.tsx',
    'resources/js/pages/projects/show.tsx',
    'resources/js/pages/calendar/index.tsx',
    'resources/js/pages/ai-planner/index.tsx',
    'resources/js/pages/ai-chat/index.tsx',
];

function isDeniedPath(pathname) {
    return DENY_LIST_PREFIXES.some(
        (prefix) => pathname === prefix || pathname.startsWith(prefix),
    );
}

function isOfflineShellRoute(pathname) {
    return OFFLINE_APP_SHELL_PREFIXES.some(
        (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
    );
}

function isCacheableStaticAsset(pathname) {
    if (pathname.startsWith('/build/') || pathname.startsWith('/icons/')) {
        return true;
    }

    if (
        pathname === '/manifest.webmanifest' ||
        pathname === '/favicon.ico' ||
        pathname === '/favicon.svg' ||
        pathname === '/apple-touch-icon.png'
    ) {
        return true;
    }

    return /\.(?:woff2?|ttf|otf|png|svg|ico|css|js)$/i.test(pathname);
}

async function precacheBuildAssets(staticCache) {
    try {
        const response = await fetch('/build/manifest.json', {
            cache: 'no-cache',
            credentials: 'omit',
        });

        if (!response.ok) {
            return;
        }

        const manifest = await response.json();
        const urls = new Set();

        const collectEntry = (entryKey, visited) => {
            if (visited.has(entryKey)) {
                return;
            }
            visited.add(entryKey);

            const chunk = manifest[entryKey];
            if (!chunk) {
                return;
            }

            if (chunk.file) {
                urls.add(`/build/${chunk.file}`);
            }

            if (Array.isArray(chunk.css)) {
                for (const cssFile of chunk.css) {
                    urls.add(`/build/${cssFile}`);
                }
            }

            if (Array.isArray(chunk.assets)) {
                for (const assetFile of chunk.assets) {
                    urls.add(`/build/${assetFile}`);
                }
            }

            if (Array.isArray(chunk.imports)) {
                for (const imp of chunk.imports) {
                    collectEntry(imp, visited);
                }
            }
        };

        const visited = new Set();
        for (const entryKey of CORE_MANIFEST_ENTRIES) {
            collectEntry(entryKey, visited);
        }

        // Also cache all font assets in manifest so Inter renders offline without fallback.
        for (const key of Object.keys(manifest)) {
            if (/\.(?:woff2?|ttf)$/i.test(key) && manifest[key]?.file) {
                urls.add(`/build/${manifest[key].file}`);
            }
        }

        await Promise.allSettled(
            Array.from(urls).map(async (url) => {
                const existing = await staticCache.match(url);
                if (!existing) {
                    const assetResponse = await fetch(url, {
                        credentials: 'omit',
                    });
                    if (assetResponse.ok) {
                        await staticCache.put(url, assetResponse);
                    }
                }
            }),
        );
    } catch {
        // Ignore in dev mode when /build/manifest.json is not served or offline during install.
    }
}

async function warmOfflineShell() {
    try {
        const shellCache = await caches.open(SHELL_CACHE);
        const response = await fetch(OFFLINE_SHELL_URL, {
            cache: 'no-cache',
            credentials: 'omit',
        });

        if (response.ok) {
            await shellCache.put(OFFLINE_SHELL_URL, response.clone());
        }
    } catch {
        // Keep previously cached shell if offline.
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const staticCache = await caches.open(STATIC_CACHE);
            await Promise.allSettled(
                PRECACHE_STATIC_URLS.map(async (url) => {
                    const response = await fetch(url, { credentials: 'omit' });
                    if (response.ok) {
                        await staticCache.put(url, response);
                    }
                }),
            );
            await warmOfflineShell();
            await precacheBuildAssets(staticCache);
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const cacheNames = await caches.keys();
            const allowedCaches = new Set([STATIC_CACHE, SHELL_CACHE]);

            await Promise.all(
                cacheNames
                    .filter(
                        (name) =>
                            name.startsWith('managjeh-') &&
                            !allowedCaches.has(name),
                    )
                    .map((name) => caches.delete(name)),
            );

            await self.clients.claim();
        })(),
    );
});

self.addEventListener('message', (event) => {
    const data = event.data;
    if (!data || typeof data !== 'object') {
        return;
    }

    if (data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (data.type === 'WARM_OFFLINE_SHELL') {
        event.waitUntil(
            (async () => {
                const staticCache = await caches.open(STATIC_CACHE);
                await warmOfflineShell();
                await precacheBuildAssets(staticCache);
            })(),
        );
    }
});

self.addEventListener('sync', (event) => {
    if (event.tag !== 'managjeh-outbox-sync') {
        return;
    }

    event.waitUntil(
        (async () => {
            const clientList = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: false,
            });

            for (const client of clientList) {
                client.postMessage({ type: 'TRIGGER_OUTBOX_SYNC' });
            }
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Never intercept non-GET mutations or cross-origin requests.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Never cache auth, security, sync, or private API endpoints.
    if (isDeniedPath(url.pathname)) {
        return;
    }

    // Never cache Inertia XHR JSON responses (private user data belongs only in IndexedDB).
    if (request.headers.get('X-Inertia') === 'true') {
        return;
    }

    // 1. Static build assets, fonts, icons, manifest: Cache-First.
    if (isCacheableStaticAsset(url.pathname)) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(STATIC_CACHE);
                const cached = await cache.match(request);
                if (cached) {
                    return cached;
                }

                const networkResponse = await fetch(request);
                if (networkResponse.ok && networkResponse.type === 'basic') {
                    await cache.put(request, networkResponse.clone());
                }
                return networkResponse;
            })(),
        );
        return;
    }

    // 2. HTML document navigations for offline-capable app routes:
    //    Network-First without caching authenticated HTML; fall back to cached /offline-shell.
    if (request.mode === 'navigate' && isOfflineShellRoute(url.pathname)) {
        event.respondWith(
            (async () => {
                try {
                    const networkResponse = await fetch(request);
                    if (
                        url.pathname === OFFLINE_SHELL_URL &&
                        networkResponse.ok
                    ) {
                        const shellCache = await caches.open(SHELL_CACHE);
                        await shellCache.put(
                            OFFLINE_SHELL_URL,
                            networkResponse.clone(),
                        );
                    }
                    return networkResponse;
                } catch {
                    const shellCache = await caches.open(SHELL_CACHE);
                    const cachedShell =
                        await shellCache.match(OFFLINE_SHELL_URL);
                    if (cachedShell) {
                        return cachedShell;
                    }

                    return new Response(
                        '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Managjeh — Offline</title></head><body style="font-family:system-ui,sans-serif;background:#fafaf9;color:#1c1917;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px"><main style="max-width:420px;background:#fff;border:1px solid #e7e5e4;border-radius:12px;padding:24px"><h1 style="margin:0 0 8px;font-size:18px">Sedang Offline</h1><p style="margin:0;font-size:14px;color:#57534e">Buka kembali Managjeh saat terhubung internet sekali untuk menyiapkan mode offline di perangkat ini.</p></main></body></html>',
                        {
                            status: 503,
                            headers: {
                                'Content-Type': 'text/html; charset=utf-8',
                            },
                        },
                    );
                }
            })(),
        );
    }
});
