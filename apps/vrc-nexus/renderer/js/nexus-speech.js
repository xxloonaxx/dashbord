/* VRC Nexus – 🎙 Sprache → Chatbox (Whisper). Aufnahme (Mikrofon → 16 kHz WAV), Sprechpausen-Erkennung, Einstellungen. Nur Windows-App. */
(function () {
    if (document.body.classList.contains('mobile')) return;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => body.append(k, typeof v === 'boolean' ? (v ? '1' : '') : (v ?? '')));
        return fetch('nexus.php', { method: 'POST', body }).then(async r => { const d = await r.json().catch(() => ({ error: 'HTTP ' + r.status })); if (!r.ok || d.error) throw new Error(d.error || 'Fehler'); return d; });
    }
    const toast = (m, bad) => (window.NexusUI ? NexusUI.toast(m, bad) : console.log(m));

    // ---------- Aufnahme ----------
    let rec = null, st = null, pollT = null;
    async function start() {
        if (rec) return;
        const c = (st && st.cfg) || {};
        let stream;
        try { stream = await openMic(c); }
        catch (e) { toast('⚠ Kein Mikrofon: ' + e.message, true); return; }
        // whisper.cpp braucht 16 kHz – manche Geräte/Treiber lehnen das ab → Standardrate nehmen und beim Speichern umrechnen
        let ctx;
        try { ctx = new AudioContext({ sampleRate: 16000 }); } catch (e) { ctx = new AudioContext(); }
        const src = ctx.createMediaStreamSource(stream);
        const gain = ctx.createGain();
        gain.gain.value = (Number(c.gain) || 100) / 100;
        const proc = ctx.createScriptProcessor(4096, 1, 1);
        rec = { stream, ctx, src, gain, proc, chunks: [], voiced: false, quiet: 0, t0: Date.now(), level: 0 };
        proc.onaudioprocess = e => {
            if (!rec) return;
            const d = e.inputBuffer.getChannelData(0);
            rec.chunks.push(new Float32Array(d));
            let s = 0;
            for (let i = 0; i < d.length; i++) s += d[i] * d[i];
            const rms = Math.sqrt(s / d.length);
            rec.level = rms;
            const secs = d.length / ctx.sampleRate;
            if (rms > (Number(c.threshold) || 2) / 100) { rec.voiced = true; rec.quiet = 0; } else rec.quiet += secs;
            if (c.vad && rec.voiced && rec.quiet >= (Number(c.silence) || 1.2)) stop();
            else if ((Date.now() - rec.t0) / 1000 >= (Number(c.max) || 30)) stop();
            meter();
        };
        src.connect(gain);
        gain.connect(proc);
        proc.connect(ctx.destination);
        api('speech_state', { recording: '1' }).catch(() => {});
        render();
    }
    async function stop() {
        if (!rec) return;
        const r = rec;
        rec = null;
        try { r.proc.disconnect(); r.gain.disconnect(); r.src.disconnect(); r.stream.getTracks().forEach(t => t.stop()); await r.ctx.close(); } catch (e) { /* egal */ }
        api('speech_state', { recording: '', cancel: r.voiced ? '' : '1' }).catch(() => {});
        render();
        if (!r.voiced) { toast('🎙 Nichts gehört.'); return; }
        const wav = encodeWav(resample(r.chunks, r.ctx.sampleRate || 16000, 16000), 16000);
        const b64 = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(String(fr.result).split(',')[1]); fr.readAsDataURL(new Blob([wav], { type: 'audio/wav' })); });
        toast('⏳ Erkenne Sprache …');
        api('speech_transcribe', { audio: b64 }).then(d => { toast(d.msg); refresh(); }).catch(e => { toast('⚠ ' + e.message, true); refresh(); });
    }
    /** gewähltes Mikrofon öffnen – fehlt es (abgesteckt, neue ID), per Name suchen, sonst Standard */
    async function openMic(c) {
        const base = { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true };
        if (c.mic) {
            try { return await navigator.mediaDevices.getUserMedia({ audio: Object.assign({ deviceId: { exact: c.mic } }, base) }); }
            catch (e) {
                const list = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'audioinput');
                const same = c.mic_label && list.find(d => d.label === c.mic_label);
                if (same) return navigator.mediaDevices.getUserMedia({ audio: Object.assign({ deviceId: { exact: same.deviceId } }, base) });
                toast('⚠ Mikrofon „' + (c.mic_label || '?') + '“ nicht gefunden – nehme das Standard-Mikrofon.', true);
            }
        }
        return navigator.mediaDevices.getUserMedia({ audio: base });
    }
    let mics = null;
    async function loadMics() {
        try {
            let list = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'audioinput');
            if (list.length && !list.some(d => d.label)) {    // Namen gibt es erst nach einer Freigabe
                const s = await navigator.mediaDevices.getUserMedia({ audio: true });
                s.getTracks().forEach(t => t.stop());
                list = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'audioinput');
            }
            mics = list.map(d => ({ id: d.deviceId, label: d.label || 'Mikrofon ' + d.deviceId.slice(0, 6) }));
        } catch (e) { mics = []; }
        render();
    }
    if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) navigator.mediaDevices.addEventListener('devicechange', () => { if (mics) loadMics(); });
    /** Pegeltest: 4 s mithören (ohne Aufnahme), Balken + Schwelle anzeigen */
    let testing = null, testMsg = '';
    async function micTest() {
        if (testing || rec) return;
        const c = Object.assign({}, (st && st.cfg) || {}, formMic());
        let stream;
        try { stream = await openMic(c); } catch (e) { toast('⚠ ' + e.message, true); return; }
        const ctx = new AudioContext();
        const src = ctx.createMediaStreamSource(stream), g = ctx.createGain(), an = ctx.createAnalyser();
        g.gain.value = (Number(c.gain) || 100) / 100;
        src.connect(g); g.connect(an);
        const buf = new Float32Array(an.fftSize);
        testing = setInterval(() => {
            an.getFloatTimeDomainData(buf);
            let s = 0; for (const v of buf) s += v * v;
            const rms = Math.sqrt(s / buf.length), m = document.getElementById('spTest');
            if (m) { m.style.width = Math.min(100, Math.round(rms * 600)) + '%'; m.classList.toggle('hit', rms > (Number(c.threshold) || 2) / 100); }
        }, 60);
        testMsg = '🎙 sprich jetzt … (' + ((stream.getAudioTracks()[0] || {}).label || 'Mikrofon') + ')';
        const lab = document.getElementById('spTestLbl');
        if (lab) lab.textContent = testMsg;
        setTimeout(() => { clearInterval(testing); testing = null; stream.getTracks().forEach(t => t.stop()); ctx.close(); testMsg = 'Test beendet – grün = wird als Sprache erkannt.'; const l = document.getElementById('spTestLbl'); if (l) l.textContent = testMsg; }, 4000);
    }
    function formMic() {
        const f = document.getElementById('spCfg');
        if (!f) return {};
        const sel = f.mic;
        return { mic: sel ? sel.value : '', mic_label: sel && sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.label || '' : '', gain: f.gain ? f.gain.value : 100, threshold: f.threshold ? f.threshold.value : 2 };
    }

    /** auf 16 kHz umrechnen (lineare Interpolation) – nur nötig, wenn das Audiosystem eine andere Rate liefert */
    function resample(chunks, from, to) {
        if (!from || from === to) return chunks;
        const n = chunks.reduce((a, c) => a + c.length, 0);
        const all = new Float32Array(n);
        let o = 0;
        for (const c of chunks) { all.set(c, o); o += c.length; }
        const out = new Float32Array(Math.floor(n * to / from));
        const k = from / to;
        for (let i = 0; i < out.length; i++) { const x = i * k, j = Math.floor(x), f = x - j; out[i] = all[j] * (1 - f) + (all[Math.min(j + 1, n - 1)] || 0) * f; }
        return [out];
    }
    window.NexusSpeechResample = resample;     // für Tests
    function encodeWav(chunks, rate) {
        const n = chunks.reduce((a, c) => a + c.length, 0);
        const buf = new ArrayBuffer(44 + n * 2), v = new DataView(buf);
        const w = (o, s) => { for (let i = 0; i < s.length; i++) v.setUint8(o + i, s.charCodeAt(i)); };
        w(0, 'RIFF'); v.setUint32(4, 36 + n * 2, true); w(8, 'WAVE'); w(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
        v.setUint32(24, rate, true); v.setUint32(28, rate * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true); w(36, 'data'); v.setUint32(40, n * 2, true);
        let o = 44;
        for (const c of chunks) for (let i = 0; i < c.length; i++, o += 2) { const s = Math.max(-1, Math.min(1, c[i])); v.setInt16(o, s < 0 ? s * 0x8000 : s * 0x7fff, true); }
        return buf;
    }
    function cmd(op) { if (op === 'start') start(); else if (op === 'stop') stop(); else if (rec) stop(); else start(); }

    // ---------- Befehle abholen (Stream Deck, Overlay, Tastenkürzel ohne direkten Weg) ----------
    function poll() {
        clearTimeout(pollT);
        api('speech_status').then(d => {
            st = d;
            if (d.pending && ((d.pending === 'start') !== !!rec)) cmd(d.pending);
            if (box() && !box().hidden) render();
        }).catch(() => {}).finally(() => { pollT = setTimeout(poll, st && st.cfg.enabled ? 1000 : 10000); });
    }
    function refresh() { return api('speech_status').then(d => { st = d; render(); }).catch(() => {}); }

    // ---------- Oberfläche (Spiel & OSC → 🎙 Sprache) ----------
    const box = () => document.getElementById('ncSpeech');
    function meter() { const m = document.getElementById('spMeter'); if (m && rec) m.style.width = Math.min(100, Math.round(rec.level * 600)) + '%'; }
    function render() {
        const b = box();
        if (!b || !st) return;
        if (b.contains(document.activeElement) && document.activeElement.matches('input, select')) { const btn = document.getElementById('spRec'); if (btn) btn.textContent = rec ? '⏹ Stopp' : '🎙 Sprechen'; return; }
        const c = st.cfg, dl = st.download;
        const opt = (o, cur) => Object.entries(o).map(([k, v]) => '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + esc(Array.isArray(v) ? v[0] + ' (' + v[1] + ' MB)' : v) + '</option>').join('');
        b.innerHTML = '<div class="nc-grid">'
            + '<div class="nc-card"><h4>🎙 Sprechen</h4>'
            + '<div class="nc-row"><button type="button" class="btn ' + (rec ? 'btn-gold' : '') + '" id="spRec"' + (st.ready && c.enabled ? '' : ' disabled') + '>' + (rec ? '⏹ Stopp' : '🎙 Sprechen') + '</button>'
            + '<div class="sp-meter"><i id="spMeter"></i></div></div>'
            + '<p class="small">' + (!c.enabled ? '⚠ Erst unten einschalten.' : !st.ready ? '⚠ ' + (c.engine === 'openai' ? 'OpenAI-Schlüssel fehlt.' : 'whisper.cpp oder Modell fehlt.') : '✔ Bereit · ' + esc(st.engine_label) + (c.hotkey ? ' · Taste <b>' + esc(c.hotkey) + '</b>' : '')) + (st.busy ? ' · ⏳ erkenne …' : '') + '</p>'
            + (st.last ? '<div class="hm-pre">„' + esc(st.last) + '“</div>' : '') + (st.error ? '<p class="small bad">⚠ ' + esc(st.error) + '</p>' : '')
            + '<p class="muted small">Auch per Stream-Deck-Taste „Spracheingabe“, im SteamVR-Overlay und auf der Startseite.</p></div>'
            + '<form class="nc-card" id="spCfg"><h4>⚙ Einstellungen</h4>'
            + '<label class="check"><input type="checkbox" name="enabled"' + (c.enabled ? ' checked' : '') + '> Spracheingabe an</label>'
            + '<div class="nc-row"><span class="small">Erkennung</span><select name="engine"><option value="local"' + (c.engine === 'local' ? ' selected' : '') + '>Lokal – whisper.cpp (offline, kostenlos)</option><option value="openai"' + (c.engine === 'openai' ? ' selected' : '') + '>OpenAI Whisper (eigener Schlüssel)</option></select></div>'
            + '<div class="nc-row"><span class="small">Modell</span><select name="model">' + opt(st.models, c.model) + '</select><span class="small">Sprache</span><select name="lang">' + opt(st.langs, c.lang) + '</select></div>'
            + '<div class="nc-row"><span class="small">🎤 Mikrofon</span>' + (mics ? '<select name="mic" style="flex:1"><option value="" data-label="">Windows-Standard</option>' + mics.map(m => '<option value="' + esc(m.id) + '" data-label="' + esc(m.label) + '"' + (m.id === c.mic || (!mics.some(x => x.id === c.mic) && m.label === c.mic_label) ? ' selected' : '') + '>' + esc(m.label) + '</option>').join('') + '</select>' : '<button type="button" class="btn btn-sm" id="spMics">Mikrofone anzeigen</button>' + (c.mic_label ? ' <span class="small">gewählt: ' + esc(c.mic_label) + '</span>' : '')) + '</div>'
            + '<div class="nc-row"><span class="small">Verstärkung</span><input type="range" name="gain" min="25" max="400" step="5" value="' + (c.gain || 100) + '" style="flex:1" oninput="this.nextElementSibling.textContent=this.value+\' %\'"><span class="small">' + (c.gain || 100) + ' %</span>'
            + '<span class="small">Empfindlichkeit</span><input type="range" name="threshold" min="1" max="20" value="' + (c.threshold || 2) + '" style="width:110px" title="niedriger = reagiert schon auf leise Sprache"></div>'
            + '<div class="nc-row"><button type="button" class="btn btn-sm" id="spTestBtn">🔊 Mikro testen</button><div class="sp-meter"><i id="spTest"></i></div><span class="small" id="spTestLbl">' + esc(testMsg) + '</span></div>'
            + '<div class="nc-row"><span class="small">Tastenkürzel</span><input type="text" name="hotkey" value="' + esc(c.hotkey) + '" placeholder="z. B. Alt+V" style="width:110px"><span class="small">Vorsatz</span><input type="text" name="prefix" value="' + esc(c.prefix) + '" style="width:60px"></div>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="vad"' + (c.vad ? ' checked' : '') + '> automatisch stoppen nach</label><input type="number" name="silence" value="' + c.silence + '" min="0.4" max="5" step="0.1" style="width:60px"><span class="small">s Stille · max.</span><input type="number" name="max" value="' + c.max + '" min="3" max="120" style="width:60px"><span class="small">s</span></div>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="typing"' + (c.typing ? ' checked' : '') + '> „tippt …“ beim Sprechen</label><label class="check small"><input type="checkbox" name="sound"' + (c.sound ? ' checked' : '') + '> Ton</label></div>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="translate"' + (c.translate ? ' checked' : '') + '> übersetzen nach</label><input type="text" name="target" value="' + esc(c.target) + '" style="width:70px" title="z. B. EN-US, JA, FR">'
            + '<label class="check small"><input type="checkbox" name="whisper_en"' + (c.whisper_en ? ' checked' : '') + '> Whisper übersetzt selbst ins Englische</label></div>'
            + '<div class="nc-row"><span class="small">Threads</span><input type="number" name="threads" value="' + c.threads + '" min="1" max="32" style="width:60px"><span class="small">eigene whisper-cli.exe</span><input type="text" name="bin" value="' + esc(c.bin) + '" placeholder="leer = automatisch" style="flex:1"></div>'
            + '<button class="btn btn-sm btn-gold">💾 Speichern</button></form>'
            + '<div class="nc-card"><h4>📦 Dateien</h4>'
            + (dl ? '<p>⏬ Lade ' + esc(dl.what) + ' … ' + (dl.total ? Math.round(dl.got / dl.total * 100) + ' % (' + Math.round(dl.got / 1048576) + ' / ' + Math.round(dl.total / 1048576) + ' MB)' : Math.round(dl.got / 1048576) + ' MB') + '</p>' : '')
            + '<div class="nc-row"><b class="small">whisper.cpp:</b> <span class="small mono">' + (st.bin ? esc(st.bin) : 'nicht installiert') + '</span>' + (st.windows ? '<button type="button" class="btn btn-sm" data-sp="bin">' + (st.bin ? '↻ neu laden' : '⬇ Programm laden') + '</button>' : '') + '</div>'
            + Object.entries(st.models).map(([k, v]) => '<div class="nc-row"><span class="small">' + esc(v[0]) + ' · ' + v[1] + ' MB</span>' + (st.have[k] ? '<span class="nc-badge ok">vorhanden</span><button type="button" class="nc-x" data-spdel="' + k + '" title="löschen">✕</button>' : '<button type="button" class="btn btn-sm" data-sp="' + k + '">⬇ laden</button>') + '</div>').join('')
            + '<p class="muted small">Tiny/Base sind schnell, Small/Medium genauer (brauchen mehr CPU). Modelle von huggingface.co/ggerganov/whisper.cpp, Programm von github.com/ggml-org/whisper.cpp.</p>'
            + '<h4 style="margin-top:10px">🔑 OpenAI-Schlüssel</h4><form class="nc-row" id="spKey"><input type="password" name="key" placeholder="' + (st.has_key ? '•••••• gespeichert' : 'sk-…') + '" style="flex:1" autocomplete="off"><button class="btn btn-sm">Speichern</button>' + (st.has_key ? '<button type="button" class="btn btn-sm" id="spKeyDel">Entfernen</button>' : '') + '</form></div>'
            + '</div>';
    }
    document.addEventListener('click', e => {
        if (!box() || !box().contains(e.target)) return;
        if (e.target.closest('#spRec')) return cmd('toggle');
        if (e.target.closest('#spMics')) return loadMics();
        if (e.target.closest('#spTestBtn')) return micTest();
        const d = e.target.closest('[data-sp]');
        if (d) return api('speech_install', { what: d.dataset.sp }).then(x => { st = x; toast(x.msg); render(); }).catch(err => toast('⚠ ' + err.message, true));
        const x = e.target.closest('[data-spdel]');
        if (x && confirm('Modell löschen?')) return api('speech_model_delete', { model: x.dataset.spdel }).then(s => { st = s; render(); }).catch(err => toast('⚠ ' + err.message, true));
        if (e.target.closest('#spKeyDel')) api('speech_key', { key: '' }).then(s => { st = s; toast(s.msg); render(); }).catch(err => toast('⚠ ' + err.message, true));
    });
    document.addEventListener('submit', e => {
        const f = e.target;
        if (f.id === 'spCfg') {
            e.preventDefault();
            const data = {};
            for (const el of f.elements) if (el.name) data[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
            if (f.mic) data.mic_label = f.mic.selectedOptions[0] ? f.mic.selectedOptions[0].dataset.label || '' : '';
            api('speech_cfg', data).then(s => { st = s; toast(s.msg); document.activeElement.blur(); render(); poll(); }).catch(err => toast('⚠ ' + err.message, true));
        }
        if (f.id === 'spKey') { e.preventDefault(); api('speech_key', { key: f.key.value }).then(s => { st = s; toast(s.msg); render(); }).catch(err => toast('⚠ ' + err.message, true)); }
    });

    window.NexusSpeech = { cmd, start, stop, open() { refresh(); if (!mics) loadMics(); }, get recording() { return !!rec; } };
    poll();
})();
