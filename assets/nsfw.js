/* NSFW-Galerie – Suche, Lightbox, Favoriten, gespeicherte Suchen, Posten nach Discord. Texte werden immer maskiert. */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const store = { get(k, d) { try { const v = localStorage.getItem('ns-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('ns-' + k, JSON.stringify(v)); } catch (e) {} } };
    const NS = window.NS;

    // Panik-Taste: Shift + P → sofort zur Startseite (und Verlauf ersetzen)
    document.addEventListener('keydown', e => {
        if (e.shiftKey && (e.key === 'P' || e.key === 'p') && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { document.body.style.display = 'none'; location.replace('index.php'); }
    });

    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => body.append(k, v ?? ''));
        return fetch('nsfw.php', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body }).then(async r => {
            let j; try { j = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort (HTTP ' + r.status + ').'); }
            if (!r.ok || j.error) throw new Error(j.error || 'Fehler ' + r.status);
            return j;
        });
    }
    function toast(msg, bad) {
        $$('.ns-toast').forEach(t => t.parentNode.removeChild(t));
        const t = document.createElement('div');
        t.className = 'ns-toast' + (bad ? ' bad' : '');
        t.textContent = msg;
        document.body.append(t);
        setTimeout(() => t.classList.add('out'), 2600);
        setTimeout(() => t.remove(), 3200);
    }

    if (!NS.age) {
        $('#ageForm').addEventListener('submit', e => { e.preventDefault(); api('age_ok', { confirm: e.target.confirm.checked ? 1 : '' }).then(() => location.reload()).catch(x => toast(x.message, true)); });
        return;
    }

    // =============== Reiter ===============
    const loaded = {};
    function tab(n) {
        $$('#tabs [data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === n));
        $$('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== n; });
        if (n === 'favs') loadFavs();
        if (n === 'saved' && !loaded.saved) { loaded.saved = true; loadSaved(); }
    }
    $('#tabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) tab(b.dataset.tab); });

    // Unschärfe (pro Browser)
    const blur = $('#blurToggle');
    blur.checked = store.get('blur', NS.blur);
    const applyBlur = () => document.body.classList.toggle('ns-blur', blur.checked);
    blur.addEventListener('change', () => { store.set('blur', blur.checked); applyBlur(); });
    applyBlur();

    // =============== Suche ===============
    const form = $('#searchForm'), prov = $('#provider');
    prov.innerHTML = Object.entries(NS.providers).map(([id, p]) => '<option value="' + esc(id) + '">' + esc(p.icon + ' ' + p.name) + (p.needs_key ? ' 🔑' : '') + '</option>').join('');
    $('#favProv').innerHTML += Object.entries(NS.providers).map(([id, p]) => '<option value="' + esc(id) + '">' + esc(p.icon + ' ' + p.name) + '</option>').join('');
    const last = store.get('last', null);
    prov.value = last && NS.providers[last.provider] ? last.provider : NS.default;
    if (last) { form.q.value = last.q || ''; form.rating.value = last.rating || 'nsfw'; form.order.value = last.order || 'new'; }
    function provChanged() {
        const p = NS.providers[prov.value];
        $('#provHint').innerHTML = esc(p.icon + ' ' + p.hint) + (p.needs_key ? ' – <a href="#" data-gosettings>🔑 API-Schlüssel eintragen</a>' : '');
        $$('#rating option').forEach(o => { o.disabled = (!p.nsfw && o.value !== 'safe') || (!p.sfw && o.value === 'safe'); });
        if (form.rating.selectedOptions[0].disabled) form.rating.value = p.nsfw ? 'nsfw' : 'safe';
        const cats = $('#cats');
        if (p.cats) {
            const nsfw = form.rating.value !== 'safe';
            cats.hidden = false;
            cats.innerHTML = '<span class="ns-small">Kategorie:</span>' + (nsfw ? p.cats.nsfw : p.cats.sfw).map(c => '<button type="button" class="ns-chip' + (form.q.value === c ? ' on' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>').join('');
            form.q.placeholder = 'Kategorie, z. B. ' + (nsfw ? p.cats.nsfw : p.cats.sfw)[0];
        } else { cats.hidden = true; form.q.placeholder = 'Tags, z. B. fox solo -feral'; }
    }
    prov.addEventListener('change', () => { if (NS.providers[prov.value].cats) form.q.value = ''; provChanged(); });
    form.rating.addEventListener('change', provChanged);
    $('#cats').addEventListener('click', e => { const b = e.target.closest('[data-cat]'); if (b) { form.q.value = b.dataset.cat; provChanged(); search(false); } });
    document.addEventListener('click', e => { if (e.target.closest('[data-gosettings]')) { e.preventDefault(); $('#lb').hidden = true; document.body.style.overflow = ''; tab('settings'); } });
    provChanged();

    let posts = [], page = 1, busy = false, current = null;
    function search(more) {
        if (busy) return;
        busy = true;
        const box = $('#results');
        if (!more) { page = 1; posts = []; box.innerHTML = '<div class="ns-empty">Suche …</div>'; } else page++;
        const q = { provider: prov.value, q: form.q.value.trim(), rating: form.rating.value, order: form.order.value, page };
        store.set('last', q);
        current = q;
        api('search', q).then(r => {
            posts = posts.concat(r.posts);
            renderGrid(box, posts);
            if (!posts.length) box.innerHTML = '<div class="ns-empty">Nichts gefunden.' + (r.hidden ? ' (' + r.hidden + ' Treffer durch die Blacklist ausgeblendet)' : '') + '</div>';
            else if (r.hidden) toast(r.hidden + ' Treffer durch die Blacklist ausgeblendet.');
            $('#moreBtn').hidden = !r.more;
        }).catch(x => { if (!more) box.innerHTML = '<div class="ns-error">⚠ ' + esc(x.message) + '</div>'; else toast(x.message, true); page = Math.max(1, page - 1); })
            .finally(() => { busy = false; });
    }
    form.addEventListener('submit', e => { e.preventDefault(); $('#ac').hidden = true; search(false); });
    $('#moreBtn').addEventListener('click', () => search(true));
    // Endlos-Scrollen
    if ('IntersectionObserver' in window) new IntersectionObserver(en => { if (en[0].isIntersecting && !$('#moreBtn').hidden && !busy && !$('[data-pane=search]').hidden) search(true); }, { rootMargin: '600px' }).observe($('#moreBtn'));

    const RT = { s: ['S', 'Sicher'], q: ['Q', 'Fragwürdig'], e: ['E', 'Explizit'] };

    // ---- Bilder laden mit Ausweichweg: direkt → über den eigenen Server → größere Variante über den Server ----
    // Blockiert der Browser die Bilder-Seite (Werbeblocker, DNS-/Jugendschutzfilter, Hotlink-Schutz), schaltet die Galerie
    // nach einigen Fehlschlägen automatisch auf „über den Server“ um.
    const auto = { direct: 0, on: store.get('autoproxy', false) };
    const isProxy = u => /nsfw\.php\?img=/.test(u || '');
    function sources(p, kind) {
        const direct = { preview: p.v_preview, sample: p.v_sample, url: p.v_url }[kind], prox = { preview: p.x_preview, sample: p.x_sample, url: p.x_url }[kind];
        const extra = kind === 'preview' ? [p.x_sample] : kind === 'sample' ? [p.x_url] : [];
        const list = (auto.on ? [prox, direct] : [direct, prox]).concat(extra).filter(Boolean);
        return list.filter((u, i) => list.indexOf(u) === i);
    }
    const media = (tag, list, attrs) => '<' + tag + ' src="' + esc(list[0]) + '" data-fb="' + esc(JSON.stringify(list.slice(1))) + '" ' + attrs + (tag === 'img' ? '>' : '></' + tag + '>');
    document.addEventListener('error', e => {
        const el = e.target;
        if (!el || !el.dataset || el.dataset.fb === undefined) return;
        let fb = [];
        try { fb = JSON.parse(el.dataset.fb); } catch (x) {}
        if (!isProxy(el.getAttribute('src'))) auto.direct++;
        if (fb.length) { el.dataset.fb = JSON.stringify(fb.slice(1)); el.dataset.fell = '1'; el.src = fb[0]; return; }
        const item = el.closest('.ns-item');
        if (item) item.classList.add('broken');
        else if (el.closest('#lbMedia')) $('#lbMedia').innerHTML = '<div class="ns-error">Das Bild ließ sich weder direkt noch über deinen Server laden. <a href="#" data-gosettings>🩺 Bilder-Test</a> zeigt den Grund.</div>';
    }, true);
    document.addEventListener('load', e => {
        const el = e.target;
        if (!el || !el.dataset || el.dataset.fell !== '1' || auto.on || !isProxy(el.getAttribute('src')) || auto.direct < 3) return;
        auto.on = true;
        store.set('autoproxy', true);
        toast('Dein Browser konnte die Bilder nicht direkt laden – sie kommen ab jetzt über deinen Server.');
    }, true);
    function renderGrid(box, list) {
        box.innerHTML = list.map((p, i) => '<button type="button" class="ns-item r-' + esc(p.rating) + '" data-i="' + i + '" title="' + esc(p.tags.slice(0, 12).join(' ')) + '">'
            + media('img', sources(p, 'preview'), 'alt="" loading="lazy" referrerpolicy="no-referrer"')
            + '<span class="ns-badges"><b class="ns-rt">' + RT[p.rating][0] + '</b>' + (p.video ? '<b>▶ ' + esc(p.ext) + '</b>' : p.ext === 'gif' ? '<b>GIF</b>' : '') + (p.fav ? '<b class="fav">⭐</b>' : '') + '</span>'
            + (p.score ? '<span class="ns-score">⬆ ' + p.score + '</span>' : '') + '</button>').join('');
        box._list = list;
    }
    ['#results', '#favBox'].forEach(s => $(s).addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (b) openLb(e.currentTarget._list, +b.dataset.i); }));

    // Tag-Vorschläge
    let acTimer = null, acSel = -1;
    const ac = $('#ac');
    form.q.addEventListener('input', () => {
        clearTimeout(acTimer);
        const p = NS.providers[prov.value];
        const term = form.q.value.split(/\s+/).pop();
        if (!p.auto || term.replace(/^[-~]/, '').length < 2) { ac.hidden = true; return; }
        acTimer = setTimeout(() => api('autocomplete', { provider: prov.value, term }).then(r => {
            acSel = -1;
            ac.hidden = !r.tags.length;
            ac.innerHTML = r.tags.map(t => '<button type="button" data-tag="' + esc(t.tag) + '"><span>' + esc(t.tag) + '</span>' + (t.count ? '<small>' + Number(t.count).toLocaleString('de-DE') + '</small>' : '') + '</button>').join('');
        }).catch(() => { ac.hidden = true; }), 250);
    });
    const pickTag = t => { const parts = form.q.value.split(/\s+/); parts[parts.length - 1] = t; form.q.value = parts.join(' ') + ' '; ac.hidden = true; form.q.focus(); };
    ac.addEventListener('click', e => { const b = e.target.closest('[data-tag]'); if (b) pickTag(b.dataset.tag); });
    form.q.addEventListener('keydown', e => {
        if (ac.hidden) return;
        const items = $$('button', ac);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); acSel = (acSel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; items.forEach((b, i) => b.classList.toggle('on', i === acSel)); }
        if ((e.key === 'Enter' || e.key === 'Tab') && acSel >= 0) { e.preventDefault(); pickTag(items[acSel].dataset.tag); }
        if (e.key === 'Escape') ac.hidden = true;
    });
    document.addEventListener('click', e => { if (!e.target.closest('.ns-tagwrap')) ac.hidden = true; });

    // =============== Lightbox ===============
    const lb = $('#lb');
    let lbList = [], lbIdx = 0;
    function openLb(list, i) { lbList = list; lbIdx = i; renderLb(); lb.hidden = false; document.body.style.overflow = 'hidden'; }
    function closeLb() { lb.hidden = true; document.body.style.overflow = ''; $('#lbMedia').innerHTML = ''; }
    function renderLb() {
        const p = lbList[lbIdx];
        if (!p) return;
        const P = NS.providers[p.provider] || { icon: '', name: p.provider };
        $('#lbMedia').innerHTML = p.video ? media('video', sources(p, 'url'), 'controls autoplay loop playsinline referrerpolicy="no-referrer"')
            : media('img', sources(p, 'sample'), 'alt="" referrerpolicy="no-referrer" data-full="1" title="Klicken für volle Auflösung"');
        const artist = new Set(p.artists);
        $('#lbSide').innerHTML = '<div class="ns-lbtitle">' + esc(P.icon + ' ' + P.name) + ' #' + esc(p.id) + '</div>'
            + '<div class="ns-meta"><span class="ns-rt r-' + esc(p.rating) + '">' + RT[p.rating][1] + '</span>' + (p.w ? '<span>' + p.w + '×' + p.h + '</span>' : '') + (p.ext ? '<span>' + esc(p.ext.toUpperCase()) + '</span>' : '') + (p.score ? '<span>⬆ ' + p.score + '</span>' : '') + (p.favs ? '<span>♥ ' + p.favs + '</span>' : '') + '</div>'
            + '<div class="ns-acts"><button type="button" class="btn btn-sm' + (p.fav ? ' btn-gold' : '') + '" data-a="fav">' + (p.fav ? '⭐ Favorit' : '☆ Favorit') + '</button>'
            + '<a class="btn btn-sm" href="' + esc(p.page) + '" target="_blank" rel="noopener noreferrer">🔗 Seite</a><a class="btn btn-sm" href="' + esc(p.url) + '" target="_blank" rel="noopener noreferrer">⬇ Original</a>'
            + '<button type="button" class="btn btn-sm" data-a="copy">⧉ Link</button>' + (NS.discord ? '<button type="button" class="btn btn-sm" data-a="discord">📨 Discord</button>' : '') + '</div>'
            + '<div id="dcBox"></div>'
            + (p.artists.length ? '<h4>🎨 Künstler</h4><div class="ns-tags">' + p.artists.map(a => '<button type="button" class="art" data-t="' + esc(a) + '">' + esc(a) + '</button>').join('') + '</div>' : '')
            + (p.sources.length ? '<h4>Quellen</h4><div class="ns-srcs">' + p.sources.slice(0, 5).map(s => '<a href="' + esc(s) + '" target="_blank" rel="noopener noreferrer">' + esc(s.replace(/^https?:\/\/(www\.)?/, '').slice(0, 50)) + '</a>').join('') + '</div>' : '')
            + '<h4>Tags <small>(klicken = hinzufügen, ⇧-Klick = ausschließen)</small></h4><div class="ns-tags">' + p.tags.filter(t => !artist.has(t)).map(t => '<button type="button" data-t="' + esc(t) + '">' + esc(t) + '</button>').join('') + '</div>'
            + '<p class="ns-small">← → blättern · F = Favorit · Esc = schließen</p>';
        $$('.ns-nav').forEach(b => { b.hidden = lbList.length < 2; });
    }
    lb.addEventListener('click', e => {
        if (e.target === lb || e.target.closest('[data-close]')) return closeLb();
        const nav = e.target.closest('[data-nav]');
        if (nav) { lbIdx = (lbIdx + +nav.dataset.nav + lbList.length) % lbList.length; renderLb(); return; }
        const im = e.target.closest('img[data-full]');
        if (im && !im.classList.contains('full')) { const l = sources(lbList[lbIdx], 'url'); im.dataset.fb = JSON.stringify(l.slice(1)); im.src = l[0]; im.classList.add('full'); return; }
        const t = e.target.closest('[data-t]');
        if (t) {
            const tag = (e.shiftKey ? '-' : '') + t.dataset.t;
            if (!current || current.provider !== lbList[lbIdx].provider) prov.value = lbList[lbIdx].provider;
            const parts = form.q.value.trim().split(/\s+/).filter(Boolean);
            if (!parts.includes(tag)) parts.push(tag);
            form.q.value = parts.join(' ');
            closeLb(); tab('search'); provChanged(); search(false);
            return;
        }
        const a = e.target.closest('[data-a]');
        if (!a) return;
        const p = lbList[lbIdx];
        if (a.dataset.a === 'fav') toggleFav(p).then(renderLb);
        if (a.dataset.a === 'copy') (navigator.clipboard ? navigator.clipboard.writeText(p.page) : Promise.reject()).then(() => toast('Link kopiert.')).catch(() => prompt('Link:', p.page));
        if (a.dataset.a === 'discord') discordBox(p);
    });
    document.addEventListener('keydown', e => {
        if (lb.hidden) return;
        if (e.key === 'Escape') closeLb();
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { lbIdx = (lbIdx + (e.key === 'ArrowRight' ? 1 : -1) + lbList.length) % lbList.length; renderLb(); }
        if ((e.key === 'f' || e.key === 'F') && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) toggleFav(lbList[lbIdx]).then(renderLb);
    });
    const slim = p => JSON.stringify({ provider: p.provider, id: p.id, url: p.url, sample: p.sample, preview: p.preview, ext: p.ext, video: p.video, w: p.w, h: p.h, rating: p.rating, score: p.score, tags: p.tags, artists: p.artists, sources: p.sources, page: p.page });
    function toggleFav(p) {
        return api('fav_toggle', { post: slim(p) }).then(r => {
            p.fav = r.fav;
            toast(r.msg);
            posts.filter(x => x.key === p.key).forEach(x => { x.fav = r.fav; });
            if (!$('[data-pane=search]').hidden) renderGrid($('#results'), posts);
        }).catch(x => toast(x.message, true));
    }

    // Discord
    let dcChannels = null;
    function discordBox(p) {
        const box = $('#dcBox');
        box.innerHTML = '<div class="ns-small">Lade Kanäle …</div>';
        (dcChannels ? Promise.resolve({ channels: dcChannels }) : api('discord_channels')).then(r => {
            dcChannels = r.channels;
            const ok = p.rating === 's' ? r.channels : r.channels.filter(c => c.nsfw);
            if (!ok.length) { box.innerHTML = '<div class="ns-error">Kein altersbeschränkter (NSFW-)Kanal auf dem Server. In Discord: Kanal bearbeiten → „Altersbeschränkter Kanal“ einschalten.</div>'; return; }
            const lastCh = store.get('dc-ch', '');
            box.innerHTML = '<form class="ns-dcform"><select name="channel">' + ok.map(c => '<option value="' + esc(c.id) + '"' + (c.id === lastCh ? ' selected' : '') + '>#' + esc(c.name) + (c.nsfw ? ' 🔞' : '') + '</option>').join('') + '</select>'
                + '<input type="text" name="note" maxlength="500" placeholder="Kommentar (optional)"><button class="btn btn-sm btn-gold">Posten</button></form>';
            $('form', box).addEventListener('submit', e => {
                e.preventDefault();
                store.set('dc-ch', e.target.channel.value);
                api('discord_post', { post: slim(p), channel: e.target.channel.value, note: e.target.note.value }).then(x => { toast(x.msg); box.innerHTML = ''; }).catch(x => toast(x.message, true));
            });
        }).catch(x => { box.innerHTML = '<div class="ns-error">⚠ ' + esc(x.message) + '</div>'; });
    }

    // =============== Favoriten ===============
    let favs = [];
    function loadFavs() {
        const box = $('#favBox');
        box.innerHTML = '<div class="ns-empty">Lade …</div>';
        api('favs').then(r => { favs = r.posts; renderFavs(); }).catch(x => { box.innerHTML = '<div class="ns-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    function renderFavs() {
        const f = $('#favFilter').value.trim().toLowerCase().split(/\s+/).filter(Boolean), pv = $('#favProv').value;
        const list = favs.filter(p => (!pv || p.provider === pv) && f.every(t => t[0] === '-' ? !p.tags.includes(t.slice(1)) : p.tags.some(x => x.includes(t))));
        $('#favCount').textContent = list.length + ' von ' + favs.length;
        if (!list.length) { $('#favBox').innerHTML = '<div class="ns-empty">' + (favs.length ? 'Keine Treffer.' : 'Noch keine Favoriten – in der Lightbox auf ☆ klicken oder F drücken.') + '</div>'; $('#favBox')._list = []; return; }
        renderGrid($('#favBox'), list);
    }
    $('#favFilter').addEventListener('input', renderFavs);
    $('#favProv').addEventListener('change', renderFavs);

    // =============== Gespeicherte Suchen ===============
    function renderSaved(items) {
        $('#savedBox').innerHTML = items.length ? items.map(s => { const P = NS.providers[s.provider] || {}; return '<div class="ns-saved"><b>' + esc(s.name) + '</b><small>' + esc((P.icon || '') + ' ' + (P.name || s.provider)) + ' · ' + esc(s.q || '(ohne Tags)') + ' · ' + esc(s.rating) + ' · ' + esc(s.order) + '</small>'
            + '<span><button type="button" class="btn btn-sm btn-gold" data-run="' + esc(s.id) + '">Öffnen</button><button type="button" class="btn btn-sm btn-danger" data-sdel="' + esc(s.id) + '">🗑</button></span></div>'; }).join('')
            : '<div class="ns-empty">Noch nichts gespeichert – in der Suche auf 📌 klicken.</div>';
        $('#savedBox')._items = items;
    }
    function loadSaved() { api('saved').then(r => renderSaved(r.items)).catch(x => toast(x.message, true)); }
    $('#saveSearch').addEventListener('click', () => {
        const name = prompt('Name für diese Suche:', form.q.value.trim() || NS.providers[prov.value].name);
        if (name === null) return;
        api('saved_add', { name, provider: prov.value, q: form.q.value, rating: form.rating.value, order: form.order.value }).then(r => { toast(r.msg); renderSaved(r.items); loaded.saved = true; }).catch(x => toast(x.message, true));
    });
    $('#savedBox').addEventListener('click', e => {
        const run = e.target.closest('[data-run]'), del = e.target.closest('[data-sdel]');
        if (run) { const s = $('#savedBox')._items.find(x => x.id === run.dataset.run); prov.value = s.provider; form.q.value = s.q; form.rating.value = s.rating; form.order.value = s.order; provChanged(); tab('search'); search(false); }
        if (del && confirm('Gespeicherte Suche löschen?')) api('saved_delete', { id: del.dataset.sdel }).then(r => renderSaved(r.items)).catch(x => toast(x.message, true));
    });

    // =============== Bilder-Test ===============
    const probe = url => new Promise(res => {
        const i = new Image(), t0 = Date.now();
        const done = ok => { clearTimeout(tm); res(ok ? 'ok (' + (Date.now() - t0) + ' ms)' : '✗ blockiert oder Fehler'); };
        const tm = setTimeout(() => { i.src = ''; res('✗ keine Antwort (Zeitüberschreitung)'); }, 10000);
        i.referrerPolicy = 'no-referrer';
        i.onload = () => done(i.naturalWidth > 0);
        i.onerror = () => done(false);
        i.src = url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now();
    });
    $('#diagBtn').addEventListener('click', async () => {
        const box = $('#diagBox'), prov = $('#settingsForm select[name=default]').value;
        box.innerHTML = '<div class="ns-small">Teste …</div>';
        try {
            const r = await api('diag', { provider: prov });
            let h = '<div>🔌 Suche über die API: <b>' + esc(r.api) + '</b></div>';
            if (r.url) {
                h += '<div>🖥 Bild über deinen Server: <b>' + esc(r.server) + '</b></div>';
                box.innerHTML = h + '<div class="ns-small">Prüfe den Browser …</div>';
                const direct = await probe(r.url), viaServer = await probe(r.proxy);
                h += '<div>🌐 Bild direkt im Browser (' + esc(r.host) + '): <b>' + esc(direct) + '</b></div><div>🌐 Bild im Browser über den Server: <b>' + esc(viaServer) + '</b></div>';
                const dOk = direct.startsWith('ok'), sOk = viaServer.startsWith('ok');
                h += '<p class="ns-diaghint">' + (dOk ? '✅ Alles in Ordnung – die Bilder sollten laden. Falls nicht: Seite neu laden (Strg+F5).'
                    : sOk ? '⚠ Dein Browser oder Netz blockiert <b>' + esc(r.host) + '</b> – typisch für Werbeblocker (z. B. uBlock mit Filterlisten), Jugendschutz- oder DNS-Filter im Router bzw. beim Anbieter. Über deinen Server klappt es: die Galerie weicht automatisch aus. Für schnelleres Laden „Bilder über deinen Server laden“ einschalten.'
                    : r.server.startsWith('ok') ? '⚠ Der Server bekommt das Bild, liefert es aber nicht an den Browser aus – evtl. eine Sicherheitsregel des Hosters. Bitte Fehlermeldung aus der Browser-Konsole (F12) schicken.'
                    : '⚠ Weder Browser noch Server erreichen die Bilder. Meldung vom Server: ' + esc(r.server)) + '</p>';
                if (!dOk && sOk && !auto.on) { auto.on = true; store.set('autoproxy', true); }
            } else h += '<p class="ns-diaghint">Keine Treffer zum Testen.</p>';
            box.innerHTML = h;
        } catch (x) { box.innerHTML = '<div class="ns-error">⚠ ' + esc(x.message) + '</div>'; }
    });

    // =============== Einstellungen ===============
    $('#settingsForm').addEventListener('submit', e => {
        e.preventDefault();
        const data = {};
        new FormData(e.target).forEach((v, k) => { data[k] = v; });
        api('settings_save', data).then(r => { toast(r.msg); setTimeout(() => location.reload(), 700); }).catch(x => toast(x.message, true));
    });

    if (last) search(false);
})();
