/* VRC Nexus – 📺 Stream-Bot (Twitch + YouTube): Live-Chat, Punkte, Befehle, Belohnungen (lösen VRChat-Makros aus), Verbindung. Nur Windows-App. */
(function () {
    const UI = window.NexusUI;
    const box = document.getElementById('streamBox');
    if (!UI || !box) return;
    const esc = UI.esc;
    const api = (a, d) => UI.api(a, d);
    const toast = (m, bad) => UI.toast(m, bad);
    const fail = e => toast('⚠ ' + e.message, true);
    let view = UI.store.get('streamView', 'chat'), st = null, seq = 0, feed = [], timer = null, plQ = '';
    const ICON = { twitch: '🟣', youtube: '🔴' };
    const STATE = s => /verbunden/.test(s) && !/getrennt/.test(s) ? 'ok' : /fehler/.test(s) ? 'bad' : '';

    function shell() {
        box.innerHTML = '<div class="nx-row rc-bar"><span class="chips">' + [['chat', '💬 Live-Chat'], ['points', '🏆 Punkte'], ['cmds', '⌨️ Befehle'], ['rewards', '🎁 Belohnungen'], ['setup', '🔌 Verbindung & Einstellungen']]
            .map(v => '<button type="button" class="chip' + (view === v[0] ? ' on' : '') + '" data-sv="' + v[0] + '">' + v[1] + '</button>').join('') + '</span><span id="stState" class="small"></span></div><div id="stBody"></div>';
    }
    function stateHtml() {
        if (!st) return '';
        const c = st.cfg;
        return (c.twitch.on ? '🟣 Twitch: <span class="' + STATE(st.twitch.state) + '">' + esc(st.twitch.state) + '</span>' + (st.twitch.error ? ' (' + esc(st.twitch.error) + ')' : '') : '🟣 Twitch aus')
            + ' · ' + (c.youtube.on ? '🔴 YouTube: <span class="' + STATE(st.youtube.state) + '">' + esc(st.youtube.state) + '</span>' + (st.youtube.error ? ' (' + esc(st.youtube.error) + ')' : '') : '🔴 YouTube aus');
    }
    const line = e => '<div class="sb-m k-' + e.kind + ' p-' + e.platform + '"><span class="muted small">' + new Date(e.t).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + '</span> '
        + (e.kind === 'reward' ? '🎁 ' : e.kind === 'alert' ? '⭐ ' : e.kind === 'system' ? 'ℹ️ ' : ICON[e.platform] + ' ') + '<b' + (e.color ? ' style="color:' + esc(e.color) + '"' : '') + '>' + esc(e.user) + (e.mod ? ' 🛡' : '') + '</b> ' + esc(e.text) + '</div>';

    function render() {
        $('#stState').innerHTML = stateHtml();
        const b = $('#stBody'), c = st.cfg;
        if (view === 'chat') {
            if (!b.querySelector('#sbFeed')) {
                b.innerHTML = '<div class="nc-grid sb-grid"><div class="nc-card"><div id="sbFeed" class="sb-feed"></div>'
                    + '<form id="sbSend" class="nx-row"><select name="platform"><option value="twitch">🟣 Twitch</option><option value="youtube">🔴 YouTube (nur Overlay)</option></select><input type="text" name="text" maxlength="480" placeholder="Nachricht in den Chat" style="flex:1"><button class="btn btn-sm btn-gold">Senden</button></form>'
                    + '<details class="small"><summary>🧪 Testnachricht (als Zuschauer)</summary><form id="sbTest" class="nx-row"><input type="text" name="user" value="Testzuschauer" style="width:140px"><input type="text" name="text" placeholder="z. B. !punkte oder !winken" style="flex:1"><label class="check small"><input type="checkbox" name="mod"> Mod</label><button class="btn btn-sm">Senden</button></form></details></div>'
                    + '<div class="nc-card"><h4>🏆 Top ' + esc(c.points.name) + '</h4><div id="sbTop"></div><p class="muted small" style="margin-top:10px">' + st.users + ' Zuschauer bekannt</p>'
                    + '<h4 style="margin-top:12px">Befehle für Zuschauer</h4><p class="small">' + ['punkte', 'top', 'geben', 'welt', 'spieler', 'song', 'befehle', 'belohnungen'].concat(c.commands.map(x => x.name)).map(x => '<code>' + esc(c.prefix + x) + '</code>').join(' ') + '</p>'
                    + (c.rewards.length ? '<h4>Belohnungen</h4><p class="small">' + c.rewards.map(r => '<code>' + esc(c.prefix + r.name) + '</code> ' + r.cost).join(' · ') + '</p>' : '') + '</div></div>';
            }
            const f = $('#sbFeed'), atEnd = f.scrollTop + f.clientHeight >= f.scrollHeight - 30;
            f.innerHTML = feed.length ? feed.map(line).join('') : '<div class="muted small">Noch keine Nachrichten. Verbinde Twitch/YouTube unter „Verbindung“ – oder schick unten eine Testnachricht.</div>';
            if (atEnd) f.scrollTop = f.scrollHeight;
            $('#sbTop').innerHTML = (st.top || []).map((u, i) => '<div class="rc-row">' + (i + 1) + '. ' + ICON[u.platform] + ' ' + esc(u.name) + '<span>' + u.p.toLocaleString('de-DE') + '</span></div>').join('') || '<span class="muted small">–</span>';
        }
        if (view === 'points') {
            b.innerHTML = '<div class="nx-row"><input type="search" id="sbPQ" placeholder="🔎 Zuschauer suchen" value="' + esc(plQ) + '"></div><div id="sbPL"><div class="vx-loading">…</div></div>';
            loadPoints();
        }
        if (view === 'cmds') {
            b.innerHTML = '<div class="nc-grid"><div class="nc-card"><h4>⌨️ Eigene Befehle</h4>' + (c.commands.length ? c.commands.map(x => '<div class="nc-mrow"><code>' + esc(c.prefix + x.name) + '</code><span class="small" style="flex:1">' + esc(x.response) + '</span><span class="muted small">' + (x.mod ? '🛡 nur Mods · ' : '') + x.cooldown + ' s</span>'
                + '<button type="button" class="nc-link" data-cedit="' + esc(x.name) + '">bearbeiten</button><button type="button" class="nc-x" data-cdel="' + esc(x.name) + '">✕</button></div>').join('') : '<p class="muted small">Noch keine.</p>') + '</div>'
                + '<form class="nc-card" id="sbCmd"><h4>✏️ Befehl</h4><input type="hidden" name="old"><div class="nx-row"><span>' + esc(c.prefix) + '</span><input type="text" name="name" placeholder="discord" required style="width:140px"><label class="small">Pause <input type="number" name="cooldown" value="30" min="0" style="width:64px"> s</label><label class="check small"><input type="checkbox" name="mod"> nur Mods</label></div>'
                + '<textarea name="response" rows="3" placeholder="Antwort, z. B. Hey {user}, du hast {punkte} {punkte_name}!" required style="width:100%"></textarea>'
                + '<p class="muted small">Platzhalter: {user} {punkte} {punkte_name} {welt} {spieler} {avatar} {musik} {zeit} {plattform} {args}</p><button class="btn btn-sm btn-gold">Speichern</button></form></div>';
        }
        if (view === 'rewards') {
            b.innerHTML = '<div class="nc-grid"><div class="nc-card"><h4>🎁 Belohnungen</h4><p class="muted small">Zuschauer lösen sie mit ' + esc(c.prefix) + 'Name im Chat ein – die Punkte werden abgezogen und das Makro läuft in VRChat (Avatar-Parameter, Chatbox, Kamera, Webhook …).</p>'
                + (c.rewards.length ? c.rewards.map(r => '<div class="nc-mrow"><code>' + esc(c.prefix + r.name) + '</code><b>' + r.cost + '</b><span class="small mono" style="flex:1">' + esc(r.script.split('\n')[0]) + (r.script.includes('\n') ? ' …' : '') + '</span><span class="muted small">' + r.cooldown + ' s</span>'
                    + '<button type="button" class="nc-link" data-redit="' + esc(r.name) + '">bearbeiten</button><button type="button" class="nc-x" data-rdel="' + esc(r.name) + '">✕</button></div>').join('') : '<p class="muted small">Noch keine.</p>') + '</div>'
                + '<form class="nc-card" id="sbRw"><h4>✏️ Belohnung</h4><input type="hidden" name="old"><div class="nx-row"><span>' + esc(c.prefix) + '</span><input type="text" name="name" placeholder="ohren" required style="width:140px"><label class="small">Kosten <input type="number" name="cost" value="100" min="1" style="width:80px"></label><label class="small">Pause <input type="number" name="cooldown" value="30" min="0" style="width:64px"> s</label></div>'
                + '<textarea name="script" rows="5" class="mono" placeholder="param Ears = 1&#10;wait 5&#10;param Ears = 0&#10;chatbox 🐾 {user} hat meine Ohren bewegt!" required style="width:100%"></textarea>'
                + '<input type="text" name="response" placeholder="Antwort im Chat, z. B. {user} hat „Ohren“ eingelöst! 🐾" style="width:100%">'
                + '<p class="muted small">Makro-Schritte wie unter Spiel &amp; OSC → Makros. Platzhalter im Makro: {user} {args} {kosten}.</p>'
                + '<div class="hm-btns"><span class="small">Vorlagen:</span>' + [['ohren', 50, 'param Ears = 1\nwait 5\nparam Ears = 0'], ['tanz', 200, 'param Dance = 1\nwait 10\nparam Dance = 0'], ['foto', 100, 'cam Capture'], ['gruss', 25, 'chatbox 👋 Grüße an {user}!'], ['hupe', 150, 'input Jump']]
                    .map(t => '<button type="button" class="chip" data-rtpl="' + esc(JSON.stringify(t)) + '">' + esc(t[0]) + '</button>').join('') + '</div><button class="btn btn-sm btn-gold">Speichern</button></form></div>';
        }
        if (view === 'setup') {
            b.innerHTML = '<form class="nc-grid" id="sbCfg">'
                + '<div class="nc-card"><h4>🟣 Twitch</h4><label class="check"><input type="checkbox" name="tw_on"' + (c.twitch.on ? ' checked' : '') + '> verbinden</label>'
                + '<div class="nx-row"><span class="small">Kanal</span><input type="text" name="tw_channel" value="' + esc(c.twitch.channel) + '" placeholder="deinkanal"><span class="small">Bot-Konto</span><input type="text" name="tw_bot" value="' + esc(c.twitch.bot) + '" placeholder="leer = Kanal"></div>'
                + '<div class="nx-row"><input type="password" id="sbTwTok" placeholder="' + (st.has.twitch ? '•••• Token gespeichert' : 'OAuth-Token (oauth:…)') + '" style="flex:1" autocomplete="off"><button type="button" class="btn btn-sm" data-key="twitch">Token speichern</button></div>'
                + '<p class="muted small">Token mit Chat-Rechten (chat:read, chat:edit) für das Bot-Konto, z. B. über die Twitch-Entwicklerkonsole oder einen Token-Generator. Wird verschlüsselt gespeichert.</p></div>'
                + '<div class="nc-card"><h4>🔴 YouTube</h4><label class="check"><input type="checkbox" name="yt_on"' + (c.youtube.on ? ' checked' : '') + '> verbinden</label>'
                + '<div class="nx-row"><span class="small">Live-Video</span><input type="text" name="yt_video" value="' + esc(c.youtube.video) + '" placeholder="Link oder Video-ID des Livestreams" style="flex:1"></div>'
                + '<div class="nx-row"><span class="small">Abfrage alle</span><input type="number" name="yt_interval" value="' + c.youtube.interval + '" min="5" max="120" style="width:64px"><span class="small">s</span></div>'
                + '<div class="nx-row"><input type="password" id="sbYtKey" placeholder="' + (st.has.youtube ? '•••• Schlüssel gespeichert' : 'Google-API-Schlüssel (YouTube Data API v3)') + '" style="flex:1" autocomplete="off"><button type="button" class="btn btn-sm" data-key="youtube">Schlüssel speichern</button></div>'
                + '<p class="muted small">YouTube: Chat lesen, Punkte, Befehle und Belohnungen gehen. Antworten des Bots erscheinen im OBS-Chat-Overlay und hier – in den YouTube-Chat schreiben bräuchte eine Google-Anmeldung (OAuth). Kostenloses Kontingent: alle 10 s reicht für ~6 h Stream.</p></div>'
                + '<div class="nc-card"><h4>🏆 Punkte</h4><div class="nx-row"><span class="small">Name</span><input type="text" name="p_name" value="' + esc(c.points.name) + '" style="width:120px"><span class="small">Start</span><input type="number" name="p_start" value="' + c.points.start + '" min="0" style="width:80px"></div>'
                + '<div class="nx-row"><span class="small">pro Nachricht</span><input type="number" name="p_per_msg" value="' + c.points.per_msg + '" min="0" style="width:64px"><span class="small">höchstens alle</span><input type="number" name="p_msg_cooldown" value="' + c.points.msg_cooldown + '" min="0" style="width:70px"><span class="small">s</span></div>'
                + '<div class="nx-row"><span class="small">fürs Zuschauen</span><input type="number" name="p_per_watch" value="' + c.points.per_watch + '" min="0" style="width:64px"><span class="small">alle</span><input type="number" name="p_watch_every" value="' + c.points.watch_every + '" min="1" style="width:64px"><span class="small">min (wer in den letzten 15 min geschrieben hat)</span></div></div>'
                + '<div class="nc-card"><h4>⚙ Bot</h4><div class="nx-row"><span class="small">Befehls-Zeichen</span><input type="text" name="prefix" value="' + esc(c.prefix) + '" maxlength="2" style="width:50px"></div>'
                + '<label class="check small"><input type="checkbox" name="chat_to_vrc"' + (c.chat_to_vrc ? ' checked' : '') + '> Stream-Chat in die VRChat-Chatbox</label> <input type="text" name="vrc_prefix" value="' + esc(c.vrc_prefix) + '" style="width:50px" title="Vorsatz">'
                + '<label class="check small"><input type="checkbox" name="vrc_only_game"' + (c.vrc_only_game ? ' checked' : '') + '> nur wenn VRChat läuft</label>'
                + '<label class="check small"><input type="checkbox" name="greet"' + (c.greet ? ' checked' : '') + '> neue Zuschauer begrüßen</label><input type="text" name="greet_text" value="' + esc(c.greet_text) + '" style="width:100%">'
                + '<p class="muted small">OBS-Chat-Overlay: Einstellungen → Integrationen → „Stream-Chat“-Adresse.</p></div>'
                + '<div><button class="btn btn-gold">💾 Speichern &amp; verbinden</button> <button type="button" class="btn btn-sm" id="sbRe">↻ Neu verbinden</button></div></form>';
        }
    }
    function $(s) { return box.querySelector(s); }
    function loadPoints() {
        api('stream_points', { q: plQ }).then(d => {
            const l = $('#sbPL');
            if (!l) return;
            l.innerHTML = d.list.length ? '<table class="ol-tab"><tbody>' + d.list.map((u, i) => '<tr><td>' + (i + 1) + '.</td><td>' + ICON[u.platform] + ' ' + esc(u.name) + '</td><td><b>' + u.p.toLocaleString('de-DE') + '</b> ' + esc(d.name) + '</td><td class="muted small">' + u.msgs + ' Nachrichten · ' + (u.last ? UI.ago(u.last) : '–') + '</td>'
                + '<td><button type="button" class="chip" data-padd="' + esc(u.key) + '" data-n="100">+100</button><button type="button" class="chip" data-padd="' + esc(u.key) + '" data-n="-100">−100</button><button type="button" class="chip" data-pset="' + esc(u.key) + '">setzen …</button></td></tr>').join('') + '</tbody></table>'
                : '<div class="nc-empty">Noch keine Zuschauer.</div>';
        }).catch(fail);
    }
    function poll() {
        clearTimeout(timer);
        api('stream_status', { since: seq }).then(d => {
            st = d;
            if (d.feed.length) { feed = feed.concat(d.feed).slice(-300); seq = d.feed[d.feed.length - 1].i; }
            if (!box.firstChild) shell();
            const a = document.activeElement;
            if (view === 'chat' || !$('#stBody').firstChild) render(); else $('#stState').innerHTML = stateHtml();
            if (a && box.contains(a) && a.focus) a.focus();
        }).catch(e => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; }).finally(() => {
            const pane = document.querySelector('[data-pane=stream]');
            if (pane && !pane.hidden) timer = setTimeout(poll, view === 'chat' ? 1500 : 5000);
        });
    }
    box.addEventListener('click', e => {
        const v = e.target.closest('[data-sv]');
        if (v) { view = v.dataset.sv; UI.store.set('streamView', view); box.querySelectorAll('[data-sv]').forEach(x => x.classList.toggle('on', x === v)); $('#stBody').innerHTML = ''; render(); return; }
        const k = e.target.closest('[data-key]');
        if (k) { const inp = $(k.dataset.key === 'twitch' ? '#sbTwTok' : '#sbYtKey'); return api('stream_key', { kind: k.dataset.key, value: inp.value }).then(d => { st = d; toast(d.msg); inp.value = ''; inp.placeholder = d.has[k.dataset.key] ? '•••• gespeichert' : inp.placeholder; $('#stState').innerHTML = stateHtml(); }).catch(fail); }   // Formular nicht neu zeichnen – sonst gehen ungespeicherte Eingaben verloren
        if (e.target.closest('#sbRe')) return api('stream_reconnect').then(d => { st = d; toast('Verbinde neu …'); render(); }).catch(fail);
        const pa = e.target.closest('[data-padd]');
        if (pa) return api('stream_points_set', { key: pa.dataset.padd, add: pa.dataset.n, q: plQ }).then(() => loadPoints()).catch(fail);
        const ps = e.target.closest('[data-pset]');
        if (ps) { const n = prompt('Neue Punktzahl:'); if (n !== null) api('stream_points_set', { key: ps.dataset.pset, p: n, q: plQ }).then(() => loadPoints()).catch(fail); return; }
        const fillForm = (f, o) => { for (const [kk, vv] of Object.entries(o)) if (f[kk]) { if (f[kk].type === 'checkbox') f[kk].checked = !!vv; else f[kk].value = vv; } };
        const ce = e.target.closest('[data-cedit]');
        if (ce) { const x = st.cfg.commands.find(y => y.name === ce.dataset.cedit); return fillForm($('#sbCmd'), Object.assign({ old: x.name }, x)); }
        const re = e.target.closest('[data-redit]');
        if (re) { const x = st.cfg.rewards.find(y => y.name === re.dataset.redit); return fillForm($('#sbRw'), Object.assign({ old: x.name }, x)); }
        const rt = e.target.closest('[data-rtpl]');
        if (rt) { const t = JSON.parse(rt.dataset.rtpl); return fillForm($('#sbRw'), { old: '', name: t[0], cost: t[1], script: t[2], response: '{user} hat „' + t[0] + '“ eingelöst!' }); }
        const cd = e.target.closest('[data-cdel]');
        if (cd && confirm('Befehl löschen?')) return api('stream_cmd_delete', { name: cd.dataset.cdel }).then(d => { st = d; render(); }).catch(fail);
        const rd = e.target.closest('[data-rdel]');
        if (rd && confirm('Belohnung löschen?')) return api('stream_reward_delete', { name: rd.dataset.rdel }).then(d => { st = d; render(); }).catch(fail);
    });
    box.addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target, data = {};
        for (const el of f.elements) if (el.name) data[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        if (f.id === 'sbSend') return api('stream_send', data).then(() => { f.text.value = ''; poll(); }).catch(fail);
        if (f.id === 'sbTest') return api('stream_test', Object.assign(data, { since: seq })).then(() => { f.text.value = ''; poll(); }).catch(fail);
        if (f.id === 'sbCmd') return api('stream_cmd_save', data).then(d => { st = d; toast(d.msg); render(); }).catch(fail);
        if (f.id === 'sbRw') return api('stream_reward_save', data).then(d => { st = d; toast(d.msg); render(); }).catch(fail);
        if (f.id === 'sbCfg') return api('stream_cfg', data).then(d => { st = d; toast(d.msg); render(); }).catch(fail);
    });
    let qt = null;
    box.addEventListener('input', e => { if (e.target.id === 'sbPQ') { plQ = e.target.value; clearTimeout(qt); qt = setTimeout(loadPoints, 300); } });
    window.NexusStream = { open() { if (!box.firstChild) shell(); poll(); } };
    const pane = document.querySelector('[data-pane=stream]');
    if (pane && !pane.hidden) window.NexusStream.open();
})();
