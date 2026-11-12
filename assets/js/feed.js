/* RSS-Reader – Oberfläche. Alle Texte werden maskiert; Artikel-HTML kommt bereits bereinigt vom Server. */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const F = window.FEED;
    const store = { get(k, d) { try { const v = localStorage.getItem('fd-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('fd-' + k, JSON.stringify(v)); } catch (e) {} } };
    const typing = () => /INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '');

    function api(act, data, file) {
        const body = file || new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => body.append(k, v ?? ''));
        return fetch('feed.php', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body }).then(async r => {
            let j; try { j = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort (HTTP ' + r.status + ')'); }
            if (!r.ok || j.error) throw new Error(j.error || 'Fehler');
            return j;
        });
    }
    function toast(msg, bad) {
        $$('.fd-toast').forEach(t => t.parentNode.removeChild(t));
        const t = document.createElement('div'); t.className = 'fd-toast' + (bad ? ' bad' : ''); t.textContent = msg; document.body.append(t);
        setTimeout(() => t.classList.add('out'), 2600); setTimeout(() => t.remove(), 3200);
    }
    const ago = ts => {
        const d = Date.now() / 1000 - ts;
        if (d < 60) return 'gerade eben';
        if (d < 3600) return 'vor ' + Math.round(d / 60) + ' Min.';
        if (d < 86400) return 'vor ' + Math.round(d / 3600) + ' Std.';
        if (d < 86400 * 7) return 'vor ' + Math.round(d / 86400) + ' T.';
        return new Date(ts * 1000).toLocaleDateString('de-DE');
    };
    const favicon = s => s.icon ? '<span class="fd-fav e">' + esc(s.icon) + '</span>' : (s.host ? '<img class="fd-fav" src="https://icons.duckduckgo.com/ip3/' + esc(s.host) + '.ico" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.replaceWith(Object.assign(document.createElement(\'span\'),{className:\'fd-fav e\',textContent:\'📰\'}))">' : '<span class="fd-fav e">📰</span>');

    // ---------- Zustand ----------
    const st = Object.assign({ view: 'all', source: 0, category: '', q: '', sort: 'new', unreadOnly: false, mode: 'list', font: 1 }, store.get('state', {}), { q: '', source: 0, category: '' });
    let sources = F.sources, items = [], offset = 0, more = false, cur = -1, loading = false;
    const save = () => store.set('state', { view: st.view, sort: st.sort, unreadOnly: st.unreadOnly, mode: st.mode, font: st.font });

    // ---------- Seitenleiste ----------
    const collapsed = store.get('collapsed', {});
    function renderSources() {
        const cats = {};
        sources.forEach(s => { (cats[s.category] = cats[s.category] || []).push(s); });
        const total = sources.filter(s => s.enabled).reduce((a, s) => a + s.unread, 0);
        $$('[data-count]').forEach(b => { b.textContent = b.dataset.count === 'unread' && F.user ? (total || '') : ''; });
        $('#srcList').innerHTML = Object.keys(cats).sort((a, b) => a.localeCompare(b, 'de')).map(c => {
            const list = cats[c], un = list.filter(s => s.enabled).reduce((a, s) => a + s.unread, 0);
            return '<div class="fd-cat' + (collapsed[c] ? ' closed' : '') + '" data-cat="' + esc(c) + '"><div class="fd-cathead"><button type="button" class="fd-catcol" aria-label="Ein-/Ausklappen">▾</button><button type="button" class="fd-catname' + (st.category === c ? ' on' : '') + '" data-pickcat="' + esc(c) + '">' + esc(c) + '</button>' + (F.user && un ? '<b>' + un + '</b>' : '') + '</div>'
                + '<div class="fd-catitems">' + list.map(s => '<button type="button" class="fd-src' + (st.source === s.id ? ' on' : '') + (s.enabled ? '' : ' off') + (s.error ? ' err' : '') + '" data-src="' + s.id + '" title="' + esc(s.title + (s.error ? ' – Fehler: ' + s.error : '') + (s.last_ok ? ' · zuletzt ' + ago(s.last_ok) : '')) + '">'
                    + favicon(s) + '<span>' + esc(s.title) + '</span>' + (s.error ? '<i title="' + esc(s.error) + '">⚠</i>' : '') + (F.user && s.unread && s.enabled ? '<b>' + s.unread + '</b>' : '') + '</button>').join('') + '</div></div>';
        }).join('') || '<div class="fd-empty">Noch keine Quellen.' + (F.canEdit ? ' Mit „＋ Quelle“ hinzufügen.' : '') + '</div>';
    }
    function reloadSources() { return api('sources').then(r => { sources = r.sources; renderSources(); }); }
    $('#srcList').addEventListener('click', e => {
        const col = e.target.closest('.fd-catcol');
        if (col) { const c = col.closest('.fd-cat'); c.classList.toggle('closed'); collapsed[c.dataset.cat] = c.classList.contains('closed'); store.set('collapsed', collapsed); return; }
        const pc = e.target.closest('[data-pickcat]');
        if (pc) { select({ category: pc.dataset.pickcat, source: 0 }); return; }
        const s = e.target.closest('[data-src]');
        if (s) select({ source: +s.dataset.src, category: '' });
    });
    $('#views').addEventListener('click', e => { const b = e.target.closest('[data-view]'); if (b) select({ view: b.dataset.view, source: 0, category: '' }); });
    function select(o) {
        Object.assign(st, o);
        save();
        $$('#views [data-view]').forEach(b => b.classList.toggle('on', !st.source && !st.category && b.dataset.view === st.view));
        renderSources();
        const s = sources.find(x => x.id === st.source);
        $('#listTitle').textContent = s ? s.title : st.category || { all: 'Alle Artikel', unread: 'Ungelesen', today: 'Heute', starred: 'Gemerkt' }[st.view];
        document.body.classList.remove('side-open');
        load(false);
    }

    // ---------- Liste ----------
    function filter() { return { view: st.source || st.category ? (st.view === 'starred' ? 'starred' : 'all') : st.view, source: st.source || '', category: st.category, q: st.q, sort: st.sort, unread_only: st.unreadOnly ? 1 : '' }; }
    function load(append) {
        if (loading) return;
        loading = true;
        if (!append) { offset = 0; $('#list').innerHTML = '<div class="fd-empty">Lade …</div>'; }
        api('items', Object.assign(filter(), { offset })).then(r => {
            items = append ? items.concat(r.items) : r.items;
            offset = items.length; more = r.more;
            renderList();
        }).catch(x => { $('#list').innerHTML = '<div class="fd-error">⚠ ' + esc(x.message) + '</div>'; }).finally(() => { loading = false; });
    }
    function renderList() {
        const L = $('#list');
        L.dataset.mode = st.mode;
        $('#moreBtn').hidden = !more;
        if (!items.length) { L.innerHTML = '<div class="fd-empty">' + (st.q ? 'Keine Treffer für „' + esc(st.q) + '“.' : st.unreadOnly || st.view === 'unread' ? '🎉 Alles gelesen!' : st.view === 'starred' ? 'Noch nichts gemerkt – im Artikel ⭐ drücken.' : 'Keine Artikel. ⟳ aktualisieren?') + '</div>'; return; }
        let lastDay = '';
        L.innerHTML = items.map((it, i) => {
            const day = new Date(it.ts * 1000).toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });
            const sep = st.mode !== 'cards' && day !== lastDay ? '<div class="fd-day">' + esc(day) + '</div>' : '';
            lastDay = day;
            const img = it.image && st.mode !== 'compact' ? '<img class="fd-thumb" src="' + esc(it.image) + '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">' : '';
            return sep + '<div class="fd-item' + (it.read ? ' read' : '') + (it.starred ? ' starred' : '') + (i === cur ? ' cur' : '') + '" data-i="' + i + '" tabindex="0">'
                + (st.mode === 'cards' ? img : '') + '<div class="fd-ibody"><div class="fd-imeta"><span class="fd-isrc">' + (it.icon ? esc(it.icon) + ' ' : '') + esc(it.source) + '</span><span>' + esc(ago(it.ts)) + '</span>' + (it.author ? '<span>' + esc(it.author) + '</span>' : '') + '</div>'
                + '<b class="fd-ititle">' + esc(it.title) + '</b>' + (st.mode !== 'compact' && it.summary ? '<p>' + esc(it.summary) + '</p>' : '') + '</div>'
                + (st.mode === 'list' ? img : '') + '<span class="fd-iacts">' + (F.user ? '<button type="button" data-star="' + i + '" title="Merken">' + (it.starred ? '★' : '☆') + '</button><button type="button" data-read="' + i + '" title="' + (it.read ? 'Als ungelesen' : 'Als gelesen') + '">' + (it.read ? '○' : '●') + '</button>' : '')
                + '<a href="' + esc(it.link) + '" target="_blank" rel="noopener noreferrer" title="Original" data-ext="' + i + '">↗</a></span></div>';
        }).join('');
    }
    $('#list').addEventListener('click', e => {
        const s = e.target.closest('[data-star]'), r = e.target.closest('[data-read]'), x = e.target.closest('[data-ext]');
        if (s) { e.stopPropagation(); toggleStar(+s.dataset.star); return; }
        if (r) { e.stopPropagation(); setRead(+r.dataset.read, !items[+r.dataset.read].read); return; }
        if (x) { setRead(+x.dataset.ext, true); return; }
        const row = e.target.closest('.fd-item');
        if (row) open(+row.dataset.i);
    });
    $('#moreBtn').addEventListener('click', () => load(true));
    if ('IntersectionObserver' in window) new IntersectionObserver(en => { if (en[0].isIntersecting && more && !loading) load(true); }, { rootMargin: '400px' }).observe($('#moreBtn'));

    function setRead(i, read) {
        const it = items[i]; if (!it || !F.user) return;
        if (it.read !== read) {
            it.read = read;
            const s = sources.find(x => x.id === it.sid); if (s) s.unread += read ? -1 : 1;
            renderSources();
        }
        api('state', { ids: it.id, read: read ? 1 : 0 }).catch(x => toast(x.message, true));
        const row = $('.fd-item[data-i="' + i + '"]'); if (row) { row.classList.toggle('read', read); const b = $('[data-read]', row); if (b) b.textContent = read ? '○' : '●'; }
    }
    function toggleStar(i) {
        const it = items[i]; if (!it) return;
        if (!F.user) return toast('Zum Merken bitte anmelden.', true);
        it.starred = !it.starred;
        api('state', { ids: it.id, starred: it.starred ? 1 : 0 }).then(() => toast(it.starred ? '⭐ Gemerkt' : 'Nicht mehr gemerkt')).catch(x => toast(x.message, true));
        const row = $('.fd-item[data-i="' + i + '"]'); if (row) { row.classList.toggle('starred', it.starred); const b = $('[data-star]', row); if (b) b.textContent = it.starred ? '★' : '☆'; }
        if (cur === i) $('[data-r=star]') && ($('[data-r=star]').textContent = it.starred ? '★' : '☆');
    }

    // ---------- Lesebereich ----------
    const reader = $('#reader');
    function open(i) {
        if (i < 0 || i >= items.length) return;
        cur = i;
        $$('.fd-item.cur').forEach(x => x.classList.remove('cur'));
        const row = $('.fd-item[data-i="' + i + '"]'); if (row) { row.classList.add('cur'); row.scrollIntoView({ block: 'nearest' }); }
        const it = items[i];
        reader.hidden = false;
        document.body.classList.add('reading');
        $('#rbody').innerHTML = '<div class="fd-empty">Lade …</div>';
        $('[data-r=open]').href = it.link;
        if ($('[data-r=star]')) $('[data-r=star]').textContent = it.starred ? '★' : '☆';
        api('item', { id: it.id }).then(r => {
            const a = r.item;
            if (!it.read && F.user) { it.read = true; const s = sources.find(x => x.id === it.sid); if (s) s.unread--; renderSources(); if (row) row.classList.add('read'); }
            const hasImg = a.image && !a.content.includes(a.image);
            $('#rbody').innerHTML = '<div class="fd-rmeta">' + (a.icon ? esc(a.icon) + ' ' : '') + '<b>' + esc(a.source) + '</b> · ' + esc(new Date(a.ts * 1000).toLocaleString('de-DE', { dateStyle: 'full', timeStyle: 'short' })) + (a.author ? ' · ' + esc(a.author) : '') + ' · ⏱ ' + a.minutes + ' Min. Lesezeit</div>'
                + '<h2><a href="' + esc(a.link) + '" target="_blank" rel="noopener noreferrer">' + esc(a.title) + '</a></h2>'
                + (hasImg ? '<img class="fd-hero" src="' + esc(a.image) + '" alt="" referrerpolicy="no-referrer" onerror="this.remove()">' : '')
                + '<div class="fd-content">' + a.content + '</div>'
                + '<div class="fd-rfoot"><a class="btn btn-gold" href="' + esc(a.link) + '" target="_blank" rel="noopener noreferrer">Ganzen Artikel öffnen ↗</a></div>';
            $('#rbody').scrollTop = 0;
        }).catch(x => { $('#rbody').innerHTML = '<div class="fd-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    function closeReader() { reader.hidden = true; document.body.classList.remove('reading'); }
    const applyFont = () => { reader.style.setProperty('--fs', [0.9, 1, 1.12, 1.25][st.font] + 'rem'); };
    applyFont();
    $('.fd-rbar').addEventListener('click', e => {
        const b = e.target.closest('[data-r]'); if (!b) return;
        const a = b.dataset.r;
        if (a === 'close') closeReader();
        if (a === 'prev') open(cur - 1);
        if (a === 'next') { if (cur + 1 >= items.length && more) load(true); else open(cur + 1); }
        if (a === 'star') toggleStar(cur);
        if (a === 'unread') { setRead(cur, false); toast('Als ungelesen markiert.'); }
        if (a === 'font') { st.font = (st.font + 1) % 4; save(); applyFont(); }
        if (a === 'copy') { const l = items[cur] && items[cur].link; (navigator.clipboard ? navigator.clipboard.writeText(l) : Promise.reject()).then(() => toast('Link kopiert.')).catch(() => prompt('Link:', l)); }
        if (a === 'open') setRead(cur, true);
    });

    // ---------- Werkzeugleiste ----------
    const MODES = ['list', 'cards', 'magazine', 'compact'], MODE_NAMES = { list: 'Liste', cards: 'Kacheln', magazine: 'Magazin', compact: 'Kompakt' };
    const syncBtns = () => {
        $('#viewBtn').textContent = { list: '▤', cards: '▦', magazine: '▥', compact: '≡' }[st.mode];
        $('#sortBtn').textContent = st.sort === 'new' ? '⇣' : '⇡';
        if ($('#unreadBtn')) $('#unreadBtn').classList.toggle('on', st.unreadOnly);
    };
    $('#viewBtn').addEventListener('click', () => { st.mode = MODES[(MODES.indexOf(st.mode) + 1) % MODES.length]; save(); syncBtns(); renderList(); toast('Ansicht: ' + MODE_NAMES[st.mode]); });
    $('#sortBtn').addEventListener('click', () => { st.sort = st.sort === 'new' ? 'old' : 'new'; save(); syncBtns(); load(false); toast(st.sort === 'new' ? 'Neueste zuerst' : 'Älteste zuerst'); });
    if ($('#unreadBtn')) $('#unreadBtn').addEventListener('click', () => { st.unreadOnly = !st.unreadOnly; save(); syncBtns(); load(false); });
    if ($('#readAllBtn')) $('#readAllBtn').addEventListener('click', markAll);
    function markAll() {
        const what = st.source ? '„' + $('#listTitle').textContent + '“' : st.category ? 'die Kategorie „' + st.category + '“' : 'alle Quellen';
        if (!confirm('Alle Artikel in ' + what + ' als gelesen markieren?')) return;
        api('mark_all', filter()).then(r => { toast(r.msg); reloadSources(); load(false); }).catch(x => toast(x.message, true));
    }
    let qt = null;
    $('#q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { st.q = e.target.value.trim(); load(false); }, 300); });
    $('#refreshBtn').addEventListener('click', () => refresh(true));
    $('#menuBtn').addEventListener('click', () => document.body.classList.toggle('side-open'));
    function refresh(force, quiet) {
        const b = $('#refreshBtn'); b.classList.add('spin');
        const s = $('#status'); if (!quiet) { s.hidden = false; s.textContent = '⟳ Lade Quellen …'; }
        return api('refresh', { force: force ? 1 : '' }).then(r => {
            s.hidden = true;
            if (!quiet || r.new) toast(r.msg);
            return reloadSources().then(() => { if (r.new || !quiet) load(false); });
        }).catch(x => { s.hidden = false; s.textContent = '⚠ ' + x.message; }).finally(() => b.classList.remove('spin'));
    }

    // ---------- Tastatur ----------
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { if ($('#fdModal').classList.contains('open')) return closeModal(); if (!reader.hidden) return closeReader(); if (typing()) { document.activeElement.blur(); } return; }
        if (typing() || e.ctrlKey || e.metaKey || e.altKey) return;
        const k = e.key;
        if (k === 'j' || k === 'ArrowDown' && !reader.hidden) { e.preventDefault(); if (cur + 1 >= items.length && more) load(true); else open(cur + 1); }
        else if (k === 'k' || k === 'ArrowUp' && !reader.hidden) { e.preventDefault(); open(Math.max(0, cur - 1)); }
        else if (k === 'o' || k === 'Enter') { if (items[cur]) { setRead(cur, true); window.open(items[cur].link, '_blank', 'noopener'); } }
        else if (k === 's') toggleStar(cur);
        else if (k === 'm') { if (items[cur]) setRead(cur, !items[cur].read); }
        else if (k === 'r') refresh(true);
        else if (k === 'v') $('#viewBtn').click();
        else if (k === 'u' && $('#unreadBtn')) $('#unreadBtn').click();
        else if (k === 'A' && F.user) markAll();
        else if (k === '/') { e.preventDefault(); $('#q').focus(); }
        else if (k === '?') help();
        else if (k === 'g') select({ view: 'all', source: 0, category: '' });
    });

    // ---------- Dialoge ----------
    const modal = $('#fdModal');
    const openModal = html => { $('#fdModalBody').onclick = null; $('#fdModalBody').innerHTML = html; modal.classList.add('open'); };
    function closeModal() { modal.classList.remove('open'); }
    modal.addEventListener('click', e => { if (e.target === modal || e.target.closest('[data-close]')) closeModal(); });
    function help() {
        openModal('<div class="panel-title">⌨ Tastenkürzel</div><table class="fd-keys">' + [['J / K', 'nächster / vorheriger Artikel'], ['O / Enter', 'Original öffnen'], ['S', 'merken'], ['M', 'gelesen / ungelesen'], ['Shift + A', 'alle als gelesen'],
            ['R', 'aktualisieren'], ['V', 'Ansicht wechseln (Liste, Kacheln, Magazin, Kompakt)'], ['U', 'nur ungelesene'], ['/', 'suchen'], ['G', 'alle Artikel'], ['Esc', 'schließen']].map(([a, b]) => '<tr><td><kbd>' + a + '</kbd></td><td>' + b + '</td></tr>').join('') + '</table>');
    }
    $('#helpBtn').addEventListener('click', help);

    const cats = () => [...new Set(sources.map(s => s.category))].sort((a, b) => a.localeCompare(b, 'de'));
    if (F.canEdit) {
        $('#addBtn').addEventListener('click', () => addDialog());
        $('#manageBtn').addEventListener('click', manage);
    }
    function addDialog(prefill) {
        openModal('<div class="panel-title">＋ Quelle hinzufügen</div><form id="discForm" class="fd-form"><label class="field">Adresse der Webseite oder des Feeds</label><div class="fd-row"><input type="text" name="url" required placeholder="z. B. heise.de, youtube.com/channel/…, reddit.com/r/furry, bsky.app/profile/…" value="' + esc(prefill || '') + '"><button class="btn btn-gold">Suchen</button></div>'
            + '<p class="fd-small">Funktioniert mit normalen Webseiten (der Feed wird automatisch gefunden), RSS/Atom/JSON-Feeds, YouTube-Kanälen und -Playlists, Subreddits, Bluesky- und Mastodon-Profilen sowie GitHub-Projekten (Releases).</p></form><div id="discOut"></div>');
        $('#discForm').addEventListener('submit', e => {
            e.preventDefault();
            const out = $('#discOut'); out.innerHTML = '<div class="fd-empty">Suche Feed …</div>';
            api('discover', { url: e.target.url.value }).then(r => {
                const f = r.feed;
                out.innerHTML = '<form id="addForm" class="fd-form fd-found"><div class="fd-ok">✔ Feed gefunden: ' + f.count + ' Artikel' + (f.sample.length ? '<small>' + f.sample.map(esc).join(' · ') + '</small>' : '') + '</div>'
                    + (f.alternatives.length ? '<label class="field">Weitere Feeds dieser Seite</label><select name="alt"><option value="">' + esc(f.url) + '</option>' + f.alternatives.map(a => '<option value="' + esc(a.url) + '">' + esc(a.title || a.url) + '</option>').join('') + '</select>' : '')
                    + '<input type="hidden" name="url" value="' + esc(f.url) + '"><input type="hidden" name="site" value="' + esc(f.site) + '">'
                    + '<div class="fd-row"><div style="max-width:80px"><label class="field">Icon</label><input type="text" name="icon" maxlength="4" placeholder="📰"></div><div><label class="field">Name</label><input type="text" name="title" value="' + esc(f.title) + '" required></div></div>'
                    + '<label class="field">Kategorie</label><input type="text" name="category" list="catList" value="' + esc(st.category || 'Allgemein') + '" required><datalist id="catList">' + cats().map(c => '<option value="' + esc(c) + '">').join('') + '</datalist>'
                    + '<button class="btn btn-gold" style="margin-top:12px;width:100%">Hinzufügen</button></form>';
                const af = $('#addForm');
                if (af.alt) af.alt.addEventListener('change', () => { af.url.value = af.alt.value || f.url; });
                af.addEventListener('submit', ev => {
                    ev.preventDefault(); const b = af.querySelector('button.btn-gold'); b.disabled = true; b.textContent = 'Lade Artikel …';
                    const d = {}; new FormData(af).forEach((v, k) => { d[k] = v; });
                    api('source_add', d).then(x => { toast(x.msg); closeModal(); reloadSources().then(() => select({ source: x.id, category: '' })); }).catch(x => { toast(x.message, true); b.disabled = false; b.textContent = 'Hinzufügen'; });
                });
            }).catch(x => { out.innerHTML = '<div class="fd-error">⚠ ' + esc(x.message) + '</div>'; });
        });
        setTimeout(() => $('#discForm input').focus(), 30);
    }
    function manage() {
        const row = s => '<tr class="' + (s.enabled ? '' : 'off') + '"><td>' + favicon(s) + '</td><td><b>' + esc(s.title) + '</b><small>' + esc(s.url) + '</small></td><td>' + esc(s.category) + '</td><td>' + s.total + '</td>'
            + '<td class="' + (s.error ? 'bad' : 'good') + '">' + (s.error ? '⚠ ' + esc(s.error) : s.last_ok ? '✓ ' + esc(ago(s.last_ok)) : '–') + '</td>'
            + '<td class="fd-macts"><button type="button" data-m="refresh" data-id="' + s.id + '" title="Jetzt laden">⟳</button><button type="button" data-m="edit" data-id="' + s.id + '" title="Bearbeiten">✎</button>'
            + '<button type="button" data-m="toggle" data-id="' + s.id + '" title="' + (s.enabled ? 'Pausieren' : 'Aktivieren') + '">' + (s.enabled ? '⏸' : '▶') + '</button><button type="button" class="bad" data-m="delete" data-id="' + s.id + '" title="Entfernen">🗑</button></td></tr>';
        openModal('<div class="panel-title">⚙ Quellen verwalten <span>' + sources.length + ' Quellen</span></div>'
            + '<div class="fd-mtools"><button type="button" class="btn btn-sm btn-gold" id="mAdd">＋ Quelle</button><a class="btn btn-sm" href="feed.php?opml=1">⬇ OPML-Export</a>'
            + '<label class="btn btn-sm">⬆ OPML-Import<input type="file" id="opmlFile" accept=".opml,.xml,text/xml" hidden></label><input type="search" id="mFilter" placeholder="Filtern …"></div>'
            + '<div class="fd-mtable"><table><thead><tr><th></th><th>Quelle</th><th>Kategorie</th><th>Artikel</th><th>Status</th><th></th></tr></thead><tbody>' + sources.map(row).join('') + '</tbody></table></div>'
            + '<div class="fd-small"><b>Kategorien umbenennen:</b> ' + cats().map(c => '<button type="button" class="fd-chip" data-rencat="' + esc(c) + '">' + esc(c) + ' ✎</button>').join(' ') + '</div>'
            + (F.cron ? '<p class="fd-small">Automatisch aktualisieren (z. B. alle 15 Min. per Cronjob beim Hoster oder cron-job.org): <code>' + esc(F.cron) + '</code></p>' : ''));
        $('#mAdd').addEventListener('click', () => addDialog());
        $('#mFilter').addEventListener('input', e => $$('.fd-mtable tbody tr').forEach(r => { r.hidden = !r.textContent.toLowerCase().includes(e.target.value.toLowerCase()); }));
        $('#opmlFile').addEventListener('change', e => {
            const fd = new FormData(); fd.append('file', e.target.files[0]);
            toast('Importiere …');
            api('opml_import', {}, fd).then(r => { toast(r.msg); reloadSources().then(manage); load(false); }).catch(x => toast(x.message, true));
        });
        $('#fdModalBody').onclick = e => {
            const rc = e.target.closest('[data-rencat]');
            if (rc) { const n = prompt('Neuer Name für „' + rc.dataset.rencat + '“:', rc.dataset.rencat); if (n && n !== rc.dataset.rencat) api('category_rename', { old: rc.dataset.rencat, name: n }).then(r => { toast(r.msg); reloadSources().then(manage); }).catch(x => toast(x.message, true)); return; }
            const b = e.target.closest('[data-m]'); if (!b) return;
            const s = sources.find(x => x.id === +b.dataset.id), m = b.dataset.m;
            if (m === 'refresh') { b.textContent = '…'; api('refresh', { id: s.id }).then(r => { toast(r.msg); reloadSources().then(manage); }).catch(x => toast(x.message, true)); }
            if (m === 'toggle') api('source_toggle', { id: s.id }).then(r => { toast(r.msg); reloadSources().then(manage); load(false); }).catch(x => toast(x.message, true));
            if (m === 'delete' && confirm('Quelle „' + s.title + '“ mit allen Artikeln entfernen?')) api('source_delete', { id: s.id }).then(r => { toast(r.msg); reloadSources().then(manage); if (st.source === s.id) select({ source: 0 }); else load(false); }).catch(x => toast(x.message, true));
            if (m === 'edit') {
                openModal('<div class="panel-title">✎ ' + esc(s.title) + '</div><form id="editForm" class="fd-form"><div class="fd-row"><div style="max-width:80px"><label class="field">Icon</label><input type="text" name="icon" maxlength="4" value="' + esc(s.icon) + '"></div><div><label class="field">Name</label><input type="text" name="title" value="' + esc(s.title) + '" required></div></div>'
                    + '<label class="field">Feed-Adresse</label><input type="url" name="url" value="' + esc(s.url) + '" required><label class="field">Kategorie</label><input type="text" name="category" list="catList2" value="' + esc(s.category) + '" required><datalist id="catList2">' + cats().map(c => '<option value="' + esc(c) + '">').join('') + '</datalist>'
                    + (s.error ? '<div class="fd-error">Letzter Fehler: ' + esc(s.error) + '</div>' : '') + '<div class="fd-row" style="margin-top:12px"><button class="btn btn-gold">Speichern</button><button type="button" class="btn" id="backBtn">Zurück</button></div></form>');
                $('#backBtn').addEventListener('click', manage);
                $('#editForm').addEventListener('submit', ev => { ev.preventDefault(); const d = { id: s.id }; new FormData(ev.target).forEach((v, k) => { d[k] = v; }); api('source_save', d).then(r => { toast(r.msg); reloadSources().then(manage); }).catch(x => toast(x.message, true)); });
            }
        };
    }

    // ---------- Start ----------
    syncBtns();
    renderSources();
    select({ view: st.view });
    if (F.stale) refresh(false, true);
    setInterval(() => { if (!document.hidden) refresh(false, true); }, 10 * 60 * 1000);
})();
