/* VRC Nexus – 🔊 Sprachausgabe (TTS): Wiedergabe auf dem gewählten Ausgabegerät (z. B. virtuelles Kabel → VRChat-Mikrofon) + Einstellungen */
(function () {
    if (document.body.classList.contains('mobile')) return;
    const UI = window.NexusUI;
    if (!UI) return;
    const esc = UI.esc, api = (a, d) => UI.api(a, d), toast = (m, bad) => UI.toast(m, bad);
    let st = null, seq = null, pollT = null, devices = null, voices = {}, playing = Promise.resolve(), box = null;

    // ---------- Abspielen (nacheinander, auf dem gewählten Gerät; optional zum Mithören auch auf dem Standard-Gerät) ----------
    function playOne(item) {
        const c = (st && st.cfg) || {};
        const src = 'data:' + item.mime + ';base64,' + item.b64;
        const vol = Math.max(0, Math.min(1, (Number(c.volume) || 100) / 100));
        const mk = async sink => {
            const a = new Audio(src);
            a.volume = vol;
            if (sink && a.setSinkId) { try { await a.setSinkId(sink); } catch (e) { toast('⚠ Ausgabegerät nicht verfügbar – spiele auf dem Standard-Gerät.', true); } }
            return a;
        };
        return (async () => {
            const main = await mk(await deviceId(c));
            const list = [main];
            if (c.monitor && c.device) list.push(await mk(''));
            await Promise.all(list.map(a => new Promise(res => { a.onended = res; a.onerror = res; a.play().catch(res); })));
        })();
    }
    /** Gerät per ID, sonst per Name wiederfinden (IDs ändern sich manchmal) */
    async function deviceId(c) {
        if (!c.device) return '';
        const list = await outputs();
        if (list.some(d => d.id === c.device)) return c.device;
        const same = c.device_label && list.find(d => d.label === c.device_label);
        return same ? same.id : '';
    }
    async function outputs() {
        try {
            let l = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'audiooutput');
            if (l.length && !l.some(d => d.label)) { const s = await navigator.mediaDevices.getUserMedia({ audio: true }); s.getTracks().forEach(t => t.stop()); l = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'audiooutput'); }
            return l.map(d => ({ id: d.deviceId, label: d.label || 'Gerät ' + d.deviceId.slice(0, 6) }));
        } catch (e) { return []; }
    }
    function poll() {
        clearTimeout(pollT);
        api('tts_poll', { since: seq === null ? 1e12 : seq }).then(d => {
            if (seq === null) seq = d.seq;                       // beim Start nichts Altes nachspielen
            for (const it of d.items) { seq = Math.max(seq, it.id); playing = playing.then(() => playOne(it)).catch(() => {}); }
        }).catch(() => {}).finally(() => { pollT = setTimeout(poll, st && st.cfg.enabled ? 700 : 5000); });
    }

    // ---------- Oberfläche (Spiel & OSC → 🔊 Sprachausgabe) ----------
    function render() {
        if (!box || !st) return;
        if (box.contains(document.activeElement) && document.activeElement.matches('input:not([type=checkbox]):not([type=range]), textarea')) return;
        const c = st.cfg, P = st.providers, p = c.provider, dl = st.download;
        const vl = voices[p];
        const cur = c.voice || { piper: 'de_DE-thorsten-medium', openai: 'nova', azure: 'de-DE-KatjaNeural', google: 'de-DE-Neural2-B' }[p] || '';
        const keyed = P[p][2];
        box.innerHTML = '<div class="nc-grid">'
            + '<div class="nc-card"><h4>🔊 Sprechen</h4>'
            + '<form class="nc-row" id="ttsSay"><textarea name="text" rows="3" maxlength="1000" placeholder="Text, der in VRChat gesprochen wird …" style="flex:1"></textarea></form>'
            + '<div class="nc-row"><button type="button" class="btn btn-gold" id="ttsGo"' + (c.enabled ? '' : ' disabled') + '>🔊 Sprechen</button><label class="check small"><input type="checkbox" id="ttsChat"> auch in die Chatbox</label></div>'
            + '<p class="small">' + (!c.enabled ? '⚠ Erst unten einschalten.' : '✔ ' + esc(P[p][0]) + (c.voice ? ' · ' + esc((vl || []).find(v => v.id === c.voice) ? vl.find(v => v.id === c.voice).name : c.voice) : '') + ' → ' + esc(c.device_label || 'Standard-Ausgabe')) + '</p>'
            + (st.error ? '<p class="small bad">⚠ ' + esc(st.error) + '</p>' : '')
            + '<div class="tts-help"><b>🎧 So hört VRChat dich:</b><ol><li>Ein virtuelles Audiokabel installieren, z. B. <b>VB-Audio Virtual Cable</b> (kostenlos) oder Voicemeeter.</li><li>Hier unten als Ausgabe <b>„CABLE Input“</b> wählen.</li><li>In VRChat als Mikrofon <b>„CABLE Output“</b> einstellen.</li></ol><span class="muted small">Tipp: „mithören“ einschalten, um die Stimme selbst zu hören. Für Mikro + TTS gleichzeitig: Voicemeeter (mischt beides).</span></div></div>'
            + '<form class="nc-card" id="ttsCfg"><h4>⚙ Einstellungen</h4>'
            + '<label class="check"><input type="checkbox" name="enabled"' + (c.enabled ? ' checked' : '') + '> Sprachausgabe an</label>'
            + '<div class="tts-prov">' + Object.entries(P).map(([k, v]) => '<button type="button" data-prov="' + k + '" class="' + (k === p ? 'on' : '') + '"><b>' + esc(v[0]) + '</b><small>' + esc(v[1]) + '</small>' + (v[2] ? '<em>' + (st.has[k] ? '🔑 Schlüssel da' : 'Schlüssel nötig') + '</em>' : '<em>offline</em>') + '</button>').join('') + '</div>'
            + (keyed ? '<div class="nc-row"><span class="small">🔑 Schlüssel</span><input type="password" id="ttsKey" placeholder="' + (st.own_key[p] ? '•••••• gespeichert' : st.has[p] ? 'nutzt den vorhandenen Schlüssel (Übersetzer/Spracheingabe)' : 'API-Schlüssel einfügen') + '" style="flex:1" autocomplete="off"><button type="button" class="btn btn-sm" id="ttsKeySave">Speichern</button>' + (st.own_key[p] ? '<button type="button" class="btn btn-sm" id="ttsKeyDel">Entfernen</button>' : '') + '</div>' : '')
            + (p === 'azure' ? '<div class="nc-row"><span class="small">Region</span><input type="text" name="azure_region" value="' + esc(c.azure_region) + '" style="width:140px" placeholder="westeurope"></div>' : '')
            + (p === 'elevenlabs' ? '<div class="nc-row"><span class="small">Modell</span><input type="text" name="el_model" value="' + esc(c.el_model) + '" style="width:220px"></div>' : '')
            + (p === 'openai' ? '<div class="nc-row"><span class="small">Modell</span><input type="text" name="oa_model" value="' + esc(c.oa_model) + '" style="width:220px"></div>' : '')
            + '<div class="nc-row"><span class="small">Stimme</span>' + (vl ? '<select name="voice" style="flex:1">' + (vl.length ? '' : '<option value="">– keine Stimmen –</option>') + vl.map(v => '<option value="' + esc(v.id) + '"' + (v.id === cur ? ' selected' : '') + '>' + esc((v.lang ? v.lang + ' · ' : '') + v.name) + '</option>').join('') + '</select>' : '<span class="small muted">lade Stimmen …</span>') + '<button type="button" class="btn btn-sm" id="ttsVoices" title="neu laden">↻</button></div>'
            + '<div class="nc-row"><span class="small">Tempo</span><input type="range" name="rate" min="0.5" max="2" step="0.05" value="' + c.rate + '" style="flex:1" oninput="this.nextElementSibling.textContent=Number(this.value).toFixed(2)+\'×\'"><span class="small">' + Number(c.rate).toFixed(2) + '×</span>'
            + '<span class="small">Lautstärke</span><input type="range" name="volume" min="0" max="100" value="' + Math.min(100, c.volume) + '" style="width:110px"></div>'
            + '<div class="nc-row"><span class="small">🎧 Ausgabe</span>' + (devices ? '<select name="device" style="flex:1"><option value="" data-label="">Standard-Ausgabe</option>' + devices.map(d => '<option value="' + esc(d.id) + '" data-label="' + esc(d.label) + '"' + (d.id === c.device || (!devices.some(x => x.id === c.device) && d.label === c.device_label) ? ' selected' : '') + '>' + esc(d.label) + (/cable|voicemeeter|virtual/i.test(d.label) ? ' 🎙' : '') + '</option>').join('') + '</select>' : '<button type="button" class="btn btn-sm" id="ttsDev">Geräte anzeigen</button>' + (c.device_label ? ' <span class="small">gewählt: ' + esc(c.device_label) + '</span>' : '')) + '</div>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="monitor"' + (c.monitor ? ' checked' : '') + '> mithören (zusätzlich auf dem Standard-Gerät)</label></div>'
            + '<h4 style="margin-top:8px">Wann sprechen?</h4>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="on_speech"' + (c.on_speech ? ' checked' : '') + '> nach der Spracheingabe</label><select name="speak"><option value="sent"' + (c.speak === 'sent' ? ' selected' : '') + '>Übersetzung (bzw. Erkanntes)</option><option value="text"' + (c.speak === 'text' ? ' selected' : '') + '>nur das Original</option><option value="both"' + (c.speak === 'both' ? ' selected' : '') + '>Original + Übersetzung</option></select></div>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="on_chat"' + (c.on_chat ? ' checked' : '') + '> getippte Chatbox-Nachrichten</label><span class="muted small">· Makro-Schritt <code>speak Text</code> nutzt ebenfalls diese Stimme</span></div>'
            + '<button class="btn btn-sm btn-gold">💾 Speichern</button></form>'
            + (p === 'piper' ? '<div class="nc-card"><h4>🐦 Piper – Dateien</h4>'
                + (dl ? '<p>⏬ Lade ' + esc(dl.what) + ' … ' + (dl.total ? Math.round(dl.got / dl.total * 100) + ' %' : Math.round(dl.got / 1048576) + ' MB') + '</p>' : '')
                + '<div class="nc-row"><b class="small">Programm:</b> <span class="small mono">' + (st.piper_bin ? esc(st.piper_bin) : 'nicht installiert') + '</span>' + (st.windows ? '<button type="button" class="btn btn-sm" data-tin="bin">' + (st.piper_bin ? '↻ neu laden' : '⬇ Piper laden') + '</button>' : '') + '</div>'
                + Object.entries(st.piper_voices).map(([k, v]) => '<div class="nc-row"><span class="small">' + esc(v[0]) + ' · ' + v[1] + ' MB</span>' + (st.piper_have[k] ? '<span class="nc-badge ok">vorhanden</span><button type="button" class="nc-x" data-tdel="' + k + '" title="löschen">✕</button>' : '<button type="button" class="btn btn-sm" data-tin="' + k + '">⬇ laden</button>') + '</div>').join('')
                + '<p class="muted small">Stimmen von huggingface.co/rhasspy/piper-voices, Programm von github.com/rhasspy/piper. Eigene Stimme: Pfad zu einer .onnx-Datei als Stimme eintragen.</p>'
                + '<div class="nc-row"><span class="small">eigene piper.exe</span><input type="text" name="piper_bin" form="ttsCfg" value="' + esc(c.piper_bin) + '" placeholder="leer = automatisch" style="flex:1"></div></div>' : '')
            + '</div>';
    }
    function loadVoices(p, force) {
        if (voices[p] && !force) return;
        voices[p] = null;
        api('tts_voices', { provider: p }).then(d => { voices[p] = d.voices; render(); }).catch(e => { voices[p] = []; toast('⚠ ' + e.message, true); render(); });
    }
    function refresh() { return api('tts_status').then(d => { st = d; render(); loadVoices(d.cfg.provider); }).catch(() => {}); }
    function bind() {
        box.addEventListener('click', e => {
            const b = e.target.closest('button'); if (!b) return;
            if (b.id === 'ttsGo') {
                const t = box.querySelector('#ttsSay [name=text]').value.trim();
                if (!t) return toast('Erst einen Text eingeben.', true);
                b.disabled = true;
                return api('tts_say', { text: t, chatbox: box.querySelector('#ttsChat').checked ? '1' : '' }).then(() => { poll(); }).catch(er => toast('⚠ ' + er.message, true)).finally(() => { b.disabled = false; });
            }
            if (b.dataset.prov) return api('tts_cfg', { provider: b.dataset.prov }).then(d => { st = d; render(); loadVoices(d.cfg.provider); }).catch(er => toast('⚠ ' + er.message, true));
            if (b.id === 'ttsVoices') return loadVoices(st.cfg.provider, true);
            if (b.id === 'ttsDev') return outputs().then(l => { devices = l; render(); });
            if (b.id === 'ttsKeySave') return api('tts_key', { provider: st.cfg.provider, key: box.querySelector('#ttsKey').value }).then(d => { st = d; toast(d.msg); loadVoices(d.cfg.provider, true); render(); }).catch(er => toast('⚠ ' + er.message, true));
            if (b.id === 'ttsKeyDel') return api('tts_key', { provider: st.cfg.provider, key: '' }).then(d => { st = d; toast(d.msg); render(); });
            if (b.dataset.tin) return api('tts_install', { what: b.dataset.tin }).then(d => { st = d; toast(d.msg); render(); watchDl(); }).catch(er => toast('⚠ ' + er.message, true));
            if (b.dataset.tdel && confirm('Stimme löschen?')) return api('tts_voice_delete', { voice: b.dataset.tdel }).then(d => { st = d; render(); });
        });
        box.addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target;
            if (f.id !== 'ttsCfg') return;
            const data = {};
            for (const el of f.elements) if (el.name) data[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
            for (const el of box.querySelectorAll('[form=ttsCfg]')) data[el.name] = el.value;
            if (f.device) data.device_label = f.device.selectedOptions[0] ? f.device.selectedOptions[0].dataset.label || '' : '';
            api('tts_cfg', data).then(d => { st = d; toast(d.msg); document.activeElement.blur(); render(); poll(); }).catch(er => toast('⚠ ' + er.message, true));
        });
    }
    function watchDl() { setTimeout(() => api('tts_status').then(d => { st = d; render(); if (d.download) watchDl(); else loadVoices('piper', true); }), 1000); }
    window.NexusTts = {
        open(el) { if (box !== el) { box = el; bind(); } if (!st) box.innerHTML = '<div class="muted">Lade …</div>'; refresh(); if (!devices) outputs().then(l => { devices = l; render(); }); },
        say: text => api('tts_say', { text }).then(() => poll()),
    };
    api('tts_status').then(d => { st = d; }).catch(() => {}).finally(poll);
})();
