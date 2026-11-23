// Оболочка приложения кэшируется, чтобы иконка открывалась мгновенно даже при плохой связи.
// Данные (/api) всегда идут напрямую на сервер.
const CACHE = 'drive-v39';
const SHELL = ['/', '/app.js?v=39', '/style.css?v=38', '/icon-192.png', '/favicon.png', '/manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api/')) return;
  // страница — сначала сеть (свежая версия), при обрыве — из кэша
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request)
      .then(r => { caches.open(CACHE).then(c => c.put('/', r.clone())); return r; })
      .catch(() => caches.match('/')));
    return;
  }
  // скрипты, стили, иконки — из кэша, обновляются вместе с номером версии
  e.respondWith(caches.match(e.request).then(hit => hit || fetch(e.request)));
});

// Push: сам текст забираем с сервера, он проксирует запрос в сервис рефералки
self.addEventListener('push', e => {
  e.waitUntil((async () => {
    let items = [];
    try {
      const r = await fetch('/api/ref/push-pending', { credentials: 'include' });
      if (r.ok) items = (await r.json()).items || [];
    } catch (err) {}
    if (!items.length) items = [{ title: 'Автошкола', body: 'Есть новости по вашим друзьям', url: '/#ref' }];
    for (const n of items) {
      await self.registration.showNotification(n.title, {
        body: n.body || '',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        data: { url: '/#ref' },
        tag: 'drive-' + (n.id || Date.now()),
      });
    }
  })());
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil((async () => {
    const all = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) { if (c.url.includes(location.origin)) { c.focus(); c.navigate('/#ref'); return; } }
    await clients.openWindow('/#ref');
  })());
});
