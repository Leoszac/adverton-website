// Adverton CRM service worker — install + push receiver.

self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => { /* network-only */ });

self.addEventListener('push', event => {
  let data = { title: 'Adverton CRM', body: '', url: '/crm/' };
  try { if (event.data) data = Object.assign(data, event.data.json()); }
  catch (e) { if (event.data) data.body = event.data.text(); }
  event.waitUntil(self.registration.showNotification(data.title, {
    body: data.body, icon: '/apple-touch-icon.png', badge: '/favicon-32.png',
    data: { url: data.url || '/crm/' }, tag: data.tag || 'adverton'
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/crm/';
  event.waitUntil(self.clients.matchAll({ type: 'window' }).then(list => {
    for (const c of list) if (c.url.includes(target) && 'focus' in c) return c.focus();
    if (self.clients.openWindow) return self.clients.openWindow(target);
  }));
});
