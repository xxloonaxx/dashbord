/* VRC Nexus Desktop – Reiter „🖥️ Spiel & OSC“: Spiel-Log aus den VRChat-Logs, getroffene Spieler, Besuche, OSC-Fernbedienung (alles lokal). */
(function () {
    const root = document.getElementById('clientPane');
    if (!root) return;
    const $ = (s, r) => (r || root).querySelector(s);
    const $$ = (s, r) => [...(r || root).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = n => Number(n || 0).toLocaleString('de-DE');
    const now = () => Date.now() / 1000;
    const hm = sec => { sec = Math.max(0, Math.round(sec)); const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return h ? h + ' h ' + m + ' min' : m + ' min'; };
    const ago = ts => { if (!ts) return 'nie'; const d = Math.max(0, now() - ts); if (d < 60) return 'gerade eben'; if (d < 3600) return 'vor ' + Math.floor(d / 60) + ' min'; if (d < 86400) return 'vor ' + Math.floor(d / 3600) + ' Std.'; return 'vor ' + Math.floor(d / 86400) + ' Tg.'; };
    const dt = ts => new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const day = ts => new Date(ts * 1000).toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
    const store = { get(k, d) { try { const v = localStorage.getItem('nc-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('nc-' + k, JSON.stringify(v)); } catch (e) { /* egal */ } } };

    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => body.append(k, typeof v === 'boolean' ? (v ? '1' : '') : (v ?? '')));
        return fetch('nexus.php', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body }).then(async r => {
            let d; try { d = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort (HTTP ' + r.status + ').'); }
            if (!r.ok || d.error) throw new Error(d.error || 'Fehler ' + r.status);
            return d;
        });
    }
    function toast(msg, bad) {
        document.querySelectorAll('.vx-toast').forEach(x => x.remove());
        const t = document.createElement('div');
        t.className = 'vx-toast' + (bad ? ' bad' : '');
        t.textContent = msg;
        document.body.append(t);
        setTimeout(() => t.classList.add('out'), 2600);
        setTimeout(() => t.remove(), 3200);
    }
    const fail = e => toast('⚠ ' + e.message, true);

    let data = null, sub = store.get('sub', 'live'), timer = null, built = false, openNow = false;
    let logBefore = 0;
    const KIND = () => (data && data.kinds) || {};

    function build() {
        built = true;
        root.innerHTML = `
            <div class="nc-live" id="ncLive"></div>
            <div class="nc-kpis" id="ncKpis"></div>
            <nav class="nc-sub" id="ncSub">
                <button type="button" class="chip" data-sub="live">📡 Live &amp; OSC</button>
                <button type="button" class="chip" data-sub="params">🎚 Parameter</button>
                <button type="button" class="chip" data-sub="cam">📷 Kamera</button>
                <button type="button" class="chip" data-sub="macros">⚡ Makros</button>
                <button type="button" class="chip" data-sub="auto">🤖 Automatik</button>
                <button type="button" class="chip" data-sub="speech">🎙 Sprache</button>
                <button type="button" class="chip" data-sub="log">📜 Spiel-Log</button>
                <button type="button" class="chip" data-sub="people">🧑‍🤝‍🧑 Getroffen</button>
                <button type="button" class="chip" data-sub="visits">🌐 Besuche</button>
                <button type="button" class="chip" data-sub="devices">⚙ Einstellungen</button>
            </nav>
            <div class="nc-pane" data-p="live">
                <div class="nc-grid">
                    <form class="nc-card" id="ncChat">
                        <h4>💬 Chatbox</h4>
                        <textarea name="text" maxlength="144" rows="3" placeholder="Text für die VRChat-Chatbox (max. 144 Zeichen)"></textarea>
                        <div class="nc-row"><span class="muted small" id="ncChatN">0 / 144</span>
                            <label class="check small"><input type="checkbox" name="sound" checked> Ton</label>
                            <button type="button" class="btn btn-sm" data-typing="1" title="Tipp-Anzeige ein">⌨️ tippt …</button>
                            <button type="button" class="btn btn-sm" data-typing="0" title="Tipp-Anzeige aus">⌨️ aus</button>
                            <button class="btn btn-sm btn-gold">Senden</button></div>
                        <button type="button" class="nc-link" data-save="chatbox">☆ als Schnellknopf speichern</button>
                    </form>
                    <form class="nc-card" id="ncParam">
                        <h4>🎛️ Avatar-Parameter</h4>
                        <div class="nc-row"><input type="text" name="name" placeholder="Parametername, z. B. Ears_Wiggle" list="ncParamList" required>
                            <select name="type"><option value="bool">An/Aus</option><option value="int">Ganzzahl</option><option value="float">Kommazahl</option></select></div>
                        <datalist id="ncParamList"></datalist>
                        <div class="nc-row" id="ncVal"></div>
                        <div class="nc-row"><button class="btn btn-sm btn-gold">Setzen</button><button type="button" class="nc-link" data-save="param">☆ als Schnellknopf speichern</button></div>
                    </form>
                    <div class="nc-card">
                        <h4>🎮 Eingaben</h4>
                        <div class="nc-inputs">
                            <button type="button" class="btn btn-sm" data-input="Jump">⤒ Springen</button>
                            <button type="button" class="btn btn-sm" data-input="Voice">🎙️ Mikro an/aus</button>
                            <button type="button" class="btn btn-sm" data-input="LookLeft">↶ Drehen</button>
                            <button type="button" class="btn btn-sm" data-input="LookRight">↷ Drehen</button>
                            <button type="button" class="btn btn-sm" data-input="QuickMenuToggleLeft">☰ Schnellmenü</button>
                        </div>
                        <h4 style="margin-top: 14px;">⭐ Schnellknöpfe</h4>
                        <div class="nc-presets" id="ncPresets"></div>
                    </div>
                </div>
                <div class="nc-card"><h4>🕒 Zuletzt gesendet</h4><div id="ncOsc"></div></div>
            </div>
            <div class="nc-pane" data-p="params" hidden>
                <div class="nc-row nc-filter">
                    <select id="ncPAv" title="Avatar"></select>
                    <input type="search" id="ncPQ" placeholder="🔎 Parameter suchen …">
                    <label class="check small"><input type="checkbox" id="ncPBuiltin"> VRChat-eigene zeigen</label>
                    <label class="check small"><input type="checkbox" id="ncPFav"> nur ★</label>
                    <button type="button" class="btn btn-sm" id="ncPReload">↻</button>
                </div>
                <div class="muted small" id="ncPInfo"></div>
                <div class="nc-pgrid" id="ncPList"></div>
            </div>
            <div class="nc-pane" data-p="cam" hidden>
                <div class="muted small" id="ncCInfo"></div>
                <div class="nc-grid">
                    <div class="nc-card"><h4>📷 Auslösen</h4>
                        <div class="nc-row" id="ncCModes"></div>
                        <div class="nc-row"><button type="button" class="btn btn-gold" data-cam="Capture">📸 Foto</button>
                            <button type="button" class="btn btn-sm" data-cam="CaptureDelayed">⏱ Selbstauslöser</button>
                            <button type="button" class="btn btn-sm" data-cam="Close">✖ Kamera zu</button></div>
                        <form class="nc-row" id="ncCBurst"><b class="small">Serie:</b> <input type="number" name="count" value="5" min="1" max="100" style="width:64px"> Fotos alle
                            <input type="number" name="seconds" value="2" min="0.3" step="0.1" style="width:64px"> s, Start nach <input type="number" name="delay" value="0" min="0" max="60" style="width:56px"> s
                            <button class="btn btn-sm">▶ Start</button><button type="button" class="btn btn-sm" id="ncCStop">■</button></form>
                        <h4 style="margin-top:12px">💾 Vorlagen</h4>
                        <div class="nc-presets" id="ncCPresets"></div>
                        <form class="nc-row" id="ncCSave"><input type="text" name="name" placeholder="Name der Vorlage (Pose + Werte)" maxlength="40"><button class="btn btn-sm">Speichern</button></form>
                    </div>
                    <div class="nc-card"><h4>🎚 Einstellungen</h4><div id="ncCSliders"></div></div>
                    <div class="nc-card"><h4>🔘 Schalter</h4><div class="nc-ctog" id="ncCToggles"></div>
                        <h4 style="margin-top:12px">📍 Pose</h4><div class="small mono" id="ncCPose">–</div>
                        <form class="nc-row" id="ncCPoseF"><input type="text" name="pose" placeholder="X Y Z  RX RY RZ" style="flex:1"><button class="btn btn-sm">Setzen</button></form></div>
                </div>
            </div>
            <div class="nc-pane" data-p="macros" hidden>
                <div class="nc-grid">
                    <div class="nc-card"><h4>⚡ Gespeicherte Makros</h4><div id="ncMList"></div>
                        <div class="nc-row"><button type="button" class="btn btn-sm" id="ncMNew">＋ Neues Makro</button><button type="button" class="btn btn-sm" id="ncMStop">■ Alle stoppen</button></div></div>
                    <form class="nc-card" id="ncMForm"><h4>✏️ Makro bearbeiten</h4>
                        <input type="hidden" name="id">
                        <div class="nc-row"><input type="text" name="name" maxlength="40" placeholder="Name, z. B. Ohren wackeln" required style="flex:1"></div>
                        <textarea name="script" rows="9" class="mono" placeholder="chatbox Hallo {name}!&#10;param Ears = 1&#10;wait 1.5&#10;param Ears = 0"></textarea>
                        <div class="nc-row"><button class="btn btn-sm btn-gold">💾 Speichern</button><button type="button" class="btn btn-sm" id="ncMTry">▶ Testen</button></div>
                        <details class="small"><summary>Schritte</summary><div class="muted">
                            <code>chatbox Text</code> (Platzhalter, /n) · <code>typing an|aus</code> · <code>param Name = Wert</code> · <code>toggle Name</code> · <code>wait Sekunden</code> ·
                            <code>input Jump|Voice|…</code> · <code>cam Capture|CaptureDelayed|Zoom 80|Mode 1</code> · <code>status join_me|active|ask_me|busy Text</code> ·
                            <code>avatar avtr_…</code> · <code>rotator an|aus</code> · <code>speak Text</code> (vorlesen) · <code>notify Text</code> · <code># Kommentar</code></div></details>
                    </form>
                </div>
            </div>
            <div class="nc-pane" data-p="auto" hidden>
                <div class="muted small" id="ncAInfo"></div>
                <div class="nc-grid">
                    <div class="nc-card"><h4>🤖 Regeln</h4><div id="ncAList"></div>
                        <h4 style="margin-top:12px">✨ Vorlagen (Chat-Widgets)</h4><div class="nc-presets" id="ncATpl"></div></div>
                    <form class="nc-card" id="ncAForm"><h4>✏️ Regel</h4>
                        <input type="hidden" name="id">
                        <div class="nc-row"><input type="text" name="name" maxlength="50" placeholder="Name" style="flex:1"></div>
                        <div class="nc-row"><b class="small">Wenn</b><select name="trigger" id="ncATrig"></select><input type="text" name="arg" placeholder="" style="flex:1"></div>
                        <div class="nc-row"><b class="small">Dann</b><span class="muted small">(Makro-Schritte, eine Zeile pro Schritt)</span></div>
                        <textarea name="script" rows="6" class="mono" placeholder="chatbox Willkommen {spieler_name}! 👋"></textarea>
                        <div class="nc-row"><label class="small">Pause danach <input type="number" name="cooldown" value="30" min="0" style="width:70px"> s</label>
                            <label class="check small"><input type="checkbox" name="only_game" checked> nur wenn VRChat läuft</label></div>
                        <div class="nc-row"><button class="btn btn-sm btn-gold">💾 Speichern</button><button type="button" class="btn btn-sm" id="ncANew">＋ Neu</button></div>
                        <p class="muted small">Zusätzliche Platzhalter: {spieler_name} {welt_name} {param_name} {param_wert} {afk_dauer} – und alle aus dem Rotator.</p>
                    </form>
                </div>
            </div>
            <div class="nc-pane" data-p="speech" hidden><div id="ncSpeech"></div></div>
            <div class="nc-pane" data-p="log" hidden>
                <div class="nc-row nc-filter">
                    <select id="ncKind"><option value="">Alle Ereignisse</option></select>
                    <input type="search" id="ncQ" placeholder="Welt, Spieler, Video …">
                    <input type="date" id="ncDay">
                    <span id="ncPlayerChip"></span>
                    <a class="btn btn-sm" href="nexus.php?client_export=csv">⇩ CSV</a>
                    <button type="button" class="btn btn-sm btn-danger" id="ncClear">🗑 Log leeren</button>
                </div>
                <div id="ncLog" class="nc-log"></div>
                <button type="button" class="btn btn-sm" id="ncMore" hidden>Ältere laden</button>
            </div>
            <div class="nc-pane" data-p="people" hidden>
                <div class="nc-row nc-filter">
                    <select id="ncDays"><option value="7">7 Tage</option><option value="30" selected>30 Tage</option><option value="90">90 Tage</option><option value="365">1 Jahr</option><option value="3650">Alles</option></select>
                    <input type="search" id="ncPQ" placeholder="Name suchen …">
                </div>
                <div id="ncPeople"></div>
            </div>
            <div class="nc-pane" data-p="visits" hidden>
                <div class="nc-row nc-filter"><select id="ncVDays"><option value="1">Heute & gestern</option><option value="7" selected>7 Tage</option><option value="30">30 Tage</option><option value="90">90 Tage</option></select></div>
                <div id="ncVisits"></div>
            </div>
            <div class="nc-pane" data-p="devices" hidden>
                <form class="nc-grid" id="ncCfg">
                    <div class="nc-card">
                        <h4>📜 VRChat-Logs</h4>
                        <label class="check"><input type="checkbox" name="logs"> Logs mitlesen (Welten, Spieler, Avatare, Videos, Screenshots, Einladungen)</label>
                        <label class="small muted">Log-Ordner (leer = Standard)</label>
                        <input type="text" name="log_dir" placeholder="%LOCALAPPDATA%Low\\VRChat\\VRChat">
                        <p class="muted small">Aktuell: <code id="ncLogDir"></code></p>
                        <div class="nc-row"><button type="button" class="btn btn-sm" id="ncImport">⇩ Alle alten Logs einlesen</button></div>
                    </div>
                    <div class="nc-card">
                        <h4>🎛️ OSC</h4>
                        <div class="nc-row"><label class="small">Ziel</label><input type="text" name="osc_host" style="width: 130px;"><label class="small">Port</label><input type="number" name="osc_send_port" min="1" max="65535" style="width: 90px;"></div>
                        <label class="check"><input type="checkbox" name="osc_listen"> Avatar-Parameter von VRChat mitlesen</label>
                        <div class="nc-row"><label class="small">Empfangs-Port</label><input type="number" name="osc_listen_port" min="1" max="65535" style="width: 90px;"></div>
                        <p class="muted small" id="ncOscErr"></p>
                        <p class="muted small">In VRChat: Action-Menü → Optionen → <b>OSC aktivieren</b>.</p>
                    </div>
                    <div class="nc-card">
                        <h4>💬 Chatbox-Rotator</h4>
                        <label class="check"><input type="checkbox" name="rotator_enabled"> automatisch Status in die Chatbox schreiben</label>
                        <label class="small">Ein Text</label>
                        <input type="text" name="rotator_text" maxlength="200">
                        <label class="small">… oder mehrere im Wechsel (eine Zeile = ein Text)</label>
                        <textarea name="rotator_texts" rows="4" placeholder="🕒 {zeit} · 🌐 {welt}/n👥 {spieler} Spieler&#10;🎧 Musik an, Mikro aus&#10;{zufall:Hallo!|Hi!|Moin!} Schön, dass du da bist"></textarea>
                        <div class="nc-row"><label class="small">alle</label><input type="number" name="rotator_seconds" min="5" max="3600" style="width: 90px;"><span class="small">Sekunden</span>
                            <select name="rotator_mode"><option value="seq">der Reihe nach</option><option value="random">zufällig</option></select></div>
                        <label class="check"><input type="checkbox" name="rotator_only_game"> nur wenn VRChat läuft</label>
                        <p class="muted small"><b>/n</b> = neue Zeile · Platzhalter: {zeit} {zeit_s} {uhr} {datum} {wochentag} {welt} {zugang} {region} {spieler} {avatar} {dauer} {name}
                            {freunde} {freunde_online} {status} {statustext} {countdown:2026-12-24 18:00} {zufall:a|b|c} {musik} {afk} {afk_zeit} {spieler_neu}</p>
                        <div class="nc-row"><button type="button" class="btn btn-sm" id="ncRotPrev">👁 Vorschau</button><span class="small" id="ncRotOut" style="white-space: pre-wrap;"></span></div>
                        <button class="btn btn-sm btn-gold">💾 Speichern</button>
                    </div>
                </form>
            </div>`;
        $('#ncSub').addEventListener('click', e => { const b = e.target.closest('[data-sub]'); if (b) showSub(b.dataset.sub); });
        // Chatbox
        const chat = $('#ncChat');
        chat.text.addEventListener('input', () => { $('#ncChatN').textContent = chat.text.value.length + ' / 144'; });
        chat.text.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chat.requestSubmit(); } });
        chat.addEventListener('submit', e => {
            e.preventDefault();
            const text = chat.text.value.trim();
            if (!text) return;
            const extra = chat.translate ? { translate: chat.translate.checked ? '1' : '', target: chat.target.value, split: chat.split.checked ? '1' : '', delay: chat.delay.value } : {};
            osc('chatbox', Object.assign({ text, sound: chat.sound.checked ? '1' : '' }, extra)).then(d => {
                chat.text.value = ''; $('#ncChatN').textContent = '0 / 144';
                if (d && d.sent) toast('🌐 gesendet: ' + d.sent);
                else if (extra.delay && extra.delay !== '0') toast('⏱ wird in ' + extra.delay + ' s gesendet.');
            });
        });
        $$('[data-typing]', chat).forEach(b => b.addEventListener('click', () => osc('typing', { on: b.dataset.typing === '1' })));
        // Parameter
        const pf = $('#ncParam');
        const valField = () => {
            const t = pf.type.value;
            $('#ncVal').innerHTML = t === 'bool' ? '<label class="check"><input type="radio" name="value" value="1" checked> An</label><label class="check"><input type="radio" name="value" value="0"> Aus</label>'
                : t === 'int' ? '<input type="number" name="value" min="0" max="255" value="0" style="width: 110px;"> <span class="muted small">0–255</span>'
                : '<input type="range" name="value" min="-1" max="1" step="0.05" value="0" style="flex: 1;"> <span class="mono small" id="ncFv">0.00</span>';
            const r = $('#ncVal input[type=range]');
            if (r) r.addEventListener('input', () => { $('#ncFv').textContent = Number(r.value).toFixed(2); });
        };
        pf.type.addEventListener('change', valField);
        valField();
        pf.addEventListener('submit', e => { e.preventDefault(); osc('param', paramData()); });
        const paramData = () => ({ name: pf.name.value.trim(), type: pf.type.value, value: (pf.querySelector('[name=value]:checked') || pf.querySelector('[name=value]')).value });
        $$('[data-input]').forEach(b => b.addEventListener('click', () => osc('input', { name: b.dataset.input, value: 1, pulse: true })));
        // Schnellknöpfe speichern
        $$('[data-save]').forEach(b => b.addEventListener('click', () => {
            const kind = b.dataset.save;
            const payload = kind === 'chatbox' ? { text: chat.text.value.trim(), sound: chat.sound.checked } : paramData();
            if (kind === 'chatbox' && !payload.text) return toast('Erst einen Text eingeben.', true);
            if (kind === 'param' && !payload.name) return toast('Erst einen Parameternamen eingeben.', true);
            const label = prompt('Beschriftung für den Schnellknopf:', kind === 'chatbox' ? payload.text.slice(0, 20) : payload.name + ' = ' + payload.value);
            if (!label) return;
            api('client_preset_save', Object.assign({ label, kind }, payload)).then(d => { data.presets = d.presets; renderPresets(); toast('Schnellknopf gespeichert.'); }).catch(fail);
        }));
        $('#ncPresets').addEventListener('click', e => {
            const del = e.target.closest('[data-pdel]');
            if (del) { api('client_preset_delete', { preset: del.dataset.pdel }).then(d => { data.presets = d.presets; renderPresets(); }).catch(fail); return; }
            const b = e.target.closest('[data-preset]');
            if (!b) return;
            const p = data.presets.find(x => String(x.id) === b.dataset.preset);
            if (p) osc(p.kind, p.payload);
        });
        // Spiel-Log
        const kinds = $('#ncKind');
        Object.entries(KIND()).forEach(([k, v]) => { const o = document.createElement('option'); o.value = k; o.textContent = v[0] + ' ' + v[1]; kinds.append(o); });
        let qt;
        ['#ncKind', '#ncDay'].forEach(s => $(s).addEventListener('change', () => loadLog(true)));
        $('#ncQ').addEventListener('input', () => { clearTimeout(qt); qt = setTimeout(() => loadLog(true), 300); });
        $('#ncMore').addEventListener('click', () => loadLog(false));
        $('#ncClear').addEventListener('click', () => {
            if (!confirm('Wirklich das komplette Spiel-Log löschen? Das lässt sich nicht rückgängig machen.')) return;
            api('client_log_clear').then(d => { toast(num(d.deleted) + ' Einträge gelöscht.'); loadLog(true); refresh(); }).catch(fail);
        });
        $('#ncPlayerChip').addEventListener('click', e => { if (e.target.closest('[data-unplayer]')) { player = null; loadLog(true); } });
        // Getroffen
        $('#ncDays').addEventListener('change', loadPeople);
        let pt;
        $('#ncPQ').addEventListener('input', () => { clearTimeout(pt); pt = setTimeout(loadPeople, 300); });
        $('#ncPeople').addEventListener('click', e => {
            const b = e.target.closest('[data-player]');
            if (!b) return;
            player = { id: b.dataset.player, name: b.dataset.name };
            showSub('log');
        });
        $('#ncVDays').addEventListener('change', loadVisits);
        // Geräte
        const cfgForm = $('#ncCfg');
        cfgForm.addEventListener('submit', e => {
            e.preventDefault();
            const f = cfgForm, d = {};
            ['log_dir', 'osc_host', 'osc_send_port', 'osc_listen_port', 'rotator_text', 'rotator_seconds', 'rotator_texts', 'rotator_mode'].forEach(k => { d[k] = f[k].value; });
            ['logs', 'osc_listen', 'rotator_enabled', 'rotator_only_game'].forEach(k => { d[k] = f[k].checked ? '1' : '0'; });
            api('client_cfg_save', d).then(r => { data.cfg = r.cfg; toast('Gespeichert.'); refresh(); }).catch(fail);
        });
        $('#ncRotPrev').addEventListener('click', () => {
            const f = cfgForm, lines = f.rotator_texts.value.split('\n').map(x => x.trim()).filter(Boolean);
            api('client_rot_preview', { text: lines[0] || f.rotator_text.value }).then(r => { $('#ncRotOut').textContent = r.text + '  (' + [...r.text].length + '/144)'; }).catch(fail);
        });
        $('#ncImport').addEventListener('click', () => {
            if (!confirm('Alle vorhandenen VRChat-Logdateien einlesen? (Doppelte Einträge werden erkannt.)')) return;
            api('client_import').then(r => { toast(r.msg); refresh(); }).catch(fail);
        });
    }
    let player = null;

    function showSub(name) {
        sub = name;
        store.set('sub', name);
        $$('#ncSub [data-sub]').forEach(b => b.classList.toggle('on', b.dataset.sub === name));
        $$('.nc-pane').forEach(p => { p.hidden = p.dataset.p !== name; });
        if (name === 'log') loadLog(true);
        if (name === 'params') loadParams();
        if (name === 'cam') loadCam();
        if (name === 'macros') loadMacros();
        if (name === 'auto') loadAuto();
        if (name === 'speech' && window.NexusSpeech) window.NexusSpeech.open();
        if (name === 'people') loadPeople();
        if (name === 'visits') loadVisits();
    }

    function osc(kind, payload) {
        return api('client_osc', Object.assign({ kind }, payload)).then(d => {
            data.osc = d.osc;
            renderOsc();
            const fresh = data.state && data.state.fresh;
            toast('An VRChat gesendet.');
        }).catch(fail);
    }

    function renderLive() {
        const s = data.state, online = data.tokens.some(t => t.online);
        let h;
        if (!data.tokens.length) h = '<div class="nc-empty">Noch keine Daten.</div>';
        else if (!s || !s.fresh) h = '<div class="nc-status"><i class="nc-dot ' + (online ? 'on' : '') + '"></i><b>' + 'VRC Nexus läuft' + '</b>'
            + '<span class="muted">' + (s ? 'letzter Stand ' + ago(s.updated) : 'noch keine Daten') + '</span></div>';
        else {
            const players = (s.players || []).slice().sort((a, b) => a.name.localeCompare(b.name));
            h = '<div class="nc-status"><i class="nc-dot on"></i><b>' + (s.running ? 'VRChat läuft' : 'VRChat ist zu') + '</b>'
                + (s.display_name ? '<span>als <b>' + esc(s.display_name) + '</b></span>' : '')
                + (s.osc ? '<span class="nc-badge ok">OSC aktiv</span>' : '<span class="nc-badge">OSC ?</span>')
                + (s.avatar ? '<span>🎭 ' + esc(s.avatar) + '</span>' : '') + '<span class="muted">aktualisiert ' + ago(s.updated) + '</span></div>';
            if (s.running && s.world_name) {
                h += '<div class="nc-world"><div><div class="nc-wname">🌐 ' + esc(s.world_name) + '</div><div class="muted small">' + (s.since ? 'seit ' + hm(now() - s.since) : '') + ' · ' + players.length + ' Spieler'
                    + (s.world_id ? ' · <a href="vrchat://launch?ref=vrc-nexus&id=' + encodeURIComponent(s.world_id + ':' + (s.instance || '')) + '">beitreten</a>' : '') + '</div></div>'
                    + '<div class="nc-players">' + players.map(p => '<span class="nc-pl" title="' + esc(p.since ? 'da seit ' + hm(now() - p.since) : '') + '">' + esc(p.name) + '</span>').join('') + '</div></div>';
            }
            const params = Object.entries(s.params || {});
            if (params.length) {
                h += '<div class="nc-params">' + params.slice(0, 40).map(([k, v]) => '<span class="nc-par" data-pname="' + esc(k) + '" data-ptype="' + (typeof v === 'boolean' ? 'bool' : Number.isInteger(v) ? 'int' : 'float') + '">' + esc(k) + ' <b>' + esc(typeof v === 'number' && !Number.isInteger(v) ? v.toFixed(2) : String(v)) + '</b></span>').join('') + '</div>';
                const dl = $('#ncParamList');
                if (dl) dl.innerHTML = params.map(([k]) => '<option value="' + esc(k) + '">').join('');
            }
        }
        $('#ncLive').innerHTML = h;
        $$('.nc-par').forEach(p => p.addEventListener('click', () => {
            showSub('live');
            const f = $('#ncParam');
            f.name.value = p.dataset.pname; f.type.value = p.dataset.ptype; f.type.dispatchEvent(new Event('change'));
        }));
        const st = data.stats, t = st.today;
        $('#ncKpis').innerHTML = [['🌐', t.worlds, 'Welten heute'], ['👋', t.players, 'Begegnungen heute'], ['⏱️', hm(t.playtime), 'Spielzeit heute'], ['📸', t.screenshots, 'Screenshots heute'],
            ['🧑‍🤝‍🧑', num(st.people), 'Leute insgesamt'], ['📜', num(st.total), 'Log-Einträge' + (st.since ? ' seit ' + new Date(st.since * 1000).toLocaleDateString('de-DE') : '')]]
            .map(([i, v, l]) => '<div class="nc-kpi"><span>' + i + '</span><b>' + esc(String(v)) + '</b><small>' + esc(l) + '</small></div>').join('');
    }

    function renderOsc() {
        const ST = { pending: ['⏳', 'wartet'], sent: ['📤', 'abgeholt'], done: ['✔', 'ausgeführt'], failed: ['✖', 'fehlgeschlagen'], expired: ['⌛', 'verfallen'] };
        const desc = o => o.kind === 'chatbox' ? '💬 „' + esc(o.payload.text) + '“' : o.kind === 'typing' ? '⌨️ Tipp-Anzeige ' + (o.payload.on ? 'an' : 'aus')
            : o.kind === 'param' ? '🎛️ ' + esc(o.payload.name) + ' = <b>' + esc(String(o.payload.value)) + '</b>' : '🎮 ' + esc(o.payload.name);
        $('#ncOsc').innerHTML = data.osc.length ? data.osc.map(o => '<div class="nc-oscrow s-' + esc(o.status) + '"><span class="mono small">' + dt(o.created).slice(-8) + '</span><span>' + desc(o) + '</span><span class="small" title="' + esc(o.result) + '">' + (ST[o.status] || ['', o.status]).join(' ') + '</span></div>').join('')
            : '<div class="muted small">Noch nichts gesendet.</div>';
    }

    function renderPresets() {
        $('#ncPresets').innerHTML = data.presets.length ? data.presets.map(p => '<span class="nc-preset"><button type="button" class="btn btn-sm" data-preset="' + p.id + '" title="' + esc(p.kind === 'chatbox' ? p.payload.text : (p.payload.name || '') + ' = ' + p.payload.value) + '">' + (p.kind === 'chatbox' ? '💬 ' : p.kind === 'param' ? '🎛️ ' : '🎮 ') + esc(p.label) + '</button><button type="button" class="nc-x" data-pdel="' + p.id + '" title="Entfernen">×</button></span>').join('')
            : '<div class="muted small">Unter Chatbox oder Parameter „☆ als Schnellknopf speichern“.</div>';
    }

    function renderTokens() {
        const f = $('#ncCfg'), c = data.cfg || {};
        if (!f || f.contains(document.activeElement)) return;
        ['log_dir', 'osc_host', 'osc_send_port', 'osc_listen_port', 'rotator_text', 'rotator_seconds', 'rotator_texts', 'rotator_mode'].forEach(k => { f[k].value = c[k] ?? ''; });
        ['logs', 'osc_listen', 'rotator_enabled', 'rotator_only_game'].forEach(k => { f[k].checked = !!c[k]; });
        $('#ncLogDir').textContent = data.log_dir || '';
        $('#ncOscErr').textContent = data.state && data.state.osc_error ? '⚠ ' + data.state.osc_error : '';
    }

    function eventText(e) {
        const w = e.world_name ? '<b>' + esc(e.world_name) + '</b>' : esc(e.world_id);
        const p = '<b>' + esc(e.player_name) + '</b>';
        switch (e.kind) {
            case 'world_join': return 'Welt ' + w + (e.instance ? ' <span class="muted small">#' + esc(e.instance.split('~')[0]) + (/~private/.test(e.instance) ? ' · privat' : /~friends/.test(e.instance) ? ' · Freunde' : /~hidden/.test(e.instance) ? ' · Freunde+' : /~group/.test(e.instance) ? ' · Gruppe' : ' · öffentlich') + '</span>' : '');
            case 'world_leave': return 'Welt verlassen' + (e.world_name ? ': ' + w : '');
            case 'player_join': return p + ' kam dazu';
            case 'player_leave': return p + ' ist gegangen';
            case 'avatar': return (e.player_name ? p + ' trägt jetzt ' : 'Avatar: ') + '<b>' + esc(e.detail) + '</b>';
            case 'video': return 'Video: ' + (/^https?:\/\//.test(e.detail) ? '<a href="' + esc(e.detail) + '" target="_blank" rel="noopener noreferrer">' + esc(e.detail.slice(0, 90)) + '</a>' : esc(e.detail));
            case 'screenshot': return 'Screenshot <span class="mono small">' + esc(e.detail.split(/[\\/]/).pop()) + '</span>';
            case 'notification': return esc(e.detail) + (e.player_name ? ' von ' + p : '');
            default: return esc(e.detail || (KIND()[e.kind] || ['', e.kind])[1]);
        }
    }

    function loadLog(reset) {
        if (reset) logBefore = 0;
        $('#ncPlayerChip').innerHTML = player ? '<span class="chip on">👤 ' + esc(player.name) + ' <b data-unplayer title="Filter entfernen">×</b></span>' : '';
        const box = $('#ncLog');
        if (reset) box.innerHTML = '<div class="vx-loading">Lade …</div>';
        api('client_log', { kind: $('#ncKind').value, q: $('#ncQ').value, day: $('#ncDay').value, before: logBefore, player: player ? player.id : '' }).then(d => {
            let lastDay = reset ? '' : box.dataset.lastDay || '';
            if (reset) box.innerHTML = '';
            const html = d.events.map(e => {
                const dd = day(e.ts);
                const head = dd !== lastDay ? '<div class="nc-day">' + dd + '</div>' : '';
                lastDay = dd;
                return head + '<div class="nc-ev k-' + esc(e.kind) + '"><span class="mono small">' + dt(e.ts).slice(-8) + '</span><span class="nc-ic">' + ((KIND()[e.kind] || ['•'])[0]) + '</span><span>' + eventText(e) + '</span></div>';
            }).join('');
            box.insertAdjacentHTML('beforeend', html || (reset ? '<div class="nc-empty">Keine Einträge. VRC Nexus liest die VRChat-Logs automatisch mit – oder unter ⚙ Einstellungen alte Logs einlesen.</div>' : ''));
            box.dataset.lastDay = lastDay;
            if (d.events.length) logBefore = d.events[d.events.length - 1].id;
            $('#ncMore').hidden = d.events.length < 100;
        }).catch(fail);
    }

    function loadPeople() {
        const box = $('#ncPeople');
        box.innerHTML = '<div class="vx-loading">Lade …</div>';
        api('client_players', { days: $('#ncDays').value, q: $('#ncPQ').value }).then(d => {
            box.innerHTML = d.players.length ? '<table class="nc-tbl"><tr><th>Spieler</th><th>Begegnungen</th><th>Zeit zusammen</th><th>Welten</th><th>Zuletzt</th></tr>' + d.players.map(p =>
                '<tr><td><button type="button" class="nc-link" data-player="' + esc(p.id) + '" data-name="' + esc(p.name) + '">' + esc(p.name) + '</button>' + (p.friend ? ' <span class="nc-badge ok">Freund</span>' : '') + '</td>'
                + '<td>' + num(p.meets) + '</td><td>' + (p.seconds ? hm(p.seconds) : '–') + '</td><td>' + num(p.worlds) + '</td><td>' + ago(p.last) + '</td></tr>').join('') + '</table>'
                : '<div class="nc-empty">Noch niemanden getroffen (laut Spiel-Log).</div>';
        }).catch(fail);
    }

    function loadVisits() {
        const box = $('#ncVisits');
        box.innerHTML = '<div class="vx-loading">Lade …</div>';
        api('client_visits', { days: $('#ncVDays').value }).then(d => {
            const max = Math.max(1, ...d.visits.map(v => v.seconds));
            box.innerHTML = d.visits.length ? d.visits.map(v => '<div class="nc-visit"><div><b>' + esc(v.name || v.world_id || 'Unbekannte Welt') + '</b><div class="muted small">' + dt(v.start) + ' · ' + v.players + ' Begegnungen' + (v.end ? '' : ' · <b class="gold">jetzt</b>') + '</div></div>'
                + '<div class="nc-vbar"><i style="width:' + Math.max(2, v.seconds / max * 100) + '%"></i></div><span class="mono small">' + hm(v.seconds) + '</span></div>').join('')
                : '<div class="nc-empty">Keine Weltbesuche im Zeitraum.</div>';
        }).catch(fail);
    }

    // ---------- 🎚 Avatar-Parameter (aus der OSC-Konfiguration von VRChat) ----------
    let pData = null, pAvatar = '', pBound = false;
    const pVal = p => p.value === null ? '' : p.type === 'float' ? Number(p.value).toFixed(2) : String(p.value);
    function loadParams() {
        bindParams();
        return api('client_params', { avatar: pAvatar }).then(d => { pData = d; renderParams(); }).catch(fail);
    }
    function bindParams() {
        if (pBound) return;
        pBound = true;
        $('#ncPAv').addEventListener('change', e => { pAvatar = e.target.value; loadParams(); });
        ['#ncPQ', '#ncPBuiltin', '#ncPFav'].forEach(s => $(s).addEventListener('input', renderParams));
        $('#ncPReload').addEventListener('click', loadParams);
        $('#ncPList').addEventListener('click', e => {
            const fav = e.target.closest('[data-pfav]');
            if (fav) { api('client_param_fav', { avatar: pData.avatar.id, name: fav.dataset.pfav }).then(() => loadParams()).catch(fail); return; }
            const b = e.target.closest('[data-pset]');
            if (b) setParam(b.dataset.pset, b.dataset.t, b.dataset.v);
        });
        $('#ncPList').addEventListener('change', e => {
            const r = e.target.closest('[data-prange]');
            if (r) setParam(r.dataset.prange, r.dataset.t, r.value);
        });
        $('#ncPList').addEventListener('input', e => { const r = e.target.closest('[data-prange]'); if (r && r.nextElementSibling) r.nextElementSibling.textContent = Number(r.value).toFixed(r.dataset.t === 'float' ? 2 : 0); });
    }
    function setParam(name, type, value) {
        const v = type === 'bool' ? (value === '1' ? '1' : '') : value;
        api('client_osc', { kind: 'param', name, type, value: v }).then(() => {
            const p = pData && pData.params.find(x => x.name === name);
            if (p) { p.value = type === 'bool' ? value === '1' : Number(value); renderParams(); }
        }).catch(fail);
    }
    function renderParams() {
        if (!pData) return;
        const d = pData;
        $('#ncPAv').innerHTML = (d.avatars.length ? '' : '<option value="">(keine Avatar-Dateien gefunden)</option>') + d.avatars.map(a => '<option value="' + esc(a.id) + '"' + (d.avatar && a.id === d.avatar.id ? ' selected' : '') + '>'
            + (a.id === d.current ? '▶ ' : '') + esc(a.name) + ' (' + a.count + ')</option>').join('');
        const q = $('#ncPQ').value.trim().toLowerCase(), bi = $('#ncPBuiltin').checked, fo = $('#ncPFav').checked;
        const list = d.params.filter(p => (bi || !p.builtin) && (!fo || p.fav) && (!q || p.name.toLowerCase().includes(q)));
        $('#ncPInfo').innerHTML = (d.avatar ? '🎭 <b>' + esc(d.avatar.name || d.avatar.id) + '</b>' + (d.avatar.id === d.current ? ' · <span class="nc-badge ok">wird getragen</span>' : ' · <span class="nc-badge">nicht getragen – Werte unbekannt</span>') : 'Kein Avatar erkannt.')
            + ' · ' + d.params.length + ' Parameter' + (d.osc_active ? '' : ' · ⚠ Kein OSC empfangen – in VRChat: Action-Menü → Optionen → OSC → Aktiviert')
            + '<br>Quelle: <span class="mono">' + esc(d.dir) + '</span>';
        $('#ncPList').innerHTML = list.length ? list.map(p => {
            let ctl;
            if (!p.input) ctl = '<span class="muted small">nur Ausgabe</span> <b>' + esc(pVal(p)) + '</b>';
            else if (p.type === 'bool') ctl = '<button type="button" class="btn btn-sm' + (p.value === true ? ' btn-gold' : '') + '" data-pset="' + esc(p.name) + '" data-t="bool" data-v="' + (p.value === true ? '0' : '1') + '">' + (p.value === true ? '● An' : '○ Aus') + '</button>';
            else if (p.type === 'int') ctl = '<input type="range" min="0" max="255" step="1" value="' + (p.value === null ? 0 : p.value) + '" data-prange="' + esc(p.name) + '" data-t="int"><b class="small">' + esc(pVal(p) || '–') + '</b>'
                + '<span class="nc-pints">' + [0, 1, 2, 3, 4, 5].map(n => '<button type="button" class="chip" data-pset="' + esc(p.name) + '" data-t="int" data-v="' + n + '">' + n + '</button>').join('') + '</span>';
            else ctl = '<input type="range" min="-1" max="1" step="0.01" value="' + (p.value === null ? 0 : p.value) + '" data-prange="' + esc(p.name) + '" data-t="float"><b class="small">' + esc(pVal(p) || '–') + '</b>';
            return '<div class="nc-prow' + (p.builtin ? ' builtin' : '') + '"><button type="button" class="nc-star' + (p.fav ? ' on' : '') + '" data-pfav="' + esc(p.name) + '" title="Favorit">★</button>'
                + '<span class="nc-pname" title="' + esc(p.name) + '">' + esc(p.name) + ' <small class="muted">' + ({ bool: 'An/Aus', int: 'Zahl', float: 'Komma' })[p.type] + (p.live_only ? ' · nur live' : '') + '</small></span><span class="nc-pctl">' + ctl + '</span></div>';
        }).join('') : '<div class="nc-empty">Keine Parameter' + (q ? ' für „' + esc(q) + '“' : '') + '. VRChat legt die Datei an, sobald du den Avatar mit eingeschaltetem OSC trägst.</div>';
    }

    // ---------- 🤖 Automatik / Chat-Widgets ----------
    const AUTO_TPL = [
        ['👋 Begrüßung', 'player_join', 'others', 'chatbox Willkommen {spieler_name}! 👋', 20],
        ['💛 Freunde begrüßen', 'player_join', 'friends', 'chatbox Heyy {spieler_name}! 💛\ntoggle Wave', 20],
        ['🚪 Abschied', 'player_leave', 'friends', 'chatbox Tschüss {spieler_name}! 👋', 20],
        ['💤 AFK-Hinweis', 'afk_on', '', 'chatbox 💤 Bin kurz AFK – seit {zeit}', 0],
        ['☀️ Zurück', 'afk_off', '', 'chatbox ☀️ Wieder da (war {afk_dauer} weg)', 0],
        ['🎵 Spielt gerade', 'interval', '2', 'chatbox 🎵 {musik}', 0],
        ['🌐 Neue Welt', 'world_join', '', 'wait 8\nchatbox 🌐 {welt_name} · 👥 {spieler}', 60],
        ['💧 Trinkpause', 'interval', '60', 'chatbox 💧 Kurze Trinkpause!\nnotify 💧 Trink was!', 0],
        ['🌙 Gute-Nacht', 'time', '23:30', 'chatbox 🌙 Gleich ist Schlafenszeit …\nstatus ask_me Bald weg', 0],
        ['📸 Danke fürs Foto', 'screenshot', '', 'chatbox 📸 Klick!', 30],
        ['🎭 Avatar-Info', 'avatar_change', '', 'wait 2\nchatbox 🎭 Neuer Look!', 30],
    ];
    let aData = null, aBound = false;
    function loadAuto() {
        if (!aBound) {
            aBound = true;
            const f = $('#ncAForm');
            f.trigger.addEventListener('change', () => { f.arg.placeholder = ((aData && aData.triggers[f.trigger.value]) || [])[1] || ''; f.arg.hidden = !f.arg.placeholder; });
            f.addEventListener('submit', e => {
                e.preventDefault();
                api('client_auto', { sub: 'auto_save', id: f.id.value, name: f.name.value, trigger: f.trigger.value, arg: f.arg.value, script: f.script.value, cooldown: f.cooldown.value, only_game: f.only_game.checked })
                    .then(d => { aData.rules = d.rules; renderAuto(); toast(d.msg); }).catch(fail);
            });
            $('#ncANew').addEventListener('click', () => { f.reset(); f.id.value = ''; f.trigger.dispatchEvent(new Event('change')); });
            $('#ncATpl').addEventListener('click', e => {
                const b = e.target.closest('[data-tpl]');
                if (!b) return;
                const t = AUTO_TPL[+b.dataset.tpl];
                f.reset(); f.id.value = ''; f.name.value = t[0]; f.trigger.value = t[1]; f.trigger.dispatchEvent(new Event('change')); f.arg.value = t[2]; f.script.value = t[3]; f.cooldown.value = t[4];
                f.name.focus();
            });
            $('#ncAList').addEventListener('click', e => {
                const b = e.target.closest('[data-a]');
                if (!b) return;
                const r = aData.rules.find(x => x.id === b.dataset.id);
                if (b.dataset.a === 'edit') { f.id.value = r.id; f.name.value = r.name; f.trigger.value = r.trigger; f.trigger.dispatchEvent(new Event('change')); f.arg.value = r.arg; f.script.value = r.script; f.cooldown.value = r.cooldown; f.only_game.checked = r.only_game; return; }
                if (b.dataset.a === 'delete' && !confirm('Regel „' + r.name + '“ löschen?')) return;
                api('client_auto', { sub: 'auto_' + b.dataset.a, id: r.id }).then(d => { if (d.rules) { aData.rules = d.rules; renderAuto(); } if (d.msg) toast(d.msg); }).catch(fail);
            });
        }
        return api('client_auto', { sub: 'auto_list' }).then(d => {
            aData = d;
            const sel = $('#ncATrig');
            if (!sel.options.length) { sel.innerHTML = Object.entries(d.triggers).map(([k, v]) => '<option value="' + k + '">' + esc(v[0]) + '</option>').join(''); sel.dispatchEvent(new Event('change')); }
            renderAuto();
        }).catch(fail);
    }
    function renderAuto() {
        const d = aData;
        $('#ncAInfo').innerHTML = '🎵 Spielt gerade: <b>' + esc(d.music || '–') + '</b>' + (d.afk ? ' · 💤 AFK' : '') + ' · Regeln laufen im Hintergrund, solange VRC Nexus offen ist (auch im Infobereich).';
        $('#ncATpl').innerHTML = AUTO_TPL.map((t, i) => '<button type="button" class="chip" data-tpl="' + i + '">' + esc(t[0]) + '</button>').join('');
        $('#ncAList').innerHTML = d.rules.length ? d.rules.map(r => '<div class="nc-mrow' + (r.on ? '' : ' off') + '"><button type="button" class="chip' + (r.on ? ' on' : '') + '" data-a="toggle" data-id="' + esc(r.id) + '">' + (r.on ? 'an' : 'aus') + '</button>'
            + '<b>' + esc(r.name) + '</b><span class="muted small">' + esc((d.triggers[r.trigger] || [r.trigger])[0]) + (r.arg ? ' · ' + esc(r.arg) : '') + '</span>'
            + '<button type="button" class="nc-link" data-a="test" data-id="' + esc(r.id) + '">testen</button><button type="button" class="nc-link" data-a="edit" data-id="' + esc(r.id) + '">bearbeiten</button>'
            + '<button type="button" class="nc-x" data-a="delete" data-id="' + esc(r.id) + '">✕</button></div>').join('')
            : '<div class="muted small">Noch keine Regeln – nimm eine Vorlage unten.</div>';
    }

    // ---------- ⚡ Makros ----------
    let mList = [], mBound = false;
    function loadMacros() {
        if (!mBound) {
            mBound = true;
            const f = $('#ncMForm');
            f.addEventListener('submit', e => { e.preventDefault(); api('client_macro_save', { id: f.id.value, name: f.name.value, script: f.script.value }).then(d => { mList = d.macros; const m = mList.find(x => x.name === f.name.value.trim()); if (m) f.id.value = m.id; renderMacros(); toast(d.msg); }).catch(fail); });
            $('#ncMTry').addEventListener('click', () => api('client_macro_run', { script: f.script.value }).then(d => toast('⚡ ' + d.msg)).catch(fail));
            $('#ncMNew').addEventListener('click', () => { f.reset(); f.id.value = ''; f.name.focus(); });
            $('#ncMStop').addEventListener('click', () => api('client_macro_stop').then(d => toast(d.msg)).catch(fail));
            $('#ncMList').addEventListener('click', e => {
                const b = e.target.closest('[data-m]');
                if (!b) return;
                const m = mList.find(x => x.id === b.dataset.id);
                if (b.dataset.m === 'run') api('client_macro_run', { id: m.id }).then(d => toast('⚡ ' + m.name + ': ' + d.msg)).catch(fail);
                if (b.dataset.m === 'edit') { f.id.value = m.id; f.name.value = m.name; f.script.value = m.script; }
                if (b.dataset.m === 'del' && confirm('Makro „' + m.name + '“ löschen?')) api('client_macro_delete', { id: m.id }).then(d => { mList = d.macros; renderMacros(); }).catch(fail);
            });
        }
        return api('client_macros').then(d => { mList = d.macros; renderMacros(); }).catch(fail);
    }
    function renderMacros() {
        $('#ncMList').innerHTML = mList.length ? mList.map(m => '<div class="nc-mrow"><button type="button" class="btn btn-sm btn-gold" data-m="run" data-id="' + esc(m.id) + '">▶</button><b>' + esc(m.name) + '</b>'
            + '<span class="muted small">' + m.script.split('\n').filter(l => l.trim() && !l.trim().startsWith('#')).length + ' Schritte</span>'
            + '<button type="button" class="nc-link" data-m="edit" data-id="' + esc(m.id) + '">bearbeiten</button><button type="button" class="nc-x" data-m="del" data-id="' + esc(m.id) + '">✕</button></div>').join('')
            : '<div class="muted small">Noch keine Makros. Makros kannst du auch aufs Stream Deck, ins Overlay und in die Automatik legen.</div>';
    }

    // ---------- 📷 Kamera (VRChat-Kamera per OSC) ----------
    let cData = null, cBound = false;
    function loadCam() { bindCam(); return api('cam_state').then(d => { cData = d; renderCam(); }).catch(fail); }
    function camCmd(cmd, value) { return api('cam_cmd', { cmd, value: value ?? '' }).then(d => { cData = d; renderCam(); toast('📷 ' + d.msg); }).catch(fail); }
    function bindCam() {
        if (cBound) return;
        cBound = true;
        const pane = $('[data-p=cam]');
        pane.addEventListener('click', e => {
            const b = e.target.closest('[data-cam]');
            if (b) return camCmd(b.dataset.cam, b.dataset.v);
            const t = e.target.closest('[data-ctog]');
            if (t) return camCmd(t.dataset.ctog, t.classList.contains('on') ? '' : '1');
            const l = e.target.closest('[data-cpload]');
            if (l) return api('cam_preset_load', { name: l.dataset.cpload }).then(d => { cData = d; renderCam(); toast('📷 ' + d.msg); }).catch(fail);
            const x = e.target.closest('[data-cpdel]');
            if (x && confirm('Vorlage „' + x.dataset.cpdel + '“ löschen?')) api('cam_preset_delete', { name: x.dataset.cpdel }).then(d => { cData = d; renderCam(); }).catch(fail);
        });
        pane.addEventListener('change', e => { const r = e.target.closest('[data-cslide]'); if (r) camCmd(r.dataset.cslide, r.value); });
        pane.addEventListener('input', e => { const r = e.target.closest('[data-cslide]'); if (r && r.nextElementSibling) r.nextElementSibling.textContent = r.value; });
        $('#ncCBurst').addEventListener('submit', e => { e.preventDefault(); const f = e.target; api('cam_burst', { count: f.count.value, seconds: f.seconds.value, delay: f.delay.value }).then(d => { cData = d; toast('📷 ' + d.msg); }).catch(fail); });
        $('#ncCStop').addEventListener('click', () => api('cam_burst_stop').then(d => toast(d.msg)).catch(fail));
        $('#ncCSave').addEventListener('submit', e => { e.preventDefault(); api('cam_preset_save', { name: e.target.name.value }).then(d => { cData = d; renderCam(); e.target.reset(); toast(d.msg); }).catch(fail); });
        $('#ncCPoseF').addEventListener('submit', e => { e.preventDefault(); camCmd('Pose', e.target.pose.value); });
    }
    function renderCam() {
        const d = cData;
        if (!d) return;
        const v = d.values || {};
        $('#ncCInfo').innerHTML = (d.osc_active ? '<span class="nc-badge ok">OSC aktiv</span>' : '⚠ Noch nichts von VRChat empfangen – OSC einschalten, sonst kommen keine Kamerawerte zurück (Senden geht trotzdem).')
            + (d.burst ? ' · 📸 Serie läuft: ' + d.burst.done + ' / ' + d.burst.count : '');
        $('#ncCModes').innerHTML = Object.entries(d.modes).map(([k, n]) => '<button type="button" class="chip' + (String(d.mode) === k ? ' on' : '') + '" data-cam="Mode" data-v="' + k + '">' + esc(n) + '</button>').join('');
        $('#ncCSliders').innerHTML = d.sliders.map(([k, n, mi, ma, st]) => '<label class="nc-cslide"><span>' + esc(n) + '</span><input type="range" min="' + mi + '" max="' + ma + '" step="' + st + '" value="' + (v[k] ?? (mi + ma) / 2) + '" data-cslide="' + k + '"><b class="small">' + (v[k] === undefined ? '–' : esc(String(Math.round(v[k] * 100) / 100))) + '</b></label>').join('');
        $('#ncCToggles').innerHTML = d.toggles.map(([k, n]) => '<button type="button" class="chip' + (v[k] === true ? ' on' : '') + '" data-ctog="' + k + '">' + esc(n) + '</button>').join('');
        $('#ncCPose').textContent = Array.isArray(d.pose) ? d.pose.map(n => Number(n).toFixed(2)).join('  ') : '– (kommt von VRChat, sobald die Kamera offen ist)';
        $('#ncCPresets').innerHTML = d.presets.length ? d.presets.map(p => '<span class="nc-preset"><button type="button" class="btn btn-sm" data-cpload="' + esc(p.name) + '">📷 ' + esc(p.name) + '</button><button type="button" class="nc-x" data-cpdel="' + esc(p.name) + '">✕</button></span>').join('')
            : '<span class="muted small">Noch keine Vorlagen.</span>';
    }

    function refresh() {
        return api('client_overview').then(d => {
            data = d;
            if (!built) build();
            renderLive(); renderOsc(); renderPresets(); renderTokens();
        }).catch(e => { root.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
    }
    function poll() {
        clearTimeout(timer);
        if (!openNow || document.hidden) return;
        timer = setTimeout(() => api('client_osc_status').then(d => { data.osc = d.osc; data.state = d.state; renderOsc(); renderLive(); if (sub === 'cam') return api('cam_state').then(c => { cData = c; renderCam(); }); if (sub === 'params' && !document.activeElement.closest('[data-p=params]')) return loadParams(); }).catch(() => {}).finally(poll), 5000);
    }

    window.NexusClient = {
        open() {
            openNow = true;
            refresh().then(() => { showSub(sub); poll(); });
        },
        close() { openNow = false; clearTimeout(timer); }
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden && openNow) poll(); });
    // Ohne verbundenes VRChat-Konto: aufklappbarer Bereich unter der Anmeldung
    const sa = document.getElementById('clientStandalone');
    if (sa) sa.addEventListener('toggle', () => { if (sa.open) window.NexusClient.open(); else window.NexusClient.close(); });
    if (!sa && document.querySelector('[data-pane=client]') && !document.querySelector('[data-pane=client]').hidden) window.NexusClient.open();
})();
