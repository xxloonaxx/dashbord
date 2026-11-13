/* VRC Nexus – Inhalt des SteamVR-Overlays (läuft unsichtbar in Electron, das Bild geht über NexusOverlay.exe nach SteamVR). */
(function () {
    const $ = s => document.querySelector(s);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => body.append(k, v ?? ''));
        return fetch('nexus.php', { method: 'POST', body }).then(async r => { const d = await r.json().catch(() => ({ error: 'HTTP ' + r.status })); if (!r.ok || d.error) throw new Error(d.error || 'Fehler'); return d; });
    }
    const act = (action, extra) => api('overlay_act', Object.assign({ action }, extra || {}));
    let toastT = null;
    function toast(m) { const t = $('#ovToast'); t.textContent = m; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => { t.hidden = true; }, 2200); }
    const run = p => p.then(d => { toast(d.msg || '✔'); setTimeout(load, 500); }).catch(e => toast('⚠ ' + e.message));

    let tab = 'friends', data = null, cfg = { phrases: [] }, text = '';
    const EMOJI = ['👋', '😊', '😂', '❤️', '👍', '🎉', '😴', '🦊', '✨', '🙏', '😭', '🔥'];

    const V = {
        friends: () => data.friends.list.length ? '<div class="ov-list">' + data.friends.list.map(f => '<div class="ov-item"><i class="ov-dot ' + esc((f.status || '').replace(' ', '-')) + '"></i><div><b>' + (f.fav ? '⭐ ' : '') + esc(f.name) + '</b><small>' + esc(f.world || '–') + '</small></div>'
            + '<button class="btn sm" data-a="friend" data-id="' + esc(f.id) + '" data-op="join">Beitreten</button><button class="btn sm" data-a="friend" data-id="' + esc(f.id) + '" data-op="invite">Einladen</button></div>').join('') + '</div>'
            : '<div class="ov-empty">Gerade ist kein Freund im Spiel.</div>',
        players: () => { const g = data.game; if (!g || !g.world) return '<div class="ov-empty">Du bist in keiner Instanz.</div>'; return '<h3>🌐 ' + esc(g.world) + ' · ' + g.players.length + ' Spieler</h3><div class="ov-list">' + g.players.map(p => '<div class="ov-item"><div><b>' + esc(p.name) + '</b><small>seit ' + Math.max(0, Math.round((Date.now() / 1000 - p.since) / 60)) + ' min</small></div></div>').join('') + '</div>'; },
        notifs: () => data.notifs && data.notifs.items.length ? data.notifs.items.map(n => '<div class="ov-item"><div><b>' + esc(n.title) + '</b><small>' + esc(n.message) + '</small></div></div>').join('') + '<p style="color:#8796a5">Annehmen/Ablehnen bitte in VRC Nexus am PC oder im VRChat-Menü.</p>' : '<div class="ov-empty">Keine offenen Benachrichtigungen.</div>',
        chat: () => '<div class="ov-chatbox"><div class="ov-text">' + (text ? esc(text) : '<span style="color:#56687a">Sätze und Emojis antippen …</span>') + '</div><button class="btn gold" data-a="send">Senden</button><button class="btn" data-a="del">⌫</button></div>'
            + '<div class="ov-emojis">' + EMOJI.map(e => '<button data-add="' + e + '">' + e + '</button>').join('') + '</div>'
            + '<h3>Sätze</h3><div class="ov-grid">' + cfg.phrases.map(p => '<button class="btn sm" data-add="' + esc(p) + ' ">' + esc(p) + '</button>').join('') + '</div>'
            + '<h3>Mehr</h3><div class="ov-grid">' + (data.speech ? '<button class="btn gold' + (data.speech.recording ? ' on' : '') + '" data-a="speech">🎙 ' + (data.speech.recording ? 'Stopp' : 'Sprechen') + '</button>' : '')
            + '<button class="btn" data-a="clear">🧹 Chatbox leeren</button><button class="btn" data-a="typing">⌨️ tippt …</button><button class="btn' + (data.rotator && data.rotator.on ? ' on' : '') + '" data-a="rotator">🔁 Rotator</button></div>',
        quick: () => '<h3>Status</h3><div class="ov-grid">' + [['join me', '🔵 Join Me'], ['active', '🟢 Online'], ['ask me', '🟠 Ask Me'], ['busy', '🔴 Beschäftigt']].map(([k, l]) => '<button class="btn' + (data.me && data.me.status === k ? ' on' : '') + '" data-a="status" data-v="' + k + '">' + l + '</button>').join('') + '</div>'
            + '<h3>Makros</h3><div class="ov-grid">' + (data.macros.length ? data.macros.map(m => '<button class="btn gold" data-a="macro" data-id="' + esc(m.id) + '">⚡ ' + esc(m.name) + '</button>').join('') : '<span style="color:#8796a5">Keine Makros.</span>') + '</div>'
            + (data.params.length ? '<h3>Parameter</h3><div class="ov-grid">' + data.params.filter(p => p.type === 'bool').map(p => '<button class="btn' + (p.value === true ? ' on' : '') + '" data-a="toggle" data-v="' + esc(p.name) + '">' + esc(p.name) + '</button>').join('') + '</div>' : '')
            + '<h3>VRChat</h3><div class="ov-grid"><button class="btn" data-a="rejoin">🔄 Neu beitreten</button><button class="btn" data-a="input" data-v="Voice">🎙 Mikro</button><button class="btn" data-a="input" data-v="Jump">⤒ Springen</button><button class="btn" data-a="hide">🙈 Overlay aus</button></div>',
        cam: () => '<div class="ov-grid"><button class="btn gold" data-a="cam" data-v="Capture">📸 Foto</button><button class="btn" data-a="cam" data-v="CaptureDelayed">⏱ Timer</button><button class="btn" data-a="burst">🎞 Serie (5)</button><button class="btn" data-a="cam" data-v="Close">✖ Kamera zu</button></div>'
            + '<h3>Modus</h3><div class="ov-grid">' + [[1, 'Foto'], [2, 'Stream'], [3, 'Emoji'], [6, 'Drohne']].map(([m, l]) => '<button class="btn' + (data.cam.mode === m ? ' on' : '') + '" data-a="mode" data-v="' + m + '">' + l + '</button>').join('') + '</div>'
            + '<h3>Zoom</h3><div class="ov-grid"><button class="btn" data-a="zoom" data-v="-10">➖ Zoom</button><button class="btn" data-a="zoom" data-v="10">➕ Zoom</button></div>'
            + (data.cam.presets.length ? '<h3>Vorlagen</h3><div class="ov-grid">' + data.cam.presets.map(p => '<button class="btn" data-a="campreset" data-v="' + esc(p) + '">' + esc(p) + '</button>').join('') + '</div>' : ''),
    };
    function render() {
        if (!data) return;
        document.querySelectorAll('#ovTabs button').forEach(b => b.classList.toggle('on', b.dataset.t === tab));
        $('#ovNf').textContent = data.notifs && data.notifs.unseen ? data.notifs.unseen : '';
        $('#ovInfo').textContent = (data.game && data.game.world ? '🌐 ' + data.game.world + ' · ' + data.game.players.length + ' 👥 · ' : '') + '🟢 ' + data.friends.in_game + ' Freunde' + (data.music ? ' · 🎵 ' + data.music : '');
        const m = $('#ovMain'), top = m.scrollTop;
        try { m.innerHTML = V[tab](); } catch (e) { m.innerHTML = '<div class="ov-empty">⚠ ' + esc(e.message) + '</div>'; }
        m.scrollTop = top;
    }
    function load() { return api('home_data').then(d => { data = d; render(); }).catch(e => { $('#ovMain').innerHTML = '<div class="ov-empty">⚠ ' + esc(e.message) + '</div>'; }); }

    $('#ovTabs').addEventListener('click', e => { const b = e.target.closest('[data-t]'); if (b) { tab = b.dataset.t; $('#ovMain').scrollTop = 0; render(); } });
    $('#ovMain').addEventListener('click', e => {
        const add = e.target.closest('[data-add]');
        if (add) { text = (text + add.dataset.add).slice(0, 500); render(); return; }
        const b = e.target.closest('[data-a]');
        if (!b) return;
        const a = b.dataset.a, v = b.dataset.v;
        if (a === 'friend') return run(act('friend', { id: b.dataset.id, op: b.dataset.op === 'invite' ? 'invite' : 'join' }));
        if (a === 'send') { if (!text.trim()) return; const t = text; text = ''; render(); return run(act('chatbox', { text: t, sound: '1', translate: cfg.translate ? '1' : '', target: cfg.target || '' }).then(d => Object.assign(d, { msg: '💬 ' + (d.text || t) }))); }
        if (a === 'del') { text = Array.from(text).slice(0, -1).join(''); render(); return; }
        if (a === 'clear') return run(api('client_osc', { kind: 'chatbox', clear: '1' }));
        if (a === 'typing') return run(act('typing', { on: '1' }));
        if (a === 'rotator') return run(act('rotator'));
        if (a === 'speech') return run(act('speech', { op: 'toggle' }));
        if (a === 'status') return run(act('status', { status: v, desc: data.me ? data.me.desc : '' }));
        if (a === 'macro') return run(act('macro', { id: b.dataset.id }));
        if (a === 'toggle') return run(act('toggle', { name: v }));
        if (a === 'rejoin') return run(act('rejoin'));
        if (a === 'input') return run(act('input', { name: v }));
        if (a === 'hide') return run(act('overlay', { on: '' }));
        if (a === 'cam') return run(act('camera', { cmd: v }));
        if (a === 'burst') return run(act('camera', { cmd: 'burst', count: 5, seconds: 2 }));
        if (a === 'mode') return run(act('camera', { cmd: 'Mode', value: v }));
        if (a === 'zoom') return run(act('camera', { cmd: 'step', name: 'Zoom', step: v, base: 45 }));
        if (a === 'campreset') return run(act('camera', { cmd: 'preset', preset: v }));
    });
    setInterval(() => { $('#ovClock').textContent = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }); }, 1000);
    api('overlay_cfg').then(c => { cfg = c; }).catch(() => {}).finally(load);
    setInterval(load, 5000);
})();
