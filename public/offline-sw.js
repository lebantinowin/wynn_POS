const CACHE = 'wynn-offline-shell-v4';
const ASSETS = ['/offline-checkout.html', '/js/offline-store.js', '/js/offline-checkout.js', '/css/grocery.css', '/fonts/InterVariable.woff2', '/images/product-placeholder.svg'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS))));
self.addEventListener('activate', event => event.waitUntil(Promise.all([self.clients.claim(), caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('wynn-offline-shell-') && key !== CACHE).map(key => caches.delete(key))))])));
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin || !ASSETS.includes(url.pathname) || url.search) return;
    event.respondWith(caches.open(CACHE).then(async cache => {
        if (url.pathname === '/offline-checkout.html') {
            try { const response = await fetch(event.request); if (response.ok) { await cache.put(event.request, response.clone()); return response; } }
            catch {}
        }
        return (await cache.match(event.request)) || fetch(event.request);
    }));
});
