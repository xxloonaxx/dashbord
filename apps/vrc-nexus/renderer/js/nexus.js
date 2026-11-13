/* VRC Nexus – Oberfläche. Alle Daten kommen als JSON von nexus.php; Texte von VRChat werden immer maskiert. */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const img = (u, cls, alt) => /^https:\/\//.test(u || '') ? '<img class="' + cls + '" src="' + esc(u) + '" alt="' + esc(alt || '') + '" loading="lazy" referrerpolicy="no-referrer">' : '<span class="' + cls + ' vx-noimg">🦊</span>';
    const ST = window.VRCX.status;
    const statusDot = s => '<i class="vx-dot" style="--sc:' + (ST[s] || ST.offline).color + '" title="' + esc((ST[s] || ST.offline).label) + '"></i>';
    const now = () => Date.now() / 1000;
    const ago = ts => {
        if (!ts) return '';
        const d = Math.max(0, now() - ts);
        if (d < 60) return 'gerade eben';
        if (d < 3600) return 'vor ' + Math.floor(d / 60) + ' min';
        if (d < 86400) return 'vor ' + Math.floor(d / 3600) + ' Std.';
        return 'vor ' + Math.floor(d / 86400) + ' Tg.';
    };
    const hm = sec => { sec = Math.max(0, Math.round(sec)); const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return h ? h + ' h ' + m + ' min' : m + ' min'; };
    const dur = ts => ts ? hm(now() - ts) : '';
    const clock = ts => new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    const isoDate = s => { const d = new Date(s); return isNaN(d) ? '' : d.toLocaleDateString('de-DE'); };
    const isoDT = s => { const d = new Date(s); return isNaN(d) ? '' : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
    const num = n => Number(n || 0).toLocaleString('de-DE');
    const launch = loc => 'vrchat://launch?ref=vrc-nexus&id=' + encodeURIComponent(loc);
    const joinable = l => l && l.kind === 'world' && l.access !== 'Invite';

    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach(x => body.append(k + '[]', x)); else body.append(k, v ?? ''); });
        return fetch('nexus.php', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body })
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
    const fail = box => x => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; };
    const loading = (box, txt) => { box.innerHTML = '<div class="vx-loading">' + esc(txt || 'Lade …') + '</div>'; };

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
    const store = {
        get(k, d) { try { const v = localStorage.getItem('vrcx-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
        set(k, v) { try { localStorage.setItem('vrcx-' + k, JSON.stringify(v)); } catch (e) {} },
    };
    const prefs = Object.assign({ notify: 'off', notify_offline: false, notify_gps: false, sound: false, interval: 60, presets: [] }, store.get('prefs', {}));
    let state = null, prevFriends = null, syncTimer = null, feedItems = [], loaded = {}, favFilter = '';
    const friendById = id => (state && state.friends.find(f => f.id === id)) || null;
    const TRUST_ORDER = ['VRChat-Team', 'Trusted User', 'Known User', 'User', 'New User', 'Visitor', 'Nuisance'];

    // ---------- Reiter ----------
    const TABS = [...new Set($$('#tabs [data-tab]').map(b => b.dataset.tab))];
    function tab(name, sub) {
        if (!TABS.includes(name)) name = 'friends';
        sub = sub || '';
        let hit = false;
        $$('#tabs [data-tab]').forEach(b => { const on = b.dataset.tab === name && (b.dataset.msub || '') === sub; hit = hit || on; b.classList.toggle('active', on); });
        if (!hit) $$('#tabs [data-tab="' + name + '"]').forEach((b, i) => b.classList.toggle('active', i === 0));
        $$('#tabs .vx-mgroup').forEach(g => { g.classList.toggle('active', !!g.querySelector('.active')); g.classList.remove('open'); });
        $$('.vx-tab').forEach(p => { p.hidden = p.dataset.pane !== name; });
        store.set('tab', name);
        store.set('tabSub', sub);
        if (name === 'favs' && sub && fv.kind !== sub) { fv.kind = sub; fv.sel = null; loaded.favsDirty = true; }
        if (name === 'feed') { const ft = $('#feedType'); if (sub === 'friendlog') ft.value = 'friend_add,friend_remove'; else if (ft.value === 'friend_add,friend_remove' && store.get('tabSubPrev') === 'friendlog') ft.value = ''; store.set('tabSubPrev', sub); }
        if (name === 'feed') loadFeed(true);
        if (name === 'stats') loadStats();
        if (name === 'worlds' && !loaded.worlds) loadWorlds('favorites');
        if (name === 'avatars' && !loaded.avatars) loadAvatars('mine');
        if (name === 'groups' && !loaded.groups) loadGroups();
        if (name === 'favs' && (!loaded.favs || loaded.favsDirty)) { loaded.favs = true; loaded.favsDirty = false; loadFavs(); }
        if (name === 'notifs') loadNotifs();
        if (name === 'shop' && !loaded.shop) loadShop();
        if (name === 'global') loadGlobal();
        if (name === 'moderation') loadModeration();
        if (name === 'calendar') loadCalendar(false);
        if (name === 'gallery' && !loaded.gallery) loadGallery(false);
        if (name === 'news' && !loaded.news) loadNews();
        if (name === 'client' && window.NexusClient) window.NexusClient.open();
        if (name !== 'client' && window.NexusClient) window.NexusClient.close();
        if (window.NexusApp) window.NexusApp.open(name, sub);
        if (window.NexusHome) { if (name === 'home') window.NexusHome.open(); else window.NexusHome.close(); }
    }
    $('#tabs').addEventListener('click', e => {
        const top = e.target.closest('.vx-mtop');
        if (top) { const g = top.parentNode, was = g.classList.contains('open'); $$('#tabs .vx-mgroup').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !was); return; }
        const b = e.target.closest('[data-tab]'); if (b) tab(b.dataset.tab, b.dataset.msub);
    });
    document.addEventListener('click', e => { if (!e.target.closest('#tabs')) $$('#tabs .vx-mgroup.open').forEach(x => x.classList.remove('open')); });
    document.addEventListener('keydown', e => {
        if (e.target.closest('input, textarea, select') || e.ctrlKey || e.metaKey || e.altKey || !$('#userModal').hidden) return;
        if (e.key === '/') { e.preventDefault(); tab('friends'); $('#friendSearch').focus(); }
        if (e.key === 'r' || e.key === 'R') load(true, true);
        if (/^[0-9]$/.test(e.key)) { const i = e.key === '0' ? 9 : +e.key - 1; if (TABS[i]) tab(TABS[i]); }
    });

    // ---------- Zustand laden ----------
    function load(refresh, force) {
        $('#refreshBtn').disabled = true;
        return api('state', { refresh: refresh ? 1 : 0, force: force ? 1 : 0 }).then(d => {
            prevFriends = state ? state.friends : null;
            state = d;
            renderMe();
            renderMyLoc();
            fillTrustFilter();
            renderFavChips();
            renderFriends();
            notifyChanges();
            showErr($('#globalErr'), d.error || '');
            const s = d.sync || {};
            $('#syncInfo').innerHTML = '<b>' + d.friends.filter(f => f.state === 'online').length + '</b> im Spiel · <b>' + d.friends.filter(f => f.state === 'active').length
                + '</b> online · ' + d.friends.length + ' Freunde · abgeglichen ' + (d.last_sync ? ago(d.last_sync) : 'noch nie')
                + (refresh && !s.synced && s.wait ? ' <span class="vx-small">(nächster Abgleich in ' + s.wait + ' s möglich)</span>' : '');
            $('#connInfo').innerHTML = 'Verbunden als <b>' + esc(d.me.name) + '</b> seit ' + esc(new Date(d.connected_at * 1000).toLocaleString('de-DE')) + '.<br>Letzter Abgleich: ' + (d.last_sync ? esc(new Date(d.last_sync * 1000).toLocaleTimeString('de-DE')) : '–');
            fillProfile();
            if (!$('[data-pane=feed]').hidden && refresh) loadFeed(true);
            if (refresh && !loaded.spark) loadSpark();
        }).catch(x => showErr($('#globalErr'), x.message)).finally(() => { $('#refreshBtn').disabled = false; });
    }
    function schedule() {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(() => {
            if ($('#autoRefresh').checked && document.visibilityState === 'visible') load(true).finally(schedule); else schedule();
        }, Math.max(60, +prefs.interval || 60, window.VRCX.syncMin + 5) * 1000);
    }
    $('#refreshBtn').addEventListener('click', () => load(true, true));
    $('#autoRefresh').checked = store.get('auto', true);
    $('#autoRefresh').addEventListener('change', e => store.set('auto', e.target.checked));
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && state && now() - state.last_sync > 120 && $('#autoRefresh').checked) load(true); });

    // Mini-Diagramm „Freunde im Spiel“ (24 h) in der Leiste
    function loadSpark() {
        loaded.spark = true;
        api('stats').then(s => { loaded.statsData = s; drawSpark(s.history.slice(-24)); }).catch(() => {});
    }
    function drawSpark(h) {
        const svg = $('#spark');
        if (!h || h.length < 2) { svg.innerHTML = ''; return; }
        const max = Math.max(1, ...h.map(x => x.online));
        const pts = h.map((x, i) => (i / (h.length - 1) * 120).toFixed(1) + ',' + (24 - x.online / max * 22).toFixed(1)).join(' ');
        svg.innerHTML = '<title>Freunde im Spiel, letzte 24 h (max. ' + max + ')</title><polyline points="' + pts + '" fill="none" stroke="currentColor" stroke-width="1.5"/>';
    }

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
        renderPresets();
    }
    function renderMyLoc() {
        const l = state.me.loc || {}, box = $('#myLoc');
        if (l.kind !== 'world' && l.kind !== 'private' && l.kind !== 'traveling') { box.hidden = true; return; }
        const here = state.friends.filter(f => f.state === 'online' && f.loc && f.loc.location && f.loc.location === l.location);
        box.hidden = false;
        box.innerHTML = '🧭 <b>Du bist gerade in:</b> ' + (l.kind === 'world' ? '<a href="#" data-world="' + esc(l.world) + '">' + esc(l.world_name || 'Welt') + '</a> <small>' + esc(l.access) + ' · ' + esc(l.region) + '</small>' : esc(l.label))
            + (here.length ? ' · mit ' + here.map(f => '<a href="#" data-user="' + esc(f.id) + '">' + esc(f.name) + '</a>').join(', ') : '')
            + (l.kind === 'world' ? ' <button type="button" class="btn btn-sm" data-inst="' + esc(l.location) + '">Instanz-Info</button><button type="button" class="btn btn-sm" data-party="' + esc(l.location) + '">👥 Freunde einladen</button>'
                + '<button type="button" class="btn btn-sm" data-link="' + esc(l.location) + '">🔗 Link</button>' + (window.VRCX.discord ? '<button type="button" class="btn btn-sm" data-share="' + esc(l.location) + '">📣 Discord</button>' : '') : '');
    }

    // ---------- Benachrichtigungen ----------
    function beep() {
        try {
            const c = new (window.AudioContext || window.webkitAudioContext)(), o = c.createOscillator(), g = c.createGain();
            o.frequency.value = 880; g.gain.value = 0.06; o.connect(g); g.connect(c.destination); o.start(); o.stop(c.currentTime + 0.18);
        } catch (e) {}
    }
    function notify(title, body, icon) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        try { new Notification(title, { body, icon: /^https:/.test(icon || '') ? icon : undefined, tag: 'vrcx-' + title }); } catch (e) {}
        if (prefs.sound) beep();
    }
    function notifyChanges() {
        if (!prevFriends || prefs.notify === 'off') return;
        const prev = Object.fromEntries(prevFriends.map(f => [f.id, f]));
        state.friends.forEach(f => {
            const p = prev[f.id];
            if (!p) return;
            if (f.watch && p.state !== 'online' && f.state === 'online') { notify('🔔 ' + f.name + ' ist jetzt im Spiel', f.loc && f.loc.kind === 'world' ? (f.loc.world_name || '') + ' (' + f.loc.access + ')' : '', f.image); return; }
            if (prefs.notify === 'off') return;
            const fav = f.favorite || (prefs.notify === 'vrcfav' && f.fav_group);
            if (prefs.notify !== 'all' && !fav) return;
            const where = f.loc && f.loc.kind === 'world' ? (f.loc.world_name || 'einer Welt') + ' (' + f.loc.access + ')' : (f.loc ? f.loc.label : '');
            if (p.state !== 'online' && f.state === 'online') notify(f.name + ' ist im Spiel', where, f.image);
            else if (prefs.notify_offline && p.state !== 'offline' && f.state === 'offline') notify(f.name + ' ist offline', '', f.image);
            else if (prefs.notify_gps && fav && p.state === 'online' && f.state === 'online' && p.loc.location !== f.loc.location) notify(f.name + ' → ' + (f.loc.world_name || f.loc.label), f.loc.access || '', f.image);
        });
    }

    // ---------- Freunde ----------
    const locLine = l => {
        if (!l || l.kind === 'offline') return '';
        if (l.kind !== 'world') return '<span class="vx-loc muted">' + esc(l.label) + '</span>';
        return '<span class="vx-loc">🌐 <a href="#" data-world="' + esc(l.world) + '">' + esc(l.world_name || 'Welt wird geladen …') + '</a> <small>' + esc(l.access) + ' · ' + esc(l.region) + '</small></span>';
    };
    const FLAG = { EU: '🇪🇺', US: '🇺🇸', USE: '🇺🇸', USW: '🇺🇸', USX: '🇺🇸', JP: '🇯🇵' };
    function friendCard(f) {
        const compact = $('#compactView').checked;
        const st = f.state === 'offline' ? 'offline' : f.status, col = (ST[st] || ST.offline).color, l = f.loc || {};
        let where;
        if (f.state === 'online' && l.kind === 'world') where = '<span class="fc-loc"><span class="fc-flag" title="Region ' + esc(l.region) + '">' + (FLAG[l.region] || '🌐') + '</span><a href="#" data-world="' + esc(l.world) + '">'
            + esc(l.world_name || 'Welt wird geladen …') + '</a><small>' + esc(l.access) + '</small></span>';
        else if (f.state === 'online') where = '<span class="fc-loc muted"><span class="fc-flag">🔒</span>' + esc(l.label || 'Private Welt') + '</span>';
        else if (f.state === 'active') where = '<span class="fc-loc muted"><span class="fc-flag">💻</span>' + esc(f.platform || 'Website / App') + '</span>';
        else where = '<span class="fc-loc muted">Offline' + (f.since ? ' · ' + ago(f.since) : '') + '</span>';
        return '<div class="vx-friend fc st-' + f.state + (f.favorite ? ' fav' : '') + (compact ? ' compact' : '') + '" data-user="' + esc(f.id) + '" tabindex="0" role="button" style="--sc:' + esc(col) + '">'
            + '<span class="fc-av">' + img(f.image, 'vx-av', f.name) + '</span>'
            + '<span class="vx-f-main"><span class="fc-name"><b style="color:' + esc(f.trust[1]) + '">' + esc(f.name) + '</b>' + (f.favorite ? ' ⭐' : '')
            + (f.fav_group ? ' <span class="vx-vfav" title="VRChat-Favorit: ' + esc((state.fav_groups || {})[f.fav_group] || f.fav_group) + '">♥</span>' : '') + (f.pronouns ? ' <span class="vx-pron" data-nogender>' + esc(f.pronouns) + '</span>' : '') + '</span>'
            + (!compact && f.status_desc ? '<span class="fc-sd"><i>✎</i>' + esc(f.status_desc) + '</span>' : '')
            + where
            + (!compact && f.note ? '<span class="vx-f-note">📝 ' + esc(f.note.slice(0, 60)) + '</span>' : '')
            + '</span><i class="fc-dot" title="' + esc((ST[st] || ST.offline).label) + '"></i>'
            + '<span class="vx-f-side"><span class="vx-f-since" title="seit">' + esc(f.state === 'offline' ? '' : dur(f.since)) + '</span>'
            + (f.state === 'online' && joinable(l) ? '<a class="vx-join" href="' + esc(launch(l.location)) + '" title="Beitreten (öffnet VRChat)">▶</a>' : '') + '</span></div>';
    }
    function fillTrustFilter() {
        const sel = $('#friendTrust');
        if (sel.options.length > 1) return;
        TRUST_ORDER.forEach(t => sel.add(new Option(t, t)));
    }
    function renderFavChips() {
        const g = state.fav_groups || {}, box = $('#favChips');
        const used = [...new Set(state.friends.map(f => f.fav_group).filter(Boolean))];
        const lists = [...new Set(state.friends.flatMap(f => f.lists || []))].sort((a, b) => a.localeCompare(b, 'de'));
        const chips = [['', 'Alle'], ['__mine', '⭐ Meine Favoriten'], ['__watch', '🔔 Beobachtet'], ...used.map(k => [k, '♥ ' + (g[k] || k)]), ...lists.map(l => ['list:' + l, '🏷 ' + l])];
        const count = k => k === '' ? state.friends.length : k === '__mine' ? state.friends.filter(f => f.favorite).length : k === '__watch' ? state.friends.filter(f => f.watch).length
            : k.startsWith('list:') ? state.friends.filter(f => (f.lists || []).includes(k.slice(5))).length : state.friends.filter(f => f.fav_group === k).length;
        box.innerHTML = chips.filter(([k]) => k === '' || count(k) > 0).length > 1 ? chips.filter(([k]) => k === '' || count(k) > 0).map(([k, n]) => '<button type="button" class="chip' + (favFilter === k ? ' on' : '') + '" data-fav="' + esc(k) + '">' + esc(n) + ' <small>' + count(k) + '</small></button>').join('') : '';
    }
    $('#favChips').addEventListener('click', e => { const b = e.target.closest('[data-fav]'); if (!b) return; favFilter = b.dataset.fav; renderFavChips(); renderFriends(); });
    function renderFriends() {
        if (!state) return;
        const q = $('#friendSearch').value.trim().toLowerCase();
        const sort = $('#friendSort').value, pf = $('#friendPlatform').value, tr = $('#friendTrust').value;
        const off = $('#showOffline').checked || !!favFilter, notes = $('#onlyNotes').checked;
        let list = state.friends.filter(f => (!q || [f.name, f.status_desc, f.note, f.loc && f.loc.world_name, f.platform].join(' ').toLowerCase().includes(q))
            && (!pf || f.pf === pf) && (!tr || f.trust[0] === tr) && (!notes || f.note)
            && (!favFilter || (favFilter === '__mine' ? f.favorite : favFilter === '__watch' ? f.watch : favFilter.startsWith('list:') ? (f.lists || []).includes(favFilter.slice(5)) : f.fav_group === favFilter)));
        const by = {
            name: (a, b) => a.name.localeCompare(b.name, 'de'), since: (a, b) => b.since - a.since, state: (a, b) => a.name.localeCompare(b.name, 'de'),
            trust: (a, b) => TRUST_ORDER.indexOf(a.trust[0]) - TRUST_ORDER.indexOf(b.trust[0]) || a.name.localeCompare(b.name, 'de'),
            world: (a, b) => ((a.loc && a.loc.world_name) || '~').localeCompare((b.loc && b.loc.world_name) || '~', 'de') || a.name.localeCompare(b.name, 'de'),
            friend_since: (a, b) => b.friend_since - a.friend_since,
        };
        list.sort(by[sort] || by.name);
        const groups = [
            ['⭐ Favoriten', list.filter(f => f.favorite && f.state !== 'offline')],
            ['🎮 Im Spiel', list.filter(f => f.state === 'online' && !f.favorite)],
            ['💻 Website / App', list.filter(f => f.state === 'active' && !f.favorite)],
        ];
        if (off || q) groups.push(['⚫ Offline', list.filter(f => f.state === 'offline')]);
        const inst = {};
        state.friends.filter(f => f.state === 'online' && f.loc && f.loc.kind === 'world').forEach(f => { (inst[f.loc.location] = inst[f.loc.location] || []).push(f); });
        const together = Object.values(inst).filter(a => a.length > 1).sort((a, b) => b.length - a.length);
        let html = '';
        if (together.length && !q && !favFilter) {
            html += '<div class="vx-sec"><h3>👯 Zusammen unterwegs <small>' + together.length + '</small></h3><div class="vx-inst">'
                + together.map(a => '<div class="vx-inst-card">' + img(a[0].loc.world_thumb, 'vx-inst-img', '') + '<div><b><a href="#" data-world="' + esc(a[0].loc.world) + '">' + esc(a[0].loc.world_name || 'Welt') + '</a></b><small>' + esc(a[0].loc.access) + ' · ' + esc(a[0].loc.region) + ' · ' + a.length + ' Freunde</small><span>'
                    + a.map(f => '<a href="#" data-user="' + esc(f.id) + '">' + esc(f.name) + '</a>').join(', ') + '</span><span class="vx-u-btns">'
                    + (joinable(a[0].loc) ? '<a class="btn btn-sm btn-gold" href="' + esc(launch(a[0].loc.location)) + '">▶ Beitreten</a>' : '') + '<button type="button" class="btn btn-sm" data-inst="' + esc(a[0].loc.location) + '">Info</button></span></div></div>').join('') + '</div></div>';
        }
        groups.forEach(([title, items]) => {
            if (!items.length && title.startsWith('⭐')) return;
            html += '<div class="vx-sec"><h3>' + title + ' <small>' + items.length + '</small></h3>'
                + (items.length ? '<div class="vx-grid' + ($('#compactView').checked ? ' compact' : '') + '">' + items.map(friendCard).join('') + '</div>' : '<div class="vx-empty">Niemand.</div>') + '</div>';
        });
        if (!state.friends.length) html = '<div class="vx-empty">Noch keine Freunde geladen – oben auf „⟳ Aktualisieren“ klicken.</div>';
        $('#friendsBox').innerHTML = html;
    }
    const FILTERS = ['#friendSearch', '#friendSort', '#friendPlatform', '#friendTrust', '#showOffline', '#onlyNotes', '#compactView'];
    FILTERS.forEach(s => {
        const el = $(s);
        const ui = window.VRCX.ui || {}, def = { '#showOffline': !!ui['social.show_offline'], '#compactView': !!ui['ui.compact'], '#friendSort': ui['social.sort'] || el.value }[s];
        if (el.type === 'checkbox') el.checked = store.get('f' + s, def || false); else if (el.tagName === 'SELECT') el.value = store.get('f' + s, def || el.value);
        el.addEventListener('input', () => { if (el.type === 'checkbox' || el.tagName === 'SELECT') store.set('f' + s, el.type === 'checkbox' ? el.checked : el.value); renderFriends(); });
    });

    // ---------- Dialog (Benutzer / Welt / Instanz / Avatar) ----------
    const modal = $('#userModal');
    function openModal(html) { modal.hidden = false; document.body.style.overflow = 'hidden'; $('#userBody').innerHTML = html; }
    function closeModal() { modal.hidden = true; document.body.style.overflow = ''; }
    modal.addEventListener('click', e => { if (e.target === modal || e.target.closest('[data-close]')) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
    document.addEventListener('click', e => {
        if (e.target.closest('a.vx-join, a.btn[href^="vrchat:"], [data-gact], [data-nact], [data-mact], select, input, textarea, label')) return;
        const w = e.target.closest('[data-world]');
        if (w) { e.preventDefault(); openWorld(w.dataset.world); return; }
        const i = e.target.closest('[data-inst]');
        if (i) { e.preventDefault(); openInstance(i.dataset.inst); return; }
        const t = e.target.closest('[data-user]');
        if (t && !(modal.contains(t) && t.matches('button'))) { e.preventDefault(); openUser(t.dataset.user); }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('.vx-friend[data-user]')) openUser(e.target.dataset.user); });

    function openUser(id) {
        const f = friendById(id);
        openModal('<div class="vx-loading">' + (f ? esc(f.name) + ' – ' : '') + 'lade Profil …</div>');
        api('user', { id }).then(d => { remember('user', d.user.id, d.user.name); renderUser(d.user); }).catch(fail($('#userBody')));
    }
    function renderUser(u) {
        const l = u.loc || {}, w = l.world_info || {}, s = u.stats || {}, myLoc = state.me.location;
        let h = '<div class="vx-u-head">' + img(u.image, 'vx-u-img', u.name) + '<div><h2 style="color:' + esc(u.trust[1]) + '">' + esc(u.name) + '</h2>'
            + '<div class="vx-u-sub">' + statusDot(u.status) + esc(u.status_desc || (ST[u.status] || {}).label || '') + (u.pronouns ? ' · ' + esc(u.pronouns) : '') + '</div>'
            + '<div class="vx-u-tags"><span class="vx-pill" style="--pc:' + esc(u.trust[1]) + '">' + esc(u.trust[0]) + '</span>' + (u.platform ? '<span class="vx-pill">' + esc(u.platform) + '</span>' : '')
            + (u.is_friend ? '<span class="vx-pill" style="--pc:#51e57e">Freund</span>' : '') + (u.favorite ? '<span class="vx-pill rep">⭐ Favorit</span>' : '') + '</div></div></div>';
        if (l.kind === 'world') {
            h += '<div class="vx-u-loc">' + img(w.thumb, 'vx-u-world', '') + '<div><b><a href="#" data-world="' + esc(l.world) + '">' + esc((w.name) || 'Unbekannte Welt') + '</a></b><small>' + esc(l.label) + (w.author ? ' · von ' + esc(w.author) : '') + (w.capacity ? ' · max. ' + w.capacity : '') + '</small><span class="vx-u-btns">'
                + (joinable(l) ? '<a class="btn btn-sm btn-gold" href="' + esc(launch(l.location)) + '">▶ Beitreten (Spiel)</a><button type="button" class="btn btn-sm" data-act="invite_self" data-loc="' + esc(l.location) + '">📨 Selbst-Einladung</button>' : '')
                + '<button type="button" class="btn btn-sm" data-inst="' + esc(l.location) + '">ℹ Instanz</button><button type="button" class="btn btn-sm" data-act="request_invite">🙋 Einladung anfragen</button></span></div></div>';
        } else if (l.kind && l.kind !== 'offline') {
            h += '<div class="vx-u-loc"><div><b>' + esc(l.label) + '</b><span class="vx-u-btns"><button type="button" class="btn btn-sm" data-act="request_invite">🙋 Einladung anfragen</button></span></div></div>';
        }
        h += '<div class="vx-kpis sm"><div><b>' + hm(s.week || 0) + '</b><span>im Spiel (7 Tage)</span></div><div><b>' + hm(s.month || 0) + '</b><span>im Spiel (30 Tage)</span></div>'
            + '<div><b>' + (s.sessions || 0) + '</b><span>Sitzungen (30 Tage)</span></div><div><b>' + (l.kind && l.kind !== 'offline' ? 'jetzt online' : s.last_seen ? esc(ago(s.last_seen)) : '–') + '</b><span>zuletzt gesehen</span></div>'
            + '<div><b>' + (s.friend_since ? esc(new Date(s.friend_since * 1000).toLocaleDateString('de-DE')) : '–') + '</b><span>Freund seit (aufgezeichnet)</span></div></div>';
        if (s.together) h += '<div class="vx-together">🤝 <b>Mit dir zusammen:</b> ' + hm(s.together.seconds) + ' in ' + s.together.instances + ' Instanz' + (s.together.instances === 1 ? '' : 'en') + ' (30 Tage) · zuletzt ' + esc(ago(s.together.last)) + ' in <a href="#" data-world="' + esc(s.together.world) + '">' + esc(s.together.world_name) + '</a></div>';
        if (s.heat && s.heat.flat().some(v => v > 0)) {
            const DN = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
            h += '<details class="vx-uheat" open><summary>🕑 Wann ist ' + esc(u.name) + ' online? <small>(30 Tage)</small></summary>' + heatmap(s.heat, 'min')
                + '<p class="vx-small">⭐ Beste Zeit: ' + (s.best_days || []).map(d => DN[d]).join(' & ') + (s.best_hours && s.best_hours.length ? ', meist um ' + s.best_hours.slice().sort((a, b) => a - b).map(x => x + ' Uhr').join(', ') : '') + '</p>'
                + (s.buddies && s.buddies.length ? '<p class="vx-small">👯 Oft gleichzeitig online mit: ' + s.buddies.map(b => '<a href="#" data-user="' + esc(b.id) + '" title="' + esc(hm(b.seconds)) + ' gleichzeitig">' + esc(b.name) + '</a>').join(', ') + '</p>' : '') + '</details>';
        }
        if (s.last_world && l.kind !== 'world') h += '<p class="vx-small">Zuletzt gesehen in <a href="#" data-world="' + esc(s.last_world.world) + '">' + esc(s.last_world.world_name || 'Welt') + '</a> (' + esc(s.last_world.access) + ', ' + esc(ago(s.last_world.ts)) + ')</p>';
        h += '<div class="vx-u-cols"><div>';
        if (u.bio_html) h += '<h4>Bio</h4><div class="fmt vx-u-bio">' + u.bio_html + '</div>';
        if (u.links && u.links.length) h += '<h4>Links</h4><ul class="vx-u-links">' + u.links.map(x => '<li><a href="' + esc(x) + '" target="_blank" rel="noopener noreferrer nofollow">' + esc(x.replace(/^https?:\/\//, '')) + '</a></li>').join('') + '</ul>';
        const mine = new Set((loaded.groupList || []).map(g => g.id));
        if (u.groups && u.groups.length) h += '<h4>Gruppen <small>' + u.groups.filter(g => mine.has(g.id)).length + ' gemeinsam</small></h4><div class="vx-u-groups">' + u.groups.sort((a, b) => (mine.has(b.id) - mine.has(a.id)) || (b.rep - a.rep)).map(g => '<button type="button" class="vx-pill' + (g.rep ? ' rep' : '') + (mine.has(g.id) ? ' mutual' : '') + '" data-opengroup="' + esc(g.id) + '" title="' + g.members + ' Mitglieder' + (mine.has(g.id) ? ' · du bist auch Mitglied' : '') + '">' + (g.rep ? '★ ' : '') + (mine.has(g.id) ? '🤝 ' : '') + esc(g.name) + '</button>').join('') + '</div>';
        h += '<h4>🤝 Gemeinsam</h4><div id="uMutual" class="vx-umutual"><div class="vx-loading">Lade gemeinsame Freunde &amp; Gruppen …</div></div>';
        h += '<div id="uModSec"><h4>🛡️ Gruppen-Moderation <small>(Gruppen, in denen du mindestens kicken darfst)</small></h4><div id="uMod" class="vx-umods" data-user="' + esc(u.id) + '" data-name="' + esc(u.name) + '"><div class="vx-loading">Prüfe deine Gruppen …</div></div></div>';
        h += '<dl class="vx-u-facts">' + (u.joined ? '<dt>Dabei seit</dt><dd>' + esc(isoDate(u.joined)) + '</dd>' : '') + (u.last_login ? '<dt>Letzter Login</dt><dd>' + esc(isoDT(u.last_login)) + '</dd>' : '')
            + (u.last_activity ? '<dt>Zuletzt aktiv</dt><dd>' + esc(isoDT(u.last_activity)) + '</dd>' : '') + '<dt>VRChat-ID</dt><dd><code class="vx-id">' + esc(u.id) + '</code> <button type="button" class="vx-copy" data-copy="' + esc(u.id) + '" title="Kopieren">⧉</button></dd></dl>';
        h += '</div><div><h4>Meine Notiz <small>(nur für dich)</small></h4><form class="vx-note-form"><textarea name="note" rows="5" maxlength="4000" placeholder="z. B. kennengelernt auf der EF, mag Kaffee …">' + esc(u.note) + '</textarea>'
            + '<label class="vx-auto"><input type="checkbox" name="favorite" value="1"' + (u.favorite ? ' checked' : '') + '> ⭐ Favorit (oben in der Liste, eigene Benachrichtigungen)</label>'
            + '<label class="vx-auto"><input type="checkbox" name="watch" value="1"' + (u.watch ? ' checked' : '') + '> 🔔 Benachrichtigen, sobald online (auch wenn Benachrichtigungen sonst aus sind)</label>'
            + '<label class="field">Eigene Listen (mit Komma trennen)</label><input type="text" name="lists" maxlength="200" value="' + esc(u.lists || '') + '" placeholder="z. B. Stammtisch, Discord, EF-Gruppe" list="listNames">'
            + '<datalist id="listNames">' + [...new Set(state.friends.flatMap(f => f.lists || []))].map(n => '<option value="' + esc(n) + '">').join('') + '</datalist>'
            + '<label class="vx-auto"><input type="checkbox" name="remote" value="1"> auch als VRChat-Notiz speichern (max. 256 Zeichen)</label><button class="btn btn-sm btn-gold">Speichern</button></form>'
            + '<h4>Verlauf</h4><div class="vx-u-feed" id="uFeed">Lade …</div></div></div>';
        h += '<div class="vx-u-actions"><a class="btn btn-sm" href="https://vrchat.com/home/user/' + encodeURIComponent(u.id) + '" target="_blank" rel="noopener noreferrer">↗ vrchat.com</a>'
            + (myLoc && u.is_friend ? '<button type="button" class="btn btn-sm btn-gold" data-act="invite" title="In deine aktuelle Instanz einladen">📨 Zu mir einladen</button>' : '')
            + '<button type="button" class="btn btn-sm" data-mod="mute">🔇 Stummschalten</button><button type="button" class="btn btn-sm" data-mod="hideAvatar">🙈 Avatar ausblenden</button><button type="button" class="btn btn-sm btn-danger" data-mod="block">⛔ Blockieren</button>'
            + (u.is_friend ? '<button type="button" class="btn btn-sm btn-danger" data-act="unfriend">Freundschaft beenden</button>' : '<button type="button" class="btn btn-sm btn-gold" data-act="friend_request">＋ Freundschaftsanfrage</button>') + '</div>';
        const body = $('#userBody');
        body.innerHTML = h;
        favPicker('friend', u.id, { name: u.name, image: u.image, sub: u.status_desc || '' }, u.is_friend);
        body.querySelector('.vx-note-form').addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target;
            api('note_save', { id: u.id, note: f.note.value, favorite: f.favorite.checked ? 1 : '', remote: f.remote.checked ? 1 : '', lists: f.lists.value, watch: f.watch.checked ? 1 : '' }).then(d => {
                toast(d.msg || 'Notiz gespeichert.');
                const fr = friendById(u.id); if (fr) { fr.note = f.note.value; fr.favorite = f.favorite.checked; fr.watch = f.watch.checked; fr.lists = f.lists.value.split(',').map(x => x.trim()).filter(Boolean); renderFriends(); renderFavChips(); }
            }).catch(x => toast(x.message, true));
        });
        body.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => {
            const act = b.dataset.act;
            if (act === 'unfriend' && !confirm('Freundschaft mit „' + u.name + '“ wirklich beenden? Das lässt sich nur mit einer neuen Anfrage rückgängig machen.')) return;
            b.disabled = true;
            api(act, { id: u.id, location: b.dataset.loc || '' }).then(d => { toast(d.msg || 'Erledigt.'); if (act === 'unfriend') { closeModal(); load(true, true); } })
                .catch(x => toast(x.message, true)).finally(() => { b.disabled = false; });
        }));
        body.querySelectorAll('[data-mod]').forEach(b => b.addEventListener('click', () => {
            const t = b.dataset.mod, label = { block: 'blockieren', mute: 'stummschalten', hideAvatar: 'den Avatar ausblenden' }[t];
            if (!confirm('„' + u.name + '“ in VRChat ' + label + '?' + (t === 'block' ? ' Blockieren beendet auch eine Freundschaft.' : ''))) return;
            api('moderate', { user: u.id, type: t }).then(d => { toast(d.msg || 'Erledigt.'); loaded.mod = false; }).catch(x => toast(x.message, true));
        }));
        if (!loaded.groupList) api('groups').then(d => { loaded.groupList = d.groups; }).catch(() => {});
        loadUserMod(u);
        loadUserMutual(u);
        if (window.NexusApp && window.NexusApp.userExtra) window.NexusApp.userExtra(u);
        api('feed', { friend: u.id }).then(d => { $('#uFeed').innerHTML = d.items.length ? d.items.slice(0, 30).map(feedRow).join('') : '<div class="vx-empty">Noch nichts aufgezeichnet.</div>'; })
            .catch(() => { $('#uFeed').textContent = '–'; });
    }
    document.addEventListener('click', e => {
        const c = e.target.closest('[data-copy]');
        if (c) { navigator.clipboard && navigator.clipboard.writeText(c.dataset.copy).then(() => toast('Kopiert.')); return; }
        const g = e.target.closest('[data-opengroup]');
        if (g) { closeModal(); tab('groups'); openGroup(g.dataset.opengroup); }
    });

    // ---------- Welten & Instanzen ----------
    const worldCard = w => '<button type="button" class="vx-card" data-world="' + esc(w.id) + '">' + img(w.thumb, 'vx-card-img', w.name) + '<span class="vx-card-body"><b>' + esc(w.name) + '</b><small>'
        + esc(w.author ? 'von ' + w.author : '') + '</small><span class="vx-card-meta">' + (w.friends ? '<span title="Freunde dort">👥 ' + w.friends + '</span>' : '') + (w.occupants ? '<span title="Gerade drin">🟢 ' + num(w.occupants) + '</span>' : '')
        + (w.favorites ? '<span title="Favoriten">⭐ ' + num(w.favorites) + '</span>' : '') + (w.capacity ? '<span title="Kapazität">👤 ' + w.capacity + '</span>' : '') + (w.status && w.status !== 'public' ? '<span>' + esc(w.status) + '</span>' : '') + '</span></span></button>';
    let worldKind = 'favorites';
    function loadWorlds(kind, q) {
        if (kind === 'roulette') { roulette(); return; }
        worldKind = kind;
        loaded.worlds = true;
        $$('#worldKinds .chip').forEach(c => c.classList.toggle('on', c.dataset.kind === kind));
        const box = $('#worldsBox');
        const instCard = (loc, name, sub, thumb, extra) => '<div class="vx-inst-card">' + img(thumb, 'vx-inst-img', '') + '<div><b><a href="#" data-inst="' + esc(loc) + '">' + esc(name) + '</a></b><small>' + esc(sub) + '</small><span class="vx-u-btns">'
            + '<a class="btn btn-sm btn-gold" href="' + esc(launch(loc)) + '">▶</a><button type="button" class="btn btn-sm" data-self="' + esc(loc) + '" title="Selbst-Einladung">📨</button><button type="button" class="btn btn-sm" data-link="' + esc(loc) + '" title="Link kopieren">🔗</button>' + (extra || '') + '</span></div></div>';
        if (kind === 'bookmarks') {
            box.innerHTML = bookmarks.length ? '<div class="vx-inst vx-wide">' + bookmarks.map(b => { const l = parseRef(b.location) ? b.location : ''; const wl = (state.friends.find(f => f.loc && f.loc.world === l.split(':')[0]) || {}).loc || {};
                return instCard(b.location, b.name, 'gemerkt ' + ago(b.ts), wl.world_thumb, '<button type="button" class="btn btn-sm btn-danger" data-bm="' + esc(b.location) + '" title="Aus der Merkliste">✕</button>'); }).join('') + '</div>'
                : '<div class="vx-empty">Noch nichts gemerkt – im Instanz-Dialog auf „🔖 Merken“ klicken.</div>';
            return;
        }
        if (kind === 'recentinst') {
            loading(box, 'Lade deine letzten Instanzen …');
            api('recent_instances').then(d => {
                box.innerHTML = d.items.length ? '<div class="vx-inst vx-wide">' + d.items.map(i => instCard(i.location, i.world_name, i.loc.access + ' · ' + i.loc.region + ' · ' + ago(i.ts) + (i.visits > 1 ? ' · ' + i.visits + '×' : ''), i.thumb,
                    '<button type="button" class="btn btn-sm" data-bm="' + esc(i.location) + '" data-name="' + esc(i.world_name + ' #' + i.loc.name) + '" title="Merken">🔖</button>')).join('') + '</div>'
                    : '<div class="vx-empty">Noch keine eigenen Orte aufgezeichnet – das passiert, während du im Spiel bist und diese Seite offen ist.</div>';
            }).catch(fail(box));
            return;
        }
        if (kind === 'friends') {
            const m = {};
            state.friends.filter(f => f.state === 'online' && f.loc && f.loc.world).forEach(f => { const w = m[f.loc.world] = m[f.loc.world] || { id: f.loc.world, name: f.loc.world_name || 'Welt', thumb: f.loc.world_thumb, friends: 0 }; w.friends++; });
            const list = Object.values(m).sort((a, b) => b.friends - a.friends);
            box.innerHTML = list.length ? list.map(worldCard).join('') : '<div class="vx-empty">Gerade ist kein Freund in einer öffentlich sichtbaren Welt.</div>';
            return;
        }
        loading(box, 'Lade Welten …');
        api('worlds', { kind, q: q || '' }).then(d => { box.innerHTML = d.worlds.length ? d.worlds.map(worldCard).join('') : '<div class="vx-empty">Keine Welten.</div>'; loaded.worldList = d.worlds; }).catch(fail(box));
    }
    $('#worldKinds').addEventListener('click', e => { const c = e.target.closest('[data-kind]'); if (c) loadWorlds(c.dataset.kind); });
    $('#worldSearch').addEventListener('submit', e => { e.preventDefault(); $$('#worldKinds .chip').forEach(c => c.classList.remove('on')); loadWorlds('search', e.target.q.value); });

    function openWorld(id) {
        openModal('<div class="vx-loading">Lade Welt …</div>');
        api('world', { id }).then(d => { remember('world', d.world.id, d.world.name); renderWorld(d.world); }).catch(fail($('#userBody')));
    }
    function renderWorld(w) {
        const favEntry = (loaded.worldList || []).find(x => x.id === w.id && x.fav_id);
        const friendsHere = state.friends.filter(f => f.state === 'online' && f.loc && f.loc.world === w.id);
        const groups = loaded.groupList || [];
        let h = (/^https:/.test(w.image) ? '<div class="vx-w-hero" style="background-image:url(\'' + esc(w.image.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"></div>' : '')
            + '<div class="vx-w-head"><div><h2>' + esc(w.name) + '</h2><div class="vx-u-sub">von <a href="#" data-user="' + esc(w.author_id) + '">' + esc(w.author) + '</a>' + (w.status && w.status !== 'public' ? ' · ' + esc(w.status) : '') + '</div>'
            + '<div class="vx-u-tags">' + w.platforms.map(p => '<span class="vx-pill">' + esc(p) + '</span>').join('') + w.tags.slice(0, 10).map(t => '<span class="vx-pill" style="--pc:#bd93f9">#' + esc(t) + '</span>').join('') + '</div></div>'
            + '<span class="vx-u-btns"><button type="button" class="btn btn-sm ' + (favEntry ? '' : 'btn-gold') + '" id="wFav">' + (favEntry ? '★ Favorit entfernen' : '☆ Favorisieren') + '</button><a class="btn btn-sm" href="https://vrchat.com/home/world/' + encodeURIComponent(w.id) + '" target="_blank" rel="noopener noreferrer">↗ vrchat.com</a></span></div>';
        h += '<div class="vx-kpis sm"><div><b>' + num(w.occupants) + '</b><span>gerade drin (' + num(w.public) + ' öffentl.)</span></div><div><b>' + num(w.visits) + '</b><span>Besuche</span></div><div><b>' + num(w.favorites) + '</b><span>Favoriten</span></div>'
            + '<div><b>' + w.capacity + (w.recommended ? ' / ' + w.recommended : '') + '</b><span>Kapazität / empfohlen</span></div><div><b>' + esc(isoDate(w.updated)) + '</b><span>aktualisiert</span></div></div>';
        h += '<div class="vx-u-cols"><div>' + (w.desc_html ? '<h4>Beschreibung</h4><div class="fmt vx-u-bio">' + w.desc_html + '</div>' : '')
            + (friendsHere.length ? '<h4>Freunde hier</h4><div>' + friendsHere.map(f => '<a href="#" class="vx-pill" data-user="' + esc(f.id) + '">' + esc(f.name) + ' · ' + esc(f.loc.access) + '</a>').join('') + '</div>' : '')
            + '<h4>Öffentliche Instanzen</h4>' + (w.instances.length ? '<div class="vx-members">' + w.instances.map(i => '<div class="vx-member"><span>#' + esc(i.loc.name) + '</span><small>' + esc(i.loc.access + ' · ' + i.loc.region) + ' · ' + i.users + ' Personen</small><span class="vx-m-acts">'
                + '<a class="btn btn-sm btn-gold" href="' + esc(launch(i.loc.location)) + '">▶</a><button type="button" class="btn btn-sm" data-self="' + esc(i.loc.location) + '">📨</button><button type="button" class="btn btn-sm" data-inst="' + esc(i.loc.location) + '">ℹ</button></span></div>').join('') + '</div>' : '<div class="vx-empty">Keine öffentlichen Instanzen offen.</div>')
            + '</div><div><h4>Neue Instanz erstellen</h4><form class="vx-newinst" id="newInst">'
            + '<label class="field">Art</label><select name="type"><option value="friends+">Friends+</option><option value="friends">Friends</option><option value="invite+">Invite+</option><option value="invite">Invite</option><option value="public">Public</option>' + (groups.length ? '<option value="group">Gruppe</option>' : '') + '</select>'
            + '<div class="vx-ni-group" hidden><label class="field">Gruppe</label><select name="group">' + groups.map(g => '<option value="' + esc(g.id) + '">' + esc(g.name) + '</option>').join('') + '</select>'
            + '<label class="field">Zugang</label><select name="access"><option value="members">Nur Mitglieder</option><option value="plus">Group+ (mit Freunden)</option><option value="public">Öffentlich</option></select></div>'
            + '<label class="field">Region</label><select name="region"><option value="eu">🇪🇺 Europa</option><option value="us">🇺🇸 USA West</option><option value="use">🇺🇸 USA Ost</option><option value="jp">🇯🇵 Japan</option></select>'
            + '<label class="vx-auto"><input type="checkbox" name="invite_me" checked> mir sofort eine Einladung schicken</label><button class="btn btn-gold" style="margin-top:10px">＋ Instanz erstellen</button>'
            + (groups.length ? '' : '<p class="vx-small">Für Gruppen-Instanzen einmal den Reiter „Gruppen“ öffnen.</p>') + '</form></div></div>';
        $('#userBody').innerHTML = h;
        favPicker('world', w.id, { name: w.name, image: w.image, sub: w.author }, true);
        const f = $('#newInst');
        f.type.addEventListener('change', () => { $('.vx-ni-group', f).hidden = f.type.value !== 'group'; });
        f.addEventListener('submit', e => {
            e.preventDefault();
            const b = f.querySelector('button'); b.disabled = true;
            api('instance_create', { world: w.id, type: f.type.value, region: f.region.value, group: f.group ? f.group.value : '', access: f.access ? f.access.value : '', invite_me: f.invite_me.checked ? 1 : '' })
                .then(d => { toast(d.msg); }).catch(x => toast(x.message, true)).finally(() => { b.disabled = false; });
        });
        $('#wFav').addEventListener('click', () => api('world_fav', { id: w.id, on: favEntry ? '' : 1, fav_id: favEntry ? favEntry.fav_id : '' }).then(d => { toast(d.msg); if (worldKind === 'favorites') loadWorlds('favorites'); }).catch(x => toast(x.message, true)));
    }
    function openInstance(loc) {
        openModal('<div class="vx-loading">Lade Instanz …</div>');
        api('instance', { location: loc }).then(d => {
            const i = d.instance, l = i.loc;
            remember('instance', i.location, (l.access || 'Instanz') + ' #' + (l.name || ''));
            const pct = i.capacity ? Math.min(100, Math.round(i.users / i.capacity * 100)) : 0;
            $('#userBody').innerHTML = '<h2>Instanz #' + esc(l.name || '') + '</h2><div class="vx-u-sub">' + esc(l.access) + ' · ' + esc(l.region) + (i.name ? ' · ' + esc(i.name) : '') + ' · <a href="#" data-world="' + esc(l.world) + '">Welt ansehen</a></div>'
                + '<div class="vx-kpis sm"><div><b>' + i.users + ' / ' + (i.capacity || '?') + '</b><span>Personen</span></div><div><b>' + i.pc + '</b><span>💻 PC</span></div><div><b>' + i.quest + '</b><span>📱 Quest</span></div>'
                + (i.ios ? '<div><b>' + i.ios + '</b><span>🍏 iOS</span></div>' : '') + (i.queue ? '<div><b>' + i.queue + '</b><span>in der Warteschlange</span></div>' : '') + '</div>'
                + '<div class="vx-cap"><div style="width:' + pct + '%"></div></div>' + (i.full ? '<p class="vx-error">Die Instanz ist voll.</p>' : '') + (i.closed ? '<p class="vx-error">Die Instanz wurde geschlossen.</p>' : '')
                + (i.friends.length ? '<h4>Deine Freunde hier</h4><div class="vx-members">' + i.friends.map(f => '<div class="vx-member">' + img(f.image, 'vx-av sm', f.name) + '<a href="#" data-user="' + esc(f.id) + '">' + esc(f.name) + '</a></div>').join('') + '</div>' : '')
                + '<div class="vx-u-actions">' + (joinable(l) ? '<a class="btn btn-sm btn-gold" href="' + esc(launch(i.location)) + '">▶ Beitreten (Spiel)</a><button type="button" class="btn btn-sm" data-self="' + esc(i.location) + '">📨 Selbst-Einladung</button>' : '')
                + '<button type="button" class="btn btn-sm" data-copy="' + esc(i.location) + '">⧉ Instanz-ID</button><button type="button" class="btn btn-sm" data-link="' + esc(i.location) + '">🔗 Einladungslink</button>'
                + '<button type="button" class="btn btn-sm" data-bm="' + esc(i.location) + '" data-name="' + esc((i.name || l.access) + ' #' + (l.name || '')) + '">🔖 Merken</button>'
                + (state.me.location === i.location ? '<button type="button" class="btn btn-sm" data-party="' + esc(i.location) + '">👥 Freunde einladen</button>' : '')
                + (window.VRCX.discord ? '<button type="button" class="btn btn-sm" data-share="' + esc(i.location) + '">📣 In Discord teilen</button>' : '') + '</div>';
        }).catch(fail($('#userBody')));
    }
    document.addEventListener('click', e => {
        const s = e.target.closest('[data-self]');
        if (s) { e.preventDefault(); api('invite_self', { location: s.dataset.self }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); }
    });

    // ---------- Avatare ----------
    let avatarList = [], avatarKind = 'mine', avatarGroups = {};
    const PF_ORDER = { 'PC': 0, 'Quest / Android': 1, 'iOS': 2 };
    function loadAvatars(kind) {
        loaded.avatars = true;
        avatarKind = kind;
        $$('#avatarKinds .chip').forEach(c => c.classList.toggle('on', c.dataset.kind === kind));
        $('#avatarSort').querySelector('[value=group]').disabled = kind !== 'favorites';
        if (kind === 'favorites' && !store.get('avSortSet', false)) $('#avatarSort').value = 'group';
        if (kind !== 'favorites' && $('#avatarSort').value === 'group') $('#avatarSort').value = 'updated';
        const box = $('#avatarsBox');
        $('#avDbForm').hidden = kind !== 'search';
        if (kind === 'search') {
            avatarList = [];
            if (['group', 'updated'].includes($('#avatarSort').value)) $('#avatarSort').value = 'vrchat';
            if (!avProviders) api('avatar_providers').then(d => { avProviders = d.providers; avCanEdit = d.can_edit; fillProviders(); }).catch(x => toast(x.message, true));
            box.innerHTML = '<div class="vx-empty">🔎 Durchsucht öffentliche Avatare über eine Avatar-Datenbank (z. B. avtrdb). Gefundene Avatare kannst du direkt anziehen oder favorisieren.</div>';
            $('#avatarCount').textContent = '';
            $('#avDbForm [name=q]').focus();
            return;
        }
        loading(box, 'Lade Avatare …');
        api('avatars', { kind }).then(d => { avatarList = d.avatars.map((a, i) => Object.assign(a, { _i: i })); avatarGroups = d.fav_groups || {}; renderAvatars(); }).catch(fail(box));
    }
    let avProviders = null, avCanEdit = false;
    function fillProviders() {
        $('#avDbProv').innerHTML = avProviders.map((u, i) => { let h = u; try { h = new URL(u).hostname; } catch (e) { /* egal */ } return '<option value="' + i + '">' + esc(h) + '</option>'; }).join('');
        $('#avDbEdit').hidden = !avCanEdit;
    }
    $('#avDbForm').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target, box = $('#avatarsBox');
        loading(box, 'Suche bei ' + ($('#avDbProv').selectedOptions[0] || {}).textContent + ' …');
        // Zuerst direkt aus dem Browser (deine eigene Verbindung – manche Datenbanken sperren Webhosting-Server), sonst über den Server
        avDbBrowser(avProviders[f.provider.value], f.q.value).catch(be => api('avatar_db', { q: f.q.value, provider: f.provider.value })
            .catch(se => { throw new Error('Direkt aus dem Browser: ' + (be.message === 'Failed to fetch' ? 'blockiert oder nicht erreichbar' : be.message) + ' · Über den Server: ' + se.message + ' – die Datenbank lässt deinen Webserver offenbar nicht zu; probier es später noch einmal oder trage über ⚙ einen anderen Provider ein.'); })).then(d => {
            avatarList = d.avatars.map((a, i) => Object.assign(a, { _i: i }));
            if (!avatarList.length) { box.innerHTML = '<div class="vx-empty">Nichts gefunden.</div>'; $('#avatarCount').textContent = '0 Treffer'; return; }
            renderAvatars();
        }).catch(fail(box));
    });
    function avDbBrowser(provider, q) {
        q = String(q || '').trim();
        if (!provider || !/^https:\/\//.test(provider) || q.length < 2) return Promise.reject(new Error('ungültig'));
        const m = q.match(/(usr_[0-9a-f-]{36})/i), m2 = q.match(/(avtr_[0-9a-f-]{36})/i);
        const params = new URLSearchParams(m ? { authorId: m[1], n: 500 } : m2 ? { search: m2[1], n: 50 } : { search: q.slice(0, 100), n: 500 });
        const ctl = new AbortController();
        const timer = setTimeout(() => ctl.abort(), 20000);
        return fetch(provider + '?' + params, { mode: 'cors', credentials: 'omit', referrerPolicy: 'no-referrer', signal: ctl.signal, headers: { Accept: 'application/json' } }).then(r => {
            clearTimeout(timer);
            if (r.status === 404) return [];
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(data => {
            if (!Array.isArray(data)) throw new Error('kein Ergebnis');
            const https = u => typeof u === 'string' && /^https:\/\//.test(u) ? u : '';
            const cur = state && state.me ? state.me.avatar : '';
            const rated = r => r && r !== 'None';
            return { avatars: data.slice(0, 500).filter(a => a && /^avtr_[0-9a-f-]{36}$/i.test(a.id || '')).map(a => {
                const p = a.performance || {};
                return { id: a.id, name: String(a.name || '').slice(0, 120), author: String(a.authorName || '').slice(0, 64), author_id: /^usr_[0-9a-f-]{36}$/i.test(a.authorId || '') ? a.authorId : '',
                         thumb: https(a.thumbnailImageUrl) || https(a.imageUrl), desc: String(a.description || '').slice(0, 300), status: String(a.releaseStatus || ''), updated: String(a.updated_at || ''), created: String(a.created_at || ''),
                         platforms: [rated(p.pc_rating) && 'PC', rated(p.android_rating) && 'Quest / Android', rated(p.ios_rating) && 'iOS'].filter(Boolean),
                         perf: Object.fromEntries([['PC', p.pc_rating], ['Quest', p.android_rating], ['iOS', p.ios_rating]].filter(x => x[1])), current: a.id === cur };
            }) };
        });
    }
    $('#avDbEdit').addEventListener('click', () => {
        const list = prompt('Avatar-Datenbanken (VRCX-kompatibel), eine https-Adresse pro Zeile bzw. mit Leerzeichen getrennt:', avProviders.join(' '));
        if (list === null) return;
        api('avatar_providers', { save: 1, list }).then(d => { avProviders = d.providers; fillProviders(); toast('Provider gespeichert.'); }).catch(x => toast(x.message, true));
    });
    const groupName = k => avatarGroups[k] || (k ? 'Gruppe ' + k.replace(/\D/g, '') : 'Ohne Gruppe');
    function avatarCard(a) {
        const groups = Object.keys(avatarGroups).length ? Object.keys(avatarGroups) : ['avatars1', 'avatars2', 'avatars3', 'avatars4'];
        return '<div class="vx-card' + (a.current ? ' current' : '') + '" data-avatar-open="' + esc(a.id) + '" role="button" tabindex="0">' + img(a.thumb, 'vx-card-img', a.name) + '<span class="vx-card-body"><b>' + esc(a.name) + (a.current ? ' <span class="vx-pill rep">trägst du</span>' : '') + '</b>'
            + '<small>' + esc(a.author ? 'von ' + a.author : '') + (a.status ? ' · ' + esc(a.status) : '') + (a.updated ? ' · ' + esc(isoDate(a.updated)) : '') + '</small><span class="vx-card-meta">' + a.platforms.map(p => '<span>' + esc(p) + '</span>').join('') + '</span>'
            + (a.perf && Object.keys(a.perf).length ? '<span class="vx-card-meta avdb-perf">' + Object.entries(a.perf).map(([p, r]) => '<span class="perf-' + esc(String(r).toLowerCase().replace(/\s+/g, '')) + '" title="Leistung ' + esc(p) + '">' + esc(p + ': ' + r) + '</span>').join('') + '</span>' : '')
            + '<span class="vx-card-acts">' + (a.current ? '' : '<button type="button" class="btn btn-sm btn-gold" data-avatar="' + esc(a.id) + '">Anziehen</button>')
            + (avatarKind === 'search' ? '<button type="button" class="btn btn-sm" data-avfav="' + esc(a.id) + '" title="Zu den VRChat-Favoriten">⭐</button>' + (a.author_id ? '<button type="button" class="btn btn-sm" data-avauthor="' + esc(a.author_id) + '" title="Weitere Avatare dieses Autors suchen">👤</button>' : '') : '')
            + (avatarKind === 'favorites' ? '<select class="vx-avgrp" data-avmove="' + esc(a.id) + '" data-favid="' + esc(a.fav_id || '') + '" title="In Favoritengruppe verschieben">' + groups.map(g => '<option value="' + esc(g) + '"' + (g === a.fav_group ? ' selected' : '') + '>' + esc(groupName(g)) + '</option>').join('') + '</select>' : '')
            + '</span></span></div>';
    }
    function renderAvatars() {
        const q = $('#avatarSearch').value.trim().toLowerCase(), sort = $('#avatarSort').value, pf = $('#avatarPf').value;
        let list = avatarList.filter(a => (!q || (a.name + ' ' + a.author + ' ' + a.desc).toLowerCase().includes(q)) && (!pf || (pf === 'cross' ? a.platforms.length > 1 : a.platforms.includes(pf))));
        const by = {
            updated: (a, b) => String(b.updated).localeCompare(String(a.updated)), name: (a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }),
            author: (a, b) => a.author.localeCompare(b.author, 'de') || a.name.localeCompare(b.name, 'de'), created: (a, b) => String(b.created).localeCompare(String(a.created)),
            platform: (a, b) => (b.platforms.length - a.platforms.length) || ((PF_ORDER[a.platforms[0]] ?? 9) - (PF_ORDER[b.platforms[0]] ?? 9)) || a.name.localeCompare(b.name, 'de'),
            vrchat: (a, b) => a._i - b._i, group: (a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }),
        };
        list.sort(by[sort] || by.updated);
        if (!list.length) { $('#avatarsBox').innerHTML = '<div class="vx-empty">Keine Avatare.</div>'; return; }
        if (sort === 'group' && avatarKind === 'favorites') {
            const g = {};
            list.forEach(a => { (g[a.fav_group] = g[a.fav_group] || []).push(a); });
            $('#avatarsBox').innerHTML = Object.keys(g).sort().map(k => '<div class="vx-avsec"><h3>⭐ ' + esc(groupName(k)) + ' <small>' + g[k].length + '</small></h3><div class="vx-cards">' + g[k].map(avatarCard).join('') + '</div></div>').join('');
        } else $('#avatarsBox').innerHTML = '<div class="vx-cards">' + list.map(avatarCard).join('') + '</div>';
        $('#avatarCount').textContent = list.length + ' von ' + avatarList.length;
    }
    $('#avatarKinds').addEventListener('click', e => { const c = e.target.closest('[data-kind]'); if (c) loadAvatars(c.dataset.kind); });
    $('#avatarSearch').addEventListener('input', renderAvatars);
    $('#avatarSort').value = store.get('avSort', 'updated');
    $('#avatarSort').addEventListener('change', e => { store.set('avSort', e.target.value); store.set('avSortSet', true); renderAvatars(); });
    $('#avatarPf').addEventListener('change', renderAvatars);
    $('#avatarsBox').addEventListener('change', e => {
        const s = e.target.closest('[data-avmove]');
        if (!s) return;
        api('avatar_fav', { id: s.dataset.avmove, move: 1, group: s.value, fav_id: s.dataset.favid }).then(r => { toast(r.msg); const a = avatarList.find(x => x.id === s.dataset.avmove); if (a) a.fav_group = s.value; renderAvatars(); }).catch(x => toast(x.message, true));
    });
    $('#avatarRandom').addEventListener('click', () => {
        const q = $('#avatarSearch').value.trim().toLowerCase(), pf = $('#avatarPf').value;
        const pool = avatarList.filter(a => !a.current && (!q || (a.name + ' ' + a.author + ' ' + a.desc).toLowerCase().includes(q)) && (!pf || (pf === 'cross' ? a.platforms.length > 1 : a.platforms.includes(pf))));
        if (!pool.length) { toast('Keine passenden Avatare zum Auswürfeln.', true); return; }
        const a = pool[Math.floor(Math.random() * pool.length)];
        const card = $('[data-avatar-open="' + CSS.escape(a.id) + '"]');
        if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); card.classList.add('vx-pick'); setTimeout(() => card.classList.remove('vx-pick'), 2500); }
        setTimeout(() => {
            if (!confirm('🎲 Zufalls-Avatar: „' + a.name + '“' + (a.author ? ' von ' + a.author : '') + ' anziehen?')) return;
            api('avatar_select', { id: a.id }).then(d => { toast(d.msg); avatarList.forEach(x => { x.current = x.id === a.id; }); renderAvatars(); load(false); }).catch(x => toast(x.message, true));
        }, 450);
    });
    $('#avatarsBox').addEventListener('click', e => {
        const fv = e.target.closest('[data-avfav]');
        if (fv) { e.stopImmediatePropagation(); fv.disabled = true; api('avatar_fav', { id: fv.dataset.avfav, on: 1 }).then(r => { toast(r.msg); fv.textContent = '★'; }).catch(x => { toast(x.message, true); fv.disabled = false; }); return; }
        const au = e.target.closest('[data-avauthor]');
        if (au) { e.stopImmediatePropagation(); $('#avDbForm [name=q]').value = au.dataset.avauthor; $('#avDbForm').requestSubmit(); return; }
        const b = e.target.closest('[data-avatar]');
        if (!b) return;
        b.disabled = true;
        api('avatar_select', { id: b.dataset.avatar }).then(d => { toast(d.msg); avatarList.forEach(a => { a.current = a.id === b.dataset.avatar; }); renderAvatars(); load(false); })
            .catch(x => { toast(x.message, true); b.disabled = false; });
    });

    // ---------- Verlauf ----------
    const FEED = { online: ['🟢', 'ist ins Spiel gekommen'], offline: ['⚫', 'ist offline gegangen'], active: ['💻', 'ist auf Website / App aktiv'], gps: ['🌐', 'ist gewechselt nach'],
                   status: ['💬', 'hat den Status geändert'], avatar: ['🎭', 'hat den Avatar gewechselt'], name: ['🏷️', 'hat sich umbenannt'], bio: ['📄', 'hat die Bio geändert'],
                   friend_add: ['🤝', 'ist jetzt dein Freund'], friend_remove: ['💔', 'ist nicht mehr in deiner Freundesliste'], trust: ['🛡️', 'hat eine neue Vertrauensstufe'], self: ['🧭', 'warst du in'] };
    const locTxt = l => !l ? '' : l.kind === 'world' ? '<a href="#" data-world="' + esc(l.world) + '"><b>' + esc(l.world_name || 'Welt') + '</b></a> <small>' + esc(l.access) + ' · ' + esc(l.region) + '</small>' : esc(l.label || '');
    function feedRow(it) {
        const [ic, txt] = FEED[it.type] || ['•', it.type];
        let extra = '';
        if (it.type === 'gps' || (it.type === 'online' && it.new_loc)) extra = locTxt(it.new_loc);
        if (it.type === 'offline' && it.old_loc) extra = '<small>zuletzt in ' + locTxt(it.old_loc) + '</small>';
        if (it.type === 'status') { const [os, od] = it.old.split('|'), [ns, nd] = it.new.split('|'); extra = statusDot(os) + esc(od || (ST[os] || {}).label || os) + ' → ' + statusDot(ns) + esc(nd || (ST[ns] || {}).label || ns); }
        if (it.type === 'name' || it.type === 'trust') extra = esc(it.old) + ' → <b>' + esc(it.new) + '</b>';
        if (it.type === 'avatar') extra = img(it.new, 'vx-feed-av', 'neuer Avatar');
        if (it.type === 'bio') extra = '<small>' + esc(it.new.slice(0, 120)) + (it.new.length > 120 ? ' …' : '') + '</small>';
        if (it.type === 'self') extra = it.new_loc ? locTxt(it.new_loc) : esc(it.new === 'offline' ? 'offline' : it.new);
        const who = it.type === 'self' ? '<b>Du</b>' : '<a href="#" data-user="' + esc(it.vrc_id) + '">' + esc(it.name) + '</a>';
        return '<div class="vx-feed-row t-' + esc(it.type) + '"><time title="' + esc(new Date(it.ts * 1000).toLocaleString('de-DE')) + '">' + esc(clock(it.ts)) + '</time><span class="vx-feed-ic">' + ic + '</span>'
            + '<span class="vx-feed-txt">' + who + ' ' + txt + (extra ? ' <span class="vx-feed-x">' + extra + '</span>' : '') + '</span></div>';
    }
    let feedTimer = null;
    function loadFeed(reset) {
        const params = { q: $('#feedSearch').value, type: $('#feedType').value, favorites: $('#feedFav').checked ? 1 : '' };
        if (!reset && feedItems.length) params.before = feedItems[feedItems.length - 1].id;
        api('feed', params).then(d => {
            feedItems = reset ? d.items : feedItems.concat(d.items);
            let last = '', html = '';
            feedItems.forEach(it => {
                const day = new Date(it.ts * 1000).toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long' });
                if (day !== last) { html += '<div class="vx-day">' + esc(day) + '</div>'; last = day; }
                html += feedRow(it);
            });
            $('#feedBox').innerHTML = feedItems.length ? html : '<div class="vx-empty">Noch keine Ereignisse. Der Verlauf füllt sich, solange diese Seite offen ist und abgleicht.</div>';
            $('#feedMore').hidden = d.items.length < 100;
        }).catch(x => showErr($('#globalErr'), x.message));
    }
    $('#feedSearch').addEventListener('input', () => { clearTimeout(feedTimer); feedTimer = setTimeout(() => loadFeed(true), 300); });
    $('#feedType').addEventListener('change', () => loadFeed(true));
    $('#feedFav').addEventListener('change', () => loadFeed(true));
    $('#feedMore').addEventListener('click', () => loadFeed(false));

    // ---------- Statistik ----------
    function bars(list, val, label, color) {
        const max = Math.max(1, ...list.map(val));
        return '<div class="vx-bars">' + list.map(x => '<div class="vx-barrow"><span class="vx-bl">' + label(x) + '</span><span class="vx-bb"><i style="width:' + (val(x) / max * 100).toFixed(1) + '%;' + (color ? 'background:' + color(x) : '') + '"></i></span><span class="vx-bv">' + esc(x._v || val(x)) + '</span></div>').join('') + '</div>';
    }
    function lineChart(h) {
        if (h.length < 2) return '<div class="vx-empty">Noch zu wenige Daten – die Kurve entsteht mit jedem Abgleich.</div>';
        const W = 700, H = 160, max = Math.max(1, ...h.map(x => Math.max(x.online, x.active)));
        const t0 = h[0].ts, t1 = h[h.length - 1].ts || t0 + 1;
        const X = ts => ((ts - t0) / Math.max(1, t1 - t0) * (W - 40) + 30).toFixed(1), Y = v => (H - 20 - v / max * (H - 30)).toFixed(1);
        const line = k => h.map(x => X(x.ts) + ',' + Y(x[k])).join(' ');
        let grid = '';
        for (let i = 0; i <= 4; i++) { const v = Math.round(max * i / 4); grid += '<line x1="30" x2="' + (W - 10) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" class="g"/><text x="24" y="' + (+Y(v) + 3) + '" text-anchor="end">' + v + '</text>'; }
        let days = '';
        for (let t = Math.ceil(t0 / 86400) * 86400; t < t1; t += 86400) days += '<line x1="' + X(t) + '" x2="' + X(t) + '" y1="10" y2="' + (H - 20) + '" class="g"/><text x="' + X(t) + '" y="' + (H - 6) + '" text-anchor="middle">' + new Date(t * 1000).toLocaleDateString('de-DE', { weekday: 'short' }) + '</text>';
        return '<svg class="vx-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Freunde online, letzte 7 Tage">' + grid + days
            + '<polyline points="' + line('active') + '" class="l2"/><polyline points="' + line('online') + '" class="l1"/></svg>'
            + '<div class="vx-legend"><span><i class="l1"></i> im Spiel</span><span><i class="l2"></i> Website / App</span></div>';
    }
    function heatmap(heat, unit) {
        const max = Math.max(1, ...heat.flat()), days = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        const tip = v => unit === 'min' ? (v >= 60 ? Math.round(v / 6) / 10 + ' Std.' : v + ' Min.') + ' online (30 Tage)' : v + '× online gekommen';
        let h = '<div class="vx-heat"><span></span>' + Array.from({ length: 24 }, (_, i) => '<span class="hh">' + (i % 3 ? '' : i) + '</span>').join('');
        heat.forEach((row, d) => { h += '<span class="hd">' + days[d] + '</span>' + row.map((v, i) => '<span class="hc" style="--a:' + (v / max).toFixed(2) + '" title="' + days[d] + ' ' + i + ' Uhr: ' + tip(v) + '"></span>').join(''); });
        return h + '</div>';
    }
    function inactivePanel() {
        const lim = Date.now() - 30 * 86400000;
        const list = state.friends.filter(f => f.state === 'offline' && f.last_login && new Date(f.last_login).getTime() < lim)
            .sort((a, b) => new Date(a.last_login) - new Date(b.last_login)).slice(0, 25);
        return '<div class="panel"><div class="panel-title">💤 Lange nicht online <small>(über 30 Tage)</small></div>' + (list.length ? '<div class="vx-members">' + list.map(f => '<div class="vx-member">' + img(f.image, 'vx-av sm', f.name)
            + '<a href="#" data-user="' + esc(f.id) + '">' + esc(f.name) + '</a><small>zuletzt ' + esc(isoDate(f.last_login)) + ' · ' + Math.floor((Date.now() - new Date(f.last_login)) / 86400000) + ' Tage</small></div>').join('') + '</div>'
            : '<div class="vx-empty">Alle deine Freunde waren in den letzten 30 Tagen online. 🎉</div>') + '</div>';
    }
    function loadStats() {
        const box = $('#statsBox');
        if (!loaded.statsData) loading(box, 'Lade Statistik …');
        api('stats').then(s => {
            loaded.statsData = s;
            drawSpark(s.history.slice(-24));
            const t = s.types || {};
            box.innerHTML = '<div class="vx-kpis">'
                + '<div><b>' + (s.state.online || 0) + '</b><span>gerade im Spiel</span></div><div><b>' + s.peak + '</b><span>Spitze (7 Tage, Ø/Std.)</span></div>'
                + '<div><b>' + hm(s.total_play) + '</b><span>Spielzeit Top-Freunde (30 T.)</span></div><div><b>' + num(t.online || 0) + '</b><span>Logins (30 T.)</span></div>'
                + '<div><b>' + num(t.gps || 0) + '</b><span>Weltwechsel (30 T.)</span></div><div><b>' + ((t.friend_add || 0) + ' / ' + (t.friend_remove || 0)) + '</b><span>Freunde + / −</span></div>'
                + '<div><b>' + (s.recording_since ? esc(new Date(s.recording_since * 1000).toLocaleDateString('de-DE')) : '–') + '</b><span>Aufzeichnung seit</span></div></div>'
                + '<div class="panel"><div class="panel-title">Freunde online – letzte 7 Tage</div>' + lineChart(s.history) + '</div>'
                + '<div class="panel" id="tlPanel"><div class="panel-title">📊 Zeitleiste – wer war wann online? <span class="chips" id="tlDays"><button type="button" class="chip on" data-day="0">Heute</button><button type="button" class="chip" data-day="1">Gestern</button><button type="button" class="chip" data-day="2">Vorgestern</button></span></div><div id="tlBox"><div class="vx-loading">Lade …</div></div></div>'
                + '<div class="vx-cols">'
                + '<div class="panel"><div class="panel-title">🏆 Meiste Spielzeit (30 Tage)</div>' + (s.play.length ? bars(s.play.map(p => Object.assign(p, { _v: hm(p.seconds) })), p => p.seconds, p => '<a href="#" data-user="' + esc(p.id) + '">' + esc(p.name) + '</a>') : '<div class="vx-empty">Noch keine Daten.</div>') + '</div>'
                + '<div class="panel"><div class="panel-title">🌐 Beliebteste Welten deiner Freunde</div>' + (s.worlds.length ? bars(s.worlds, w => w.count, w => '<a href="#" data-world="' + esc(w.id) + '">' + esc(w.name) + '</a>') : '<div class="vx-empty">Noch keine Daten.</div>') + '</div>'
                + '<div class="panel"><div class="panel-title">🕐 Wann kommen deine Freunde online?</div>' + heatmap(s.heat) + '</div>'
                + '<div class="panel"><div class="panel-title">🛡️ Vertrauensstufen</div>' + bars(s.trust, x => x.count, x => '<span style="color:' + esc(x.color) + '">' + esc(x.name) + '</span>', x => x.color) + '</div>'
                + '<div class="panel"><div class="panel-title">💻 Plattformen (zuletzt)</div>' + bars(Object.entries(s.platform).map(([k, v]) => ({ k, v })), x => x.v, x => esc(x.k)) + '</div>'
                + inactivePanel()
                + '<div class="panel"><div class="panel-title">🤝 Zuletzt zusammen mit <small>(gleiche Instanz, 30 Tage)</small></div><div id="encBox"><div class="vx-loading">Lade …</div></div></div>'
                + anniversaryPanel()
                + '<div class="panel"><div class="panel-title">🧭 Deine meistbesuchten Welten</div>' + (s.my_worlds.length ? bars(s.my_worlds, w => w.count, w => '<a href="#" data-world="' + esc(w.id) + '">' + esc(w.name) + '</a>') : '<div class="vx-empty">Wird aufgezeichnet, sobald du im Spiel bist und diese Seite offen ist.</div>') + '</div>'
                + '</div>';
            loadTimeline(0); loadEncounters();
        }).catch(fail(box));
    }
    // Zeitleiste: Balken je Freund über 24 Stunden
    function loadTimeline(day) {
        const box = $('#tlBox'); if (!box) return;
        $$('#tlDays .chip').forEach(c => c.classList.toggle('on', +c.dataset.day === day));
        api('timeline', { day }).then(t => {
            if (!t.rows.length) { box.innerHTML = '<div class="vx-empty">Für diesen Tag ist nichts aufgezeichnet.</div>'; return; }
            const span = t.end - t.start, pct = x => ((x - t.start) / span * 100).toFixed(2) + '%';
            const hh = new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' });
            box.innerHTML = '<div class="vx-tl"><div class="vx-tl-row head"><span></span><div class="vx-tl-track">' + [0, 3, 6, 9, 12, 15, 18, 21].map(h => '<i style="left:' + (h / 24 * 100) + '%">' + h + '</i>').join('') + '</div></div>'
                + t.rows.map(r => '<div class="vx-tl-row"><a href="#" data-user="' + esc(r.id) + '" class="vx-tl-name">' + img(r.image, 'vx-av xs', r.name) + '<span>' + esc(r.name) + '</span></a><div class="vx-tl-track">'
                    + r.seg.map(([a, b]) => '<span class="vx-tl-seg" style="left:' + pct(a) + ';width:' + ((b - a) / span * 100).toFixed(2) + '%" title="' + hh.format(a * 1000) + '–' + hh.format(b * 1000) + ' (' + hm(b - a) + ')"></span>').join('')
                    + (t.now < t.end ? '<span class="vx-tl-now" style="left:' + pct(t.now) + '"></span>' : '') + '</div><small>' + hm(r.total) + '</small></div>').join('') + '</div>';
        }).catch(fail(box));
    }
    document.addEventListener('click', e => { const c = e.target.closest('#tlDays [data-day]'); if (c) loadTimeline(+c.dataset.day); });
    function loadEncounters() {
        const box = $('#encBox'); if (!box) return;
        api('encounters').then(d => {
            box.innerHTML = d.items.length ? '<div class="vx-members">' + d.items.slice(0, 20).map(x => '<div class="vx-member">' + img(x.image, 'vx-av sm', x.name) + '<a href="#" data-user="' + esc(x.id) + '">' + esc(x.name) + '</a><small>'
                + esc(ago(x.last)) + ' in <a href="#" data-world="' + esc(x.world) + '">' + esc(x.world_name) + '</a> · ' + hm(x.seconds) + ' gesamt · ' + x.instances + '×</small></div>').join('') + '</div>'
                : '<div class="vx-empty">Noch keine Begegnungen aufgezeichnet – das passiert, während du im Spiel bist und diese Seite (oder der Cron) läuft.</div>';
        }).catch(fail(box));
    }
    // Freundschafts-Jahrestage & neueste Freunde
    function anniversaryPanel() {
        const now = new Date(), today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const list = [];
        state.friends.filter(f => f.friend_since > 0).forEach(f => {
            const d = new Date(f.friend_since * 1000);
            let next = new Date(today.getFullYear(), d.getMonth(), d.getDate());
            if (next < today) next = new Date(today.getFullYear() + 1, d.getMonth(), d.getDate());
            const years = next.getFullYear() - d.getFullYear(), days = Math.round((next - today) / 86400000);
            if (years >= 1 && days <= 30) list.push({ f, years, days, next });
        });
        list.sort((a, b) => a.days - b.days);
        const newest = state.friends.filter(f => f.friend_since > 0 && Date.now() / 1000 - f.friend_since < 30 * 86400).sort((a, b) => b.friend_since - a.friend_since).slice(0, 12);
        return '<div class="panel"><div class="panel-title">🎂 Freundschafts-Jahrestage <small>(nächste 30 Tage)</small></div>'
            + (list.length ? '<div class="vx-members">' + list.map(x => '<div class="vx-member">' + img(x.f.image, 'vx-av sm', x.f.name) + '<a href="#" data-user="' + esc(x.f.id) + '">' + esc(x.f.name) + '</a><small>' + (x.days === 0 ? '🎉 <b>heute</b>' : 'in ' + x.days + ' Tag' + (x.days === 1 ? '' : 'en')) + ' · ' + x.years + ' Jahr' + (x.years === 1 ? '' : 'e') + ' befreundet</small></div>').join('') + '</div>'
                : '<div class="vx-empty">Keine Jahrestage in den nächsten 30 Tagen' + (state.friends.some(f => f.friend_since > 0) ? '.' : ' – Freundschaften werden ab Beginn der Aufzeichnung gezählt.') + '</div>')
            + (newest.length ? '<h4 class="vx-sub">🆕 Neueste Freunde</h4><div class="vx-members">' + newest.map(f => '<div class="vx-member">' + img(f.image, 'vx-av sm', f.name) + '<a href="#" data-user="' + esc(f.id) + '">' + esc(f.name) + '</a><small>seit ' + esc(new Date(f.friend_since * 1000).toLocaleDateString('de-DE')) + '</small></div>').join('') + '</div>' : '')
            + '</div>';
    }

    // ---------- Favoriten (wie VRCX): VRChat-Gruppen, lokale Gruppen, Verlauf ----------
    const FAVK = { friend: ['👥', 'Freunde'], world: ['🌐', 'Welten'], avatar: ['🎭', 'Avatare'] };
    const fv = Object.assign({ kind: 'friend', sel: null, cols: 'auto', q: '' }, store.get('favPrefs', {}), { q: '' });
    let favData = null, favEdit = false, favChecked = new Set();
    const favSave = () => store.set('favPrefs', { kind: fv.kind, sel: fv.sel, cols: fv.cols });
    function loadFavs(kind) {
        if (kind) { fv.kind = kind; fv.sel = null; favEdit = false; }
        favSave();
        const main = $('#favMain');
        $('#favSide').innerHTML = '';
        main.innerHTML = '<div class="vx-loading">Lade Favoriten …</div>';
        $$('#favKinds [data-fk]').forEach(b => b.classList.toggle('on', b.dataset.fk === fv.kind));
        api('favs', { kind: fv.kind }).then(d => { favData = d; favChecked.clear(); renderFavSide(); renderFavMain(); }).catch(fail(main));
    }
    const favLists = () => {
        const d = favData;
        return [...d.groups.map(g => ({ key: 'r:' + g.name, title: g.display, count: g.count, max: d.limit, vis: g.visibility, remote: g })),
                ...d.local_groups.map(g => ({ key: 'l:' + g.name, title: g.name, count: d.local.filter(x => x.group === g.name).length, local: g })),
                ...(fv.kind !== 'friend' ? [{ key: 'h', title: 'Lokaler Verlauf', count: d.history.length, max: 100, history: true }] : [])];
    };
    const VIS = { private: 'Privat', friends: 'Freunde', public: 'Öffentlich' };
    function renderFavSide() {
        const lists = favLists();
        if (!lists.some(l => l.key === fv.sel)) fv.sel = lists.length ? lists[0].key : null;
        const card = l => '<button type="button" class="vx-fgcard' + (l.key === fv.sel ? ' on' : '') + (l.remote ? ' remote' : '') + '" data-fsel="' + esc(l.key) + '"><span class="vx-fgtop"><b>' + esc(l.title) + '</b><em>' + l.count + (l.max ? '/' + l.max : '') + '</em></span>'
            + '<span class="vx-fgbot"><small>' + (l.remote ? esc(VIS[l.vis] || l.vis) : l.history ? 'angesehene ' + FAVK[fv.kind][1] : (window.VRCX.app ? 'nur lokal' : 'nur im Dashboard')) + '</small>' + (l.history || l.local || l.remote ? '<i data-fgmenu="' + esc(l.key) + '" title="Optionen">⋯</i>' : '') + '</span></button>';
        $('#favSide').innerHTML = '<div class="vx-fghead"><b>VRChat-Favoriten</b><button type="button" class="vx-icbtn" data-freload title="Neu laden">⟳</button></div>' + lists.filter(l => l.remote).map(card).join('')
            + (lists.some(l => l.remote) ? '' : '<div class="vx-small">Keine VRChat-Gruppen.</div>')
            + '<div class="vx-fghead"><b>Lokale Favoriten</b></div>' + lists.filter(l => l.local).map(card).join('')
            + '<button type="button" class="vx-fgnew" data-fgnew>＋ Neue Gruppe</button>'
            + (fv.kind !== 'friend' ? '<div class="vx-fghead"><b>Lokaler Verlauf</b></div>' + lists.filter(l => l.history).map(card).join('') : '');
    }
    function favItems() {
        const d = favData, k = fv.sel || '';
        if (k.startsWith('r:')) return d.items.filter(x => x.group === k.slice(2)).map(x => Object.assign({ src: 'remote' }, x));
        if (k.startsWith('l:')) return d.local.filter(x => x.group === k.slice(2)).map(x => Object.assign({ src: 'local' }, x, Object.assign({}, fv.kind === 'friend' ? friendLive(x.id) : {})));
        if (k === 'h') return d.history.map(x => Object.assign({ src: 'history' }, x));
        return [];
    }
    function friendLive(id) {
        const f = friendById(id);
        if (!f) return {};
        const where = f.state === 'offline' ? '' : (f.loc && f.loc.world_name ? f.loc.world_name : f.state === 'active' ? 'Web/App' : '');
        return { name: f.name, image: f.image, sub: f.status_desc || where, state: f.state, status: f.status, color: f.trust[1] };
    }
    function renderFavMain() {
        const main = $('#favMain'), l = favLists().find(x => x.key === fv.sel);
        if (!l) { main.innerHTML = '<div class="vx-empty">Keine Liste ausgewählt.</div>'; return; }
        let list = favItems();
        const q = fv.q.toLowerCase();
        if (q) list = list.filter(x => (x.name + ' ' + (x.sub || '')).toLowerCase().includes(q));
        if (fv.kind === 'friend') list.sort((a, b) => ((a.state || 'offline') === 'offline') - ((b.state || 'offline') === 'offline') || String(a.name).localeCompare(String(b.name), 'de'));
        const cols = fv.cols === 'auto' ? '' : ' style="grid-template-columns:repeat(' + (+fv.cols) + ',minmax(0,1fr))"';
        main.innerHTML = '<div class="vx-fmhead"><h3>' + esc(l.title) + ' <small>' + l.count + (l.max ? '/' + l.max : '') + '</small></h3>'
            + '<input type="search" id="favQ" placeholder="Filtern …" value="' + esc(fv.q) + '"><select id="favCols" title="Spalten">' + ['auto', '1', '2', '3', '4', '5'].map(c => '<option value="' + c + '"' + (fv.cols === c ? ' selected' : '') + '>' + (c === 'auto' ? 'Spalten: auto' : c + ' Spalten') + '</option>').join('') + '</select>'
            + (l.history ? '' : '<label class="vx-auto vx-editsw"><input type="checkbox" id="favEditSw"' + (favEdit ? ' checked' : '') + '> Bearbeiten</label>') + '</div>'
            + (favEdit && !l.history ? '<div class="vx-fbulk"><span>' + favChecked.size + ' ausgewählt</span><select id="favBulkTo"><option value="">Verschieben nach …</option>' + moveOptions(l.key) + '</select><button type="button" class="btn btn-sm btn-danger" id="favBulkDel">Entfernen</button><button type="button" class="btn btn-sm" id="favBulkAll">Alle wählen</button></div>' : '')
            + (list.length ? '<div class="vx-fgrid ' + fv.kind + '"' + cols + '>' + list.map((x, i) => favCard(x, i)).join('') + '</div>' : '<div class="vx-empty">' + (q ? 'Keine Treffer.' : 'Diese Liste ist leer.' + (fv.kind !== 'friend' && l.local ? ' Im ' + (fv.kind === 'world' ? 'Welt' : 'Avatar') + '-Dialog unter „⭐ Merken in“ hinzufügen.' : '')) + '</div>');
        main._list = list;
        const fq = $('#favQ');
        fq.addEventListener('input', () => { fv.q = fq.value; const p = fq.selectionStart; renderFavMain(); const n = $('#favQ'); n.focus(); n.setSelectionRange(p, p); });
        $('#favCols').addEventListener('change', e => { fv.cols = e.target.value; favSave(); renderFavMain(); });
        if ($('#favEditSw')) $('#favEditSw').addEventListener('change', e => { favEdit = e.target.checked; favChecked.clear(); renderFavMain(); });
        if ($('#favBulkTo')) {
            $('#favBulkTo').addEventListener('change', e => { if (e.target.value) bulk(e.target.value); });
            $('#favBulkDel').addEventListener('click', () => bulk(''));
            $('#favBulkAll').addEventListener('click', () => { list.forEach(x => favChecked.add(x.id)); renderFavMain(); });
        }
    }
    function favCard(x, i) {
        const dot = fv.kind === 'friend' ? '<span class="vx-fdot" style="background:' + esc((ST[x.state === 'offline' || !x.state ? 'offline' : x.status] || ST.offline || {}).color || '#666') + '"></span>' : '';
        return '<div class="vx-fcard' + (fv.kind === 'friend' && (!x.state || x.state === 'offline') ? ' off' : '') + '" data-fi="' + i + '" tabindex="0">'
            + (favEdit ? '<input type="checkbox" class="vx-fchk" data-fchk="' + esc(x.id) + '"' + (favChecked.has(x.id) ? ' checked' : '') + '>' : '')
            + '<span class="vx-fimg">' + img(vrcImg(x.image), fv.kind === 'friend' ? 'vx-av' : 'vx-fthumb', x.name) + dot + '</span>'
            + '<span class="vx-ftxt"><b' + (x.color ? ' style="color:' + esc(x.color) + '"' : '') + '>' + esc(x.name || x.id) + (x.locked ? ' 🔒' : '') + '</b><small>' + esc(x.sub || '') + '</small></span>'
            + '<button type="button" class="vx-fmenu" data-fmenu="' + i + '" title="Optionen">⋯</button></div>';
    }
    const vrcImg = u => u || '';
    function moveOptions(exclude) {
        const d = favData;
        return '<optgroup label="VRChat-Favoriten">' + d.groups.filter(g => 'r:' + g.name !== exclude).map(g => '<option value="remote:' + esc(g.name) + '">♥ ' + esc(g.display) + ' (' + g.count + '/' + d.limit + ')</option>').join('') + '</optgroup>'
            + '<optgroup label="Lokal">' + d.local_groups.filter(g => 'l:' + g.name !== exclude).map(g => '<option value="local:' + esc(g.name) + '">📌 ' + esc(g.name) + '</option>').join('') + '</optgroup>';
    }
    function favMove(x, target) {
        const from = fv.sel || '';
        const data = { kind: fv.kind, id: x.id, target, name: x.name || '', image: x.image || '', sub: x.sub || '' };
        if (from.startsWith('r:') && target.startsWith('remote:')) data.fav_id = x.fav_id || '';
        if (from.startsWith('l:') && target.startsWith('local:')) data.from_local = from.slice(2);
        return api('fav_set', data).then(r => {
            // Verschieben = an alter Stelle entfernen (VRChat → lokal bzw. lokal → VRChat)
            if (from.startsWith('r:') && target.startsWith('local:')) return api('fav_remove', { kind: fv.kind, id: x.id, fav_id: x.fav_id || '' }).then(() => r);
            if (from.startsWith('l:') && target.startsWith('remote:')) return api('fav_remove', { kind: fv.kind, id: x.id, local: from.slice(2) }).then(() => r);
            return r;
        });
    }
    function favDel(x) {
        const from = fv.sel || '';
        if (from === 'h') return Promise.resolve({ msg: '' });
        return api('fav_remove', from.startsWith('l:') ? { kind: fv.kind, id: x.id, local: from.slice(2) } : { kind: fv.kind, id: x.id, fav_id: x.fav_id || '' });
    }
    async function bulk(target) {
        const list = ($('#favMain')._list || []).filter(x => favChecked.has(x.id));
        if (!list.length) return toast('Nichts ausgewählt.', true);
        if (!target && !confirm(list.length + ' Einträge entfernen?')) return;
        let ok = 0;
        for (const x of list) { try { await (target ? favMove(x, target) : favDel(x)); ok++; } catch (e) { toast(e.message, true); } }
        toast(ok + ' ' + (target ? 'verschoben' : 'entfernt') + '.');
        loadFavs();
    }
    function popMenu(anchor, items) {
        $$('.vx-pop').forEach(p => p.remove());
        const p = document.createElement('div');
        p.className = 'vx-pop';
        p.innerHTML = items.map((it, i) => it === '-' ? '<hr>' : it.head ? '<div class="vx-pophead">' + esc(it.head) + '</div>' : '<button type="button" data-pi="' + i + '"' + (it.bad ? ' class="bad"' : '') + '>' + esc(it.label) + '</button>').join('');
        document.body.append(p);
        const r = anchor.getBoundingClientRect();
        p.style.top = Math.min(window.scrollY + r.bottom + 4, window.scrollY + window.innerHeight - p.offsetHeight - 8) + 'px';
        p.style.left = Math.max(8, Math.min(window.scrollX + r.right - p.offsetWidth, window.scrollX + document.documentElement.clientWidth - p.offsetWidth - 8)) + 'px';
        p.addEventListener('click', e => { const b = e.target.closest('[data-pi]'); if (b) { p.remove(); items[+b.dataset.pi].fn(); } });
        setTimeout(() => document.addEventListener('click', function off(e) { if (!p.contains(e.target)) { p.remove(); document.removeEventListener('click', off); } }), 0);
    }
    const openFavItem = x => fv.kind === 'friend' ? openUser(x.id) : fv.kind === 'world' ? openWorld(x.id) : openAvatar(x.id);
    function favEntryMenu(btn, x) {
        const d = favData, from = fv.sel || '', items = [{ label: '🔎 Öffnen', fn: () => openFavItem(x) }, { label: '⧉ ID kopieren', fn: () => copy(x.id) }, '-', { head: from === 'h' ? 'Merken in …' : 'Verschieben nach …' }];
        d.groups.filter(g => 'r:' + g.name !== from).forEach(g => items.push({ label: '♥ ' + g.display + ' (' + g.count + '/' + d.limit + ')', fn: () => (from === 'h' ? api('fav_set', { kind: fv.kind, id: x.id, target: 'remote:' + g.name }) : favMove(x, 'remote:' + g.name)).then(r => { toast(r.msg); loadFavs(); }).catch(e => toast(e.message, true)) }));
        d.local_groups.filter(g => 'l:' + g.name !== from).forEach(g => items.push({ label: '📌 ' + g.name, fn: () => (from === 'h' ? api('fav_set', { kind: fv.kind, id: x.id, target: 'local:' + g.name, name: x.name, image: x.image, sub: x.sub }) : favMove(x, 'local:' + g.name)).then(r => { toast(r.msg); loadFavs(); }).catch(e => toast(e.message, true)) }));
        if (from !== 'h') items.push('-', { label: '🗑 Entfernen', bad: true, fn: () => favDel(x).then(r => { toast(r.msg); loadFavs(); }).catch(e => toast(e.message, true)) });
        popMenu(btn, items);
    }
    function favGroupMenu(btn, key) {
        const l = favLists().find(x => x.key === key);
        if (!l) return;
        if (l.remote) popMenu(btn, [
            { label: '✎ Umbenennen', fn: () => { const n = prompt('Neuer Name (max. 30 Zeichen):', l.title); if (n) api('fav_group_rename', { kind: fv.kind, group: l.remote.name, name: n }).then(r => { toast(r.msg); loadFavs(); }).catch(e => toast(e.message, true)); } },
            { head: 'Sichtbarkeit' }, ...['private', 'friends', 'public'].map(v => ({ label: (l.vis === v ? '● ' : '○ ') + VIS[v], fn: () => api('fav_group_rename', { kind: fv.kind, group: l.remote.name, visibility: v }).then(r => { toast(r.msg); loadFavs(); }).catch(e => toast(e.message, true)) }))]);
        if (l.local) popMenu(btn, [
            { label: '✎ Umbenennen', fn: () => { const n = prompt('Neuer Name:', l.title); if (n && n !== l.title) api('local_group', { kind: fv.kind, op: 'rename', old: l.title, name: n }).then(r => { toast(r.msg); fv.sel = 'l:' + n; loadFavs(); }).catch(e => toast(e.message, true)); } },
            { label: '🗑 Gruppe löschen', bad: true, fn: () => { if (confirm('Lokale Gruppe „' + l.title + '“ mit ' + l.count + ' Einträgen löschen?')) api('local_group', { kind: fv.kind, op: 'delete', old: l.title }).then(r => { toast(r.msg); loadFavs(); }).catch(e => toast(e.message, true)); } }]);
        if (l.history) popMenu(btn, [{ label: '🗑 Verlauf leeren', bad: true, fn: () => { if (confirm('Verlauf leeren?')) api('history_clear', { kind: fv.kind }).then(r => { toast(r.msg); loadFavs(); }).catch(e => toast(e.message, true)); } }]);
    }
    const copy = t => (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => toast('Kopiert.')).catch(() => prompt('Kopieren:', t));
    $('#favKinds').addEventListener('click', e => { const b = e.target.closest('[data-fk]'); if (b) loadFavs(b.dataset.fk); });
    $('#favSide').addEventListener('click', e => {
        const m = e.target.closest('[data-fgmenu]');
        if (m) { e.stopPropagation(); favGroupMenu(m, m.dataset.fgmenu); return; }
        if (e.target.closest('[data-freload]')) return loadFavs();
        if (e.target.closest('[data-fgnew]')) { const n = prompt('Name der neuen lokalen Gruppe:'); if (n) api('local_group', { kind: fv.kind, op: 'create', name: n }).then(r => { toast(r.msg); fv.sel = 'l:' + n.trim(); loadFavs(); }).catch(x => toast(x.message, true)); return; }
        const c = e.target.closest('[data-fsel]');
        if (c) { fv.sel = c.dataset.fsel; favSave(); favChecked.clear(); renderFavSide(); renderFavMain(); }
    });
    $('#favMain').addEventListener('click', e => {
        const list = $('#favMain')._list || [];
        const ck = e.target.closest('[data-fchk]');
        if (ck) { e.stopPropagation(); if (ck.checked) favChecked.add(ck.dataset.fchk); else favChecked.delete(ck.dataset.fchk); const s = $('.vx-fbulk span'); if (s) s.textContent = favChecked.size + ' ausgewählt'; return; }
        const m = e.target.closest('[data-fmenu]');
        if (m) { e.stopPropagation(); favEntryMenu(m, list[+m.dataset.fmenu]); return; }
        const c = e.target.closest('[data-fi]');
        if (c && !e.target.closest('select, input')) { if (favEdit) { const x = list[+c.dataset.fi]; if (favChecked.has(x.id)) favChecked.delete(x.id); else favChecked.add(x.id); renderFavMain(); } else openFavItem(list[+c.dataset.fi]); }
    });

    // Auswahl „⭐ Merken in …“ für Profil-, Welt- und Avatar-Dialoge
    function favPicker(kind, id, meta, isFriend) {
        const head = $('#userBody .vx-u-head, #userBody .vx-w-head');
        if (!head) return;
        const box = document.createElement('div');
        box.className = 'vx-favpick';
        box.innerHTML = '<span class="vx-small">⭐ Lade Favoriten …</span>';
        head.insertAdjacentElement('afterend', box);
        api('fav_status', { kind, id }).then(d => {
            const cur = d.current;
            const remote = kind === 'friend' && !isFriend ? '' : '<label>♥ VRChat-Favorit <select data-fpremote><option value="">– keiner –</option>' + d.groups.map(g => '<option value="' + esc(g.name) + '"' + (cur && cur.group === g.name ? ' selected' : '') + '>' + esc(g.display) + '</option>').join('') + '</select></label>';
            box.innerHTML = remote + '<span class="vx-fplocal">📌 Lokal: ' + d.local_groups.map(g => '<button type="button" class="vx-fpchip' + (d.local.includes(g.name) ? ' on' : '') + '" data-fplocal="' + esc(g.name) + '">' + (d.local.includes(g.name) ? '✓ ' : '＋ ') + esc(g.name) + '</button>').join('') + '<button type="button" class="vx-fpchip new" data-fpnew>＋ neue Gruppe</button></span>';
            const sel = box.querySelector('[data-fpremote]');
            if (sel) sel.addEventListener('change', () => {
                const p = sel.value ? api('fav_set', { kind, id, target: 'remote:' + sel.value, fav_id: cur ? cur.fav_id : '' }) : api('fav_remove', { kind, id, fav_id: cur ? cur.fav_id : '' });
                p.then(r => { toast(r.msg); if (r.fav_id) d.current = { group: sel.value, fav_id: r.fav_id }; if (loaded.favs) loaded.favsDirty = true; }).catch(x => toast(x.message, true));
            });
            box.addEventListener('click', e => {
                const b = e.target.closest('[data-fplocal]');
                if (b) {
                    const on = b.classList.contains('on'), g = b.dataset.fplocal;
                    (on ? api('fav_remove', { kind, id, local: g }) : api('fav_set', Object.assign({ kind, id, target: 'local:' + g }, meta))).then(r => { toast(r.msg); b.classList.toggle('on', !on); b.textContent = (!on ? '✓ ' : '＋ ') + g; }).catch(x => toast(x.message, true));
                }
                if (e.target.closest('[data-fpnew]')) { const n = prompt('Name der neuen lokalen Gruppe:'); if (n) api('fav_set', Object.assign({ kind, id, target: 'local:' + n.trim() }, meta)).then(r => { toast(r.msg); box.remove(); favPicker(kind, id, meta, isFriend); }).catch(x => toast(x.message, true)); }
            });
        }).catch(x => { box.innerHTML = '<span class="vx-small">⭐ ' + esc(x.message) + '</span>'; });
    }

    // ---------- Gruppen ----------
    const can = (g, p) => g.owner || g.perms.includes('*') || g.perms.includes(p);
    // Gruppenliste: gestapelte, kompakte Zeilen (Moderiert oben), Details öffnen sich im Popup
    const gPrefs = Object.assign({ q: '', sort: 'name', closed: {}, view: 'grid', cols: 'auto' }, store.get('groupPrefs', {}));
    function loadGroups() {
        $('#groupsBox').innerHTML = '<div class="vx-loading">Lade Gruppen …</div>';
        api('groups').then(d => {
            loaded.groups = true;
            loaded.groupList = d.groups;
            renderGroups();
        }).catch(x => { $('#groupsBox').innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    function renderGroups() {
        const all = loaded.groupList || [];
        const q = gPrefs.q.toLowerCase();
        const sorter = (a, b) => gPrefs.sort === 'members' ? b.members - a.members : gPrefs.sort === 'short' ? String(a.short).localeCompare(String(b.short), 'de') : a.name.localeCompare(b.name, 'de');
        const list = all.filter(g => !q || (g.name + ' ' + g.short).toLowerCase().includes(q));
        const tile = g => '<button type="button" class="vx-gtile' + (g.mod ? ' mod' : '') + '" data-group="' + esc(g.id) + '">' + (/^https:/.test(g.banner || '') ? '<span class="vx-gtile-ban" style="background-image:url(\'' + esc(g.banner.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"></span>' : '<span class="vx-gtile-ban none"></span>')
            + img(g.icon, 'vx-g-icon', '') + '<b>' + esc(g.name) + '</b><small>' + esc(g.short) + ' · 👥 ' + Number(g.members).toLocaleString('de-DE') + '</small>'
            + '<span class="vx-grow-b">' + (g.owner ? '<span class="vx-pill" style="--pc:#f3c262">👑 Besitzer</span>' : g.mod ? '<span class="vx-pill" style="--pc:#50fa7b">🛡️ Mod</span>' : g.staff ? '<span class="vx-pill" style="--pc:#8be9fd" title="Moderationsrechte ohne Kick">🔧 Teilrechte</span>' : '') + (g.rep ? '<span class="vx-pill rep">★</span>' : '') + '</span></button>';
        const row = g => gPrefs.view === 'grid' ? tile(g) : '<button type="button" class="vx-grow' + (g.mod ? ' mod' : '') + '" data-group="' + esc(g.id) + '">' + img(g.icon, 'vx-g-icon', '')
            + '<span class="vx-grow-n"><b>' + esc(g.name) + '</b><small>' + esc(g.short) + '</small></span>'
            + '<span class="vx-grow-b">' + (g.owner ? '<span class="vx-pill" style="--pc:#f3c262">👑 Besitzer</span>' : g.mod ? '<span class="vx-pill" style="--pc:#50fa7b" title="' + esc(g.perms.join(', ')) + '">🛡️ Mod</span>' : g.staff ? '<span class="vx-pill" style="--pc:#8be9fd" title="' + esc(g.perms.join(', ')) + '">🔧 Teilrechte</span>' : '')
            + (g.rep ? '<span class="vx-pill rep">★</span>' : '') + '</span><span class="vx-grow-m">👥 ' + Number(g.members).toLocaleString('de-DE') + '</span><span class="vx-grow-c">›</span></button>';
        const sec = (key, title, items) => items.length ? '<div class="vx-gstack' + (gPrefs.closed[key] ? ' closed' : '') + '" data-gsec="' + key + '"><button type="button" class="vx-gstack-h">' + title + ' <small>' + items.length + '</small></button><div class="vx-gstack-b' + (gPrefs.view === 'grid' ? ' grid' : '') + '"' + (gPrefs.view === 'grid' && gPrefs.cols !== 'auto' ? ' style="grid-template-columns:repeat(' + (+gPrefs.cols) + ',minmax(0,1fr))"' : '') + '>' + items.map(row).join('') + '</div></div>' : '';
        const owned = list.filter(g => g.owner).sort(sorter), mod = list.filter(g => g.mod && !g.owner).sort(sorter), rest = list.filter(g => !g.mod).sort(sorter);
        $('#groupsBox').innerHTML = '<div class="vx-tools vx-gtools"><input type="search" id="gFilter" placeholder="Gruppen filtern …" value="' + esc(gPrefs.q) + '" aria-label="Gruppen filtern">'
            + '<span class="vx-seg"><button type="button" data-gview="grid" class="' + (gPrefs.view === 'grid' ? 'on' : '') + '" title="Raster">▦</button><button type="button" data-gview="list" class="' + (gPrefs.view === 'list' ? 'on' : '') + '" title="Liste">☰</button></span>'
            + (gPrefs.view === 'grid' ? '<select id="gCols" aria-label="Spalten">' + ['auto', '2', '3', '4', '5', '6'].map(c => '<option value="' + c + '"' + (gPrefs.cols === c ? ' selected' : '') + '>' + (c === 'auto' ? 'Spalten: auto' : c + ' Spalten') + '</option>').join('') + '</select>' : '')
            + '<select id="gSort" aria-label="Sortierung"><option value="name">Name A–Z</option><option value="members">Meiste Mitglieder</option><option value="short">Kürzel</option></select>'
            + '<span class="vx-small">' + all.length + ' Gruppen · ' + all.filter(g => g.mod).length + ' mit Kick-Rechten</span></div>'
            + (all.length ? sec('own', '👑 Eigene Gruppen', owned) + sec('mod', '🛡️ Gruppen, die du moderierst (mit Kick-Rechten)', mod) + sec('rest', '👥 Weitere Gruppen', rest) || '<div class="vx-empty">Keine Treffer.</div>' : '<div class="vx-empty">Du bist in keiner Gruppe.</div>');
        $('#gSort').value = gPrefs.sort;
        const f = $('#gFilter');
        f.addEventListener('input', () => { gPrefs.q = f.value; store.set('groupPrefs', gPrefs); const pos = f.selectionStart; renderGroups(); const nf = $('#gFilter'); nf.focus(); nf.setSelectionRange(pos, pos); });
        $('#gSort').addEventListener('change', e => { gPrefs.sort = e.target.value; store.set('groupPrefs', gPrefs); renderGroups(); });
        $$('[data-gview]').forEach(b => b.addEventListener('click', () => { gPrefs.view = b.dataset.gview; store.set('groupPrefs', gPrefs); renderGroups(); }));
        if ($('#gCols')) $('#gCols').addEventListener('change', e => { gPrefs.cols = e.target.value; store.set('groupPrefs', gPrefs); renderGroups(); });
    }
    $('#groupsBox').addEventListener('click', e => {
        const h = e.target.closest('.vx-gstack-h');
        if (h) { const sx = h.parentElement; sx.classList.toggle('closed'); gPrefs.closed[sx.dataset.gsec] = sx.classList.contains('closed'); store.set('groupPrefs', gPrefs); return; }
        const b = e.target.closest('[data-group]');
        if (b) { $$('.vx-grow, .vx-gtile').forEach(x => x.classList.toggle('on', x === b)); openGroup(b.dataset.group); }
    });
    const gModal = $('#groupModal');
    const closeGroup = () => { gModal.hidden = true; if ($('#userModal').hidden) document.body.style.overflow = ''; };
    gModal.addEventListener('click', e => { if (e.target === gModal || e.target.closest('[data-gclose]')) closeGroup(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !gModal.hidden && $('#userModal').hidden) closeGroup(); });
    let group = null;
    function openGroup(id) {
        const box = $('#groupDetail');
        gModal.hidden = false;
        document.body.style.overflow = 'hidden';
        box.innerHTML = '<div class="vx-loading">Lade Gruppe …</div>';
        api('group', { id }).then(d => {
            group = d.group;
            const g = group;
            remember('group', g.id, g.name);
            const tabs = [['info', 'ℹ Info'], ['friends', '🤝 Freunde'], ['posts', '📢 Beiträge'], ['members', '👥 Mitglieder'], ['instances', '🌐 Instanzen']];
            if (g.member && can(g, 'group-invites-manage')) tabs.push(['requests', '📥 Anfragen'], ['invite', '✉ Einladen']);
            if (g.member && can(g, 'group-bans-manage')) tabs.push(['bans', '🚫 Banns']);
            if (g.member && can(g, 'group-audit-view')) tabs.push(['audit', '📋 Protokoll']);
            box.innerHTML = '<div class="panel vx-gd">' + (/^https:/.test(g.banner) ? '<div class="vx-gd-banner" style="background-image:url(\'' + esc(g.banner.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"></div>' : '')
                + '<div class="vx-gd-head">' + img(g.icon, 'vx-g-icon lg', '') + '<div><h2>' + esc(g.name) + '</h2><small>' + esc(g.short) + ' · ' + g.members + ' Mitglieder' + (g.online ? ' · ' + g.online + ' online' : '') + ' · ' + esc(g.privacy) + ' · Beitritt: ' + esc(g.join_state || '–') + '</small></div>'
                + '<span class="vx-u-btns">' + (g.member ? '<button type="button" class="btn btn-sm" id="gRep">' + (g.rep ? '★ Repräsentation entfernen' : '☆ Repräsentieren') + '</button>' + (g.owner ? '' : '<button type="button" class="btn btn-sm btn-danger" id="gLeave">Verlassen</button>')
                    : '<button type="button" class="btn btn-sm btn-gold" id="gJoin">＋ Beitreten</button>') + '<button type="button" class="btn btn-sm" id="gCopy" title="Link kopieren">⧉</button>' + (window.VRCX.discord ? '<button type="button" class="btn btn-sm" id="gShare" title="In Discord teilen">📣</button>' : '')
                    + (g.member ? '<button type="button" class="btn btn-sm" id="gCsv" title="Mitgliederliste als CSV">⬇ CSV</button>' : '')
                    + '<a class="btn btn-sm" href="https://vrchat.com/home/group/' + encodeURIComponent(g.id) + '" target="_blank" rel="noopener noreferrer" title="Auf vrchat.com öffnen">↗</a></span></div>'
                + '<div class="vx-gd-tabs">' + tabs.map((t, i) => '<button type="button" class="chip' + (i ? '' : ' on') + '" data-gt="' + t[0] + '">' + t[1] + '</button>').join('') + '</div><div id="gPane"></div></div>';
            const act = (b, a, data, reload) => b && b.addEventListener('click', () => { if (a === 'group_leave' && !confirm('Gruppe „' + g.name + '“ wirklich verlassen?')) return; api(a, Object.assign({ id: g.id }, data)).then(r => { toast(r.msg); openGroup(g.id); if (reload) loadGroups(); }).catch(x => toast(x.message, true)); });
            act($('#gRep'), 'group_represent', { on: g.rep ? '' : 1 }, true);
            act($('#gLeave'), 'group_leave', {}, true);
            act($('#gJoin'), 'group_join', {}, true);
            $('#gCopy').addEventListener('click', () => { const u = 'https://vrchat.com/home/group/' + g.id; (navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(() => toast('Link kopiert.')).catch(() => prompt('Link:', u)); });
            if ($('#gShare')) $('#gShare').addEventListener('click', () => { const t = prompt('Text zur Gruppe (optional):', ''); if (t === null) return; api('group_share', { id: g.id, text: t }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); });
            if ($('#gCsv')) $('#gCsv').addEventListener('click', async e => {
                const b = e.currentTarget; b.disabled = true;
                const rows = [];
                try {
                    for (let off = 0; off < 2000; off += 50) {
                        b.textContent = '⏳ ' + rows.length;
                        const d = await api('group_members', { id: g.id, offset: off });
                        rows.push(...d.items);
                        if (d.items.length < 50) break;
                    }
                    const q = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
                    const csv = '\ufeffName;ID;Beigetreten;Rollen\n' + rows.map(m => [m.name, m.id, isoDate(m.joined), (m.roles || []).map(roleName).filter(Boolean).join(', ')].map(q).join(';')).join('\n');
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                    a.download = 'mitglieder_' + (g.short || 'gruppe').replace(/\W+/g, '_') + '.csv';
                    a.click();
                    toast(rows.length + ' Mitglieder exportiert.');
                } catch (x) { toast(x.message, true); }
                b.disabled = false; b.textContent = '⬇ CSV';
            });
            box.querySelector('.vx-gd-tabs').addEventListener('click', e => { const b = e.target.closest('[data-gt]'); if (!b) return; $$('.vx-gd-tabs .chip').forEach(x => x.classList.toggle('on', x === b)); groupPane(b.dataset.gt); });
            groupPane('info');
            api('group_friends', { id: g.id }).then(d => { group.friendsData = d; const t = $('.vx-gd-tabs [data-gt=friends]'); if (t) t.innerHTML = '🤝 Freunde <b class="vx-badge sm">' + d.friends.length + '</b>'; }).catch(() => {});
            if (window.NexusApp && window.NexusApp.groupExtra) window.NexusApp.groupExtra(g, () => openGroup(g.id));
            gModal.scrollTop = 0;
        }).catch(x => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    const roleName = id => ((group.roles || []).find(r => r.id === id) || {}).name || '';
    const memberRow = (m, actions) => '<div class="vx-member">' + img(m.image, 'vx-av sm', m.name) + '<a href="#" data-user="' + esc(m.id) + '">' + esc(m.name) + '</a>'
        + (m.rep ? '<span class="vx-pill rep">★</span>' : '') + (m.roles || []).filter(roleName).map(r => '<span class="vx-pill vx-role">' + esc(roleName(r)) + (can(group, 'group-roles-assign') ? '<button type="button" data-gact="unrole" data-role="' + esc(r) + '" data-user="' + esc(m.id) + '" data-name="' + esc(m.name) + '" title="Rolle entziehen">×</button>' : '') + '</span>').join('')
        + '<small>' + esc(isoDate(m.joined || m.banned)) + '</small><span class="vx-m-acts">' + actions(m) + '</span></div>';
    function groupPane(name, offset) {
        const g = group, pane = $('#gPane');
        const btn = (act, label, cls, m, extra) => '<button type="button" class="btn btn-sm ' + (cls || '') + '" data-gact="' + act + '" data-user="' + esc(m.id) + '" data-name="' + esc(m.name) + '"' + (extra || '') + '>' + label + '</button>';
        if (name === 'info') {
            pane.innerHTML = '<div class="vx-cols"><div><h4>Beschreibung</h4><div class="fmt">' + (g.desc_html || '<span class="muted">–</span>') + '</div>'
                + (g.links.length ? '<h4>Links</h4><ul class="vx-u-links">' + g.links.map(x => '<li><a href="' + esc(x) + '" target="_blank" rel="noopener noreferrer nofollow">' + esc(x) + '</a></li>').join('') + '</ul>' : '') + '</div>'
                + '<div><h4>Regeln</h4><div class="fmt">' + (g.rules_html || '<span class="muted">–</span>') + '</div>' + (g.roles.length ? '<h4>Rollen</h4><div>' + g.roles.map(r => '<span class="vx-pill">' + esc(r.name) + '</span>').join('') + '</div>' : '')
                + '<h4>Deine Rechte</h4><div class="vx-small">' + (!g.member ? 'Kein Mitglied' : g.owner ? 'Besitzer – alle Rechte' : g.perms.length ? g.perms.map(esc).join(', ') : 'keine besonderen Rechte') + '</div></div></div>';
            return;
        }
        if (name === 'invite') {
            const friends = (state ? state.friends : []).slice().sort((a, b) => a.name.localeCompare(b.name, 'de'));
            pane.innerHTML = '<form class="vx-tools" id="gInvite"><select name="user" required><option value="">Freund auswählen …</option>' + friends.map(f => '<option value="' + esc(f.id) + '">' + esc(f.name) + (f.state !== 'offline' ? ' 🟢' : '') + '</option>').join('')
                + '</select><button class="btn btn-gold">✉ Einladen</button></form><p class="vx-small">Die Person bekommt eine Gruppen-Einladung in VRChat.</p>';
            $('#gInvite').addEventListener('submit', e => { e.preventDefault(); api('group_invite', { id: g.id, user: e.target.user.value }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); });
            return;
        }
        loading(pane);
        if (name === 'posts') {
            api('group_posts', { id: g.id }).then(d => {
                const mayPost = g.member && can(g, 'group-announcement-manage');
                pane.innerHTML = (mayPost ? '<form class="vx-post-form" id="gPost"><input type="text" name="title" maxlength="120" placeholder="Titel" required><textarea name="text" rows="4" maxlength="2000" placeholder="Text des Beitrags …" required></textarea>'
                    + '<div class="vx-tools"><select name="visibility"><option value="group">Nur für Mitglieder</option><option value="public">Öffentlich</option></select><label class="vx-auto"><input type="checkbox" name="notify"> Mitglieder benachrichtigen</label><button class="btn btn-gold">📢 Veröffentlichen</button></div></form>' : '')
                    + (d.items.length ? d.items.map(p => '<article class="vx-post"><header><b>' + esc(p.title) + '</b><small>' + esc(isoDT(p.created)) + ' · ' + esc(p.visibility) + '</small>'
                        + (mayPost ? '<button type="button" class="btn btn-sm btn-danger" data-gact="post_delete" data-post="' + esc(p.id) + '" data-user="" data-name="' + esc(p.title) + '">Löschen</button>' : '') + '</header>'
                        + (/^https:/.test(p.image) ? img(p.image, 'vx-post-img', '') : '') + '<div class="fmt">' + p.html + '</div></article>').join('') : '<div class="vx-empty">Noch keine Beiträge.</div>');
                const f = $('#gPost');
                if (f) f.addEventListener('submit', e => { e.preventDefault(); api('group_post', { id: g.id, title: f.title.value, text: f.text.value, visibility: f.visibility.value, notify: f.notify.checked ? 1 : '' }).then(r => { toast(r.msg); groupPane('posts'); }).catch(x => toast(x.message, true)); });
            }).catch(fail(pane));
            return;
        }
        if (name === 'friends') {
            const show = d => {
                const fr = d.friends;
                pane.innerHTML = '<p class="vx-small">' + (d.complete ? 'Alle deine Freunde in dieser Gruppe' : 'Geprüft: ' + d.checked + ' von ' + d.total + ' Freunden (Online & Favoriten zuerst – die Gruppe ist zu groß für eine vollständige Liste)') + '.</p>'
                    + (fr.length ? '<div class="vx-mchips">' + fr.map(m => '<button type="button" class="vx-mchip" data-user="' + esc(m.id) + '">' + img(m.image, 'vx-av xs', m.name) + statusDot(m.state === 'offline' ? 'offline' : m.status) + esc(m.name)
                        + (m.rep ? ' <span class="vx-pill rep">★</span>' : '') + (m.world ? ' <small>🌐 ' + esc(m.world) + '</small>' : '') + '</button>').join('') + '</div>' : '<div class="vx-empty">Keine deiner Freunde ist in dieser Gruppe.</div>');
            };
            if (g.friendsData) show(g.friendsData);
            else { loading(pane, 'Suche deine Freunde in der Gruppe …'); api('group_friends', { id: g.id }).then(d => { g.friendsData = d; show(d); }).catch(fail(pane)); }
            return;
        }
        if (name === 'instances') {
            api('group_instances', { id: g.id }).then(d => {
                pane.innerHTML = d.items.length ? '<div class="vx-inst">' + d.items.map(i => '<div class="vx-inst-card">' + img(i.thumb, 'vx-inst-img', '') + '<div><b><a href="#" data-world="' + esc(i.loc.world || '') + '">' + esc(i.world) + '</a></b><small>' + esc(i.loc.label || '') + ' · ' + i.users + (i.capacity ? '/' + i.capacity : '') + ' Personen</small>'
                    + '<span class="vx-u-btns"><a class="btn btn-sm btn-gold" href="' + esc(launch(i.loc.location || '')) + '">▶ Beitreten</a><button type="button" class="btn btn-sm" data-self="' + esc(i.loc.location || '') + '">📨 Selbst-Einladung</button><button type="button" class="btn btn-sm" data-inst="' + esc(i.loc.location || '') + '">ℹ</button></span></div></div>').join('') + '</div>'
                    : '<div class="vx-empty">Gerade keine offenen Gruppen-Instanzen.</div>';
            }).catch(fail(pane));
            return;
        }
        if (name === 'audit') {
            api('group_audit', { id: g.id }).then(d => {
                pane.innerHTML = d.items.length ? '<div class="vx-feed">' + d.items.map(l => '<div class="vx-feed-row"><time>' + esc(new Date(l.ts).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })) + '</time><span class="vx-feed-ic">📋</span><span class="vx-feed-txt"><b>' + esc(l.actor) + '</b> ' + esc(l.desc || l.type) + ' <small class="muted">' + esc(l.type) + '</small></span></div>').join('') + '</div>'
                    : '<div class="vx-empty">Keine Einträge.</div>';
            }).catch(fail(pane));
            return;
        }
        const act = { members: 'group_members', requests: 'group_requests', bans: 'group_bans' }[name];
        api(act, { id: g.id, offset: offset || 0 }).then(d => {
            const roleSel = m => can(g, 'group-roles-assign') && g.roles.length ? '<select class="vx-rolesel" data-user="' + esc(m.id) + '" aria-label="Rolle vergeben"><option value="">＋ Rolle</option>' + g.roles.filter(r => !(m.roles || []).includes(r.id)).map(r => '<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>').join('') + '</select>' : '';
            const actions = m => name === 'requests' ? btn('accept', '✓ Aufnehmen', 'btn-gold', m) + btn('reject', '✕ Ablehnen', '', m) + btn('block', '⛔ Ablehnen + sperren', 'btn-danger', m)
                : name === 'bans' ? btn('unban', 'Bann aufheben', '', m)
                : roleSel(m) + (g.member && can(g, 'group-members-remove') ? btn('kick', 'Entfernen', '', m) : '') + (g.member && can(g, 'group-bans-manage') ? btn('ban', 'Bannen', 'btn-danger', m) : '');
            let h = d.items.length ? d.items.map(m => memberRow(m, actions)).join('') : '<div class="vx-empty">' + ({ members: 'Keine Mitglieder sichtbar.', requests: 'Keine offenen Beitrittsanfragen.', bans: 'Niemand gebannt.' }[name]) + '</div>';
            if (name === 'members' && (offset || d.items.length === 50)) h += '<div class="vx-pager">' + (offset ? '<button type="button" class="btn btn-sm" data-page="' + (offset - 50) + '">‹ Zurück</button>' : '') + (d.items.length === 50 ? '<button type="button" class="btn btn-sm" data-page="' + ((offset || 0) + 50) + '">Weiter ›</button>' : '') + '</div>';
            pane.innerHTML = (name === 'members' ? '<input type="search" class="vx-msearch" placeholder="Mitglieder auf dieser Seite filtern …" aria-label="Mitglieder filtern">' : '') + '<div class="vx-members">' + h + '</div>';
            $$('[data-page]', pane).forEach(b => b.addEventListener('click', () => groupPane('members', +b.dataset.page)));
            const ms = $('.vx-msearch', pane);
            if (ms) ms.addEventListener('input', () => $$('.vx-member', pane).forEach(r => { r.hidden = !r.textContent.toLowerCase().includes(ms.value.toLowerCase()); }));
            $$('.vx-rolesel', pane).forEach(sel => sel.addEventListener('change', () => {
                if (!sel.value) return;
                api('group_role', { id: g.id, user: sel.dataset.user, role: sel.value, on: 1 }).then(r => { toast(r.msg); groupPane('members', offset); }).catch(x => { toast(x.message, true); sel.value = ''; });
            }));
        }).catch(fail(pane));
    }
    $('#groupDetail').addEventListener('click', e => {
        const b = e.target.closest('[data-gact]');
        if (!b) return;
        e.preventDefault();
        e.stopPropagation();
        const a = b.dataset.gact, who = b.dataset.name;
        const q = { kick: '„' + who + '“ aus der Gruppe entfernen?', ban: '„' + who + '“ aus der Gruppe bannen?', block: 'Anfrage von „' + who + '“ ablehnen und weitere Anfragen sperren?',
                    post_delete: 'Beitrag „' + who + '“ löschen?', unrole: 'Rolle „' + roleName(b.dataset.role) + '“ von „' + who + '“ entziehen?' }[a];
        if (q && !confirm(q)) return;
        const map = { accept: ['group_request', { decision: 'accept' }], reject: ['group_request', { decision: 'reject' }], block: ['group_request', { decision: 'reject', block: 1 }],
                      kick: ['group_kick', {}], ban: ['group_ban', {}], unban: ['group_unban', {}], post_delete: ['group_post_delete', { post: b.dataset.post }], unrole: ['group_role', { role: b.dataset.role, on: '' }] };
        b.disabled = true;
        api(map[a][0], Object.assign({ id: group.id, user: b.dataset.user }, map[a][1])).then(r => {
            toast(r.msg || 'Erledigt.');
            if (a === 'post_delete') b.closest('.vx-post').remove(); else if (a === 'unrole') b.closest('.vx-role').remove(); else b.closest('.vx-member').remove();
        }).catch(x => { toast(x.message, true); b.disabled = false; });
    }, true);

    // ---------- Benachrichtigungen (alte API + V2, mit Verlauf) ----------
    let nfView = 'open', nfLabels = {}, nfTimer = null;
    const fileImg = (id, cls) => /^file_[0-9a-f-]{36}$/i.test(id || '') ? '<img class="' + cls + '" src="nexus.php?img=' + encodeURIComponent(id) + '" alt="" loading="lazy">' : '';
    const ago2 = ts => ts ? new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
    function loadNotifs(cached) {
        const box = $('#notifBox');
        if (!cached) loading(box, 'Hole Benachrichtigungen von VRChat …');
        api('notifications', { view: nfView, type: $('#nfType').value, q: $('#nfQ').value, cached: cached ? 1 : '' }).then(d => {
            loaded.notifs = true;
            nfLabels = d.labels;
            const sel = $('#nfType'), cur = sel.value;
            sel.innerHTML = '<option value="">Alle Arten</option>' + Object.entries(d.types).map(([t, n]) => '<option value="' + esc(t) + '">' + esc((d.labels[t] || ['•', t]).join(' ')) + ' (' + n + ')</option>').join('');
            sel.value = cur;
            const warn = (d.warn || []).map(w => '<div class="vx-error">⚠ ' + esc(w) + '</div>').join('');
            box.innerHTML = warn + (d.items.length ? '<div class="nf-table"><div class="nf-row nf-head"><span>Zeit</span><span>Art</span><span>Von</span><span>Gruppe</span><span></span><span>Nachricht</span><span>Aktionen</span></div>'
                + d.items.map(n => {
                    const lab = d.labels[n.type] || ['•', n.type];
                    const acts = [];
                    if (n.active && n.src === 'v1' && n.type === 'friendRequest') acts.push('<button type="button" class="nf-a ok" data-nop="accept" title="Annehmen">✓</button>');
                    if (n.active && n.src === 'v1' && n.type === 'requestInvite' && state && state.me.location) acts.push('<button type="button" class="nf-a ok" data-ninvite="' + esc(n.sender_id) + '" title="Einladen">📨</button>');
                    if (n.active) n.responses.forEach(r => { if (r.type !== 'delete') acts.push('<button type="button" class="nf-a' + (r.type === 'accept' ? ' ok' : r.type === 'block' || r.type === 'decline' ? ' bad' : '') + '" data-nop="respond" data-rtype="' + esc(r.type) + '" title="' + esc(r.text || d.resp_labels[r.type]) + '">' + esc((d.resp_labels[r.type] || r.type).split(' ')[0]) + '</button>'); });
                    if (n.active && !n.seen) acts.push('<button type="button" class="nf-a" data-nop="see" title="Als gelesen markieren">👁</button>');
                    acts.push(n.active ? '<button type="button" class="nf-a" data-nop="delete" title="Bei VRChat entfernen">🗑</button>' : '<button type="button" class="nf-a" data-nop="forget" title="Aus dem Verlauf löschen">✕</button>');
                    const msg = (n.title && n.message.indexOf(n.title) < 0 ? '<b>' + esc(n.title) + '</b> ' : '') + esc(n.message);
                    return '<div class="nf-row' + (n.seen ? '' : ' unseen') + (n.active ? '' : ' gone') + '" data-nid="' + esc(n.id) + '">'
                        + '<span class="mono small">' + ago2(n.created) + '</span>'
                        + '<span><span class="nf-type">' + esc(lab[0] + ' ' + lab[1]) + '</span></span>'
                        + '<span>' + (n.sender_id ? '<a href="#" data-user="' + esc(n.sender_id) + '">' + esc(n.sender_name) + '</a>' : esc(n.sender_name)) + '</span>'
                        + '<span>' + (n.group_id ? '<a href="#" data-ngroup="' + esc(n.group_id) + '">' + esc(n.group_name || 'Gruppe') + '</a>' : esc(n.group_name)) + '</span>'
                        + '<span>' + (n.image ? img(n.image, 'nf-img', '') : '') + '</span>'
                        + '<span class="nf-msg" title="' + esc(n.message) + '">' + msg + '</span>'
                        + '<span class="nf-acts">' + acts.join('') + '</span></div>';
                }).join('') + '</div>' : '<div class="vx-empty">' + (nfView === 'open' ? 'Keine offenen Benachrichtigungen. 🎉' : 'Noch nichts im Verlauf.') + '</div>');
            $('[data-tab=notifs]').innerHTML = '<span>🔔</span><em>Benachrichtigungen</em>' + (d.unseen ? ' <b class="vx-badge">' + d.unseen + '</b>' : '');
        }).catch(fail(box));
    }
    $('#nfView').addEventListener('click', e => { const b = e.target.closest('[data-view]'); if (!b) return; nfView = b.dataset.view; $$('#nfView .chip').forEach(c => c.classList.toggle('on', c === b)); loadNotifs(true); });
    $('#nfType').addEventListener('change', () => loadNotifs(true));
    $('#nfQ').addEventListener('input', () => { clearTimeout(nfTimer); nfTimer = setTimeout(() => loadNotifs(true), 300); });
    $('#nfReload').addEventListener('click', () => loadNotifs(false));
    $('#nfClear').addEventListener('click', () => { if (confirm('Alle Einträge aus dem Verlauf löschen, die es bei VRChat nicht mehr gibt?')) api('notif_clear').then(r => { toast(r.msg); loadNotifs(true); }).catch(x => toast(x.message, true)); });
    $('#notifBox').addEventListener('click', e => {
        const g = e.target.closest('[data-ngroup]');
        if (g) { e.preventDefault(); e.stopPropagation(); tab('groups'); openGroup(g.dataset.ngroup); return; }
        const inv = e.target.closest('[data-ninvite]');
        if (inv) { e.stopPropagation(); inv.disabled = true; api('invite', { id: inv.dataset.ninvite }).then(r => toast(r.msg || 'Eingeladen.')).catch(x => { toast(x.message, true); inv.disabled = false; }); return; }
        const b = e.target.closest('[data-nop]');
        if (!b) return;
        e.stopPropagation();
        const row = b.closest('[data-nid]');
        if (b.dataset.rtype === 'block' && !confirm('Wirklich blockieren?')) return;
        b.disabled = true;
        api('notif_act', { id: row.dataset.nid, op: b.dataset.nop, rtype: b.dataset.rtype || '' }).then(r => {
            toast(r.msg || 'Erledigt.');
            if (b.dataset.nop === 'accept') load(true, true);
            loadNotifs(true);
        }).catch(x => { toast(x.message, true); b.disabled = false; });
    }, true);

    // ---------- Shop ----------
    const credits = n => '<span class="sh-cr">◈ ' + num(n) + '</span>';
    const LT = { subscription: 'Abo', permanent: 'Dauerhaft', instant: 'Sofort', duration: 'Zeitlich' };
    const listingCard = l => '<div class="sh-card' + (l.active ? '' : ' off') + '">' + (l.image ? img(l.image, 'sh-img', '') : fileImg(l.image_id, 'sh-img') || '<span class="sh-img vx-noimg">🛒</span>')
        + '<div class="sh-body"><b>' + esc(l.name) + '</b>' + (l.subtitle ? '<small>' + esc(l.subtitle) + '</small>' : '')
        + '<div class="sh-meta">' + credits(l.price) + (l.vrcplus_price !== null && l.vrcplus_price !== l.price ? ' <span class="sh-plus" title="Preis mit VRC+">VRC+ ' + num(l.vrcplus_price) + '</span>' : '')
        + ' · ' + esc(LT[l.type] || l.type) + (l.duration ? ' · ' + esc(l.duration + ' ' + l.duration_type) : '') + (l.kinds.length ? ' · ' + esc(l.kinds.join(', ')) : '') + '</div>'
        + '<div class="sh-meta">' + (l.seller_id ? 'von <a href="#" data-user="' + esc(l.seller_id) + '">' + esc(l.seller) + '</a>' : esc(l.seller)) + (l.group ? ' · 🛡 ' + esc(l.group) : '') + (l.sold ? ' · ' + num(l.sold) + '× verkauft' : '') + (l.by_vrc ? ' · VRChat' : '') + '</div>'
        + (l.desc ? '<p class="sh-desc">' + esc(l.desc) + '</p>' : '') + '</div></div>';
    function loadShop() {
        const box = $('#shopBox');
        loading(box, 'Lade Shop-Konto …');
        api('shop').then(d => {
            loaded.shop = true;
            const active = d.subs.filter(s => s.active);
            box.innerHTML = '<div class="sh-kpis">'
                + '<div class="sh-kpi"><span>◈</span><b>' + (d.balance === null ? '–' : num(d.balance)) + '</b><small>VRChat Credits</small></div>'
                + '<div class="sh-kpi"><span>💎</span><b>' + (active.length ? 'aktiv' : 'kein Abo') + '</b><small>' + (active.length ? esc(active.map(s => s.name).join(', ')) + (active[0].expires ? ' · bis ' + esc(isoDate(active[0].expires)) : '') : 'VRC+') + '</small></div>'
                + '<div class="sh-kpi"><span>🎟️</span><b>' + num(d.licenses) + '</b><small>aktive Lizenzen</small></div>'
                + '<div class="sh-kpi"><span>🧾</span><b>' + num(d.purchases.length) + '</b><small>Käufe</small></div>'
                + '<div class="sh-kpi"><span>🏷️</span><b>' + num(d.listings.length) + '</b><small>eigene Angebote</small></div></div>'
                + (d.errors.length ? '<div class="vx-note small">ℹ Nicht verfügbar: ' + esc(d.errors.join(' · ')) + '</div>' : '')
                + '<p class="muted small">Kaufen geht bewusst nur im <a href="https://vrchat.com/home/marketplace" target="_blank" rel="noopener noreferrer">VRChat-Marktplatz</a> – hier kannst du alles in Ruhe ansehen.</p>'
                + (d.subs.length ? '<div class="vx-sec"><h3>💎 Abos</h3><div class="sh-list">' + d.subs.map(s => '<div class="sh-row"><b>' + esc(s.name) + '</b><span>' + (s.active ? '🟢 aktiv' : '⚪ ' + esc(s.status || 'beendet')) + '</span><span class="muted small">'
                    + esc([({ year: 'jährlich', month: 'monatlich' })[s.period] || s.period, s.store, s.gift ? 'Geschenk' : '', s.starts ? 'seit ' + isoDate(s.starts) : '', s.expires ? 'bis ' + isoDate(s.expires) : ''].filter(Boolean).join(' · ')) + '</span></div>').join('') + '</div></div>' : '')
                + '<div class="vx-sec"><h3>🧾 Meine Käufe</h3>' + (d.purchases.length ? '<div class="sh-list">' + d.purchases.map(p => '<div class="sh-row">' + (fileImg(p.image_id, 'sh-thumb') || '<span class="sh-thumb vx-noimg">🛒</span>')
                    + '<b>' + esc(p.name) + (p.subtitle ? ' <small>' + esc(p.subtitle) + '</small>' : '') + '</b><span>' + credits(p.price) + '</span><span class="muted small">' + esc(isoDate(p.date))
                    + (p.seller ? ' · von <a href="#" data-user="' + esc(p.seller_id) + '">' + esc(p.seller) + '</a>' : '') + (p.gift ? ' · 🎁 ' + esc(p.receiver ? 'an ' + p.receiver : 'Geschenk') : '')
                    + (p.end ? ' · bis ' + esc(isoDate(p.end)) : '') + (p.recurs ? ' · verlängert sich' : '') + (p.active ? '' : ' · abgelaufen') + '</span></div>').join('') + '</div>' : '<div class="vx-empty">Noch nichts gekauft.</div>') + '</div>'
                + (d.listings.length ? '<div class="vx-sec"><h3>🏷️ Meine Angebote</h3><div class="sh-grid">' + d.listings.map(listingCard).join('') + '</div></div>' : '');
        }).catch(fail(box));
    }
    $('#shopForm').addEventListener('submit', e => {
        e.preventDefault();
        const box = $('#shopBrowse');
        loading(box, 'Lade Angebote …');
        api('shop_browse', { target: e.target.target.value }).then(d => {
            box.innerHTML = '<div class="vx-sec"><h3>🛒 ' + esc(d.title) + (d.seller ? ' <small>von ' + esc(d.seller) + '</small>' : '') + '</h3>'
                + (d.shelves.some(s => s.listings.length) ? d.shelves.filter(s => s.listings.length).map(s => (d.shelves.length > 1 ? '<h4 class="sh-shelf">' + esc(s.title) + (s.desc ? ' <small>' + esc(s.desc) + '</small>' : '') + '</h4>' : '')
                    + '<div class="sh-grid">' + s.listings.map(listingCard).join('') + '</div>').join('') : '<div class="vx-empty">Keine Angebote gefunden.</div>') + '</div>';
        }).catch(fail(box));
    });

    // ---------- VRChat weltweit ----------
    let gDays = 1;
    function sparkPath(series, w, h) {
        if (series.length < 2) return '';
        const t0 = series[0][0], t1 = series[series.length - 1][0], vals = series.map(s => s[1]);
        const lo = Math.min(...vals) * 0.97, hi = Math.max(...vals) * 1.01 || 1;
        return series.map((s, i) => (i ? 'L' : 'M') + ((s[0] - t0) / Math.max(1, t1 - t0) * w).toFixed(1) + ' ' + (h - (s[1] - lo) / Math.max(1, hi - lo) * h).toFixed(1)).join(' ');
    }
    function loadGlobal() {
        const box = $('#globalBox');
        if (!box.innerHTML) loading(box, 'Frage VRChat …');
        api('global_stats', { days: gDays }).then(d => {
            const diff = d.yesterday ? Math.round((d.now - d.yesterday) / d.yesterday * 100) : null;
            const W = 900, H = 160, path = sparkPath(d.series, W, H);
            const days = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
            const hv = d.heat.flat().filter(v => v !== null), hmax = Math.max(1, ...hv), hmin = hv.length ? Math.min(...hv) : 0;
            const fr = d.friends || { n: 0, online: 0 };
            box.innerHTML = '<div class="sh-kpis">'
                + '<div class="sh-kpi big"><span>🌍</span><b>' + (d.now === null ? '–' : num(d.now)) + '</b><small>gerade in VRChat' + (diff !== null ? ' · <span class="' + (diff >= 0 ? 'up' : 'down') + '">' + (diff >= 0 ? '▲ +' : '▼ ') + diff + ' % zu gestern</span>' : '') + '</small></div>'
                + '<div class="sh-kpi"><span>📈</span><b>' + (d.max === null ? '–' : num(d.max)) + '</b><small>Spitze (' + (gDays === 1 ? '24 Std.' : gDays + ' Tage') + ')</small></div>'
                + '<div class="sh-kpi"><span>📉</span><b>' + (d.min === null ? '–' : num(d.min)) + '</b><small>Tiefstwert</small></div>'
                + '<div class="sh-kpi"><span>⌀</span><b>' + (d.avg === null ? '–' : num(d.avg)) + '</b><small>Durchschnitt</small></div>'
                + '<div class="sh-kpi"><span>🏆</span><b>' + (d.record ? num(d.record.online) : '–') + '</b><small>Rekord' + (d.record ? ' · ' + ago2(d.record.ts) : '') + '</small></div>'
                + '<div class="sh-kpi"><span>👥</span><b>' + num(fr.online) + ' / ' + num(fr.n) + '</b><small>deiner Freund*innen im Spiel</small></div></div>'
                + '<div class="vx-sec"><h3>Spieler online <span class="chips gl-days">' + [[1, '24 Std.'], [7, '7 Tage'], [30, '30 Tage'], [365, '1 Jahr']].map(([v, l]) => '<button type="button" class="chip' + (v === gDays ? ' on' : '') + '" data-gdays="' + v + '">' + l + '</button>').join('') + '</span></h3>'
                + (path ? '<svg class="gl-chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none"><path d="' + path + ' L' + W + ' ' + H + ' L0 ' + H + ' Z" class="gl-area"/><path d="' + path + '" class="gl-line"/></svg>'
                    + '<div class="gl-axis"><span>' + ago2(d.series[0][0]) + '</span><span>' + d.samples + ' Messpunkte</span><span>' + ago2(d.series[d.series.length - 1][0]) + '</span></div>'
                    : '<div class="vx-empty">Noch zu wenige Messpunkte – ' + (window.VRCX.app ? 'VRC Nexus misst alle 15 Minuten, solange die App läuft.' : 'das Dashboard misst alle 10–15 Minuten (Aufgabenplaner „VRChat-Spielerzahl“).') + ' Morgen gibt es hier eine Kurve.</div>')
                + '</div>'
                + (hv.length > 5 ? '<div class="vx-sec"><h3>⌀ Spieler je Wochentag & Uhrzeit <small>seit ' + new Date(d.since * 1000).toLocaleDateString('de-DE') + '</small></h3><div class="gl-heat"><span></span>'
                    + Array.from({ length: 24 }, (_, h) => '<span class="gl-h">' + (h % 3 ? '' : h) + '</span>').join('')
                    + d.heat.map((row, i) => '<span class="gl-d">' + days[i] + '</span>' + row.map((v, h) => '<i title="' + days[i] + ' ' + h + ' Uhr: ' + (v === null ? 'keine Daten' : num(v)) + '" style="opacity:' + (v === null ? 0.06 : (0.15 + 0.85 * (v - hmin) / Math.max(1, hmax - hmin)).toFixed(2)) + '"></i>').join('')).join('')
                    + '</div></div>' : '');
        }).catch(fail(box));
        if (!loaded.hot) api('hot_worlds').then(d => {
            loaded.hot = true;
            $('#hotWorlds').innerHTML = d.worlds.length ? '<div class="gl-worlds">' + d.worlds.map((w, i) => '<a href="#" class="gl-w" data-world="' + esc(w.id) + '">' + img(w.thumb, 'gl-wimg', '') + '<span class="gl-rank">' + (i + 1) + '</span><b>' + esc(w.name) + '</b><small>👥 ' + num(w.occupants) + ' · ⭐ ' + num(w.favorites) + ' · von ' + esc(w.author) + '</small></a>').join('') + '</div>' : '<div class="vx-empty">Keine Daten.</div>';
        }).catch(fail($('#hotWorlds')));
    }
    $('#globalBox').addEventListener('click', e => { const b = e.target.closest('[data-gdays]'); if (b) { gDays = +b.dataset.gdays; loadGlobal(); } });

    // ---------- Moderation ----------
    const MT = { block: '⛔ Blockiert', mute: '🔇 Stummgeschaltet', unmute: '🔊 Immer hören', hideAvatar: '🙈 Avatar ausgeblendet', showAvatar: '👁 Avatar immer zeigen', interactOff: '✋ Interaktion aus', interactOn: '🤝 Interaktion an', muteChat: '💬 Chat stumm' };
    function loadModeration() {
        const box = $('#modBox');
        loading(box);
        api('moderations').then(d => {
            const by = {};
            d.items.forEach(m => { (by[m.type] = by[m.type] || []).push(m); });
            box.innerHTML = d.items.length ? Object.entries(by).map(([t, list]) => '<div class="vx-sec"><h3>' + esc(MT[t] || t) + ' <small>' + list.length + '</small></h3><div class="vx-members">'
                + list.map(m => '<div class="vx-member"><a href="#" data-user="' + esc(m.user) + '">' + esc(m.name) + '</a><small>' + esc(isoDT(m.created)) + '</small><span class="vx-m-acts"><button type="button" class="btn btn-sm" data-mact="' + esc(t) + '" data-user="' + esc(m.user) + '">Aufheben</button></span></div>').join('')
                + '</div></div>').join('') : '<div class="vx-empty">Keine Moderationen – du hast niemanden blockiert oder stummgeschaltet.</div>';
        }).catch(fail(box));
    }
    $('#modBox').addEventListener('click', e => {
        const b = e.target.closest('[data-mact]');
        if (!b) return;
        e.stopPropagation();
        b.disabled = true;
        api('unmoderate', { user: b.dataset.user, type: b.dataset.mact }).then(r => { toast(r.msg); b.closest('.vx-member').remove(); }).catch(x => { toast(x.message, true); b.disabled = false; });
    }, true);

    // ---------- Suche ----------
    let searchKind = 'users';
    $('#searchKinds').addEventListener('click', e => { const c = e.target.closest('[data-kind]'); if (!c) return; searchKind = c.dataset.kind; $$('#searchKinds .chip').forEach(x => x.classList.toggle('on', x === c)); $('#userSearch').focus(); });
    $('#searchForm').addEventListener('submit', e => {
        e.preventDefault();
        const box = $('#searchBox'), q = $('#userSearch').value;
        loading(box, 'Suche …');
        if (searchKind === 'worlds') { api('worlds', { kind: 'search', q }).then(d => { box.innerHTML = d.worlds.length ? '<div class="vx-cards">' + d.worlds.map(worldCard).join('') + '</div>' : '<div class="vx-empty">Keine Welten gefunden.</div>'; }).catch(fail(box)); return; }
        if (searchKind === 'groups') {
            api('group_search', { q }).then(d => { box.innerHTML = d.groups.length ? '<div class="vx-groups">' + d.groups.map(g => '<button type="button" class="vx-group" data-opengroup="' + esc(g.id) + '">' + img(g.icon, 'vx-g-icon', '') + '<span><b>' + esc(g.name) + '</b><small>' + esc(g.short) + ' · ' + g.members + ' Mitglieder</small></span></button>').join('') + '</div>' : '<div class="vx-empty">Keine Gruppen gefunden.</div>'; }).catch(fail(box));
            return;
        }
        api('search', { q }).then(d => {
            box.innerHTML = d.users.length ? '<div class="vx-grid">' + d.users.map(u => '<div class="vx-friend" data-user="' + esc(u.id) + '" tabindex="0" role="button">' + img(u.image, 'vx-av', u.name)
                + '<span class="vx-f-main"><span class="vx-f-name">' + statusDot(u.status) + '<b style="color:' + esc(u.trust[1]) + '">' + esc(u.name) + '</b>' + (u.is_friend ? ' 🤝' : '') + '</span><span class="vx-f-sd">' + esc(u.trust[0]) + (u.status_desc ? ' · ' + esc(u.status_desc) : '') + '</span></span></div>').join('') + '</div>'
                : '<div class="vx-empty">Niemanden gefunden.</div>';
        }).catch(fail(box));
    });

    // ---------- Konto ----------
    $('#statusForm').addEventListener('input', e => { e.currentTarget.dataset.touched = '1'; });
    $('#statusForm').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target;
        api('status', { status: (f.querySelector('input[name=status]:checked') || {}).value || 'active', desc: f.desc.value })
            .then(r => { toast(r.msg); delete f.dataset.touched; load(true, true); }).catch(x => toast(x.message, true));
    });
    function renderPresets() {
        $('#statusPresets').innerHTML = (prefs.presets || []).map((p, i) => '<span class="chip vx-preset" data-preset="' + i + '">' + statusDot(p.status) + esc(p.desc || (ST[p.status] || {}).label) + ' <button type="button" data-predel="' + i + '" title="Vorlage löschen">×</button></span>').join('');
    }
    $('#presetSave').addEventListener('click', () => {
        const f = $('#statusForm'), s = (f.querySelector('input[name=status]:checked') || {}).value || 'active';
        prefs.presets = (prefs.presets || []).filter(p => !(p.status === s && p.desc === f.desc.value)).concat([{ status: s, desc: f.desc.value }]).slice(-8);
        store.set('prefs', prefs); renderPresets(); toast('Vorlage gespeichert.');
    });
    $('#statusPresets').addEventListener('click', e => {
        const d = e.target.closest('[data-predel]');
        if (d) { prefs.presets.splice(+d.dataset.predel, 1); store.set('prefs', prefs); renderPresets(); return; }
        const p = e.target.closest('[data-preset]');
        if (!p) return;
        const v = prefs.presets[+p.dataset.preset], f = $('#statusForm');
        const r = f.querySelector('input[value="' + CSS.escape(v.status) + '"]'); if (r) r.checked = true;
        f.desc.value = v.desc; f.dataset.touched = '1';
        f.requestSubmit();
    });
    function fillProfile() {
        const f = $('#profileForm');
        if (f.dataset.touched) return;
        f.pronouns.value = state.me.pronouns || '';
        f.bio.value = state.me.bio || '';
        $$('input[name="links[]"]', f).forEach((i, k) => { i.value = (state.me.links || [])[k] || ''; });
    }
    $('#profileForm').addEventListener('input', e => { e.currentTarget.dataset.touched = '1'; });
    $('#profileForm').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target;
        api('profile_save', { pronouns: f.pronouns.value, bio: f.bio.value, links: $$('input[name="links[]"]', f).map(i => i.value) }).then(r => { toast(r.msg); delete f.dataset.touched; load(false); }).catch(x => toast(x.message, true));
    });
    const pf = $('#prefsForm');
    pf.notify.value = prefs.notify; pf.notify_offline.checked = !!prefs.notify_offline; pf.notify_gps.checked = !!prefs.notify_gps; pf.sound.checked = !!prefs.sound; pf.interval.value = String(prefs.interval || 60); pf.clipwatch.checked = !!prefs.clipwatch;
    if (window.GenderMode) pf.gender.value = window.GenderMode.get(); else setTimeout(() => { if (window.GenderMode) pf.gender.value = window.GenderMode.get(); }, 500);
    pf.gender.addEventListener('change', e => { e.stopPropagation(); if (window.GenderMode) window.GenderMode.set(pf.gender.value); });
    pf.addEventListener('change', () => {
        Object.assign(prefs, { notify: pf.notify.value, notify_offline: pf.notify_offline.checked, notify_gps: pf.notify_gps.checked, sound: pf.sound.checked, interval: +pf.interval.value, clipwatch: pf.clipwatch.checked });
        if (prefs.clipwatch && navigator.clipboard && navigator.clipboard.readText) navigator.clipboard.readText().catch(() => {});
        store.set('prefs', prefs);
        if (prefs.notify !== 'off' && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission();
        schedule();
        toast('Gespeichert.');
    });
    $('#notifyTest').addEventListener('click', () => {
        if (!('Notification' in window)) return toast('Dieser Browser kann keine Benachrichtigungen.', true);
        Notification.requestPermission().then(p => { if (p === 'granted') notify('VRC Nexus', 'So sehen Benachrichtigungen aus 🐾'); else toast('Benachrichtigungen sind im Browser blockiert.', true); });
    });
    $('#logoutBtn').addEventListener('click', () => { if (confirm('VRChat-Verbindung trennen? Freunde, Verlauf und Notizen bleiben gespeichert.')) api('logout').then(() => location.reload()).catch(x => toast(x.message, true)); });

    // ---------- Gruppen-Moderation im Profil ----------
    const gcan = (g, p) => g.perms.includes('*') || g.perms.includes(p);
    function loadUserMutual(u) {
        const box = $('#uMutual');
        if (!box) return;
        api('user_mutuals', { id: u.id }).then(d => {
            const fr = d.friends || [], gr = d.groups || [];
            const chip = m => '<button type="button" class="vx-mchip" data-user="' + esc(m.id) + '">' + img(m.image, 'vx-av xs', m.name) + statusDot(m.state === 'offline' ? 'offline' : (m.status || 'offline')) + esc(m.name) + '</button>';
            box.innerHTML = '<div class="vx-small">👥 <b>' + fr.length + '</b> gemeinsame Freunde' + (d.friends_error ? ' <span class="muted">(' + esc(d.friends_error) + ')</span>' : '') + '</div>'
                + (fr.length ? '<div class="vx-mchips">' + fr.slice(0, 40).map(chip).join('') + (fr.length > 40 ? '<span class="vx-small">… und ' + (fr.length - 40) + ' weitere</span>' : '') + '</div>' : '')
                + '<div class="vx-small" style="margin-top:6px">🛡️ <b>' + gr.length + '</b> gemeinsame Gruppen</div>'
                + (gr.length ? '<div class="vx-mchips">' + gr.map(g => '<button type="button" class="vx-mchip" data-opengroup="' + esc(g.id) + '">' + img(g.icon, 'vx-av xs', g.name) + esc(g.name) + (g.members ? ' <small>' + g.members + '</small>' : '') + '</button>').join('') + '</div>' : '');
        }).catch(x => { box.innerHTML = '<div class="vx-small muted">' + esc(x.message) + '</div>'; });
    }
    function loadUserMod(u) {
        const box = $('#uMod');
        if (!box) return;
        api('user_mod', { id: u.id }).then(d => {
            if (!d.groups.length) { const sec = $('#uModSec'); if (sec) sec.parentNode.removeChild(sec); return; }
            box.innerHTML = d.groups.map(g => {
                const m = g.member, roleName = id => (g.roles.find(r => r.id === id) || {}).name || '';
                const b = (act, label, cls, extra) => '<button type="button" class="btn btn-sm ' + (cls || '') + '" data-umod="' + act + '" data-g="' + esc(g.id) + '"' + (extra || '') + '>' + label + '</button>';
                return '<div class="vx-umod">' + img(g.icon, 'vx-g-icon sm', '') + '<div><b>' + esc(g.name) + '</b><small>' + (m ? '✅ Mitglied seit ' + esc(isoDate(m.joined)) + (m.rep ? ' · ★ repräsentiert' : '') : '— kein Mitglied') + '</small>'
                    + (m && m.roles.length ? '<div>' + m.roles.filter(roleName).map(r => '<span class="vx-pill vx-role">' + esc(roleName(r)) + (gcan(g, 'group-roles-assign') ? '<button type="button" data-umod="unrole" data-g="' + esc(g.id) + '" data-role="' + esc(r) + '" title="Rolle entziehen">×</button>' : '') + '</span>').join('') + '</div>' : '')
                    + '<span class="vx-u-btns">'
                    + (m && gcan(g, 'group-roles-assign') && g.roles.length ? '<select class="vx-rolesel" data-umodrole="' + esc(g.id) + '"><option value="">＋ Rolle</option>' + g.roles.filter(r => !m.roles.includes(r.id)).map(r => '<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>').join('') + '</select>' : '')
                    + (!m && gcan(g, 'group-invites-manage') ? b('invite', '✉ Einladen', 'btn-gold') : '')
                    + (m && (gcan(g, 'group-members-remove') || gcan(g, 'group-members-manage')) ? b('kick', '👢 Entfernen') : '')
                    + (gcan(g, 'group-bans-manage') ? b('ban', '🔨 Bannen', 'btn-danger') + b('unban', 'Bann aufheben') : '')
                    + '<button type="button" class="btn btn-sm" data-opengroup="' + esc(g.id) + '">Gruppe öffnen</button></span></div></div>';
            }).join('');
        }).catch(x => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-umod]');
        if (!b) return;
        e.preventDefault();
        e.stopPropagation();
        const uid = $('#uMod').dataset.user, name = $('#uMod').dataset.name, a = b.dataset.umod;
        const ask = { kick: '„' + name + '“ aus der Gruppe entfernen?', ban: '„' + name + '“ in dieser Gruppe bannen?', unrole: 'Rolle entziehen?' }[a];
        if (ask && !confirm(ask)) return;
        const map = { invite: ['group_invite', {}], kick: ['group_kick', {}], ban: ['group_ban', {}], unban: ['group_unban', {}], unrole: ['group_role', { role: b.dataset.role, on: '' }] };
        b.disabled = true;
        api(map[a][0], Object.assign({ id: b.dataset.g, user: uid }, map[a][1])).then(r => { toast(r.msg || 'Erledigt.'); loadUserMod({ id: uid }); }).catch(x => { toast(x.message, true); b.disabled = false; });
    }, true);
    document.addEventListener('change', e => {
        const s = e.target.closest('[data-umodrole]');
        if (!s || !s.value) return;
        const uid = $('#uMod').dataset.user;
        api('group_role', { id: s.dataset.umodrole, user: uid, role: s.value, on: 1 }).then(r => { toast(r.msg); loadUserMod({ id: uid }); }).catch(x => { toast(x.message, true); s.value = ''; });
    });

    // ---------- VRChat-Kalender ----------
    const CAT = { hangout: '💬 Treffen', gaming: '🎮 Gaming', music: '🎵 Musik', dance: '💃 Tanz', performance: '🎭 Auftritt', arts: '🎨 Kunst', education: '📚 Bildung', exploration: '🧭 Erkunden',
                  film_media: '🎬 Film & Medien', avatars: '🦊 Avatare', wellness: '🧘 Wohlfühlen', roleplaying: '🐉 Rollenspiel', other: '📦 Sonstiges' };
    let calData = null;
    function loadCalendar(refresh) {
        const box = $('#calList');
        if (!calData || refresh) loading(box, 'Lade VRChat-Kalender …');
        api('calendar', { refresh: refresh ? 1 : '' }).then(d => {
            calData = d;
            $('#calInfo').innerHTML = (d.error ? '<span class="bad">⚠ ' + esc(d.error) + '</span> · ' : '') + d.events.length + ' Events · abgerufen ' + (d.refreshed ? esc(ago(d.refreshed)) : 'noch nie');
            const gs = [...new Map(d.events.map(e => [e.group, e.group_name])).entries()];
            const sel = $('#calGroup'), keep = sel.value;
            sel.innerHTML = '<option value="">Alle Gruppen</option>' + gs.map(([id, n]) => '<option value="' + esc(id) + '">' + esc(n || id) + '</option>').join('');
            sel.value = keep;
            if (d.app) $('#calSync').innerHTML = '<p class="vx-small">📅 Alle Events als Kalender-Datei für Outlook / Google / Handy:</p><a class="btn btn-sm" href="nexus.php?ics=1">⬇ Als .ics speichern</a>';
            else {
            $('#calSync').innerHTML = (d.can_link ? '<label class="vx-auto"><input type="checkbox" id="calLink"' + (d.linked ? ' checked' : '') + '> 🔄 Mit dem Dashboard-Kalender synchronisieren (privat, aktualisiert sich automatisch)</label>' : '<p class="vx-small">Für die Kalender-Verbindung fehlt dir das Recht „Kalender bearbeiten“.</p>')
                    + '<p class="vx-small">📱 Abo-Link für Handy / Outlook / Google-Kalender:</p><div class="vx-icsrow"><code>' + esc(d.ics) + '</code><button type="button" class="btn btn-sm" data-copy="' + esc(d.ics) + '">⧉</button><button type="button" class="btn btn-sm btn-danger" id="icsReset" title="Neuen geheimen Link erzeugen">↻</button></div>';
                const cl = $('#calLink');
                if (cl) cl.addEventListener('change', () => api('calendar_link', { on: cl.checked ? 1 : '' }).then(r => toast(r.msg)).catch(x => { toast(x.message, true); cl.checked = !cl.checked; }));
                $('#icsReset').addEventListener('click', () => { if (confirm('Neuen Abo-Link erzeugen? Der alte funktioniert dann nicht mehr.')) api('ics_reset').then(r => { toast(r.msg); loadCalendar(false); }).catch(x => toast(x.message, true)); });
            }
            const f = $('#calCreate');
            f.hidden = !d.can_create.length;
            f.group.innerHTML = d.can_create.map(g => '<option value="' + esc(g.id) + '">' + esc(g.name) + '</option>').join('');
            renderCalendar();
        }).catch(fail(box));
    }
    function renderCalendar() {
        if (!calData) return;
        const g = $('#calGroup').value, onlyFav = $('#calFav').checked, cat = $('#calCat').value;
        const list = calData.events.filter(e => (!g || e.group === g) && (!onlyFav || e.following) && (!cat || e.category === cat));
        const canDel = new Set(calData.can_create.map(x => x.id));
        let last = '', h = '';
        list.forEach(e => {
            const day = new Date(e.start * 1000).toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long' });
            if (day !== last) { h += '<div class="vx-day">' + esc(day) + '</div>'; last = day; }
            const now = Date.now() / 1000, live = e.start <= now && e.end > now;
            h += '<div class="vx-cal' + (e.following ? ' fav' : '') + (live ? ' live' : '') + '">' + (e.image ? img(e.image, 'vx-cal-img', '') : '<span class="vx-cal-img vx-noimg">📅</span>')
                + '<div class="vx-cal-main"><b>' + esc(e.title) + (live ? ' <span class="vx-pill" style="--pc:#50fa7b">läuft gerade</span>' : '') + '</b>'
                + '<small>🕐 ' + esc(new Date(e.start * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })) + '–' + esc(new Date(e.end * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }))
                + ' · <a href="#" data-opengroup="' + esc(e.group) + '">' + esc(e.group_name || 'Gruppe') + '</a> · ' + esc(CAT[e.category] || e.category || '') + (e.access === 'public' ? ' · 🌍 öffentlich' : ' · 🔒 Gruppe')
                + ' · ⭐ ' + e.interested + (e.platforms.length ? ' · ' + e.platforms.map(p => ({ standalonewindows: 'PC', android: 'Quest', ios: 'iOS' }[p] || p)).join('/') : '') + '</small>'
                + (e.html ? '<details><summary>Beschreibung</summary><div class="fmt">' + e.html + '</div></details>' : '') + '</div>'
                + '<span class="vx-m-acts"><button type="button" class="btn btn-sm' + (e.following ? ' btn-gold' : '') + '" data-calfollow="' + esc(e.id) + '" data-g="' + esc(e.group) + '" data-on="' + (e.following ? '' : '1') + '">' + (e.following ? '★ Interessiert' : '☆ Interessiert') + '</button>'
                + '<a class="btn btn-sm" href="https://vrchat.com/home/group/' + encodeURIComponent(e.group) + '/calendar/' + encodeURIComponent(e.id) + '" target="_blank" rel="noopener noreferrer">↗</a>'
                + (canDel.has(e.group) ? '<button type="button" class="btn btn-sm btn-danger" data-caldel="' + esc(e.id) + '" data-g="' + esc(e.group) + '" title="Event löschen">🗑</button>' : '') + '</span></div>';
        });
        $('#calList').innerHTML = h || '<div class="vx-empty">Keine kommenden Events in deinen Gruppen.</div>';
    }
    ['#calGroup', '#calFav', '#calCat'].forEach(s => $(s).addEventListener('change', renderCalendar));
    $('#calRefresh').addEventListener('click', () => loadCalendar(true));
    $('#calList').addEventListener('click', e => {
        const f = e.target.closest('[data-calfollow]'), d = e.target.closest('[data-caldel]');
        if (f) api('cal_follow', { id: f.dataset.calfollow, group: f.dataset.g, on: f.dataset.on }).then(r => { toast(r.msg); const ev = calData.events.find(x => x.id === f.dataset.calfollow); if (ev) { ev.following = !!f.dataset.on; ev.interested += f.dataset.on ? 1 : -1; } renderCalendar(); }).catch(x => toast(x.message, true));
        if (d && confirm('Event wirklich im VRChat-Kalender löschen?')) api('cal_delete', { id: d.dataset.caldel, group: d.dataset.g }).then(r => { toast(r.msg); loadCalendar(false); }).catch(x => toast(x.message, true));
    });
    $('#calCreate').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target, iso = v => v ? new Date(v).toISOString() : '';
        api('cal_create', { group: f.group.value, title: f.title.value, start: iso(f.start.value), end: iso(f.end.value), description: f.description.value, category: f.category.value,
                            access: f.access.value, notify: f.notify.checked ? 1 : '', platforms: $$('input[name=pf]:checked', f).map(c => c.value) })
            .then(r => { toast(r.msg); f.reset(); loadCalendar(false); }).catch(x => toast(x.message, true));
    });

    // ---------- Galerie: Fotos, Icons, Emojis, Sticker, Prints, Inventar ----------
    let galData = null, galKind = 'photos', invItems = [], invTotal = 0;
    const GAL = { photos: '📷 Fotos', icons: '🙂 Icons', emojis: '😺 Emojis', stickers: '🏷️ Sticker', prints: '🖨️ Prints', inventory: '🎒 Inventar' };
    function loadGallery(refresh) {
        loaded.gallery = true;
        if (!galData || refresh) loading($('#galBox'), 'Lade Galerie …');
        api('gallery').then(d => { galData = d; renderGalKinds(); renderGallery(); }).catch(fail($('#galBox')));
    }
    function renderGalKinds() {
        const cnt = k => k === 'inventory' ? (invTotal || '…') : Array.isArray(galData.gallery[k]) ? galData.gallery[k].length + (galData.limits[k] ? '/' + galData.limits[k] : '') : '!';
        $('#galKinds').innerHTML = Object.entries(GAL).map(([k, l]) => '<button type="button" class="chip' + (k === galKind ? ' on' : '') + '" data-gk="' + k + '">' + l + ' <small>' + cnt(k) + '</small></button>').join('');
        $('#galUpload').hidden = !['photos', 'icons', 'emojis', 'stickers'].includes(galKind);
        $('#galAnim').hidden = galKind !== 'emojis';
    }
    function renderGallery() {
        const box = $('#galBox');
        if (galKind === 'inventory') { if (!invItems.length) loadInventory(false); else renderInventory(); return; }
        const list = galData.gallery[galKind];
        if (!Array.isArray(list)) { box.innerHTML = '<div class="vx-error">⚠ ' + esc(list && list.error || 'Nicht verfügbar') + '</div>'; return; }
        if (!list.length) { box.innerHTML = '<div class="vx-empty">Hier ist noch nichts.</div>'; return; }
        box.innerHTML = '<div class="vx-gal' + (galKind === 'emojis' || galKind === 'stickers' || galKind === 'icons' ? ' sm' : '') + '">' + list.map(f => galKind === 'prints'
            ? '<figure class="vx-gitem"><img src="' + esc(f.img) + '" alt="" loading="lazy" data-zoom="' + esc(f.img) + '"><figcaption><b>' + esc(f.note || 'Print') + '</b><small>' + (f.world_id ? '<a href="#" data-world="' + esc(f.world_id) + '">' + esc(f.world) + '</a> · ' : esc(f.world) + ' · ') + esc(isoDT(f.created)) + '</small></figcaption>'
                + '<span class="vx-gacts"><button type="button" class="bad" data-gdel="' + esc(f.id) + '" data-print="1" title="Löschen">🗑</button></span></figure>'
            : '<figure class="vx-gitem"><img src="' + esc(f.img) + '" alt="" loading="lazy" data-zoom="' + esc(f.img) + '">' + (f.anim ? '<figcaption><small>✨ ' + esc(f.anim) + '</small></figcaption>' : '')
                + '<span class="vx-gacts">' + (galKind === 'icons' ? '<button type="button" data-seticon="' + esc(f.id) + '" data-v="' + f.v + '" title="Als Profil-Icon verwenden">👤</button>' : '')
                + '<button type="button" data-copy="' + esc(f.id) + '" title="Datei-ID kopieren">⧉</button><button type="button" class="bad" data-gdel="' + esc(f.id) + '" title="Löschen">🗑</button></span></figure>').join('') + '</div>';
    }
    function loadInventory(more) {
        const box = $('#galBox');
        if (!more) loading(box, 'Lade Inventar …');
        api('inventory', { offset: more ? invItems.length : 0 }).then(d => { invItems = more ? invItems.concat(d.items) : d.items; invTotal = d.total; if (galData) renderGalKinds(); renderInventory(); }).catch(fail(box));
    }
    function renderInventory() {
        const types = [...new Set(invItems.map(i => i.type).filter(Boolean))];
        const t = ($('#invType') || {}).value || '';
        const list = invItems.filter(i => !t || i.type === t);
        $('#galBox').innerHTML = '<div class="vx-tools"><select id="invType"><option value="">Alle Arten (' + invItems.length + ')</option>' + types.map(x => '<option value="' + esc(x) + '"' + (x === t ? ' selected' : '') + '>' + esc(x) + '</option>').join('') + '</select></div>'
            + (list.length ? '<div class="vx-gal sm">' + list.map(i => '<figure class="vx-gitem' + (i.archived ? ' arch' : '') + '">' + (i.img ? '<img src="' + esc(i.img) + '" alt="" loading="lazy" data-zoom="' + esc(i.img) + '">' : '<span class="vx-noimg vx-ginv">🎁</span>')
                + '<figcaption><b>' + esc(i.name) + '</b><small>' + esc(i.type) + (i.created ? ' · ' + esc(isoDate(i.created)) : '') + '</small></figcaption></figure>').join('') + '</div>' : '<div class="vx-empty">Inventar ist leer.</div>')
            + (invItems.length < invTotal ? '<button type="button" class="btn btn-sm" id="invMore">Weitere laden (' + (invTotal - invItems.length) + ')</button>' : '');
        $('#invType').addEventListener('change', renderInventory);
        const m = $('#invMore'); if (m) m.addEventListener('click', () => loadInventory(true));
    }
    $('#galKinds').addEventListener('click', e => { const c = e.target.closest('[data-gk]'); if (!c) return; galKind = c.dataset.gk; renderGalKinds(); renderGallery(); });
    $('#galRefresh').addEventListener('click', () => { invItems = []; loadGallery(true); });
    $('#galBox').addEventListener('click', e => {
        const z = e.target.closest('[data-zoom]'), del = e.target.closest('[data-gdel]'), ic = e.target.closest('[data-seticon]');
        if (z) { openModal('<img class="vx-zoom" src="' + esc(z.dataset.zoom) + '" alt="">'); return; }
        if (del && confirm('Wirklich löschen? Das lässt sich nicht rückgängig machen.')) api(del.dataset.print ? 'print_delete' : 'file_delete', { id: del.dataset.gdel }).then(r => { toast(r.msg); loadGallery(true); }).catch(x => toast(x.message, true));
        if (ic) api('set_icon', { id: ic.dataset.seticon, v: ic.dataset.v }).then(r => toast(r.msg)).catch(x => toast(x.message, true));
    });
    $('#galUpload').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target, body = new FormData(f);
        body.append('act', 'file_upload'); body.append('kind', galKind);
        const b = f.querySelector('button'); b.disabled = true; b.textContent = 'Lädt hoch …';
        fetch('nexus.php', { method: 'POST', credentials: 'same-origin', body }).then(r => r.json()).then(d => { if (d.error) throw new Error(d.error); toast(d.msg); f.reset(); loadGallery(true); })
            .catch(x => toast(x.message, true)).finally(() => { b.disabled = false; b.textContent = '⬆ Hochladen'; });
    });

    // ---------- Info-Push (VRChat-Neuigkeiten) ----------
    let newsData = [], newsKind = 'help';
    const NEWS = { help: '📘 Hilfe-Artikel', banner: '🪧 Quick-Menü-Banner', loading: '⏳ Ladebildschirme', other: '📦 Sonstiges' };
    function loadNews() {
        loaded.news = true;
        loading($('#newsBox'), 'Lade Neuigkeiten …');
        api('infopush').then(d => { newsData = d.items.sort((a, b) => String(b.date).localeCompare(String(a.date))); renderNews(); }).catch(fail($('#newsBox')));
    }
    function renderNews() {
        const cnt = k => newsData.filter(i => i.kind === k).length;
        $('#newsKinds').innerHTML = Object.entries(NEWS).filter(([k]) => cnt(k) || k === 'help').map(([k, l]) => '<button type="button" class="chip' + (k === newsKind ? ' on' : '') + '" data-nk="' + k + '">' + l + ' <small>' + cnt(k) + '</small></button>').join('');
        const list = newsData.filter(i => i.kind === newsKind);
        $('#newsBox').innerHTML = list.length ? list.map((i, n) => '<button type="button" class="vx-news' + (newsKind === 'loading' || newsKind === 'banner' ? ' wide' : '') + '" data-news="' + esc(i.id) + '">' + (i.img ? '<img src="' + esc(i.img) + '" alt="" loading="lazy">' : '<span class="vx-noimg">📰</span>')
            + '<span><b>' + esc(i.title) + '</b><small>' + esc(i.desc) + '</small></span><time>' + esc(isoDT(i.date)) + '</time></button>').join('') : '<div class="vx-empty">Nichts in dieser Kategorie.</div>';
    }
    $('#newsRefresh').addEventListener('click', loadNews);
    $('#newsKinds').addEventListener('click', e => { const c = e.target.closest('[data-nk]'); if (c) { newsKind = c.dataset.nk; renderNews(); } });
    $('#newsBox').addEventListener('click', e => {
        const b = e.target.closest('[data-news]');
        if (!b) return;
        const i = newsData.find(x => x.id === b.dataset.news);
        openModal((i.article_img || i.img ? '<img class="vx-zoom" src="' + esc(i.article_img || i.img) + '" alt="">' : '') + '<h2>' + esc(i.title) + '</h2><p class="vx-small">' + esc(isoDT(i.date)) + '</p>'
            + (i.article ? '<div class="fmt">' + i.article + '</div>' : '<p>' + esc(i.desc) + '</p>') + (i.link ? '<p><a class="btn btn-sm" href="' + esc(i.link) + '" target="_blank" rel="noopener noreferrer">↗ Mehr erfahren</a></p>' : ''));
    });

    // =============== Direkt-Zugriff (Zwischenablage, Einfügen, Befehlspalette) ===============
    const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    function parseRef(text) {
        const t = String(text || '').trim();
        if (!t) return null;
        let s = t;
        try { s = decodeURIComponent(t); } catch (e) {}
        let m = s.match(/worldId=(wrld_[0-9a-f-]{36})&(?:amp;)?instanceId=([^&\s"'<>]+)/i);
        if (m) return { type: 'instance', id: m[1] + ':' + m[2], label: 'Instanz' };
        m = s.match(new RegExp('(wrld_' + UUID + '):([\\w~()\\-.:]+)', 'i'));
        if (m) return { type: 'instance', id: m[1] + ':' + m[2], label: 'Instanz' };
        const kinds = [['wrld', 'world', 'Welt'], ['avtr', 'avatar', 'Avatar'], ['usr', 'user', 'Benutzer'], ['grp', 'group', 'Gruppe']];
        for (const [p, type, label] of kinds) { m = s.match(new RegExp('\\b' + p + '_' + UUID, 'i')); if (m) return { type, id: m[0].toLowerCase(), label }; }
        m = s.match(/(?:vrc\.group\/|^)([A-Z0-9]{3,6}\.\d{4})\b/i);
        if (m) return { type: 'groupcode', id: m[1].toUpperCase(), label: 'Gruppen-Kürzel' };
        return null;
    }
    const recent = store.get('recent', []);
    function remember(type, id, name) {
        const i = recent.findIndex(r => r.type === type && r.id === id);
        if (i >= 0) recent.splice(i, 1);
        recent.unshift({ type, id, name: name || id, ts: Math.round(now()) });
        recent.length = Math.min(recent.length, 25);
        store.set('recent', recent);
    }
    function openRef(ref) {
        if (!ref) return false;
        if (ref.type === 'user') openUser(ref.id);
        else if (ref.type === 'world') openWorld(ref.id);
        else if (ref.type === 'avatar') openAvatar(ref.id);
        else if (ref.type === 'instance') openInstance(ref.id);
        else if (ref.type === 'group') { closeModal(); tab('groups'); openGroup(ref.id); }
        else if (ref.type === 'groupcode') {
            api('group_search', { q: ref.id }).then(d => { const g = d.groups.find(x => x.short.toUpperCase() === ref.id) || d.groups[0]; if (g) openRef({ type: 'group', id: g.id }); else toast('Keine Gruppe mit dem Kürzel ' + ref.id + ' gefunden.', true); }).catch(x => toast(x.message, true));
        }
        return true;
    }
    // Strg+V irgendwo auf der Seite (außer in Eingabefeldern) öffnet erkannte IDs sofort
    document.addEventListener('paste', e => {
        if (e.target.closest('input, textarea, [contenteditable]')) return;
        const text = (e.clipboardData || window.clipboardData).getData('text');
        const ref = parseRef(text);
        if (ref) { e.preventDefault(); toast('📋 ' + ref.label + ' erkannt – wird geöffnet …'); openRef(ref); }
        else if (text.trim()) openPalette(text.trim());
    });
    // Button „Zwischenablage“
    function readClipboard(silent) {
        if (!navigator.clipboard || !navigator.clipboard.readText) { if (!silent) openPalette(''); return Promise.resolve(); }
        return navigator.clipboard.readText().then(text => {
            const ref = parseRef(text);
            if (ref) { if (silent) offerRef(ref, text); else openRef(ref); }
            else if (!silent) { toast('In der Zwischenablage ist keine VRChat-ID – einfach einfügen oder suchen.'); openPalette(text.trim().slice(0, 80)); }
        }).catch(() => { if (!silent) { toast('Der Browser erlaubt das Lesen der Zwischenablage nicht – einfach Strg+V drücken.'); openPalette(''); } });
    }
    let lastOffered = '';
    function offerRef(ref, raw) {
        if (raw === lastOffered) return;
        lastOffered = raw;
        $$('.vx-offer').forEach(x => x.remove());
        const d = document.createElement('div');
        d.className = 'vx-offer';
        d.innerHTML = '📋 In der Zwischenablage: <b>' + esc(ref.label) + '</b> <code>' + esc(ref.id.length > 46 ? ref.id.slice(0, 46) + '…' : ref.id) + '</code> <button type="button" class="btn btn-sm btn-gold">Öffnen</button><button type="button" class="vx-x" aria-label="Schließen">×</button>';
        d.querySelector('.btn').onclick = () => { d.remove(); openRef(ref); };
        d.querySelector('.vx-x').onclick = () => d.remove();
        document.body.append(d);
        setTimeout(() => d.remove(), 15000);
    }
    // Beim Zurückkehren in den Tab automatisch prüfen (nur wenn erlaubt)
    window.addEventListener('focus', () => {
        if (!prefs.clipwatch || !navigator.permissions) return;
        navigator.permissions.query({ name: 'clipboard-read' }).then(p => { if (p.state === 'granted') readClipboard(true); }).catch(() => {});
    });

    // ---------- Befehlspalette (Strg+K) ----------
    const pal = document.createElement('div');
    pal.className = 'pm vx-pal';
    pal.hidden = true;
    pal.innerHTML = '<div class="pm-box"><input type="search" id="palInput" placeholder="ID / Link einfügen, Freund suchen oder Befehl … (Strg+K)" autocomplete="off" aria-label="Befehlspalette"><div id="palList" role="listbox"></div>'
        + '<p class="vx-small">Erkennt <code>usr_…</code>, <code>wrld_…</code>, <code>avtr_…</code>, <code>grp_…</code>, Instanzen, vrchat.com-Links und Gruppen-Kürzel wie <code>FURRY.1234</code>.</p></div>';
    document.body.append(pal);
    let palItems = [], palSel = 0;
    const ACTIONS = [
        ['⟳', 'Jetzt aktualisieren', () => load(true, true)],
        ['🎲', 'Welt-Roulette (zufällige Welt)', () => roulette()],
        ['👥', 'Freunde in meine Instanz einladen', () => partyInvite()],
        ['🟦', 'Status: Komm vorbei', () => quickStatus('join me')], ['🟩', 'Status: Online', () => quickStatus('active')],
        ['🟧', 'Status: Frag mich', () => quickStatus('ask me')], ['🟥', 'Status: Beschäftigt', () => quickStatus('busy')],
        ['📋', 'Zwischenablage prüfen', () => readClipboard(false)],
        ...TABS.map(t => ['➡', 'Reiter: ' + $('[data-tab=' + t + ']').textContent.trim(), () => tab(t)]),
    ];
    function openPalette(q) {
        pal.hidden = false;
        document.body.style.overflow = 'hidden';
        const i = $('#palInput');
        i.value = q || '';
        renderPalette();
        setTimeout(() => i.focus(), 0);
    }
    function closePalette() { pal.hidden = true; if (modal.hidden) document.body.style.overflow = ''; }
    function renderPalette() {
        const q = $('#palInput').value.trim(), ql = q.toLowerCase(), ref = parseRef(q);
        palItems = [];
        if (ref) palItems.push({ ic: '📋', label: ref.label + ' öffnen', sub: ref.id, run: () => openRef(ref) });
        if (!q) recent.slice(0, 8).forEach(r => palItems.push({ ic: '🕘', label: r.name, sub: { user: 'Benutzer', world: 'Welt', avatar: 'Avatar', group: 'Gruppe', instance: 'Instanz' }[r.type] + ' · zuletzt angesehen', run: () => openRef(r) }));
        if (q && !ref) {
            (state ? state.friends : []).filter(f => f.name.toLowerCase().includes(ql)).slice(0, 6)
                .forEach(f => palItems.push({ ic: f.state === 'online' ? '🟢' : f.state === 'active' ? '💻' : '⚫', label: f.name, sub: f.state === 'online' && f.loc ? (f.loc.world_name || f.loc.label) : 'Freund', run: () => openUser(f.id) }));
            ACTIONS.filter(a => a[1].toLowerCase().includes(ql)).slice(0, 5).forEach(a => palItems.push({ ic: a[0], label: a[1], run: a[2] }));
            if (q.length >= 2) {
                palItems.push({ ic: '🔍', label: 'Benutzer suchen: „' + q + '“', run: () => { tab('search'); setSearch('users', q); } });
                palItems.push({ ic: '🌐', label: 'Welten suchen: „' + q + '“', run: () => { tab('search'); setSearch('worlds', q); } });
                palItems.push({ ic: '🛡️', label: 'Gruppen suchen: „' + q + '“', run: () => { tab('search'); setSearch('groups', q); } });
            }
        }
        if (!q) ACTIONS.slice(0, 8).forEach(a => palItems.push({ ic: a[0], label: a[1], run: a[2] }));
        palSel = 0;
        $('#palList').innerHTML = palItems.map((it, i) => '<button type="button" class="vx-pal-item' + (i ? '' : ' on') + '" data-i="' + i + '" role="option"><span>' + it.ic + '</span><b>' + esc(it.label) + '</b>' + (it.sub ? '<small>' + esc(it.sub) + '</small>' : '') + '</button>').join('')
            || '<div class="vx-empty">Nichts gefunden.</div>';
    }
    function runPal(i) { const it = palItems[i]; if (!it) return; closePalette(); it.run(); }
    $('#palInput').addEventListener('input', renderPalette);
    $('#palInput').addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            palSel = (palSel + (e.key === 'ArrowDown' ? 1 : -1) + palItems.length) % Math.max(1, palItems.length);
            $$('.vx-pal-item').forEach((b, i) => b.classList.toggle('on', i === palSel));
            const on = $('.vx-pal-item.on'); if (on) on.scrollIntoView({ block: 'nearest' });
        }
        if (e.key === 'Enter') { e.preventDefault(); runPal(palSel); }
        if (e.key === 'Escape') closePalette();
    });
    pal.addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (b) runPal(+b.dataset.i); else if (e.target === pal) closePalette(); });
    document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal.hidden ? openPalette('') : closePalette(); } });
    $('#clipBtn').addEventListener('click', () => readClipboard(false));
    $('#palBtn').addEventListener('click', () => openPalette(''));
    function setSearch(kind, q) {
        searchKind = kind;
        $$('#searchKinds .chip').forEach(x => x.classList.toggle('on', x.dataset.kind === kind));
        $('#userSearch').value = q;
        $('#searchForm').requestSubmit();
    }
    function quickStatus(s) { api('status', { status: s, desc: state.me.status_desc || '' }).then(r => { toast(r.msg); load(true, true); }).catch(x => toast(x.message, true)); }

    // ---------- Avatar-Dialog ----------
    function openAvatar(id) {
        openModal('<div class="vx-loading">Lade Avatar …</div>');
        api('avatar', { id }).then(d => {
            const a = d.avatar;
            remember('avatar', a.id, a.name);
            $('#userBody').innerHTML = (/^https:/.test(a.image) ? '<div class="vx-w-hero" style="background-image:url(\'' + esc(a.image.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"></div>' : '')
                + '<div class="vx-w-head"><div><h2>' + esc(a.name) + (a.current ? ' <span class="vx-pill rep">trägst du</span>' : '') + '</h2><div class="vx-u-sub">von <a href="#" data-user="' + esc(a.author_id) + '">' + esc(a.author) + '</a> · ' + esc(a.status) + (a.version ? ' · Version ' + a.version : '') + '</div>'
                + '<div class="vx-u-tags">' + a.platforms.map(p => '<span class="vx-pill">' + esc(p) + '</span>').join('') + a.performance.map(p => '<span class="vx-pill" style="--pc:#ffb86c">' + esc(p) + '</span>').join('') + a.tags.slice(0, 8).map(t => '<span class="vx-pill" style="--pc:#bd93f9">#' + esc(t) + '</span>').join('') + '</div></div>'
                + '<span class="vx-u-btns">' + (a.current ? '' : '<button type="button" class="btn btn-sm btn-gold" id="avSel">🎭 Anziehen</button>') + '<button type="button" class="btn btn-sm" id="avFav">☆ Favorisieren</button><button type="button" class="btn btn-sm" id="avUnfav">Favorit entfernen</button>'
                + '<a class="btn btn-sm" href="https://vrchat.com/home/avatar/' + encodeURIComponent(a.id) + '" target="_blank" rel="noopener noreferrer">↗ vrchat.com</a></span></div>'
                + (a.desc_html ? '<h4>Beschreibung</h4><div class="fmt vx-u-bio">' + a.desc_html + '</div>' : '')
                + '<dl class="vx-u-facts">' + (a.created ? '<dt>Erstellt</dt><dd>' + esc(isoDate(a.created)) + '</dd>' : '') + (a.updated ? '<dt>Aktualisiert</dt><dd>' + esc(isoDate(a.updated)) + '</dd>' : '')
                + '<dt>Avatar-ID</dt><dd><code class="vx-id">' + esc(a.id) + '</code> <button type="button" class="vx-copy" data-copy="' + esc(a.id) + '">⧉</button></dd></dl>'
                + (a.status !== 'public' && !a.current ? '<p class="vx-small">Hinweis: Private Avatare anderer Personen kann VRChat nicht anziehen lassen.</p>' : '');
            favPicker('avatar', a.id, { name: a.name, image: a.image, sub: a.author }, true);
            const sel = $('#avSel');
            if (sel) sel.onclick = () => api('avatar_select', { id: a.id }).then(r => { toast(r.msg); load(false); openAvatar(a.id); }).catch(x => toast(x.message, true));
            $('#avFav').onclick = () => api('avatar_fav', { id: a.id, on: 1 }).then(r => toast(r.msg)).catch(x => toast(x.message, true));
            $('#avUnfav').onclick = () => api('avatar_fav', { id: a.id, on: '' }).then(r => toast(r.msg)).catch(x => toast(x.message, true));
        }).catch(fail($('#userBody')));
    }
    $('#avatarsBox').addEventListener('click', e => { const c = e.target.closest('[data-avatar-open]'); if (c && !e.target.closest('[data-avatar]')) openAvatar(c.dataset.avatarOpen); });

    // ---------- Instanzen merken, teilen, Party-Einladung ----------
    const bookmarks = store.get('bookmarks', []);
    function bookmarkToggle(loc, name) {
        const i = bookmarks.findIndex(b => b.location === loc);
        if (i >= 0) bookmarks.splice(i, 1); else bookmarks.unshift({ location: loc, name: name || 'Instanz', ts: Math.round(now()) });
        store.set('bookmarks', bookmarks.slice(0, 40));
        toast(i >= 0 ? 'Aus der Merkliste entfernt.' : '🔖 Instanz gemerkt – unter Welten → Gemerkt.');
    }
    const launchUrl = loc => { const [w, ...r] = loc.split(':'); return 'https://vrchat.com/home/launch?worldId=' + encodeURIComponent(w) + '&instanceId=' + encodeURIComponent(r.join(':')); };
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-bm]');
        if (b) { e.preventDefault(); bookmarkToggle(b.dataset.bm, b.dataset.name); if (worldKind === 'bookmarks') loadWorlds('bookmarks'); return; }
        const l = e.target.closest('[data-link]');
        if (l) { e.preventDefault(); const u = launchUrl(l.dataset.link); (navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(() => toast('🔗 Einladungslink kopiert – zum Teilen einfügen.')).catch(() => prompt('Link kopieren:', u)); return; }
        const s = e.target.closest('[data-share]');
        if (s) {
            e.preventDefault();
            const text = prompt('Text zur Einladung in Discord (optional):', 'Kommt vorbei! 🐾');
            if (text === null) return;
            api('discord_share', { location: s.dataset.share, text }).then(r => toast(r.msg)).catch(x => toast(x.message, true));
            return;
        }
        const p = e.target.closest('[data-party]');
        if (p) { e.preventDefault(); partyInvite(p.dataset.party); }
    });
    function partyInvite(loc) {
        loc = loc || state.me.location;
        if (!loc) return toast('Du bist gerade in keiner Instanz – erst ins Spiel gehen.', true);
        const fr = state.friends.filter(f => f.state !== 'offline').sort((a, b) => (b.favorite - a.favorite) || a.name.localeCompare(b.name, 'de'));
        openModal('<h2>👥 Freunde einladen</h2><p class="vx-small">Wähle, wer eine Einladung in deine Instanz bekommen soll (max. 25).</p>'
            + '<div class="vx-tools"><button type="button" class="btn btn-sm" id="pAll">Alle online</button><button type="button" class="btn btn-sm" id="pFav">⭐ Favoriten</button><button type="button" class="btn btn-sm" id="pNone">Keine</button></div>'
            + '<div class="vx-party">' + (fr.length ? fr.map(f => '<label class="vx-party-item"><input type="checkbox" value="' + esc(f.id) + '"' + (f.favorite ? ' data-fav="1"' : '') + '> ' + statusDot(f.status) + esc(f.name)
                + (f.state === 'online' && f.loc && f.loc.kind === 'world' ? ' <small>' + esc(f.loc.world_name || '') + '</small>' : '') + '</label>').join('') : '<div class="vx-empty">Gerade ist niemand online.</div>') + '</div>'
            + '<div class="vx-u-actions"><button type="button" class="btn btn-gold" id="pGo">📨 Einladungen senden</button></div>');
        const boxes = () => $$('.vx-party input');
        $('#pAll').onclick = () => boxes().forEach(b => { b.checked = true; });
        $('#pFav').onclick = () => boxes().forEach(b => { b.checked = !!b.dataset.fav; });
        $('#pNone').onclick = () => boxes().forEach(b => { b.checked = false; });
        $('#pGo').onclick = () => {
            const ids = boxes().filter(b => b.checked).map(b => b.value);
            if (!ids.length) return toast('Bitte mindestens eine Person auswählen.', true);
            $('#pGo').disabled = true; $('#pGo').textContent = 'Sende …';
            api('invite_many', { ids, location: loc }).then(r => { toast(r.msg); closeModal(); }).catch(x => { toast(x.message, true); $('#pGo').disabled = false; });
        };
    }
    document.addEventListener('click', e => { if (e.target.closest('.vx-reroll')) roulette(); });
    document.addEventListener('keydown', e => { if ((e.key === 'w' || e.key === 'W') && !e.ctrlKey && !e.altKey && !e.target.closest('input, textarea, select') && roulette.last && !modal.hidden && $('#userBody .vx-reroll')) roulette(); });
    // Alle offenen Benachrichtigungen nacheinander als gelesen markieren
    if ($('#nfSeeAll')) $('#nfSeeAll').addEventListener('click', async e => {
        const btn = e.currentTarget;
        if (!$('#notifBox [data-nop="see"]')) return toast('Nichts Ungelesenes.');
        btn.disabled = true;
        // die Liste wird nach jedem Klick neu aufgebaut → immer den ersten noch offenen nehmen
        let n = 0;
        for (let b; n < 60 && (b = $('#notifBox [data-nop="see"]')); n++) {
            b.click();
            for (let w = 0; w < 30 && b.isConnected; w++) await new Promise(r => setTimeout(r, 100));
            await new Promise(r => setTimeout(r, 150));
        }
        btn.disabled = false;
        toast(n + ' als gelesen markiert.');
    });
    // Zufälliger Freund, der gerade im Spiel ist (bevorzugt in betretbaren Instanzen)
    if ($('#randomFriend')) $('#randomFriend').addEventListener('click', () => {
        const on = state.friends.filter(f => f.state === 'online');
        const open = on.filter(f => joinable(f.loc));
        const pool = open.length ? open : on;
        if (!pool.length) return toast('Gerade ist niemand im Spiel.', true);
        const f = pool[Math.floor(Math.random() * pool.length)];
        toast('🎲 ' + f.name + (f.loc && f.loc.world_name ? ' in ' + f.loc.world_name : ''));
        openUser(f.id);
    });
    function roulette() {
        tab('worlds');
        loading($('#worldsBox'), '🎲 Würfle eine Welt …');
        Promise.all([api('worlds', { kind: 'favorites' }).catch(() => ({ worlds: [] })), api('worlds', { kind: 'active' }).catch(() => ({ worlds: [] }))]).then(([a, b]) => {
            const all = a.worlds.concat(b.worlds).filter((w, i, l) => l.findIndex(x => x.id === w.id) === i);
            loadWorlds(['search', 'roulette'].includes(worldKind) ? 'favorites' : worldKind);
            if (!all.length) return toast('Keine Welten zum Auswürfeln gefunden.', true);
            let w;
            do { w = all[Math.floor(Math.random() * all.length)]; } while (all.length > 1 && w.id === roulette.last);
            roulette.last = w.id;
            toast('🎲 ' + w.name);
            openWorld(w.id);
            // Knopf „nochmal würfeln“ ins Welten-Fenster, sobald es geladen ist
            const add = (n = 0) => { const h = $('#userBody .vx-w-head h2'); if (h && !$('#userBody .vx-reroll')) h.insertAdjacentHTML('afterend', '<button type="button" class="btn btn-sm btn-gold vx-reroll" title="Taste W">🎲 Nochmal würfeln</button>'); else if (!h && n < 30) setTimeout(() => add(n + 1), 200); };
            add();
        });
    }

    // Start
    load(false).then(() => load(true)).finally(schedule);
    window.NexusUI = { popMenu, renderUser, feedRow, tab, api, toast, esc, img, ago, hm, dur, clock, num, launch, openUser, openWorld, openAvatar, openInstance, openModal, closeModal, load, readClipboard,
        state: () => state, statusDot, isoDT, renderFriends, store };
    { const st = (window.VRCX.ui || {})['ui.start_tab']; if (st && st !== 'last') tab(st); else tab(store.get('tab', 'home'), store.get('tabSub', '')); }
    setInterval(() => { if (state && !$('[data-pane=friends]').hidden) renderFriends(); }, 60000);
})();
