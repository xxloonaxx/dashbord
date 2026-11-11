/* VRCX Lite – Oberfläche. Alle Daten kommen als JSON von vrcx.php; Texte von VRChat werden immer maskiert. */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const img = (u, cls, alt) => /^https:\/\//.test(u || '') ? '<img class="' + cls + '" src="' + esc(u) + '" alt="' + esc(alt || '') + '" loading="lazy" referrerpolicy="no-referrer">' : '<span class="' + cls + ' vx-noimg">🦊</span>';
    const ST = window.VRCX.status;
    const statusDot = s => '<i class="vx-dot" style="--sc:' + (ST[s] || ST.offline).color + '" title="' + esc((ST[s] || ST.offline).label) + '"></i>';
    const ago = ts => {
        if (!ts) return '';
        const d = Math.max(0, Date.now() / 1000 - ts);
        if (d < 60) return 'gerade eben';
        if (d < 3600) return 'vor ' + Math.floor(d / 60) + ' min';
        if (d < 86400) return 'vor ' + Math.floor(d / 3600) + ' Std.';
        return 'vor ' + Math.floor(d / 86400) + ' Tg.';
    };
    const dur = ts => { if (!ts) return ''; const m = Math.floor(Math.max(0, Date.now() / 1000 - ts) / 60); return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; };
    const clock = ts => new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    const isoDate = s => { const d = new Date(s); return isNaN(d) ? '' : d.toLocaleDateString('de-DE'); };

    function api(act, data) {
        const body = new URLSearchParams(Object.assign({ act }, data || {}));
        return fetch('vrcx.php', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body })
            .then(async r => {
                let d;
                try { d = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort vom Server (HTTP ' + r.status + ').'); }
                if (d.auth && window.VRCX.connected) { location.reload(); throw new Error(d.error); }
                if (!r.ok || d.error) throw new Error(d.error || 'Fehler ' + r.status);
                return d;
            });
    }
    function toast(msg, bad) {
        $$('.vx-toast').forEach(x => x.parentNode.removeChild(x));
        const t = document.createElement('div');
        t.className = 'vx-toast' + (bad ? ' bad' : '');
        t.textContent = msg;
        document.body.append(t);
        setTimeout(() => t.classList.add('out'), 2600);
        setTimeout(() => t.remove(), 3200);
    }
    // Bilder, die nicht laden (z. B. gelöschte Avatare), durch einen Platzhalter ersetzen
    document.addEventListener('error', e => {
        const i = e.target;
        if (i.tagName !== 'IMG' || !/\bvx-/.test(i.className)) return;
        const s = document.createElement('span');
        s.className = i.className + ' vx-noimg';
        s.textContent = '🦊';
        i.replaceWith(s);
    }, true);
    function showErr(box, msg) { box.hidden = !msg; box.textContent = msg ? '⚠ ' + msg : ''; }

    // =============== Anmeldung ===============
    if (!window.VRCX.connected) {
        const lf = $('#loginForm'), tf = $('#tfaForm'), err = $('#loginErr');
        const TFA = { totp: ['Authenticator-App', 'Code aus deiner Authenticator-App (6 Ziffern).'], emailOtp: ['E-Mail-Code', 'VRChat hat dir einen Code per E-Mail geschickt.'], otp: ['Wiederherstellungscode', 'Einer deiner Wiederherstellungscodes (z. B. abcd-1234).'] };
        let type = 'totp';
        function show2fa(types) {
            lf.hidden = true; tf.hidden = false;
            const list = types.includes('totp') ? ['totp', 'otp', ...types.filter(t => t !== 'totp' && t !== 'otp')] : types;
            type = list[0];
            $('#tfaTypes').innerHTML = list.length > 1 ? list.map(t => '<button type="button" class="chip' + (t === type ? ' on' : '') + '" data-t="' + esc(t) + '">' + esc((TFA[t] || [t])[0]) + '</button>').join('') : '';
            $('#tfaInfo').textContent = (TFA[type] || ['', 'Code eingeben.'])[1];
            $('#vxCode').value = '';
            $('#vxCode').focus();
        }
        $('#tfaTypes').addEventListener('click', e => {
            const b = e.target.closest('[data-t]');
            if (!b) return;
            type = b.dataset.t;
            $$('#tfaTypes .chip').forEach(x => x.classList.toggle('on', x === b));
            $('#tfaInfo').textContent = (TFA[type] || ['', ''])[1];
            $('#vxCode').focus();
        });
        if (!tf.hidden) show2fa(tf.dataset.types.split(',').filter(Boolean));
        lf.addEventListener('submit', e => {
            e.preventDefault();
            const btn = lf.querySelector('button'); btn.disabled = true; btn.textContent = 'Verbinde …';
            showErr(err, '');
            api('login', { username: lf.username.value, password: lf.password.value })
                .then(d => { lf.password.value = ''; if (d.need2fa) show2fa(d.need2fa); else location.reload(); })
                .catch(x => showErr(err, x.message))
                .finally(() => { btn.disabled = false; btn.textContent = 'Anmelden'; });
        });
        tf.addEventListener('submit', e => {
            e.preventDefault();
            const btn = tf.querySelector('button[type=submit]'); btn.disabled = true;
            showErr(err, '');
            api('verify', { type, code: $('#vxCode').value }).then(() => location.reload()).catch(x => { showErr(err, x.message); btn.disabled = false; $('#vxCode').select(); });
        });
        $('#tfaCancel').addEventListener('click', () => { api('cancel_login'); tf.hidden = true; lf.hidden = false; showErr(err, ''); });
        return;
    }

    // =============== Verbunden ===============
    let state = null, syncTimer = null, feedItems = [], groupsLoaded = false, notifsLoaded = false;
    const friendById = id => (state && state.friends.find(f => f.id === id)) || null;

    // Tabs
    function tab(name) {
        $$('#tabs [data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        $$('.vx-tab').forEach(p => { p.hidden = p.dataset.pane !== name; });
        try { localStorage.setItem('vrcx-tab', name); } catch (e) {}
        if (name === 'feed') loadFeed(true);
        if (name === 'groups' && !groupsLoaded) loadGroups();
        if (name === 'notifs' && !notifsLoaded) loadNotifs();
    }
    $('#tabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) tab(b.dataset.tab); });

    // ---------- Zustand laden ----------
    function load(refresh, force) {
        $('#refreshBtn').disabled = true;
        return api('state', { refresh: refresh ? 1 : 0, force: force ? 1 : 0 }).then(d => {
            state = d;
            renderMe();
            renderFriends();
            showErr($('#globalErr'), d.error || '');
            const s = d.sync || {};
            $('#syncInfo').innerHTML = '<b>' + d.friends.filter(f => f.state === 'online').length + '</b> im Spiel · <b>' + d.friends.filter(f => f.state === 'active').length
                + '</b> online · ' + d.friends.length + ' Freunde · abgeglichen ' + (d.last_sync ? ago(d.last_sync) : 'noch nie')
                + (refresh && !s.synced && s.wait ? ' <span class="vx-small">(nächster Abgleich in ' + s.wait + ' s möglich)</span>' : '');
            $('#connInfo').innerHTML = 'Verbunden als <b>' + esc(d.me.name) + '</b> seit ' + esc(new Date(d.connected_at * 1000).toLocaleString('de-DE')) + '.<br>Letzter Abgleich: ' + (d.last_sync ? esc(new Date(d.last_sync * 1000).toLocaleTimeString('de-DE')) : '–');
            if (!$('[data-pane=feed]').hidden && refresh) loadFeed(true);
        }).catch(x => showErr($('#globalErr'), x.message)).finally(() => { $('#refreshBtn').disabled = false; });
    }
    function schedule() {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(() => {
            if ($('#autoRefresh').checked && document.visibilityState === 'visible') load(true).finally(schedule); else schedule();
        }, Math.max(60, window.VRCX.syncMin + 5) * 1000);
    }
    $('#refreshBtn').addEventListener('click', () => load(true, true));
    try { $('#autoRefresh').checked = localStorage.getItem('vrcx-auto') !== '0'; } catch (e) {}
    $('#autoRefresh').addEventListener('change', e => { try { localStorage.setItem('vrcx-auto', e.target.checked ? '1' : '0'); } catch (x) {} });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && state && Date.now() / 1000 - state.last_sync > 120 && $('#autoRefresh').checked) load(true); });

    function renderMe() {
        const m = state.me;
        $('#me').innerHTML = img(m.image, 'vx-me-img', m.name) + '<div><b style="color:' + esc(m.trust[1]) + '">' + esc(m.name) + '</b><span>' + statusDot(m.status)
            + esc(m.status_desc || (ST[m.status] || {}).label || '') + '</span></div>';
        const f = $('#statusForm');
        if (f && !f.dataset.touched) {
            const r = f.querySelector('input[value="' + CSS.escape(m.status) + '"]'); if (r) r.checked = true;
            f.desc.value = m.status_desc || '';
            $('#statusHistory').innerHTML = (m.history || []).map(h => '<option value="' + esc(h) + '">').join('');
        }
    }

    // ---------- Freunde ----------
    const locLine = l => {
        if (!l || l.kind === 'offline') return '';
        if (l.kind !== 'world') return '<span class="vx-loc muted">' + esc(l.label) + '</span>';
        return '<span class="vx-loc">🌐 ' + esc(l.world_name || 'Welt wird geladen …') + ' <small>' + esc(l.access) + ' · ' + esc(l.region) + '</small></span>';
    };
    function friendCard(f) {
        return '<button type="button" class="vx-friend st-' + f.state + (f.favorite ? ' fav' : '') + '" data-user="' + esc(f.id) + '">'
            + img(f.image, 'vx-av', f.name)
            + '<span class="vx-f-main"><span class="vx-f-name">' + statusDot(f.state === 'offline' ? 'offline' : f.status) + '<b style="color:' + esc(f.trust[1]) + '">' + esc(f.name) + '</b>' + (f.favorite ? ' ⭐' : '') + '</span>'
            + (f.status_desc ? '<span class="vx-f-sd">' + esc(f.status_desc) + '</span>' : '')
            + (f.state === 'online' ? locLine(f.loc) : '<span class="vx-loc muted">' + (f.state === 'active' ? '💻 ' + esc(f.platform || 'Website / App') : 'Offline') + '</span>')
            + (f.note ? '<span class="vx-f-note">📝 ' + esc(f.note.slice(0, 60)) + '</span>' : '')
            + '</span><span class="vx-f-since" title="seit">' + esc(f.state === 'offline' ? ago(f.since) : dur(f.since)) + '</span></button>';
    }
    function renderFriends() {
        if (!state) return;
        const q = $('#friendSearch').value.trim().toLowerCase();
        const sort = $('#friendSort').value;
        const off = $('#showOffline').checked;
        let list = state.friends.filter(f => !q || [f.name, f.status_desc, f.note, f.loc && f.loc.world_name, f.platform].join(' ').toLowerCase().includes(q));
        const by = { name: (a, b) => a.name.localeCompare(b.name, 'de'), since: (a, b) => b.since - a.since, state: (a, b) => a.name.localeCompare(b.name, 'de') };
        list.sort(by[sort] || by.name);
        const groups = [
            ['⭐ Favoriten', list.filter(f => f.favorite && f.state !== 'offline')],
            ['🎮 Im Spiel', list.filter(f => f.state === 'online' && !f.favorite)],
            ['💻 Website / App', list.filter(f => f.state === 'active' && !f.favorite)],
        ];
        if (off || q) groups.push(['⚫ Offline', list.filter(f => f.state === 'offline')]);
        // Freunde in derselben Instanz zusammenfassen
        const inst = {};
        state.friends.filter(f => f.state === 'online' && f.loc && f.loc.kind === 'world').forEach(f => { (inst[f.loc.location] = inst[f.loc.location] || []).push(f); });
        const together = Object.values(inst).filter(a => a.length > 1);
        let html = '';
        if (together.length && !q) {
            html += '<div class="vx-sec"><h3>👯 Zusammen unterwegs <small>' + together.length + '</small></h3><div class="vx-inst">'
                + together.map(a => '<div class="vx-inst-card">' + img(a[0].loc.world_thumb, 'vx-inst-img', '') + '<div><b>' + esc(a[0].loc.world_name || 'Welt') + '</b><small>' + esc(a[0].loc.access) + ' · ' + esc(a[0].loc.region) + '</small><span>'
                    + a.map(f => '<a href="#" data-user="' + esc(f.id) + '">' + esc(f.name) + '</a>').join(', ') + '</span></div></div>').join('') + '</div></div>';
        }
        groups.forEach(([title, items]) => {
            if (!items.length && title.startsWith('⭐')) return;
            html += '<div class="vx-sec"><h3>' + title + ' <small>' + items.length + '</small></h3>'
                + (items.length ? '<div class="vx-grid">' + items.map(friendCard).join('') + '</div>' : '<div class="vx-empty">Niemand.</div>') + '</div>';
        });
        if (!state.friends.length) html = '<div class="vx-empty">Noch keine Freunde geladen – oben auf „⟳ Aktualisieren“ klicken.</div>';
        $('#friendsBox').innerHTML = html;
    }
    ['#friendSearch', '#friendSort', '#showOffline'].forEach(s => $(s).addEventListener('input', renderFriends));
    try { $('#showOffline').checked = localStorage.getItem('vrcx-off') === '1'; } catch (e) {}
    $('#showOffline').addEventListener('change', e => { try { localStorage.setItem('vrcx-off', e.target.checked ? '1' : '0'); } catch (x) {} });

    // ---------- Benutzer-Details ----------
    const modal = $('#userModal');
    function closeModal() { modal.hidden = true; document.body.style.overflow = ''; }
    modal.addEventListener('click', e => { if (e.target === modal || e.target.closest('[data-close]')) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
    document.addEventListener('click', e => {
        const t = e.target.closest('[data-user]');
        if (!t || modal.contains(t) && !t.matches('a')) return;
        e.preventDefault();
        openUser(t.dataset.user);
    });
    function openUser(id) {
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        const f = friendById(id);
        $('#userBody').innerHTML = '<div class="vx-loading">' + (f ? esc(f.name) + ' – ' : '') + 'lade Profil …</div>';
        api('user', { id }).then(d => renderUser(d.user)).catch(x => { $('#userBody').innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    function renderUser(u) {
        const l = u.loc || {};
        const w = l.world_info || {};
        const joinable = l.kind === 'world' && !/^Invite$/.test(l.access);
        let h = '<div class="vx-u-head">' + img(u.image, 'vx-u-img', u.name) + '<div><h2 style="color:' + esc(u.trust[1]) + '">' + esc(u.name) + '</h2>'
            + '<div class="vx-u-sub">' + statusDot(u.status) + esc(u.status_desc || (ST[u.status] || {}).label || '') + (u.pronouns ? ' · ' + esc(u.pronouns) : '') + '</div>'
            + '<div class="vx-u-tags"><span class="vx-pill" style="--pc:' + esc(u.trust[1]) + '">' + esc(u.trust[0]) + '</span>' + (u.platform ? '<span class="vx-pill">' + esc(u.platform) + '</span>' : '')
            + (u.is_friend ? '<span class="vx-pill" style="--pc:#51e57e">Freund</span>' : '') + '</div></div></div>';
        if (l.kind === 'world') {
            h += '<div class="vx-u-loc">' + img(w.thumb, 'vx-u-world', '') + '<div><b>' + esc((w.name) || 'Unbekannte Welt') + '</b><small>' + esc(l.label) + (w.author ? ' · von ' + esc(w.author) : '') + (w.capacity ? ' · max. ' + w.capacity : '') + '</small><span class="vx-u-btns">'
                + (joinable ? '<a class="btn btn-sm btn-gold" href="vrchat://launch?ref=vrcx-lite&amp;id=' + encodeURIComponent(l.location) + '">▶ Beitreten (Spiel)</a><button type="button" class="btn btn-sm" data-act="invite_self" data-loc="' + esc(l.location) + '">📨 Selbst-Einladung</button>' : '')
                + '<button type="button" class="btn btn-sm" data-act="request_invite">🙋 Einladung anfragen</button></span></div></div>';
        } else if (l.kind && l.kind !== 'offline') {
            h += '<div class="vx-u-loc"><div><b>' + esc(l.label) + '</b><span class="vx-u-btns"><button type="button" class="btn btn-sm" data-act="request_invite">🙋 Einladung anfragen</button></span></div></div>';
        }
        h += '<div class="vx-u-cols"><div>';
        if (u.bio_html) h += '<h4>Bio</h4><div class="fmt vx-u-bio">' + u.bio_html + '</div>';
        if (u.links && u.links.length) h += '<h4>Links</h4><ul class="vx-u-links">' + u.links.map(x => '<li><a href="' + esc(x) + '" target="_blank" rel="noopener noreferrer nofollow">' + esc(x.replace(/^https?:\/\//, '')) + '</a></li>').join('') + '</ul>';
        if (u.groups && u.groups.length) h += '<h4>Gruppen</h4><div class="vx-u-groups">' + u.groups.sort((a, b) => b.rep - a.rep).map(g => '<span class="vx-pill' + (g.rep ? ' rep' : '') + '" title="' + g.members + ' Mitglieder">' + (g.rep ? '★ ' : '') + esc(g.name) + '</span>').join('') + '</div>';
        h += '<dl class="vx-u-facts">' + (u.joined ? '<dt>Dabei seit</dt><dd>' + esc(isoDate(u.joined)) + '</dd>' : '') + (u.last_login ? '<dt>Letzter Login</dt><dd>' + esc(new Date(u.last_login).toLocaleString('de-DE')) + '</dd>' : '')
            + (u.last_activity ? '<dt>Zuletzt aktiv</dt><dd>' + esc(new Date(u.last_activity).toLocaleString('de-DE')) + '</dd>' : '') + '</dl>';
        h += '</div><div><h4>Meine Notiz <small>(nur für dich)</small></h4><form class="vx-note-form"><textarea name="note" rows="5" maxlength="4000" placeholder="z. B. kennengelernt auf der EF, mag Kaffee …">' + esc(u.note) + '</textarea>'
            + '<label class="vx-auto"><input type="checkbox" name="favorite" value="1"' + (u.favorite ? ' checked' : '') + '> ⭐ Favorit (oben in der Liste, eigener Filter im Verlauf)</label><button class="btn btn-sm btn-gold">Speichern</button></form>'
            + '<h4>Verlauf</h4><div class="vx-u-feed" id="uFeed">Lade …</div></div></div>';
        h += '<div class="vx-u-actions"><a class="btn btn-sm" href="https://vrchat.com/home/user/' + encodeURIComponent(u.id) + '" target="_blank" rel="noopener noreferrer">↗ Auf vrchat.com</a>'
            + (u.is_friend ? '<button type="button" class="btn btn-sm btn-danger" data-act="unfriend">Freundschaft beenden</button>' : '<button type="button" class="btn btn-sm btn-gold" data-act="friend_request">＋ Freundschaftsanfrage</button>') + '</div>';
        const body = $('#userBody');
        body.innerHTML = h;
        body.querySelector('.vx-note-form').addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target;
            api('note_save', { id: u.id, note: f.note.value, favorite: f.favorite.checked ? 1 : '' }).then(() => {
                toast('Notiz gespeichert.');
                const fr = friendById(u.id); if (fr) { fr.note = f.note.value; fr.favorite = f.favorite.checked; renderFriends(); }
            }).catch(x => toast(x.message, true));
        });
        body.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'unfriend' && !confirm('Freundschaft mit „' + u.name + '“ wirklich beenden? Das lässt sich nur mit einer neuen Anfrage rückgängig machen.')) return;
            b.disabled = true;
            api(act, { id: u.id, location: b.dataset.loc || '' }).then(d => { toast(d.msg || 'Erledigt.'); if (act === 'unfriend') { closeModal(); load(true, true); } })
                .catch(x => toast(x.message, true)).finally(() => { b.disabled = false; });
        }));
        api('feed', { friend: u.id }).then(d => { $('#uFeed').innerHTML = d.items.length ? d.items.slice(0, 25).map(feedRow).join('') : '<div class="vx-empty">Noch nichts aufgezeichnet.</div>'; })
            .catch(() => { $('#uFeed').textContent = '–'; });
    }

    // ---------- Verlauf ----------
    const FEED = { online: ['🟢', 'ist ins Spiel gekommen'], offline: ['⚫', 'ist offline gegangen'], active: ['💻', 'ist auf Website / App aktiv'], gps: ['🌐', 'ist gewechselt nach'],
                   status: ['💬', 'hat den Status geändert'], avatar: ['🎭', 'hat den Avatar gewechselt'], name: ['🏷️', 'hat sich umbenannt'], bio: ['📄', 'hat die Bio geändert'],
                   friend_add: ['🤝', 'ist jetzt dein Freund'], friend_remove: ['💔', 'ist nicht mehr in deiner Freundesliste'] };
    const locTxt = l => !l ? '' : l.kind === 'world' ? '<b>' + esc(l.world_name || 'Welt') + '</b> <small>' + esc(l.access) + ' · ' + esc(l.region) + '</small>' : esc(l.label || '');
    function feedRow(it) {
        const [ic, txt] = FEED[it.type] || ['•', it.type];
        let extra = '';
        if (it.type === 'gps' || (it.type === 'online' && it.new_loc)) extra = locTxt(it.new_loc);
        if (it.type === 'offline' && it.old_loc) extra = '<small>zuletzt in ' + locTxt(it.old_loc) + '</small>';
        if (it.type === 'status') { const [os, od] = it.old.split('|'), [ns, nd] = it.new.split('|'); extra = statusDot(os) + esc(od || (ST[os] || {}).label || os) + ' → ' + statusDot(ns) + esc(nd || (ST[ns] || {}).label || ns); }
        if (it.type === 'name') extra = esc(it.old) + ' → <b>' + esc(it.new) + '</b>';
        if (it.type === 'avatar') extra = img(it.new, 'vx-feed-av', 'neuer Avatar');
        if (it.type === 'bio') extra = '<small>' + esc(it.new.slice(0, 120)) + (it.new.length > 120 ? ' …' : '') + '</small>';
        return '<div class="vx-feed-row t-' + esc(it.type) + '"><time title="' + esc(new Date(it.ts * 1000).toLocaleString('de-DE')) + '">' + esc(clock(it.ts)) + '</time><span class="vx-feed-ic">' + ic + '</span>'
            + '<span class="vx-feed-txt"><a href="#" data-user="' + esc(it.vrc_id) + '">' + esc(it.name) + '</a> ' + txt + (extra ? ' <span class="vx-feed-x">' + extra + '</span>' : '') + '</span></div>';
    }
    let feedTimer = null;
    function loadFeed(reset) {
        const params = { q: $('#feedSearch').value, type: $('#feedType').value, favorites: $('#feedFav').checked ? 1 : '' };
        if (!reset && feedItems.length) params.before = feedItems[feedItems.length - 1].id;
        api('feed', params).then(d => {
            feedItems = reset ? d.items : feedItems.concat(d.items);
            $('#feedBox').innerHTML = feedItems.length ? feedItems.map(feedRow).join('') : '<div class="vx-empty">Noch keine Ereignisse. Der Verlauf füllt sich, solange diese Seite offen ist und abgleicht.</div>';
            $('#feedMore').hidden = d.items.length < 100;
        }).catch(x => showErr($('#globalErr'), x.message));
    }
    $('#feedSearch').addEventListener('input', () => { clearTimeout(feedTimer); feedTimer = setTimeout(() => loadFeed(true), 300); });
    $('#feedType').addEventListener('change', () => loadFeed(true));
    $('#feedFav').addEventListener('change', () => loadFeed(true));
    $('#feedMore').addEventListener('click', () => loadFeed(false));

    // ---------- Gruppen ----------
    const can = (g, p) => g.owner || g.perms.includes('*') || g.perms.includes(p);
    function loadGroups() {
        $('#groupsBox').innerHTML = '<div class="vx-loading">Lade Gruppen …</div>';
        api('groups').then(d => {
            groupsLoaded = true;
            $('#groupsBox').innerHTML = d.groups.length ? d.groups.map(g => '<button type="button" class="vx-group" data-group="' + esc(g.id) + '">' + img(g.icon, 'vx-g-icon', '') + '<span><b>' + esc(g.name) + '</b><small>' + esc(g.short) + ' · ' + g.members + ' Mitglieder</small>'
                + '<span>' + (g.owner ? '<span class="vx-pill" style="--pc:#f3c262">Besitzer</span>' : '') + (g.rep ? '<span class="vx-pill rep">★ repräsentiert</span>' : '') + '</span></span></button>').join('')
                : '<div class="vx-empty">Du bist in keiner Gruppe.</div>';
        }).catch(x => { $('#groupsBox').innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    $('#groupsBox').addEventListener('click', e => { const b = e.target.closest('[data-group]'); if (b) { $$('.vx-group').forEach(x => x.classList.toggle('on', x === b)); openGroup(b.dataset.group); } });
    let group = null;
    function openGroup(id) {
        const box = $('#groupDetail');
        box.innerHTML = '<div class="vx-loading">Lade Gruppe …</div>';
        api('group', { id }).then(d => {
            group = d.group;
            const g = group;
            const tabs = [['info', 'ℹ Info'], ['members', '👥 Mitglieder'], ['instances', '🌐 Instanzen']];
            if (can(g, 'group-invites-manage')) tabs.push(['requests', '📥 Anfragen'], ['invite', '✉ Einladen']);
            if (can(g, 'group-bans-manage')) tabs.push(['bans', '🚫 Banns']);
            if (can(g, 'group-audit-view')) tabs.push(['audit', '📋 Protokoll']);
            box.innerHTML = '<div class="panel vx-gd">' + (/^https:/.test(g.banner) ? '<div class="vx-gd-banner" style="background-image:url(\'' + esc(g.banner.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"></div>' : '')
                + '<div class="vx-gd-head">' + img(g.icon, 'vx-g-icon lg', '') + '<div><h2>' + esc(g.name) + '</h2><small>' + esc(g.short) + ' · ' + g.members + ' Mitglieder' + (g.online ? ' · ' + g.online + ' online' : '') + ' · ' + esc(g.privacy) + '</small></div>'
                + '<button type="button" class="btn btn-sm" id="gRep">' + (g.rep ? '★ Repräsentation entfernen' : '☆ Gruppe repräsentieren') + '</button></div>'
                + '<div class="vx-gd-tabs">' + tabs.map((t, i) => '<button type="button" class="chip' + (i ? '' : ' on') + '" data-gt="' + t[0] + '">' + t[1] + '</button>').join('') + '</div><div id="gPane"></div></div>';
            $('#gRep').addEventListener('click', () => api('group_represent', { id: g.id, on: g.rep ? '' : 1 }).then(r => { toast(r.msg); openGroup(g.id); loadGroups(); }).catch(x => toast(x.message, true)));
            box.querySelector('.vx-gd-tabs').addEventListener('click', e => { const b = e.target.closest('[data-gt]'); if (!b) return; $$('.vx-gd-tabs .chip').forEach(x => x.classList.toggle('on', x === b)); groupPane(b.dataset.gt); });
            groupPane('info');
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }).catch(x => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    const memberRow = (m, actions) => '<div class="vx-member">' + img(m.image, 'vx-av sm', m.name) + '<a href="#" data-user="' + esc(m.id) + '">' + esc(m.name) + '</a>'
        + (m.rep ? '<span class="vx-pill rep">★</span>' : '') + '<small>' + esc(isoDate(m.joined || m.banned)) + '</small><span class="vx-m-acts">' + actions(m) + '</span></div>';
    function groupPane(name, offset) {
        const g = group, pane = $('#gPane');
        const btn = (act, label, cls, m, extra) => '<button type="button" class="btn btn-sm ' + (cls || '') + '" data-gact="' + act + '" data-user="' + esc(m.id) + '" data-name="' + esc(m.name) + '"' + (extra || '') + '>' + label + '</button>';
        if (name === 'info') {
            pane.innerHTML = '<div class="vx-cols"><div><h4>Beschreibung</h4><div class="fmt">' + (g.desc_html || '<span class="muted">–</span>') + '</div>'
                + (g.links.length ? '<h4>Links</h4><ul class="vx-u-links">' + g.links.map(x => '<li><a href="' + esc(x) + '" target="_blank" rel="noopener noreferrer nofollow">' + esc(x) + '</a></li>').join('') + '</ul>' : '') + '</div>'
                + '<div><h4>Regeln</h4><div class="fmt">' + (g.rules_html || '<span class="muted">–</span>') + '</div>' + (g.roles.length ? '<h4>Rollen</h4><div>' + g.roles.map(r => '<span class="vx-pill">' + esc(r.name) + '</span>').join('') + '</div>' : '')
                + '<h4>Deine Rechte</h4><div class="vx-small">' + (g.owner ? 'Besitzer – alle Rechte' : g.perms.length ? g.perms.map(esc).join(', ') : 'keine besonderen Rechte') + '</div></div></div>';
            return;
        }
        if (name === 'invite') {
            const friends = (state ? state.friends : []).slice().sort((a, b) => a.name.localeCompare(b.name, 'de'));
            pane.innerHTML = '<form class="vx-tools" id="gInvite"><select name="user" required><option value="">Freund auswählen …</option>' + friends.map(f => '<option value="' + esc(f.id) + '">' + esc(f.name) + (f.state !== 'offline' ? ' 🟢' : '') + '</option>').join('')
                + '</select><button class="btn btn-gold">✉ Einladen</button></form><p class="vx-small">Die Person bekommt eine Gruppen-Einladung in VRChat.</p>';
            $('#gInvite').addEventListener('submit', e => { e.preventDefault(); api('group_invite', { id: g.id, user: e.target.user.value }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); });
            return;
        }
        pane.innerHTML = '<div class="vx-loading">Lade …</div>';
        if (name === 'instances') {
            api('group_instances', { id: g.id }).then(d => {
                pane.innerHTML = d.items.length ? '<div class="vx-inst">' + d.items.map(i => '<div class="vx-inst-card">' + img(i.thumb, 'vx-inst-img', '') + '<div><b>' + esc(i.world) + '</b><small>' + esc(i.loc.label || '') + ' · ' + i.users + (i.capacity ? '/' + i.capacity : '') + ' Personen</small>'
                    + '<span><a class="btn btn-sm btn-gold" href="vrchat://launch?ref=vrcx-lite&amp;id=' + encodeURIComponent(i.loc.location || '') + '">▶ Beitreten</a> <button type="button" class="btn btn-sm" data-self="' + esc(i.loc.location || '') + '">📨 Selbst-Einladung</button></span></div></div>').join('') + '</div>'
                    : '<div class="vx-empty">Gerade keine offenen Gruppen-Instanzen.</div>';
            }).catch(x => { pane.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
            return;
        }
        if (name === 'audit') {
            api('group_audit', { id: g.id }).then(d => {
                pane.innerHTML = d.items.length ? '<div class="vx-feed">' + d.items.map(l => '<div class="vx-feed-row"><time>' + esc(new Date(l.ts).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })) + '</time><span class="vx-feed-ic">📋</span><span class="vx-feed-txt"><b>' + esc(l.actor) + '</b> ' + esc(l.desc || l.type) + ' <small class="muted">' + esc(l.type) + '</small></span></div>').join('') + '</div>'
                    : '<div class="vx-empty">Keine Einträge.</div>';
            }).catch(x => { pane.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
            return;
        }
        const act = { members: 'group_members', requests: 'group_requests', bans: 'group_bans' }[name];
        api(act, { id: g.id, offset: offset || 0 }).then(d => {
            const actions = m => name === 'requests' ? btn('accept', '✓ Aufnehmen', 'btn-gold', m) + btn('reject', '✕ Ablehnen', '', m) + btn('block', '⛔ Ablehnen + sperren', 'btn-danger', m)
                : name === 'bans' ? btn('unban', 'Bann aufheben', '', m)
                : (can(g, 'group-members-remove') ? btn('kick', 'Entfernen', '', m) : '') + (can(g, 'group-bans-manage') ? btn('ban', 'Bannen', 'btn-danger', m) : '');
            let h = d.items.length ? d.items.map(m => memberRow(m, actions)).join('') : '<div class="vx-empty">' + ({ members: 'Keine Mitglieder sichtbar.', requests: 'Keine offenen Beitrittsanfragen.', bans: 'Niemand gebannt.' }[name]) + '</div>';
            if (name === 'members' && (offset || d.items.length === 50)) h += '<div class="vx-pager">' + (offset ? '<button type="button" class="btn btn-sm" data-page="' + (offset - 50) + '">‹ Zurück</button>' : '') + (d.items.length === 50 ? '<button type="button" class="btn btn-sm" data-page="' + ((offset || 0) + 50) + '">Weiter ›</button>' : '') + '</div>';
            pane.innerHTML = '<div class="vx-members">' + h + '</div>';
            $$('[data-page]', pane).forEach(b => b.addEventListener('click', () => groupPane('members', +b.dataset.page)));
        }).catch(x => { pane.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    $('#groupDetail').addEventListener('click', e => {
        const s = e.target.closest('[data-self]');
        if (s) { api('invite_self', { location: s.dataset.self }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); return; }
        const b = e.target.closest('[data-gact]');
        if (!b) return;
        e.preventDefault();
        e.stopPropagation();
        const a = b.dataset.gact, who = b.dataset.name;
        const q = { kick: '„' + who + '“ aus der Gruppe entfernen?', ban: '„' + who + '“ aus der Gruppe bannen?', block: 'Anfrage von „' + who + '“ ablehnen und weitere Anfragen sperren?' }[a];
        if (q && !confirm(q)) return;
        const map = { accept: ['group_request', { decision: 'accept' }], reject: ['group_request', { decision: 'reject' }], block: ['group_request', { decision: 'reject', block: 1 }],
                      kick: ['group_kick', {}], ban: ['group_ban', {}], unban: ['group_unban', {}] };
        b.disabled = true;
        api(map[a][0], Object.assign({ id: group.id, user: b.dataset.user }, map[a][1])).then(r => { toast(r.msg || 'Erledigt.'); b.closest('.vx-member').remove(); })
            .catch(x => { toast(x.message, true); b.disabled = false; });
    }, true);

    // ---------- Anfragen / Benachrichtigungen ----------
    const NT = { friendRequest: '🤝 Freundschaftsanfrage', invite: '📨 Einladung', requestInvite: '🙋 möchte eingeladen werden', inviteResponse: '↩ Antwort auf Einladung', requestInviteResponse: '↩ Antwort', votetokick: '🗳 Abstimmung', message: '💬 Nachricht' };
    function loadNotifs() {
        $('#notifBox').innerHTML = '<div class="vx-loading">Lade …</div>';
        api('notifications').then(d => {
            notifsLoaded = true;
            $('#notifBox').innerHTML = d.items.length ? '<div class="vx-members">' + d.items.map(n => '<div class="vx-member' + (n.seen ? '' : ' unseen') + '"><span>' + esc(NT[n.type] || n.type) + '</span>'
                + '<a href="#" data-user="' + esc(n.from_id) + '">' + esc(n.from) + '</a><small>' + esc(n.message || '') + ' · ' + esc(n.created ? new Date(n.created).toLocaleString('de-DE') : '') + '</small><span class="vx-m-acts">'
                + (n.type === 'friendRequest' ? '<button type="button" class="btn btn-sm btn-gold" data-nact="notif_accept" data-id="' + esc(n.id) + '">✓ Annehmen</button>' : '')
                + '<button type="button" class="btn btn-sm" data-nact="notif_hide" data-id="' + esc(n.id) + '">Ausblenden</button></span></div>').join('') + '</div>'
                : '<div class="vx-empty">Keine offenen Anfragen.</div>';
            const n = d.items.filter(x => x.type === 'friendRequest').length;
            $('[data-tab=notifs]').textContent = '🔔 Anfragen' + (n ? ' (' + n + ')' : '');
        }).catch(x => { $('#notifBox').innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    $('#notifBox').addEventListener('click', e => {
        const b = e.target.closest('[data-nact]');
        if (!b) return;
        e.stopPropagation();
        b.disabled = true;
        api(b.dataset.nact, { id: b.dataset.id }).then(r => { toast(r.msg || 'Erledigt.'); b.closest('.vx-member').remove(); if (b.dataset.nact === 'notif_accept') load(true, true); })
            .catch(x => { toast(x.message, true); b.disabled = false; });
    }, true);

    // ---------- Suche ----------
    $('#searchForm').addEventListener('submit', e => {
        e.preventDefault();
        $('#searchBox').innerHTML = '<div class="vx-loading">Suche …</div>';
        api('search', { q: $('#userSearch').value }).then(d => {
            $('#searchBox').innerHTML = d.users.length ? '<div class="vx-grid">' + d.users.map(u => '<button type="button" class="vx-friend" data-user="' + esc(u.id) + '">' + img(u.image, 'vx-av', u.name)
                + '<span class="vx-f-main"><span class="vx-f-name">' + statusDot(u.status) + '<b style="color:' + esc(u.trust[1]) + '">' + esc(u.name) + '</b>' + (u.is_friend ? ' 🤝' : '') + '</span><span class="vx-f-sd">' + esc(u.trust[0]) + (u.status_desc ? ' · ' + esc(u.status_desc) : '') + '</span></span></button>').join('') + '</div>'
                : '<div class="vx-empty">Niemanden gefunden.</div>';
        }).catch(x => { $('#searchBox').innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    });

    // ---------- Konto ----------
    $('#statusForm').addEventListener('input', e => { e.currentTarget.dataset.touched = '1'; });
    $('#statusForm').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target;
        api('status', { status: (f.querySelector('input[name=status]:checked') || {}).value || 'active', desc: f.desc.value })
            .then(r => { toast(r.msg); delete f.dataset.touched; load(true, true); }).catch(x => toast(x.message, true));
    });
    $('#logoutBtn').addEventListener('click', () => { if (confirm('VRChat-Verbindung trennen? Freunde, Verlauf und Notizen bleiben gespeichert.')) api('logout').then(() => location.reload()).catch(x => toast(x.message, true)); });
    const sf = $('#settingsForm');
    if (sf) sf.addEventListener('submit', e => { e.preventDefault(); api('settings', { user_agent: sf.user_agent.value }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); });

    // Start
    let start = 'friends';
    try { start = localStorage.getItem('vrcx-tab') || 'friends'; } catch (e) {}
    if (!$('[data-pane="' + start + '"]')) start = 'friends';
    load(false).then(() => load(true)).finally(schedule);
    tab(start);
    setInterval(() => { if (state && $('[data-pane=friends]').hidden === false) renderFriends(); }, 60000);
})();
