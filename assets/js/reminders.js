/*
 * Termin-Erinnerungen: holt anstehende Termine vom Kalender und meldet sich
 *  - als Hinweis-Kärtchen auf der Seite und
 *  - als Browser-Benachrichtigung (wenn erlaubt),
 * solange Startseite oder Kalender geöffnet sind.
 */
(function () {
    const script = document.currentScript;
    const endpoint = (script && script.dataset.endpoint) || 'calendar.php?reminders=1';
    const timers = [];
    const store = (k, v) => { try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } };
    const shown = new Set(store('calReminded') || []);

    // Kärtchen-Container
    const box = document.createElement('div');
    box.className = 'rem-toasts';
    box.setAttribute('aria-live', 'polite');
    document.body.appendChild(box);
    const css = document.createElement('style');
    css.textContent = `.rem-toasts{position:fixed;left:16px;bottom:16px;z-index:2000;display:flex;flex-direction:column;gap:8px;max-width:min(360px,calc(100vw - 32px))}
.rem-toast{background:rgba(10,16,24,.97);border:1px solid var(--c,#38d5e6);border-left-width:4px;color:#e2e8f0;padding:10px 34px 10px 12px;font:13px/1.4 "Segoe UI",Roboto,sans-serif;box-shadow:0 0 22px rgba(0,0,0,.6);position:relative;animation:remIn .3s ease}
.rem-toast b{display:block;font-size:14px}.rem-toast small{color:#94a3b8}.rem-toast a{color:#38d5e6}
.rem-toast button{position:absolute;top:4px;right:6px;background:none;border:none;color:#ff5555;font-size:18px;cursor:pointer}
@keyframes remIn{from{transform:translateY(12px);opacity:0}to{transform:none;opacity:1}}`;
    document.head.appendChild(css);

    const fmt = d => d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    const relative = start => {
        const min = Math.round((start - Date.now()) / 60000);
        if (min <= 0) return 'jetzt';
        if (min < 60) return 'in ' + min + ' Min.';
        if (min < 1440) return 'in ' + Math.round(min / 60) + ' Std. (' + fmt(start) + ' Uhr)';
        return 'am ' + start.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' }) + ', ' + fmt(start) + ' Uhr';
    };

    function notify(it) {
        const id = it.key + '|' + (it.remind_at || it.start);
        if (shown.has(id)) return;
        shown.add(id);
        store('calReminded', [...shown].slice(-200));
        const start = new Date(it.start);
        const when = it.all_day ? 'ganztägig' : relative(start);
        const t = document.createElement('div');
        t.className = 'rem-toast';
        t.style.setProperty('--c', it.color || '#38d5e6');
        t.innerHTML = '<b></b><small></small><button type="button" aria-label="Schließen">×</button>';
        t.querySelector('b').textContent = '🔔 ' + it.title;
        t.querySelector('small').textContent = when + (it.location ? ' · ' + it.location : '');
        t.querySelector('button').onclick = () => t.remove();
        box.appendChild(t);
        setTimeout(() => t.remove(), 60000);
        if ('Notification' in window && Notification.permission === 'granted') {
            try { new Notification('🔔 ' + it.title, { body: when + (it.location ? '\n' + it.location : ''), tag: id }); } catch (e) { /* manche Browser nur über Service Worker */ }
        }
    }

    function schedule(items) {
        timers.splice(0).forEach(clearTimeout);
        const now = Date.now();
        items.forEach(it => {
            if (!it.remind_at) return;
            const at = new Date(it.remind_at).getTime();
            const start = new Date(it.start).getTime();
            if (start < now) return;
            if (at <= now) notify(it);                                              // Erinnerungszeit schon erreicht
            else if (at - now < 6 * 3600000) timers.push(setTimeout(() => notify(it), at - now));
        });
    }

    function load() {
        fetch(endpoint, { credentials: 'same-origin' }).then(r => r.ok ? r.json() : null).then(d => { if (d) schedule(d.items || []); }).catch(() => {});
    }
    load();
    setInterval(load, 10 * 60000);

    // Knopf "Benachrichtigungen aktivieren" (Kalender)
    const btn = document.getElementById('notifyBtn');
    const note = document.getElementById('notifyNote');
    function syncBtn() {
        if (!btn) return;
        if (!('Notification' in window)) { btn.disabled = true; btn.textContent = 'Browser unterstützt keine Benachrichtigungen'; return; }
        if (Notification.permission === 'granted') { btn.textContent = '✓ Benachrichtigungen aktiv'; btn.disabled = true; }
        if (Notification.permission === 'denied') { btn.textContent = 'Benachrichtigungen blockiert'; btn.disabled = true; if (note) note.textContent = 'Im Browser (Schloss-Symbol neben der Adresse) für diese Seite wieder erlauben.'; }
    }
    if (btn) btn.addEventListener('click', () => Notification.requestPermission().then(() => { syncBtn(); load(); }));
    syncBtn();
})();
