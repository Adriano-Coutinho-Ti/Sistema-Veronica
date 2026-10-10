const VERSAO = 'loja-v1';
const BASE = new URL('./', self.location.href).href;
const OFFLINE = BASE + 'offline.html';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(VERSAO).then((c) => c.add(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== VERSAO).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

// Sempre rede primeiro: páginas e dados nunca vêm do cache (estoque, carrinho e pagamento têm que ser
// os de agora). O cache serve só a página "sem conexão" e CSS/JS quando a rede falha.
self.addEventListener('fetch', (e) => {
    const req = e.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== self.location.origin) return;
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
        return;
    }
    if (/^\/assets\/(css|js)\//.test(url.pathname)) {
        e.respondWith(fetch(req).then((r) => {
            if (r.ok) { const c = r.clone(); caches.open(VERSAO).then((cache) => cache.put(req, c)); }
            return r;
        }).catch(() => caches.match(req)));
    }
});
