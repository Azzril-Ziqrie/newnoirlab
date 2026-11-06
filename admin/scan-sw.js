/**
 * Service worker halaman scan tiket: menyimpan halaman dan skripnya supaya bisa dibuka tanpa sinyal.
 * Jaringan didahulukan (deploy baru langsung terpakai); cache hanya dipakai kalau jaringan gagal.
 * Data tamu tidak disimpan di sini, itu tugas scan-offline.js (localStorage).
 */
const CACHE = 'scan-shell-v1';
const PRECACHE = ['../assets/vendor/html5-qrcode.min.js', '../assets/js/scan-offline.js', '../assets/js/scan-tiket.js'];
const PAGE_WAIT_MS = 4000;   // sinyal lambat: setelah ini pakai halaman tersimpan

const isScanPage = url => /\/scan-tiket(\.php)?$/.test(url.pathname);
const pageKey = url => new URL(url.pathname.replace(/\.php$/, ''), url.origin).href;   // satu salinan, apa pun query-nya

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(names => Promise.all(names.filter(name => name !== CACHE).map(name => caches.delete(name))))
            .then(() => self.clients.claim())
    );
});

/** Simpan hanya jawaban sukses langsung (bukan redirect ke halaman login). */
function remember(cache, key, response) {
    if (response.ok && !response.redirected) cache.put(key, response.clone());
}

function withTimeout(promise, ms) {
    return Promise.race([promise, new Promise((_, reject) => setTimeout(() => reject(new Error('slow')), ms))]);
}

async function networkFirst(request, key, waitMs) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(key, { ignoreSearch: true });
    try {
        const response = await (cached && waitMs ? withTimeout(fetch(request), waitMs) : fetch(request));
        remember(cache, key, response);
        return response;
    } catch (error) {
        if (cached) return cached;
        throw error;
    }
}

self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (request.mode === 'navigate' && isScanPage(url)) {
        event.respondWith(networkFirst(request, pageKey(url), PAGE_WAIT_MS));
    } else if (url.pathname.includes('/assets/')) {
        event.respondWith(networkFirst(request, request.url, 0));
    }
});

/** Kunjungan pertama belum lewat service worker, jadi halaman diminta menyimpannya sendiri. */
self.addEventListener('message', event => {
    const data = event.data || {};
    if (data.type !== 'cache-page') return;
    const url = new URL(data.url);
    if (url.origin !== self.location.origin || !isScanPage(url)) return;
    event.waitUntil(
        caches.open(CACHE).then(cache => fetch(url.href, { credentials: 'same-origin' }).then(res => remember(cache, pageKey(url), res)))
    );
});
