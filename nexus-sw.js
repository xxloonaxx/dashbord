/* VRC Nexus – Service Worker für Push-Benachrichtigungen (Handy & PC, auch bei geschlossenem Tab) */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('push', e => {
    let d = {};
    try { d = e.data ? e.data.json() : {}; } catch (x) { d = { title: 'VRC Nexus', body: e.data ? e.data.text() : '' }; }
    e.waitUntil(self.registration.showNotification(d.title || 'VRC Nexus', {
        body: d.body || '', tag: d.tag || undefined, renotify: !!d.tag, icon: 'assets/img/nexus-push.png', badge: 'assets/img/nexus-push.png',
        data: { url: d.url || 'nexus.php' },
    }));
});
self.addEventListener('notificationclick', e => {
    e.notification.close();
    const url = new URL((e.notification.data && e.notification.data.url) || 'nexus.php', self.registration.scope).href;
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
        for (const c of list) if (c.url.split('#')[0] === url && 'focus' in c) return c.focus();
        return self.clients.openWindow(url);
    }));
});
