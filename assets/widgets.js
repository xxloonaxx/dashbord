/* Startseiten-Widgets: Laden, Aktualisieren, Aktionen und (im Bearbeiten-Modus) Hinzufügen, Sortieren, Einstellungen */
(function () {
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const store = { get(k, d) { try { const v = localStorage.getItem('wg-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('wg-' + k, JSON.stringify(v)); } catch (e) {} } };
    const handlers = {}, cleanups = new Map(), timers = new Map();
    const EDIT = !!(window.IDX && window.IDX.edit);

    function call(act, data, get) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => body.append(k, v ?? ''));
        const opts = get ? {} : { method: 'POST', body, credentials: 'same-origin' };
        return fetch('widgets.php' + (get ? '?' + body.toString() : ''), Object.assign({ headers: { Accept: 'application/json' }, credentials: 'same-origin' }, opts))
            .then(async r => { let j; try { j = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort (HTTP ' + r.status + ')'); } if (!r.ok || j.error) throw new Error(j.error || 'Fehler'); return j; });
    }
    function toast(msg, bad) {
        $$('.wg-toast').forEach(t => t.parentNode.removeChild(t));
        const t = document.createElement('div');
        t.className = 'wg-toast' + (bad ? ' bad' : '');
        t.textContent = msg;
        document.body.append(t);
        setTimeout(() => t.classList.add('out'), 2400);
        setTimeout(() => t.remove(), 3000);
    }

    // Öffentliche Schnittstelle für widget.js-Dateien
    window.Widgets = {
        register(type, fn) { handlers[type] = fn; $$('.wg[data-type="' + type + '"]').forEach(mount); },
        toast,
    };

    // ---------- Mauerwerk-Raster: Höhe → Zeilen-Span, damit keine Lücken entstehen ----------
    let layoutQueued = false;
    function layout() {
        const g = document.getElementById('wgGrid');
        if (!g) return;
        const cs = getComputedStyle(g), row = parseFloat(cs.gridAutoRows) || 4, gap = parseFloat(cs.columnGap) || 14;
        const ws = $$('.wg', g);
        g.classList.add('ready');
        // 1. Spans nach Inhaltshöhe
        ws.forEach(el => { el.classList.remove('fill'); el.style.gridRowEnd = 'span ' + Math.max(1, Math.ceil((el.offsetHeight + gap) / row)); });
        // 2. Verbleibende Lücken schließen: jedes Widget bis zum nächsten darunter (bzw. bis zum Ende des Rasters) verlängern
        const rects = ws.map(el => ({ el, r: el.getBoundingClientRect() }));
        const bottom = Math.max(0, ...rects.map(x => x.r.bottom));
        rects.forEach(({ el, r }) => {
            if (el.classList.contains('min')) return;
            const below = rects.filter(o => o.el !== el && o.r.top >= r.bottom - 2 && o.r.left < r.right - 5 && o.r.right > r.left + 5).map(o => o.r.top - gap);
            const target = below.length ? Math.min(...below) : bottom;
            const extra = target - r.bottom;
            if (extra > 2) { el.style.gridRowEnd = 'span ' + Math.max(1, Math.round((r.height + extra + gap) / row)); el.classList.add('fill'); }
        });
        const cols = cs.gridTemplateColumns.split(' ').filter(Boolean);
        g.style.setProperty('--colw', (parseFloat(cols[0]) || 0) + 'px');
        g.dataset.cols = cols.length + ' Spalten · Widgets ziehen zum Sortieren';
    }
    const relayout = () => { if (layoutQueued) return; layoutQueued = true; requestAnimationFrame(() => { layoutQueued = false; layout(); }); };
    window.addEventListener('resize', relayout);
    window.addEventListener('load', relayout);
    const ro = 'ResizeObserver' in window ? new ResizeObserver(relayout) : null;

    function mount(el) {
        // Inhalt beobachten (nicht die Karte selbst – die wird beim Lückenfüllen gestreckt, sonst entstünde eine Schleife)
        if (ro) [...(el.querySelector('.wg-body') || el).children].forEach(c => ro.observe(c));
        relayout();
        const old = cleanups.get(el);
        if (typeof old === 'function') { try { old(); } catch (e) {} }
        cleanups.delete(el);
        const fn = handlers[el.dataset.type];
        if (fn) {
            const api = { uid: el.dataset.uid, action: (op, data) => action(el, op, data), reload: () => reload(el) };
            try { cleanups.set(el, fn(el, api)); } catch (e) { console.error('Widget ' + el.dataset.type, e); }
        }
        // Formatierungs-Werkzeugleiste für neue Textfelder (falls editor.js geladen ist)
        if (window.FormatEditor && window.FormatEditor.attach) $$('textarea[data-format]', el).forEach(t => window.FormatEditor.attach(t));
    }
    function setBody(el, html) { $('.wg-body', el).innerHTML = html; mount(el); $$('img', el).forEach(i => i.addEventListener('load', relayout, { once: true })); }
    document.addEventListener('load', e => { if (e.target.tagName === 'IMG' && e.target.closest('.wg')) relayout(); }, true);
    function reload(el) {
        el.classList.add('loading');
        return call('render', { uid: el.dataset.uid }, true).then(r => setBody(el, r.html)).catch(x => toast(x.message, true)).finally(() => el.classList.remove('loading'));
    }
    function action(el, op, data) {
        el.classList.add('loading');
        return call('action', Object.assign({ uid: el.dataset.uid, op }, data || {})).then(r => { setBody(el, r.html); if (r.msg) toast(r.msg); }).catch(x => toast(x.message, true)).finally(() => el.classList.remove('loading'));
    }

    // Klicks und Formulare mit data-wg-action
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-wg-action]');
        if (!b || b.tagName === 'FORM') return;
        const el = b.closest('.wg');
        if (!el) return;
        e.preventDefault();
        const d = Object.assign({}, b.dataset); delete d.wgAction;
        action(el, b.dataset.wgAction, d);
    });
    document.addEventListener('submit', e => {
        const f = e.target.closest('form[data-wg-action]');
        if (!f) return;
        e.preventDefault();
        const d = {}; new FormData(f).forEach((v, k) => { d[k] = v; });
        action(f.closest('.wg'), f.dataset.wgAction, d);
    });

    const grid = $('#wgGrid');
    if (!grid) return;
    $$('.wg', grid).forEach(el => {
        mount(el);
        const sec = +el.dataset.refresh;
        if (sec >= 30 && !EDIT) timers.set(el, setInterval(() => { if (!document.hidden) reload(el); }, sec * 1000));
    });

    // Einklappen (pro Browser)
    const mins = store.get('min', {});
    $$('.wg', grid).forEach(el => { if (mins[el.dataset.uid]) el.classList.add('min'); });
    grid.addEventListener('click', e => {
        const b = e.target.closest('.wg-min');
        if (!b) return;
        const el = b.closest('.wg');
        el.classList.toggle('min');
        relayout();
        mins[el.dataset.uid] = el.classList.contains('min');
        store.set('min', mins);
    });
    const area = $('#wgArea'), tog = $('#wgToggle');
    if (tog) {
        const hidden = store.get('hidden', false);
        area.classList.toggle('collapsed', hidden);
        relayout();
        tog.textContent = hidden ? '▸ einblenden' : '▾ ausblenden';
        tog.addEventListener('click', () => { const h = !area.classList.contains('collapsed'); area.classList.toggle('collapsed', h); relayout(); store.set('hidden', h); tog.textContent = h ? '▸ einblenden' : '▾ ausblenden'; });
    }

    if (!EDIT) return;

    // ---------- Bearbeiten-Modus ----------
    const modal = $('#wgModal'), mbody = $('#wgModalBody');
    const open = html => { mbody.innerHTML = html; modal.classList.add('open'); };
    const close = () => modal.classList.remove('open');
    modal.addEventListener('click', e => { if (e.target === modal || e.target.closest('[data-close]')) close(); });

    $('#wgAdd').addEventListener('click', () => {
        call('catalog', {}, true).then(r => {
            open('<div class="panel-title">// Widget hinzufügen</div><p class="side-note">Widgets liegen im Ordner <code>widgets/</code>. Eigene Widgets: <code>widgets/_vorlage</code> kopieren und anpassen – sie erscheinen dann hier.</p><div class="wg-cat">'
                + r.widgets.map(w => '<button type="button" class="wg-pick' + (w.error ? ' err' : '') + '" data-type="' + esc(w.type) + '"' + (w.error ? ' disabled' : '') + '><span class="wg-pick-ic">' + esc(w.icon) + '</span><span><b>' + esc(w.name) + '</b>'
                    + '<small>' + esc(w.description) + '</small><em>' + esc('widgets/' + w.type + ' · v' + w.version + (w.author ? ' · ' + w.author : '') + (w.permission ? ' · 🔑 ' + w.permission : w.login ? ' · 🔑 angemeldet' : '') + (w.used ? ' · ' + w.used + '× auf der Startseite' : '')) + '</em></span></button>').join('') + '</div>');
            $$('.wg-pick', mbody).forEach(b => b.addEventListener('click', () => call('add', { type: b.dataset.type }).then(async x => {
                await loadAssets(x.assets || {});
                grid.insertAdjacentHTML('beforeend', x.html);
                const el = grid.lastElementChild;
                mount(el); dragify(el);
                $('.wg-empty-grid', grid) && $('.wg-empty-grid', grid).remove();
                close(); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); toast('Widget hinzugefügt.');
            }).catch(x => toast(x.message, true))));
        }).catch(x => toast(x.message, true));
    });

    grid.addEventListener('click', e => {
        const el = e.target.closest('.wg');
        if (!el) return;
        if (e.target.closest('[data-wg-remove]') && confirm('Widget entfernen? Seine gespeicherten Inhalte (z. B. Aufgaben) gehen verloren.'))
            call('remove', { uid: el.dataset.uid }).then(() => { el.remove(); toast('Entfernt.'); }).catch(x => toast(x.message, true));
        if (e.target.closest('[data-wg-size]'))
            call('size', { uid: el.dataset.uid }).then(r => { el.className = el.className.replace(/\bwg-(s|m|l|xl)\b/, 'wg-' + r.size); toast('Größe: ' + r.label); }).catch(x => toast(x.message, true));
        if (e.target.closest('[data-wg-settings]')) settings(el);
    });

    function settings(el) {
        call('settings', { uid: el.dataset.uid }).then(r => {
            const field = (k, d) => {
                const v = r.values[k] ?? d.default, id = 'wgf_' + k;
                if (d.type === 'checkbox') return '<label class="toggle-line"><input type="checkbox" name="' + esc(k) + '"' + (v ? ' checked' : '') + '> ' + esc(d.label) + '</label>';
                let input;
                if (d.type === 'select') input = '<select id="' + id + '" name="' + esc(k) + '">' + Object.entries(d.options || {}).map(([o, l]) => '<option value="' + esc(o) + '"' + (String(v) === o ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select>';
                else if (d.type === 'textarea') input = '<textarea id="' + id + '" name="' + esc(k) + '" rows="4">' + esc(v) + '</textarea>';
                else input = '<input id="' + id + '" type="' + (d.type === 'number' ? 'number' : 'text') + '" name="' + esc(k) + '" value="' + esc(v) + '"' + (d.min !== undefined ? ' min="' + d.min + '"' : '') + (d.max !== undefined ? ' max="' + d.max + '"' : '') + '>';
                return '<label class="field" for="' + id + '">' + esc(d.label) + '</label>' + input;
            };
            open('<div class="panel-title">// ' + esc(r.name) + ' – Einstellungen</div><form id="wgSet">' + Object.entries(r.schema).map(([k, d]) => field(k, d)).join('') + '<button class="btn btn-gold" style="width:100%;margin-top:14px">Speichern</button></form>');
            $('#wgSet').addEventListener('submit', ev => {
                ev.preventDefault();
                const cfg = {};
                Object.entries(r.schema).forEach(([k, d]) => { const f = ev.target.elements[k]; cfg[k] = d.type === 'checkbox' ? f.checked : f.value; });
                call('settings', { uid: el.dataset.uid, cfg: JSON.stringify(cfg) }).then(x => {
                    const tmp = document.createElement('div'); tmp.innerHTML = x.card;
                    const nel = tmp.firstElementChild; el.replaceWith(nel); mount(nel); dragify(nel); close(); toast('Gespeichert.');
                }).catch(x => toast(x.message, true));
            });
        }).catch(x => toast(x.message, true));
    }

    // Gestaltung/Skript eines neuen Widget-Typs sofort laden (sonst erst nach dem Neuladen)
    function loadAssets(a) {
        const jobs = [];
        if (a.css && !document.querySelector('link[href^="' + a.css.split('?')[0] + '"]')) {
            jobs.push(new Promise(res => { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = a.css; l.onload = l.onerror = res; document.head.append(l); }));
        }
        if (a.js && !document.querySelector('script[src^="' + a.js.split('?')[0] + '"]')) {
            jobs.push(new Promise(res => { const sc = document.createElement('script'); sc.src = a.js; sc.onload = sc.onerror = res; document.body.append(sc); }));
        }
        return Promise.all(jobs);
    }

    // Drag & Drop zum Sortieren
    let dragged = null;
    function dragify(el) {
        el.addEventListener('dragstart', e => { if (!e.target.closest('.wg-head')) { /* nur am Kopf ziehen */ } dragged = el; el.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        el.addEventListener('dragend', () => { el.classList.remove('dragging'); dragged = null; relayout(); saveOrder(); });
        el.addEventListener('dragover', e => {
            if (!dragged || dragged === el) return;
            e.preventDefault();
            const r = el.getBoundingClientRect();
            const after = (e.clientY - r.top) > r.height / 2 || (e.clientX - r.left) > r.width * 0.66;
            el.parentNode.insertBefore(dragged, after ? el.nextSibling : el);
            relayout();
        });
    }
    $$('.wg', grid).forEach(dragify);
    function saveOrder() { call('reorder', { order: JSON.stringify($$('.wg', grid).map(w => w.dataset.uid)) }).catch(x => toast(x.message, true)); }
})();
