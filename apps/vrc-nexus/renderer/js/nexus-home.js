/* VRC Nexus – 🏠 Startseite mit Widgets (Windows + Android). Widgets ein-/ausblenden, verschieben, Größe; Anordnung je Gerät gespeichert. */
(function () {
    const UI = window.NexusUI;
    if (!UI) return;
    const box = document.getElementById('homeBox');
    if (!box) return;
    const MOBILE = document.body.classList.contains('mobile');
    const $ = (s, r) => (r || document).querySelector(s);
    const esc = UI.esc;
    const api = (act, data) => UI.api(act, data);
    const toast = (m, bad) => UI.toast(m, bad);
    const STATUS = { 'join me': ['🔵', 'Join Me'], active: ['🟢', 'Online'], 'ask me': ['🟠', 'Ask Me'], busy: ['🔴', 'Beschäftigt'] };

    /* [schlüssel, titel, nur PC, Standardbreite 1|2] */
    const W = [
        ['clock', '🕒 Uhr & Countdown', false, 1], ['me', '👤 Mein Status', false, 1], ['friends', '🟢 Freunde im Spiel', false, 2], ['instance', '🌐 Meine Instanz', true, 1],
        ['notifs', '🔔 Benachrichtigungen', false, 1], ['feed', '📰 Letzte Ereignisse', false, 1], ['chat', '💬 Chatbox', true, 1], ['macros', '⚡ Makros', true, 1],
        ['params', '🎚 Lieblings-Parameter', true, 1], ['camera', '📷 Kamera', true, 1], ['music', '🎵 Spielt gerade', true, 1], ['rotator', '🔁 Chatbox-Rotator', true, 1],
        ['today', '📊 Heute', true, 1], ['speech', '🎙 Sprache → Chatbox', true, 1], ['overlay', '🥽 SteamVR-Overlay', true, 1], ['notes', '📝 Notizzettel', false, 1], ['update', '⬆ Version', false, 1],
    ].filter(w => !(MOBILE && w[2]));
    const DEF = MOBILE ? ['me', 'friends', 'notifs', 'feed', 'clock', 'notes'] : ['me', 'friends', 'instance', 'chat', 'macros', 'notifs', 'today', 'music', 'camera', 'params'];
    const key = 'home' + (MOBILE ? 'M' : '');
    let layout = UI.store.get(key, null);
    if (!Array.isArray(layout)) layout = DEF.map(k => ({ k, w: (W.find(x => x[0] === k) || [])[3] || 1 }));
    layout = layout.filter(l => W.some(w => w[0] === l.k));
    let data = null, editing = false, timer = null, clockTimer = null;
    const save = () => UI.store.set(key, layout);

    function shell() {
        box.innerHTML = '<div class="hm-bar"><b class="hm-hi" id="hmHi"></b><span class="hm-sp"></span>'
            + '<button type="button" class="btn btn-sm" id="hmRef" title="Aktualisieren">⟳</button><button type="button" class="btn btn-sm" id="hmEdit">✏️ Anpassen</button></div>'
            + '<div class="hm-add" id="hmAdd" hidden></div><div class="hm-grid" id="hmGrid"></div>';
        $('#hmEdit').addEventListener('click', () => { editing = !editing; render(); });
        $('#hmRef').addEventListener('click', load);
        $('#hmAdd').addEventListener('click', e => { const b = e.target.closest('[data-add]'); if (!b) return; layout.push({ k: b.dataset.add, w: W.find(w => w[0] === b.dataset.add)[3] }); save(); render(); });
        $('#hmGrid').addEventListener('click', onClick);
        $('#hmGrid').addEventListener('submit', onSubmit);
        $('#hmGrid').addEventListener('change', e => {
            const r = e.target.closest('[data-hparam]');
            if (r) api('client_osc', { kind: 'param', name: r.dataset.hparam, type: r.dataset.t, value: r.value }).catch(err => toast('⚠ ' + err.message, true));
        });
        $('#hmGrid').addEventListener('input', e => { const n = e.target.closest('#hmNotes'); if (n) UI.store.set('homeNotes', n.value); });
    }
    function onClick(e) {
        const ed = e.target.closest('[data-hed]');
        if (ed) {
            const i = +ed.dataset.i, op = ed.dataset.hed;
            if (op === 'del') layout.splice(i, 1);
            if (op === 'up' && i > 0) [layout[i - 1], layout[i]] = [layout[i], layout[i - 1]];
            if (op === 'down' && i < layout.length - 1) [layout[i + 1], layout[i]] = [layout[i], layout[i + 1]];
            if (op === 'wide') layout[i].w = layout[i].w === 2 ? 1 : 2;
            save(); render(); return;
        }
        if (e.target.closest('#hmReset')) { layout = DEF.map(k => ({ k, w: W.find(x => x[0] === k)[3] })); save(); render(); return; }
        const b = e.target.closest('[data-h]');
        if (!b) return;
        const a = b.dataset.h, v = b.dataset.v;
        const done = p => p.then(d => { if (d && d.msg) toast(d.msg); setTimeout(load, 400); }).catch(err => toast('⚠ ' + err.message, true));
        if (a === 'status') return done(api('status', { status: v, desc: data.me ? data.me.desc : '' }));
        if (a === 'macro') return done(api('client_macro_run', { id: v }));
        if (a === 'cam') return done(api('cam_cmd', { cmd: v }));
        if (a === 'campreset') return done(api('cam_preset_load', { name: v }));
        if (a === 'rotator') return done(api('client_cfg_save', { rotator_enabled: data.rotator && data.rotator.on ? '0' : '1' }));
        if (a === 'toggle') return done(api('client_osc', { kind: 'param', name: v, type: 'bool', value: b.dataset.on === '1' ? '' : '1' }));
        if (a === 'speech') return done(api('speech_ptt', { op: 'toggle' }));
        if (a === 'overlay') return done(api('tools_overlay', { op: 'toggle' }));
        if (a === 'tab') return UI.tab(v, b.dataset.sub || '');
    }
    function onSubmit(e) {
        const f = e.target.closest('form');
        if (!f) return;
        e.preventDefault();
        if (f.id === 'hmChat') api('client_osc', { kind: 'chatbox', text: f.text.value, sound: '1', split: '1' }).then(() => { f.text.value = ''; toast('💬 gesendet'); }).catch(err => toast('⚠ ' + err.message, true));
        if (f.id === 'hmCd') { UI.store.set('homeCd', { t: f.t.value, l: f.l.value }); render(); }
    }

    // ---------- Widgets ----------
    const empty = t => '<div class="muted small">' + t + '</div>';
    const fmtDur = s => { s = Math.max(0, Math.round(s)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? h + ' h ' + m + ' min' : m + ' min'; };
    function countdown() {
        const cd = UI.store.get('homeCd', { t: '', l: '' });
        if (!cd.t) return '';
        let r = Math.floor((new Date(cd.t).getTime() - Date.now()) / 1000);
        if (isNaN(r)) return '';
        const past = r < 0; r = Math.abs(r);
        return '<div class="hm-cd"><small>' + esc(cd.l || 'Countdown') + '</small><b>' + (past ? 'seit ' : '') + (Math.floor(r / 86400) ? Math.floor(r / 86400) + ' T ' : '') + String(Math.floor(r % 86400 / 3600)).padStart(2, '0') + ':' + String(Math.floor(r % 3600 / 60)).padStart(2, '0') + ':' + String(r % 60).padStart(2, '0') + '</b></div>';
    }
    const R = {
        clock: () => { const d = new Date(), cd = UI.store.get('homeCd', { t: '', l: '' }); return '<div class="hm-clock" id="hmClock"><b>' + d.toLocaleTimeString('de-DE') + '</b><small>' + d.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + '</small></div><div id="hmCdBox">' + countdown() + '</div>'
            + '<details class="small"><summary>Countdown einstellen</summary><form id="hmCd" class="nx-row"><input type="text" name="l" placeholder="Name" value="' + esc(cd.l) + '" style="width:110px"><input type="datetime-local" name="t" value="' + esc(cd.t) + '"><button class="btn btn-sm">OK</button></form></details>'; },
        me: () => !data.me ? empty('Nicht angemeldet.') : '<div class="hm-me"><b>' + esc(data.me.name) + '</b><span>' + (STATUS[data.me.status] || ['⚪', data.me.status]).join(' ') + (data.me.desc ? ' · ' + esc(data.me.desc) : '') + '</span></div>'
            + '<div class="hm-btns">' + Object.entries(STATUS).map(([k, v]) => '<button type="button" class="chip' + (data.me.status === k ? ' on' : '') + '" data-h="status" data-v="' + k + '">' + v[0] + ' ' + v[1] + '</button>').join('') + '</div>',
        friends: () => '<div class="hm-kpis"><span><b>' + data.friends.in_game + '</b> im Spiel</span><span><b>' + data.friends.online + '</b> online</span><span><b>' + data.friends.total + '</b> gesamt</span></div>'
            + (data.friends.list.length ? '<div class="hm-flist">' + data.friends.list.map(f => '<div class="hm-f" data-user="' + esc(f.id) + '"><i class="hm-dot s-' + esc((f.status || '').replace(' ', '-')) + '"></i>' + (f.fav ? '⭐' : '') + '<b>' + esc(f.name) + '</b><span class="muted small">' + esc(f.world || '') + '</span></div>').join('') + '</div>' : empty('Gerade ist niemand im Spiel.'))
            + '<button type="button" class="nc-link" data-h="tab" data-v="locations">Alle Freunde-Orte →</button>',
        instance: () => { const g = data.game; if (!g || !g.running || !g.world) return empty(g && g.running ? 'VRChat läuft – noch in keiner Welt.' : 'VRChat läuft nicht.'); return '<div class="hm-me"><b>🌐 ' + esc(g.world) + '</b><span>seit ' + fmtDur(Date.now() / 1000 - g.since) + ' · ' + g.players.length + ' Spieler</span></div><div class="hm-pl">' + g.players.slice(0, 40).map(p => '<span' + (p.id ? ' data-user="' + esc(p.id) + '"' : '') + '>' + esc(p.name) + '</span>').join('') + '</div><button type="button" class="nc-link" data-h="tab" data-v="players">Spielerliste →</button>'; },
        notifs: () => !data.notifs ? empty('–') : '<div class="hm-kpis"><span><b>' + data.notifs.unseen + '</b> ungelesen</span></div>' + (data.notifs.items.length ? data.notifs.items.map(n => '<div class="hm-row"><b>' + esc(n.title) + '</b> <span class="muted small">' + esc(n.message) + '</span></div>').join('') : empty('Keine offenen Benachrichtigungen.')) + '<button type="button" class="nc-link" data-h="tab" data-v="notifs">Öffnen →</button>',
        feed: () => data.feed.length ? data.feed.slice(0, 8).map(f => '<div class="hm-row"><span class="muted small">' + UI.ago(f.ts) + '</span> <b data-user="' + esc(f.vrc_id) + '">' + esc(f.name) + '</b> <span class="small">' + esc(FEED[f.type] || f.type) + (f.new_loc && f.new_loc.world_name ? ' → ' + esc(f.new_loc.world_name) : f.type === 'status' || f.type === 'bio' ? ' ' + esc(String(f.new).slice(0, 40)) : '') + '</span></div>').join('') + '<button type="button" class="nc-link" data-h="tab" data-v="feed">Verlauf →</button>' : empty('Noch keine Ereignisse.'),
        chat: () => '<form id="hmChat" class="nx-row"><input type="text" name="text" maxlength="500" placeholder="Text für die Chatbox (/n = neue Zeile)" style="flex:1" required><button class="btn btn-sm btn-gold">Senden</button></form>',
        macros: () => data.macros.length ? '<div class="hm-btns">' + data.macros.map(m => '<button type="button" class="btn btn-sm" data-h="macro" data-v="' + esc(m.id) + '">⚡ ' + esc(m.name) + '</button>').join('') + '</div>' : empty('Noch keine Makros.') + '<button type="button" class="nc-link" data-h="tab" data-v="client" data-sub="macros">Makros anlegen →</button>',
        params: () => data.params.length ? data.params.map(p => '<div class="hm-row hm-prm"><span>' + esc(p.name) + '</span>' + (p.type === 'bool' ? '<button type="button" class="chip' + (p.value === true ? ' on' : '') + '" data-h="toggle" data-v="' + esc(p.name) + '" data-on="' + (p.value === true ? 1 : 0) + '">' + (p.value === true ? 'an' : 'aus') + '</button>'
            : '<input type="range" min="' + (p.type === 'int' ? 0 : -1) + '" max="' + (p.type === 'int' ? 255 : 1) + '" step="' + (p.type === 'int' ? 1 : 0.01) + '" value="' + (p.value ?? 0) + '" data-hparam="' + esc(p.name) + '" data-t="' + p.type + '">') + '</div>').join('')
            : empty('Markiere Parameter mit ★ unter Spiel & OSC → Parameter.') + '<button type="button" class="nc-link" data-h="tab" data-v="client" data-sub="params">Parameter →</button>',
        camera: () => '<div class="hm-btns"><button type="button" class="btn btn-sm btn-gold" data-h="cam" data-v="Capture">📸 Foto</button><button type="button" class="btn btn-sm" data-h="cam" data-v="CaptureDelayed">⏱ Timer</button><button type="button" class="btn btn-sm" data-h="tab" data-v="client" data-sub="cam">Mehr →</button></div>'
            + (data.cam.presets.length ? '<div class="hm-btns">' + data.cam.presets.map(p => '<button type="button" class="chip" data-h="campreset" data-v="' + esc(p) + '">' + esc(p) + '</button>').join('') + '</div>' : ''),
        music: () => '<div class="hm-big">' + (data.music ? '🎵 ' + esc(data.music) : '<span class="muted">Nichts erkannt (Spotify, foobar2000, AIMP, MusicBee, VLC …)</span>') + '</div><span class="muted small">Platzhalter <code>{musik}</code> für Rotator & Automatik.</span>',
        rotator: () => !data.rotator ? empty('–') : '<div class="hm-row"><button type="button" class="btn btn-sm' + (data.rotator.on ? ' btn-gold' : '') + '" data-h="rotator">' + (data.rotator.on ? '● läuft' : '○ aus') + '</button></div><div class="hm-pre">' + esc(data.rotator.text) + '</div>',
        today: () => !data.stats ? empty('–') : '<div class="hm-kpis"><span><b>' + fmtDur(data.stats.playtime) + '</b> Spielzeit</span><span><b>' + data.stats.worlds + '</b> Welten</span><span><b>' + data.stats.players + '</b> Begegnungen</span><span><b>' + data.stats.screenshots + '</b> Fotos</span></div>',
        speech: () => !data.speech ? empty('Spracheingabe nicht verfügbar.') : '<div class="hm-row"><button type="button" class="btn btn-sm' + (data.speech.recording ? ' btn-gold' : '') + '" data-h="speech">' + (data.speech.recording ? '⏹ Stopp' : '🎙 Sprechen') + '</button> <span class="muted small">' + esc(data.speech.engine_label || '') + '</span></div>' + (data.speech.last ? '<div class="hm-pre">„' + esc(data.speech.last) + '“</div>' : ''),
        overlay: () => !data.overlay ? empty('Nur in der Windows-App mit SteamVR.') : '<div class="hm-row"><button type="button" class="btn btn-sm' + (data.overlay.visible ? ' btn-gold' : '') + '" data-h="overlay">' + (data.overlay.visible ? '👁 sichtbar' : '🙈 versteckt') + '</button> <span class="muted small">' + esc(data.overlay.status || '') + '</span></div>',
        notes: () => '<textarea id="hmNotes" rows="5" placeholder="Notizen (bleiben auf diesem Gerät)">' + esc(UI.store.get('homeNotes', '')) + '</textarea>',
        update: () => !data.update ? empty('–') : '<div class="hm-me"><b>VRC Nexus ' + esc(data.update.current) + '</b><span>' + (data.update.available ? '⬆ ' + esc(data.update.version) + ' verfügbar' : '✔ aktuell') + '</span></div>' + (data.update.available ? '<button type="button" class="nc-link" data-h="tab" data-v="settings">Aktualisieren →</button>' : ''),
    };
    const FEED = { online: 'ist online', offline: 'ist offline', location: 'ist jetzt', status: 'Status:', avatar: 'neuer Avatar', bio: 'Bio geändert', friend_add: 'neuer Freund', friend_remove: 'entfreundet', gps: 'ist jetzt' };

    function render() {
        if (!data) return;
        const h = new Date().getHours();
        $('#hmHi').textContent = (h < 5 ? '🌙 Gute Nacht' : h < 11 ? '☀️ Guten Morgen' : h < 18 ? '👋 Hallo' : '🌆 Guten Abend') + (data.me ? ', ' + data.me.name : '') + '!';
        $('#hmEdit').textContent = editing ? '✔ Fertig' : '✏️ Anpassen';
        const add = $('#hmAdd');
        add.hidden = !editing;
        add.innerHTML = '<span class="small">Hinzufügen:</span> ' + W.filter(w => !layout.some(l => l.k === w[0])).map(w => '<button type="button" class="chip" data-add="' + w[0] + '">＋ ' + esc(w[1]) + '</button>').join('') + ' <button type="button" class="nc-link" id="hmReset">Standard</button>';
        $('#hmGrid').innerHTML = layout.map((l, i) => {
            const w = W.find(x => x[0] === l.k);
            let body;
            try { body = R[l.k](); } catch (e) { body = empty('⚠ ' + esc(e.message)); }
            return '<section class="hm-w' + (l.w === 2 ? ' wide' : '') + '" data-w="' + l.k + '"><header><b>' + esc(w[1]) + '</b>'
                + (editing ? '<span class="hm-ed"><button type="button" data-hed="up" data-i="' + i + '" title="nach vorne">◀</button><button type="button" data-hed="down" data-i="' + i + '" title="nach hinten">▶</button><button type="button" data-hed="wide" data-i="' + i + '" title="breit/schmal">⇔</button><button type="button" data-hed="del" data-i="' + i + '" title="entfernen">✕</button></span>' : '')
                + '</header><div class="hm-body">' + body + '</div></section>';
        }).join('') || '<div class="nc-empty">Keine Widgets – „Anpassen“ drücken.</div>';
    }
    function load() {
        return api('home_data').then(d => {
            data = d;
            const a = document.activeElement;
            if (a && a.closest && a.closest('#homeBox') && a.matches('input, textarea')) return;   // nicht beim Tippen neu zeichnen
            render();
        }).catch(e => { box.querySelector('#hmGrid').innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
    }
    window.NexusHome = {
        open() {
            if (!box.firstChild) shell();
            load();
            clearInterval(timer); timer = setInterval(() => { if (!document.hidden) load(); }, 10000);
            clearInterval(clockTimer); clockTimer = setInterval(() => { const c = $('#hmClock b'); if (c) c.textContent = new Date().toLocaleTimeString('de-DE'); const cd = $('#hmCdBox'); if (cd) cd.innerHTML = countdown(); }, 1000);
        },
        close() { clearInterval(timer); clearInterval(clockTimer); },
    };
    // Startseite war schon beim Laden gewählt (nexus.js läuft vorher)
    const pane = document.querySelector('[data-pane=home]');
    if (pane && !pane.hidden) window.NexusHome.open();
})();
