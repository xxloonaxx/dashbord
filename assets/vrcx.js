/* VRCX – Oberfläche. Alle Daten kommen als JSON von vrcx.php; Texte von VRChat werden immer maskiert. */
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
    const launch = loc => 'vrchat://launch?ref=vrcx-dashboard&id=' + encodeURIComponent(loc);
    const joinable = l => l && l.kind === 'world' && l.access !== 'Invite';

    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach(x => body.append(k + '[]', x)); else body.append(k, v ?? ''); });
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
    const TABS = $$('#tabs [data-tab]').map(b => b.dataset.tab);
    function tab(name) {
        if (!TABS.includes(name)) name = 'friends';
        $$('#tabs [data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        $$('.vx-tab').forEach(p => { p.hidden = p.dataset.pane !== name; });
        store.set('tab', name);
        if (name === 'feed') loadFeed(true);
        if (name === 'stats') loadStats();
        if (name === 'worlds' && !loaded.worlds) loadWorlds('favorites');
        if (name === 'avatars' && !loaded.avatars) loadAvatars('mine');
        if (name === 'groups' && !loaded.groups) loadGroups();
        if (name === 'notifs' && !loaded.notifs) loadNotifs();
        if (name === 'moderation') loadModeration();
    }
    $('#tabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) tab(b.dataset.tab); });
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
            + (l.kind === 'world' ? ' <button type="button" class="btn btn-sm" data-inst="' + esc(l.location) + '">Instanz-Info</button>' : '');
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
    function friendCard(f) {
        const compact = $('#compactView').checked;
        return '<div class="vx-friend st-' + f.state + (f.favorite ? ' fav' : '') + (compact ? ' compact' : '') + '" data-user="' + esc(f.id) + '" tabindex="0" role="button">'
            + img(f.image, 'vx-av', f.name)
            + '<span class="vx-f-main"><span class="vx-f-name">' + statusDot(f.state === 'offline' ? 'offline' : f.status) + '<b style="color:' + esc(f.trust[1]) + '">' + esc(f.name) + '</b>' + (f.favorite ? ' ⭐' : '') + (f.fav_group ? ' <span class="vx-vfav" title="VRChat-Favorit: ' + esc((state.fav_groups || {})[f.fav_group] || f.fav_group) + '">♥</span>' : '') + '</span>'
            + (!compact && f.status_desc ? '<span class="vx-f-sd">' + esc(f.status_desc) + '</span>' : '')
            + (f.state === 'online' ? locLine(f.loc) : '<span class="vx-loc muted">' + (f.state === 'active' ? '💻 ' + esc(f.platform || 'Website / App') : 'Offline' + (f.since ? ' · ' + ago(f.since) : '')) + '</span>')
            + (!compact && f.note ? '<span class="vx-f-note">📝 ' + esc(f.note.slice(0, 60)) + '</span>' : '')
            + '</span><span class="vx-f-side"><span class="vx-f-since" title="seit">' + esc(f.state === 'offline' ? '' : dur(f.since)) + '</span>'
            + (f.state === 'online' && joinable(f.loc) ? '<a class="vx-join" href="' + esc(launch(f.loc.location)) + '" title="Beitreten (öffnet VRChat)">▶</a>' : '') + '</span></div>';
    }
    function fillTrustFilter() {
        const sel = $('#friendTrust');
        if (sel.options.length > 1) return;
        TRUST_ORDER.forEach(t => sel.add(new Option(t, t)));
    }
    function renderFavChips() {
        const g = state.fav_groups || {}, box = $('#favChips');
        const used = [...new Set(state.friends.map(f => f.fav_group).filter(Boolean))];
        const chips = [['', 'Alle'], ['__mine', '⭐ Meine Favoriten'], ...used.map(k => [k, '♥ ' + (g[k] || k)])];
        box.innerHTML = chips.length > 2 || state.friends.some(f => f.favorite) ? chips.map(([k, n]) => '<button type="button" class="chip' + (favFilter === k ? ' on' : '') + '" data-fav="' + esc(k) + '">' + esc(n) + ' <small>'
            + (k === '' ? state.friends.length : k === '__mine' ? state.friends.filter(f => f.favorite).length : state.friends.filter(f => f.fav_group === k).length) + '</small></button>').join('') : '';
    }
    $('#favChips').addEventListener('click', e => { const b = e.target.closest('[data-fav]'); if (!b) return; favFilter = b.dataset.fav; renderFavChips(); renderFriends(); });
    function renderFriends() {
        if (!state) return;
        const q = $('#friendSearch').value.trim().toLowerCase();
        const sort = $('#friendSort').value, pf = $('#friendPlatform').value, tr = $('#friendTrust').value;
        const off = $('#showOffline').checked || !!favFilter, notes = $('#onlyNotes').checked;
        let list = state.friends.filter(f => (!q || [f.name, f.status_desc, f.note, f.loc && f.loc.world_name, f.platform].join(' ').toLowerCase().includes(q))
            && (!pf || f.pf === pf) && (!tr || f.trust[0] === tr) && (!notes || f.note)
            && (!favFilter || (favFilter === '__mine' ? f.favorite : f.fav_group === favFilter)));
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
        if (el.type === 'checkbox') el.checked = store.get('f' + s, false); else if (el.tagName === 'SELECT') el.value = store.get('f' + s, el.value);
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
        api('user', { id }).then(d => renderUser(d.user)).catch(fail($('#userBody')));
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
        if (s.last_world && l.kind !== 'world') h += '<p class="vx-small">Zuletzt gesehen in <a href="#" data-world="' + esc(s.last_world.world) + '">' + esc(s.last_world.world_name || 'Welt') + '</a> (' + esc(s.last_world.access) + ', ' + esc(ago(s.last_world.ts)) + ')</p>';
        h += '<div class="vx-u-cols"><div>';
        if (u.bio_html) h += '<h4>Bio</h4><div class="fmt vx-u-bio">' + u.bio_html + '</div>';
        if (u.links && u.links.length) h += '<h4>Links</h4><ul class="vx-u-links">' + u.links.map(x => '<li><a href="' + esc(x) + '" target="_blank" rel="noopener noreferrer nofollow">' + esc(x.replace(/^https?:\/\//, '')) + '</a></li>').join('') + '</ul>';
        if (u.groups && u.groups.length) h += '<h4>Gruppen</h4><div class="vx-u-groups">' + u.groups.sort((a, b) => b.rep - a.rep).map(g => '<button type="button" class="vx-pill' + (g.rep ? ' rep' : '') + '" data-opengroup="' + esc(g.id) + '" title="' + g.members + ' Mitglieder">' + (g.rep ? '★ ' : '') + esc(g.name) + '</button>').join('') + '</div>';
        h += '<dl class="vx-u-facts">' + (u.joined ? '<dt>Dabei seit</dt><dd>' + esc(isoDate(u.joined)) + '</dd>' : '') + (u.last_login ? '<dt>Letzter Login</dt><dd>' + esc(isoDT(u.last_login)) + '</dd>' : '')
            + (u.last_activity ? '<dt>Zuletzt aktiv</dt><dd>' + esc(isoDT(u.last_activity)) + '</dd>' : '') + '<dt>VRChat-ID</dt><dd><code class="vx-id">' + esc(u.id) + '</code> <button type="button" class="vx-copy" data-copy="' + esc(u.id) + '" title="Kopieren">⧉</button></dd></dl>';
        h += '</div><div><h4>Meine Notiz <small>(nur für dich)</small></h4><form class="vx-note-form"><textarea name="note" rows="5" maxlength="4000" placeholder="z. B. kennengelernt auf der EF, mag Kaffee …">' + esc(u.note) + '</textarea>'
            + '<label class="vx-auto"><input type="checkbox" name="favorite" value="1"' + (u.favorite ? ' checked' : '') + '> ⭐ Favorit (oben in der Liste, eigene Benachrichtigungen)</label>'
            + '<label class="vx-auto"><input type="checkbox" name="remote" value="1"> auch als VRChat-Notiz speichern (max. 256 Zeichen)</label><button class="btn btn-sm btn-gold">Speichern</button></form>'
            + '<h4>Verlauf</h4><div class="vx-u-feed" id="uFeed">Lade …</div></div></div>';
        h += '<div class="vx-u-actions"><a class="btn btn-sm" href="https://vrchat.com/home/user/' + encodeURIComponent(u.id) + '" target="_blank" rel="noopener noreferrer">↗ vrchat.com</a>'
            + (myLoc && u.is_friend ? '<button type="button" class="btn btn-sm btn-gold" data-act="invite" title="In deine aktuelle Instanz einladen">📨 Zu mir einladen</button>' : '')
            + '<button type="button" class="btn btn-sm" data-mod="mute">🔇 Stummschalten</button><button type="button" class="btn btn-sm" data-mod="hideAvatar">🙈 Avatar ausblenden</button><button type="button" class="btn btn-sm btn-danger" data-mod="block">⛔ Blockieren</button>'
            + (u.is_friend ? '<button type="button" class="btn btn-sm btn-danger" data-act="unfriend">Freundschaft beenden</button>' : '<button type="button" class="btn btn-sm btn-gold" data-act="friend_request">＋ Freundschaftsanfrage</button>') + '</div>';
        const body = $('#userBody');
        body.innerHTML = h;
        body.querySelector('.vx-note-form').addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target;
            api('note_save', { id: u.id, note: f.note.value, favorite: f.favorite.checked ? 1 : '', remote: f.remote.checked ? 1 : '' }).then(d => {
                toast(d.msg || 'Notiz gespeichert.');
                const fr = friendById(u.id); if (fr) { fr.note = f.note.value; fr.favorite = f.favorite.checked; renderFriends(); renderFavChips(); }
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
        worldKind = kind;
        loaded.worlds = true;
        $$('#worldKinds .chip').forEach(c => c.classList.toggle('on', c.dataset.kind === kind));
        const box = $('#worldsBox');
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
        api('world', { id }).then(d => renderWorld(d.world)).catch(fail($('#userBody')));
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
            const pct = i.capacity ? Math.min(100, Math.round(i.users / i.capacity * 100)) : 0;
            $('#userBody').innerHTML = '<h2>Instanz #' + esc(l.name || '') + '</h2><div class="vx-u-sub">' + esc(l.access) + ' · ' + esc(l.region) + (i.name ? ' · ' + esc(i.name) : '') + ' · <a href="#" data-world="' + esc(l.world) + '">Welt ansehen</a></div>'
                + '<div class="vx-kpis sm"><div><b>' + i.users + ' / ' + (i.capacity || '?') + '</b><span>Personen</span></div><div><b>' + i.pc + '</b><span>💻 PC</span></div><div><b>' + i.quest + '</b><span>📱 Quest</span></div>'
                + (i.ios ? '<div><b>' + i.ios + '</b><span>🍏 iOS</span></div>' : '') + (i.queue ? '<div><b>' + i.queue + '</b><span>in der Warteschlange</span></div>' : '') + '</div>'
                + '<div class="vx-cap"><div style="width:' + pct + '%"></div></div>' + (i.full ? '<p class="vx-error">Die Instanz ist voll.</p>' : '') + (i.closed ? '<p class="vx-error">Die Instanz wurde geschlossen.</p>' : '')
                + (i.friends.length ? '<h4>Deine Freunde hier</h4><div class="vx-members">' + i.friends.map(f => '<div class="vx-member">' + img(f.image, 'vx-av sm', f.name) + '<a href="#" data-user="' + esc(f.id) + '">' + esc(f.name) + '</a></div>').join('') + '</div>' : '')
                + '<div class="vx-u-actions">' + (joinable(l) ? '<a class="btn btn-sm btn-gold" href="' + esc(launch(i.location)) + '">▶ Beitreten (Spiel)</a><button type="button" class="btn btn-sm" data-self="' + esc(i.location) + '">📨 Selbst-Einladung</button>' : '')
                + '<button type="button" class="btn btn-sm" data-copy="' + esc(i.location) + '">⧉ Instanz-ID kopieren</button></div>';
        }).catch(fail($('#userBody')));
    }
    document.addEventListener('click', e => {
        const s = e.target.closest('[data-self]');
        if (s) { e.preventDefault(); api('invite_self', { location: s.dataset.self }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); }
    });

    // ---------- Avatare ----------
    let avatarList = [];
    function loadAvatars(kind) {
        loaded.avatars = true;
        $$('#avatarKinds .chip').forEach(c => c.classList.toggle('on', c.dataset.kind === kind));
        const box = $('#avatarsBox');
        loading(box, 'Lade Avatare …');
        api('avatars', { kind }).then(d => { avatarList = d.avatars; renderAvatars(); }).catch(fail(box));
    }
    function renderAvatars() {
        const q = $('#avatarSearch').value.trim().toLowerCase();
        const list = avatarList.filter(a => !q || (a.name + ' ' + a.author + ' ' + a.desc).toLowerCase().includes(q));
        $('#avatarsBox').innerHTML = list.length ? list.map(a => '<div class="vx-card' + (a.current ? ' current' : '') + '">' + img(a.thumb, 'vx-card-img', a.name) + '<span class="vx-card-body"><b>' + esc(a.name) + (a.current ? ' <span class="vx-pill rep">trägst du</span>' : '') + '</b>'
            + '<small>' + esc(a.author ? 'von ' + a.author : '') + (a.status ? ' · ' + esc(a.status) : '') + '</small><span class="vx-card-meta">' + a.platforms.map(p => '<span>' + esc(p) + '</span>').join('') + '</span>'
            + (a.current ? '' : '<button type="button" class="btn btn-sm btn-gold" data-avatar="' + esc(a.id) + '">Anziehen</button>') + '</span></div>').join('') : '<div class="vx-empty">Keine Avatare.</div>';
    }
    $('#avatarKinds').addEventListener('click', e => { const c = e.target.closest('[data-kind]'); if (c) loadAvatars(c.dataset.kind); });
    $('#avatarSearch').addEventListener('input', renderAvatars);
    $('#avatarsBox').addEventListener('click', e => {
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
    function heatmap(heat) {
        const max = Math.max(1, ...heat.flat()), days = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        let h = '<div class="vx-heat"><span></span>' + Array.from({ length: 24 }, (_, i) => '<span class="hh">' + (i % 3 ? '' : i) + '</span>').join('');
        heat.forEach((row, d) => { h += '<span class="hd">' + days[d] + '</span>' + row.map((v, i) => '<span class="hc" style="--a:' + (v / max).toFixed(2) + '" title="' + days[d] + ' ' + i + ' Uhr: ' + v + '× online gekommen"></span>').join(''); });
        return h + '</div>';
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
                + '<div class="vx-cols">'
                + '<div class="panel"><div class="panel-title">🏆 Meiste Spielzeit (30 Tage)</div>' + (s.play.length ? bars(s.play.map(p => Object.assign(p, { _v: hm(p.seconds) })), p => p.seconds, p => '<a href="#" data-user="' + esc(p.id) + '">' + esc(p.name) + '</a>') : '<div class="vx-empty">Noch keine Daten.</div>') + '</div>'
                + '<div class="panel"><div class="panel-title">🌐 Beliebteste Welten deiner Freunde</div>' + (s.worlds.length ? bars(s.worlds, w => w.count, w => '<a href="#" data-world="' + esc(w.id) + '">' + esc(w.name) + '</a>') : '<div class="vx-empty">Noch keine Daten.</div>') + '</div>'
                + '<div class="panel"><div class="panel-title">🕐 Wann kommen deine Freunde online?</div>' + heatmap(s.heat) + '</div>'
                + '<div class="panel"><div class="panel-title">🛡️ Vertrauensstufen</div>' + bars(s.trust, x => x.count, x => '<span style="color:' + esc(x.color) + '">' + esc(x.name) + '</span>', x => x.color) + '</div>'
                + '<div class="panel"><div class="panel-title">💻 Plattformen (zuletzt)</div>' + bars(Object.entries(s.platform).map(([k, v]) => ({ k, v })), x => x.v, x => esc(x.k)) + '</div>'
                + '<div class="panel"><div class="panel-title">🧭 Deine meistbesuchten Welten</div>' + (s.my_worlds.length ? bars(s.my_worlds, w => w.count, w => '<a href="#" data-world="' + esc(w.id) + '">' + esc(w.name) + '</a>') : '<div class="vx-empty">Wird aufgezeichnet, sobald du im Spiel bist und diese Seite offen ist.</div>') + '</div>'
                + '</div>';
        }).catch(fail(box));
    }

    // ---------- Gruppen ----------
    const can = (g, p) => g.owner || g.perms.includes('*') || g.perms.includes(p);
    function loadGroups() {
        $('#groupsBox').innerHTML = '<div class="vx-loading">Lade Gruppen …</div>';
        api('groups').then(d => {
            loaded.groups = true;
            loaded.groupList = d.groups;
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
            const tabs = [['info', 'ℹ Info'], ['posts', '📢 Beiträge'], ['members', '👥 Mitglieder'], ['instances', '🌐 Instanzen']];
            if (g.member && can(g, 'group-invites-manage')) tabs.push(['requests', '📥 Anfragen'], ['invite', '✉ Einladen']);
            if (g.member && can(g, 'group-bans-manage')) tabs.push(['bans', '🚫 Banns']);
            if (g.member && can(g, 'group-audit-view')) tabs.push(['audit', '📋 Protokoll']);
            box.innerHTML = '<div class="panel vx-gd">' + (/^https:/.test(g.banner) ? '<div class="vx-gd-banner" style="background-image:url(\'' + esc(g.banner.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"></div>' : '')
                + '<div class="vx-gd-head">' + img(g.icon, 'vx-g-icon lg', '') + '<div><h2>' + esc(g.name) + '</h2><small>' + esc(g.short) + ' · ' + g.members + ' Mitglieder' + (g.online ? ' · ' + g.online + ' online' : '') + ' · ' + esc(g.privacy) + ' · Beitritt: ' + esc(g.join_state || '–') + '</small></div>'
                + '<span class="vx-u-btns">' + (g.member ? '<button type="button" class="btn btn-sm" id="gRep">' + (g.rep ? '★ Repräsentation entfernen' : '☆ Repräsentieren') + '</button>' + (g.owner ? '' : '<button type="button" class="btn btn-sm btn-danger" id="gLeave">Verlassen</button>')
                    : '<button type="button" class="btn btn-sm btn-gold" id="gJoin">＋ Beitreten</button>') + '<a class="btn btn-sm" href="https://vrchat.com/home/group/' + encodeURIComponent(g.id) + '" target="_blank" rel="noopener noreferrer">↗</a></span></div>'
                + '<div class="vx-gd-tabs">' + tabs.map((t, i) => '<button type="button" class="chip' + (i ? '' : ' on') + '" data-gt="' + t[0] + '">' + t[1] + '</button>').join('') + '</div><div id="gPane"></div></div>';
            const act = (b, a, data, reload) => b && b.addEventListener('click', () => { if (a === 'group_leave' && !confirm('Gruppe „' + g.name + '“ wirklich verlassen?')) return; api(a, Object.assign({ id: g.id }, data)).then(r => { toast(r.msg); openGroup(g.id); if (reload) loadGroups(); }).catch(x => toast(x.message, true)); });
            act($('#gRep'), 'group_represent', { on: g.rep ? '' : 1 }, true);
            act($('#gLeave'), 'group_leave', {}, true);
            act($('#gJoin'), 'group_join', {}, true);
            box.querySelector('.vx-gd-tabs').addEventListener('click', e => { const b = e.target.closest('[data-gt]'); if (!b) return; $$('.vx-gd-tabs .chip').forEach(x => x.classList.toggle('on', x === b)); groupPane(b.dataset.gt); });
            groupPane('info');
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

    // ---------- Anfragen / Benachrichtigungen ----------
    const NT = { friendRequest: '🤝 Freundschaftsanfrage', invite: '📨 Einladung', requestInvite: '🙋 möchte eingeladen werden', inviteResponse: '↩ Antwort auf Einladung', requestInviteResponse: '↩ Antwort', votetokick: '🗳 Abstimmung', message: '💬 Nachricht' };
    function loadNotifs() {
        $('#notifBox').innerHTML = '<div class="vx-loading">Lade …</div>';
        api('notifications').then(d => {
            loaded.notifs = true;
            $('#notifBox').innerHTML = d.items.length ? '<div class="vx-members">' + d.items.map(n => '<div class="vx-member' + (n.seen ? '' : ' unseen') + '"><span>' + esc(NT[n.type] || n.type) + '</span>'
                + '<a href="#" data-user="' + esc(n.from_id) + '">' + esc(n.from) + '</a><small>' + esc(n.message || '') + ' · ' + esc(n.created ? new Date(n.created).toLocaleString('de-DE') : '') + '</small><span class="vx-m-acts">'
                + (n.type === 'friendRequest' ? '<button type="button" class="btn btn-sm btn-gold" data-nact="notif_accept" data-id="' + esc(n.id) + '">✓ Annehmen</button>' : '')
                + (n.type === 'requestInvite' && state.me.location ? '<button type="button" class="btn btn-sm btn-gold" data-nact="invite" data-user="' + esc(n.from_id) + '">📨 Einladen</button>' : '')
                + '<button type="button" class="btn btn-sm" data-nact="notif_hide" data-id="' + esc(n.id) + '">Ausblenden</button></span></div>').join('') + '</div>'
                : '<div class="vx-empty">Keine offenen Anfragen.</div>';
            const n = d.items.filter(x => x.type === 'friendRequest').length;
            $('[data-tab=notifs]').innerHTML = '<span>🔔</span>Anfragen' + (n ? ' <b class="vx-badge">' + n + '</b>' : '');
        }).catch(x => { $('#notifBox').innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }
    $('#notifBox').addEventListener('click', e => {
        const b = e.target.closest('[data-nact]');
        if (!b) return;
        e.stopPropagation();
        b.disabled = true;
        const a = b.dataset.nact;
        api(a, a === 'invite' ? { id: b.dataset.user } : { id: b.dataset.id }).then(r => { toast(r.msg || 'Erledigt.'); if (a !== 'invite') b.closest('.vx-member').remove(); if (a === 'notif_accept') load(true, true); })
            .catch(x => { toast(x.message, true); b.disabled = false; });
    }, true);

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
    pf.notify.value = prefs.notify; pf.notify_offline.checked = !!prefs.notify_offline; pf.notify_gps.checked = !!prefs.notify_gps; pf.sound.checked = !!prefs.sound; pf.interval.value = String(prefs.interval || 60);
    pf.addEventListener('change', () => {
        Object.assign(prefs, { notify: pf.notify.value, notify_offline: pf.notify_offline.checked, notify_gps: pf.notify_gps.checked, sound: pf.sound.checked, interval: +pf.interval.value });
        store.set('prefs', prefs);
        if (prefs.notify !== 'off' && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission();
        schedule();
        toast('Gespeichert.');
    });
    $('#notifyTest').addEventListener('click', () => {
        if (!('Notification' in window)) return toast('Dieser Browser kann keine Benachrichtigungen.', true);
        Notification.requestPermission().then(p => { if (p === 'granted') notify('VRCX', 'So sehen Benachrichtigungen aus 🐾'); else toast('Benachrichtigungen sind im Browser blockiert.', true); });
    });
    $('#logoutBtn').addEventListener('click', () => { if (confirm('VRChat-Verbindung trennen? Freunde, Verlauf und Notizen bleiben gespeichert.')) api('logout').then(() => location.reload()).catch(x => toast(x.message, true)); });
    const sf = $('#settingsForm');
    if (sf) sf.addEventListener('submit', e => { e.preventDefault(); api('settings', { user_agent: sf.user_agent.value }).then(r => toast(r.msg)).catch(x => toast(x.message, true)); });

    // Start
    load(false).then(() => load(true)).finally(schedule);
    tab(store.get('tab', 'friends'));
    setInterval(() => { if (state && !$('[data-pane=friends]').hidden) renderFriends(); }, 60000);
})();
