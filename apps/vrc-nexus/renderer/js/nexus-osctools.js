/* VRC Nexus – 💬 Chatbox-Extras (Live & OSC) und 🧰 OSC-Werkzeuge (Spiel & OSC → OSC-Werkzeuge) – eigene Umsetzung */
(function () {
    const UI = window.NexusUI;
    if (!UI) return;
    const esc = UI.esc, api = (a, d) => UI.api(a, d), toast = (m, bad) => UI.toast(m, bad);
    const $ = s => document.querySelector(s);
    const fmtT = ms => new Date(ms).toLocaleString(window.nxLoc ? nxLoc() : 'de-DE', { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    const ago = ms => { const s = Math.round((Date.now() - ms) / 1000); return s < 60 ? 'gerade eben' : s < 3600 ? 'vor ' + Math.round(s / 60) + ' min' : s < 86400 ? 'vor ' + Math.round(s / 3600) + ' h' : new Date(ms).toLocaleDateString(window.nxLoc ? nxLoc() : 'de-DE'); };
    const fail = box => e => { if (box) box.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; };

    // ============================ Chatbox-Extras ============================
    let cbx = { quick: [], hist: [], sched: [] }, prevTimer = null, wired = false;
    function chat() {
        const box = $('#cbxBox');
        if (!box) return;
        if (!wired) wire();
        api('cbx_get').then(d => { cbx = d; renderCbx(); }).catch(fail(box));
    }
    function renderCbx() {
        const box = $('#cbxBox');
        if (!box) return;
        box.innerHTML = '<h4>⚡ Schnelltexte</h4>'
            + (cbx.quick.length ? '<div class="cbx-quick">' + cbx.quick.map(q => '<span class="cbx-q"><button type="button" class="chip" data-cq="' + esc(q.id) + '" title="' + esc(q.text) + ' – Klick sendet, Umschalt+Klick ins Feld">' + esc(q.label || q.text.slice(0, 28)) + '</button><button type="button" class="cbx-x" data-cqdel="' + esc(q.id) + '" title="Löschen">✕</button></span>').join('') + '</div>'
                : '<p class="muted small">Noch keine – Text oben eingeben und „＋ Schnelltext“.</p>')
            + '<div class="nc-row"><input type="text" id="cbxLabel" maxlength="30" placeholder="Name (optional)"><button type="button" class="btn btn-sm" data-cbx="quick">＋ Schnelltext aus Feld</button></div>'
            + '<h4 style="margin-top:12px">⏰ Geplant</h4>'
            + (cbx.sched.length ? '<div class="cbx-list">' + cbx.sched.map(x => '<div class="cbx-row"><span class="mono small">' + esc(fmtT(x.at)) + '</span><span class="cbx-t" data-no-i18n>' + esc(x.text) + '</span><button type="button" class="cbx-x" data-csdel="' + esc(x.id) + '" title="Abbrechen">✕</button></div>').join('') + '</div>' : '')
            + '<div class="nc-row"><input type="time" id="cbxAt" title="Uhrzeit"><span class="muted small">oder in</span><input type="number" id="cbxMins" min="1" max="1440" placeholder="Min." style="width:80px"><button type="button" class="btn btn-sm" data-cbx="sched">⏰ Feld-Text planen</button></div>'
            + '<h4 style="margin-top:12px">🕘 Verlauf</h4>'
            + (cbx.hist.length ? '<div class="cbx-list cbx-hist">' + cbx.hist.slice(0, 15).map((h, i) => '<div class="cbx-row"><span class="muted small">' + esc(ago(h.at)) + '</span><span class="cbx-t" data-no-i18n title="' + esc(h.text) + '">' + esc(h.text) + '</span><button type="button" class="nc-a" data-ch="' + i + '" data-op="edit" title="Ins Feld">✎</button><button type="button" class="nc-a" data-ch="' + i + '" data-op="send" title="Erneut senden">↻</button></div>').join('') + '</div>'
                + '<button type="button" class="nc-link" data-cbx="histclear">Verlauf leeren</button>' : '<p class="muted small">Gesendete Nachrichten erscheinen hier.</p>');
    }
    function field() { return $('#ncChat') && $('#ncChat').text; }
    function preview() {
        const f = field(), pv = $('#cbxPrev');
        if (!f || !pv) return;
        const t = f.value;
        if (!/\{\w+/.test(t) && t.length <= 144) { pv.hidden = true; return; }
        api('cbx_preview', { text: t }).then(d => {
            pv.hidden = false;
            pv.innerHTML = '<span class="muted">Vorschau:</span> <span data-no-i18n>' + esc(d.text) + '</span> <b class="' + (d.len > 144 ? 'cbx-over' : '') + '">' + d.len + ' / 144' + (d.len > 144 ? ' – wird aufgeteilt' : '') + '</b>';
        }).catch(() => { pv.hidden = true; });
    }
    function wire() {
        wired = true;
        const f = field();
        if (f) f.addEventListener('input', () => { clearTimeout(prevTimer); prevTimer = setTimeout(preview, 300); });
        document.addEventListener('click', e => {
            if (!e.target.closest('#cbxBox')) return;
            const q = e.target.closest('[data-cq]');
            if (q) {
                const it = cbx.quick.find(x => x.id === q.dataset.cq);
                if (!it) return;
                if (e.shiftKey && field()) { field().value = it.text; field().dispatchEvent(new Event('input')); field().focus(); return; }
                q.disabled = true;
                return api('cbx_send', { text: it.text, sound: '1' }).then(d => { cbx = d; toast(d.msg); renderCbx(); }).catch(x => { q.disabled = false; toast(x.message, true); });
            }
            const qd = e.target.closest('[data-cqdel]');
            if (qd) return api('cbx_quick_del', { id: qd.dataset.cqdel }).then(d => { cbx = d; renderCbx(); });
            const sd = e.target.closest('[data-csdel]');
            if (sd) return api('cbx_sched_del', { id: sd.dataset.csdel }).then(d => { cbx = d; renderCbx(); });
            const h = e.target.closest('[data-ch]');
            if (h) {
                const it = cbx.hist[+h.dataset.ch];
                if (!it) return;
                if (h.dataset.op === 'edit' && field()) { field().value = it.text; field().dispatchEvent(new Event('input')); field().focus(); return; }
                return api('cbx_send', { text: it.text, sound: '1' }).then(d => { cbx = d; toast(d.msg); renderCbx(); }).catch(x => toast(x.message, true));
            }
            const b = e.target.closest('[data-cbx]');
            if (!b) return;
            const text = field() ? field().value.trim() : '';
            if (b.dataset.cbx === 'quick') { if (!text) return toast('Erst oben einen Text eingeben.', true); return api('cbx_quick_save', { text, label: $('#cbxLabel').value }).then(d => { cbx = d; toast(d.msg); renderCbx(); }).catch(x => toast(x.message, true)); }
            if (b.dataset.cbx === 'sched') { if (!text) return toast('Erst oben einen Text eingeben.', true); return api('cbx_sched_add', { text, at: $('#cbxAt').value, mins: $('#cbxMins').value }).then(d => { cbx = d; toast(d.msg); renderCbx(); }).catch(x => toast(x.message, true)); }
            if (b.dataset.cbx === 'histclear') return api('cbx_hist_clear').then(d => { cbx = d; renderCbx(); });
        });
        // nach dem normalen Senden den Verlauf auffrischen
        const form = $('#ncChat');
        if (form) form.addEventListener('submit', () => setTimeout(() => api('cbx_get').then(d => { cbx = d; renderCbx(); }).catch(() => {}), 2500));
    }

    // ============================ OSC-Werkzeuge ============================
    let st = null, poll = null;
    function open() {
        const box = $('#otBox');
        if (!box) return;
        if (!box.dataset.wired) { box.dataset.wired = '1'; box.addEventListener('click', onClick); }
        load();
    }
    function load() { return api('osct_status').then(d => { st = d; render(); }).catch(fail($('#otBox'))); }
    function render() {
        const box = $('#otBox');
        if (!box || !st) return;
        clearTimeout(poll);
        if (st.recording || st.playing) poll = setTimeout(() => { if (!box.closest('[hidden]')) load(); }, 1000);
        box.innerHTML = '<div class="nc-grid">'
            + '<div class="nc-card"><h4>🔌 Port-Test</h4><p class="muted small">Prüft, ob VRC Nexus OSC empfangen kann, ob gerade etwas von VRChat ankommt und ob Senden klappt.</p>'
            + '<div id="otTest"></div><button type="button" class="btn btn-sm btn-gold" data-ot="test">Test starten</button></div>'
            + '<div class="nc-card"><h4>🎬 Parameter-Szenen</h4><p class="muted small">Speichert die aktuellen Werte deiner Avatar-Parameter (ohne VRChat-eigene) – später mit einem Klick wieder setzen.' + (st.live ? ' Gerade empfangen: <b>' + st.live + '</b> Parameter.' : '') + '</p>'
            + (st.scenes.length ? '<div class="cbx-list">' + st.scenes.map(s => '<div class="cbx-row"><b class="cbx-t" data-no-i18n>' + esc(s.name) + '</b><span class="muted small">' + s.n + ' Werte' + (s.avatar && st.avatar && s.avatar !== st.avatar ? ' · anderer Avatar' : '') + '</span><button type="button" class="btn btn-sm" data-ot="scene" data-id="' + esc(s.id) + '">▶ Setzen</button><button type="button" class="cbx-x" data-ot="scenedel" data-id="' + esc(s.id) + '" title="Löschen">✕</button></div>').join('') + '</div>' : '')
            + '<div class="nc-row"><input type="text" id="otScene" maxlength="40" placeholder="Name, z. B. Party-Outfit"><button type="button" class="btn btn-sm" data-ot="scenesave">＋ Aktuellen Stand speichern</button></div></div>'
            + '<div class="nc-card"><h4>⏺ Aufnahme &amp; Wiedergabe</h4><p class="muted small">Nimmt Änderungen deiner Avatar-Parameter mit Zeitablauf auf (z. B. eine Geste oder Show) und spielt sie wieder ab.</p>'
            + (st.recording ? '<div class="ot-rec">⏺ Aufnahme läuft: <b>' + st.recording.sec + ' s</b> / ' + st.recording.max + ' s · ' + st.recording.n + ' Änderungen <button type="button" class="btn btn-sm btn-danger" data-ot="recstop">⏹ Stopp &amp; speichern</button></div>'
                : '<div class="nc-row"><input type="text" id="otRecName" maxlength="40" placeholder="Name"><select id="otRecMax"><option value="15">15 s</option><option value="30">30 s</option><option value="60" selected>60 s</option><option value="120">2 min</option><option value="300">5 min</option></select><button type="button" class="btn btn-sm btn-gold" data-ot="rec">⏺ Aufnehmen</button></div>')
            + (st.recs.length ? '<div class="cbx-list">' + st.recs.slice().reverse().map(r => '<div class="cbx-row"><b class="cbx-t" data-no-i18n>' + esc(r.name) + '</b><span class="muted small">' + r.dur + ' s · ' + r.n + '</span>'
                + (st.playing && st.playing.id === r.id ? '<button type="button" class="btn btn-sm" data-ot="playstop">⏹ ' + st.playing.left + ' s</button>' : '<select class="ot-speed" data-for="' + esc(r.id) + '"><option value="0.5">½×</option><option value="1" selected>1×</option><option value="2">2×</option></select><button type="button" class="btn btn-sm" data-ot="play" data-id="' + esc(r.id) + '">▶</button>')
                + '<button type="button" class="cbx-x" data-ot="recdel" data-id="' + esc(r.id) + '" title="Löschen">✕</button></div>').join('') + '</div>' : '') + '</div>'
            + '<div class="nc-card"><h4>📡 Weiterleitung</h4><p class="muted small">Leitet alles, was VRChat an VRC Nexus schickt, zusätzlich an andere OSC-Programme weiter (z. B. Gesichts-Tracking, Haptik). Je Zeile <code>Host:Port</code>, nur im eigenen Netz.</p>'
            + '<label class="check"><input type="checkbox" id="otFwdOn"' + (st.fwd.on ? ' checked' : '') + '> Weiterleitung an</label>'
            + '<textarea id="otFwd" rows="3" placeholder="127.0.0.1:9002">' + esc(st.fwd.targets.map(t => t.host + ':' + t.port).join('\n')) + '</textarea>'
            + '<div class="nc-row"><button type="button" class="btn btn-sm" data-ot="fwd">Speichern</button>' + (st.fwd.on && st.fwd.count ? '<span class="muted small">' + st.fwd.count + ' Pakete weitergeleitet</span>' : '') + '</div></div>'
            + '</div>';
    }
    function testHtml(t) {
        const ok = (b, y, n) => '<div class="ot-t ' + (b ? 'ok' : 'bad') + '">' + (b ? '✔ ' + y : '✖ ' + n) + '</div>';
        const ago2 = t.vrchat.last ? Math.round((Date.now() - t.vrchat.last) / 1000) : null;
        return ok(t.listen.open && t.listen.self, 'Empfang auf Port ' + t.listen.port + ' funktioniert', 'Empfang auf Port ' + t.listen.port + ' gestört' + (t.listen.error ? ': ' + t.listen.error : t.listen.open ? ' (Selbsttest kam nicht an)' : ' (Empfang ist ausgeschaltet)'))
            + ok(t.vrchat.active, 'VRChat sendet (zuletzt vor ' + ago2 + ' s)', ago2 !== null ? 'Von VRChat kam seit ' + ago2 + ' s nichts – ist VRChat offen?' : 'Von VRChat kam noch nichts – VRChat starten und OSC im Action-Menü einschalten')
            + ok(t.send.ok, 'Senden an ' + t.send.host + ':' + t.send.port + ' klappt', 'Senden fehlgeschlagen: ' + t.send.error);
    }
    function onClick(e) {
        const b = e.target.closest('[data-ot]');
        if (!b) return;
        const op = b.dataset.ot, done = d => { st = d; if (d.msg) toast(d.msg); render(); }, err = x => { b.disabled = false; toast(x.message, true); };
        b.disabled = true;
        if (op === 'test') return api('osct_test').then(t => { b.disabled = false; $('#otTest').innerHTML = testHtml(t); }).catch(err);
        if (op === 'scenesave') return api('osct_scene_save', { name: $('#otScene').value }).then(done).catch(err);
        if (op === 'scene') return api('osct_scene_apply', { id: b.dataset.id }).then(done).catch(err);
        if (op === 'scenedel') { if (!confirm('Szene löschen?')) { b.disabled = false; return; } return api('osct_scene_del', { id: b.dataset.id }).then(done).catch(err); }
        if (op === 'rec') return api('osct_rec_start', { name: $('#otRecName').value, max: $('#otRecMax').value }).then(done).catch(err);
        if (op === 'recstop') return api('osct_rec_stop').then(done).catch(err);
        if (op === 'play') { const sp = $('.ot-speed[data-for="' + b.dataset.id + '"]'); return api('osct_rec_play', { id: b.dataset.id, speed: sp ? sp.value : 1 }).then(done).catch(err); }
        if (op === 'playstop') return api('osct_rec_stopplay').then(done).catch(err);
        if (op === 'recdel') { if (!confirm('Aufnahme löschen?')) { b.disabled = false; return; } return api('osct_rec_del', { id: b.dataset.id }).then(done).catch(err); }
        if (op === 'fwd') return api('osct_fwd', { on: $('#otFwdOn').checked ? '1' : '', targets: $('#otFwd').value }).then(done).catch(err);
        b.disabled = false;
    }
    window.NexusOscTools = { chat, open };
})();
