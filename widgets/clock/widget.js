Widgets.register('clock', function (el) {
    const c = el.querySelector('.ck');
    if (!c) return;
    const sec = c.dataset.seconds === '1', z2 = c.dataset.zone2;
    const kw = d => { const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())); const n = t.getUTCDay() || 7; t.setUTCDate(t.getUTCDate() + 4 - n); const y = new Date(Date.UTC(t.getUTCFullYear(), 0, 1)); return Math.ceil(((t - y) / 86400000 + 1) / 7); };
    const tick = () => {
        const n = new Date();
        c.querySelector('.ck-time').textContent = n.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: sec ? '2-digit' : undefined });
        c.querySelector('.ck-date').textContent = n.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        const doy = Math.floor((n - new Date(n.getFullYear(), 0, 0)) / 86400000);
        const days = (new Date(n.getFullYear(), 11, 31) - new Date(n.getFullYear(), 0, 0)) / 86400000;
        c.querySelector('.ck-meta').textContent = 'KW ' + kw(n) + ' · Tag ' + doy + ' von ' + Math.round(days) + ' · noch ' + Math.round(days - doy) + ' Tage im Jahr';
        const z = c.querySelector('.ck-z2');
        if (z) try { z.textContent = n.toLocaleString('de-DE', { timeZone: z2, weekday: 'short', hour: '2-digit', minute: '2-digit' }); } catch (e) { z.textContent = '?'; }
    };
    tick();
    const t = setInterval(tick, 1000);
    return () => clearInterval(t);
});
