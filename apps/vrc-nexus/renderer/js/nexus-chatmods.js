/* VRC Nexus – 🧩 Chatbox-Module (Spiel & OSC → Chatbox-Module): Bausteine an/aus, Vorlage, Desktop/VR, Reihenfolge, Live-Vorschau */
(function () {
    const UI = window.NexusUI;
    if (!UI) return;
    const esc = UI.esc, api = (a, d) => UI.api(a, d), toast = (m, bad) => UI.toast(m, bad);
    // Platzhalter je Modul (für die Hilfe-Chips unter der Vorlage)
    const PH = {
        status: ['status_text', 'afk_text'], window: ['fenster', 'fenster_prozess', 'fenster_titel', 'modus'], media: ['musik'], vrchat: ['welt', 'spieler', 'avatar', 'dauer'],
        time: ['zeit', 'zeit_s', 'zone', 'zeitzone', 'datum', 'wochentag'], weather: ['wetter_symbol', 'wetter', 'temp', 'temp_gefuehlt', 'wind', 'luftfeuchte', 'ort'],
        system: ['cpu', 'gpu', 'gpu_temp', 'gpu_watt', 'ram', 'ram_pct', 'vram', 'gpu_name'], network: ['netz_down', 'netz_up', 'netz_spitze_down', 'netz_spitze_up', 'netz_summe_down', 'netz_summe_up'],
        twitch: ['twitch_zuschauer', 'twitch_spiel', 'twitch_dauer', 'twitch_kanal'], heart: ['puls'],
    };
    const WHERE = [['both', 'Desktop + VR'], ['desktop', 'nur Desktop'], ['vr', 'nur VR']];
    let box = null, d = null, openMod = '', timer = null;

    function render() {
        const c = d.cfg;
        const pv = d.preview;
        box.innerHTML = '<div class="cm-head">'
            + '<label class="cm-master"><span class="switch"><input type="checkbox" id="cmOn"' + (c.on ? ' checked' : '') + '><i></i></span><b>Chatbox-Module senden</b></label>'
            + '<label>alle <input type="number" id="cmSec" min="3" max="600" value="' + c.seconds + '"> s</label>'
            + '<label>Trennung <select id="cmSep">' + [['line', 'neue Zeile'], ['dot', ' · '], ['bar', ' | '], ['space', 'Leerzeichen']].map(([k, l]) => '<option value="' + k + '"' + (c.sep === k ? ' selected' : '') + '>' + l + '</option>').join('') + '</select></label>'
            + '<label><input type="checkbox" id="cmGame"' + (c.only_game ? ' checked' : '') + '> nur wenn VRChat läuft</label>'
            + '<button type="button" class="btn btn-sm btn-gold" id="cmSend">📨 Jetzt senden</button>'
            + '<span class="cm-mode">' + (d.vr ? '🥽 VR erkannt' : '🖥 Desktop') + '</span></div>'
            + (c.on && d.rotator_on ? '<div class="muted small">ℹ Der Chatbox-Rotator pausiert, solange die Module senden – der Text vom Rotator kommt über das Modul „Persönlicher Status“.</div>' : '')
            + '<div class="cm-preview"><div class="cm-bubble" id="cmPrev">' + (pv.text ? esc(pv.text).replace(/\n/g, '<br>') : '<span class="muted">– noch nichts eingeschaltet –</span>') + '</div>'
            + '<div class="cm-count' + (pv.length > 144 ? ' over' : '') + '" id="cmCount">' + Math.min(pv.length, 144) + ' / 144' + (pv.dropped && pv.dropped.length ? ' · ⚠ zu lang – weggelassen: ' + pv.dropped.map(k => esc(d.modules[k][1])).join(', ') : '') + '</div></div>'
            + '<div class="cm-list">' + c.order.map((k, i) => row(k, i)).join('') + '</div>';
    }
    function row(k, i) {
        const m = d.modules[k], s = d.cfg.mods[k], open = openMod === k;
        const note = { weather: d.cfg.place ? '📍 ' + d.cfg.place : 'Ort fehlt', twitch: d.cfg.twitch || d.twitch_default ? '📺 ' + (d.cfg.twitch || d.twitch_default) : 'Kanal fehlt', heart: d.has_hr ? (d.hr_error ? '⚠ ' + d.hr_error : 'verbunden') : 'kein Token', system: d.gpu ? '' : 'keine NVIDIA-GPU erkannt', window: d.windows ? '' : 'nur unter Windows' }[k] || '';
        return '<section class="cm-row' + (s.on ? ' on' : '') + '" data-k="' + k + '"><span class="cm-ic">' + m[0] + '</span>'
            + '<div class="cm-txt"><b>' + esc(m[1]) + '</b>' + (note ? ' <small class="cm-note">' + esc(note) + '</small>' : '') + '<p>' + esc(m[2]) + '</p>'
            + (d.preview.per[k] ? '<code class="cm-val">' + esc(d.preview.per[k]).replace(/\n/g, ' ⏎ ') + '</code>' : '') + '</div>'
            + '<div class="cm-ctl"><span class="cm-where">' + WHERE.map(([w, l]) => '<button type="button" data-where="' + w + '" class="' + (s.where === w ? 'on' : '') + '">' + l + '</button>').join('') + '</span>'
            + '<span class="cm-btns"><button type="button" data-mv="-1" title="nach oben"' + (i ? '' : ' disabled') + '>▲</button><button type="button" data-mv="1" title="nach unten"' + (i < d.cfg.order.length - 1 ? '' : ' disabled') + '>▼</button>'
            + '<button type="button" class="cm-open" data-open="1">' + (open ? 'Schließen ▴' : 'Anpassen ›') + '</button></span></div>'
            + '<label class="switch cm-sw"><input type="checkbox" data-on="1"' + (s.on ? ' checked' : '') + '><i></i></label>'
            + (open ? edit(k, s) : '') + '</section>';
    }
    function edit(k, s) {
        const v = d.values, c = d.cfg;
        let extra = '';
        if (k === 'weather') extra = '<form class="cm-f" data-f="place"><label>Ort</label><input name="city" value="' + esc(c.city) + '" placeholder="z. B. Leipzig"><button class="btn btn-sm">📍 Suchen</button></form>';
        if (k === 'window') extra = '<form class="cm-f" data-f="private"><label>Privat (nur Name zeigen)</label><input name="private" value="' + esc(c.private) + '" placeholder="discord, chrome, …"><button class="btn btn-sm">Speichern</button></form>';
        if (k === 'twitch') extra = '<form class="cm-f" data-f="twitch"><label>Kanal</label><input name="twitch" value="' + esc(c.twitch) + '" placeholder="' + esc(d.twitch_default || 'deinkanal') + '"><button class="btn btn-sm">Speichern</button></form><div class="muted small">Ohne Eintrag wird der Kanal vom Stream-Bot genommen. Ist der Kanal offline, fällt das Modul weg.</div>';
        if (k === 'heart') extra = '<form class="cm-f" data-f="hr"><label>Pulsoid-Token</label><input name="token" type="password" placeholder="' + (d.has_hr ? '•••••• gespeichert – neu eingeben zum Ändern' : 'Token von pulsoid.net → Integrationen') + '" autocomplete="off"><button class="btn btn-sm">Speichern</button></form>'
            + '<form class="cm-f" data-f="hrosc"><label>Avatar-Parameter</label><input name="hr_param" value="' + esc(c.hr_param) + '"><label class="cm-inl"><input type="checkbox" name="hr_osc"' + (c.hr_osc ? ' checked' : '') + '> senden (Int + Float)</label><button class="btn btn-sm">Speichern</button></form>';
        if (k === 'status') extra = '<div class="muted small">Die Texte kommen aus dem Chatbox-Rotator (Spiel & OSC → Live & OSC → Rotator). Bei AFK erscheint automatisch „💤 AFK seit … min“.</div>';
        return '<div class="cm-edit"><form class="cm-f" data-f="tpl"><label>Vorlage</label><input name="tpl" value="' + esc(s.tpl) + '" maxlength="200"><button class="btn btn-sm">Speichern</button><button type="button" class="nc-link" data-reset="1">Standard</button></form>'
            + '<div class="cm-ph">' + (PH[k] || []).map(p => '<button type="button" class="chip" data-ph="' + p + '" title="einfügen">{' + p + '}' + (v[p] !== undefined && v[p] !== '' ? ' <small>= ' + esc(String(v[p]).slice(0, 24)) + '</small>' : '') + '</button>').join('') + '</div>' + extra + '</div>';
    }
    function save(data, quiet) {
        return api('cm_save', Object.assign({ quiet: quiet ? '1' : '' }, data)).then(r => { if (r.msg) toast(r.msg); return load(); }).catch(e => toast('⚠ ' + e.message, true));
    }
    function load(refresh) {
        return api('cm_get', { refresh: refresh ? '1' : '' }).then(r => {
            d = r;
            const a = document.activeElement;
            if (a && box.contains(a) && a.matches('input[type=text], input:not([type]), input[type=password], input[type=number]')) { livePreview(); return; }   // beim Tippen nicht neu zeichnen
            render();
        }).catch(e => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
    }
    function livePreview() {
        const p = box.querySelector('#cmPrev'); if (p) p.innerHTML = d.preview.text ? esc(d.preview.text).replace(/\n/g, '<br>') : '';
    }
    function bind() {
        box.addEventListener('change', e => {
            const t = e.target;
            if (t.id === 'cmOn') return save({ on: t.checked ? '1' : '0' });
            if (t.id === 'cmSec') return save({ seconds: t.value }, true);
            if (t.id === 'cmSep') return save({ sep: t.value }, true);
            if (t.id === 'cmGame') return save({ only_game: t.checked ? '1' : '0' }, true);
            if (t.dataset.on) return save({ mod: t.closest('[data-k]').dataset.k, mod_on: t.checked ? '1' : '0' }, true);
        });
        box.addEventListener('click', e => {
            const b = e.target.closest('button'); if (!b) return;
            const r = b.closest('[data-k]'), k = r && r.dataset.k;
            if (b.id === 'cmSend') return api('cm_send').then(x => toast(x.msg)).catch(er => toast('⚠ ' + er.message, true));
            if (b.dataset.where) return save({ mod: k, where: b.dataset.where }, true);
            if (b.dataset.mv) { const o = d.cfg.order.slice(), i = o.indexOf(k), j = i + Number(b.dataset.mv); if (j < 0 || j >= o.length) return; [o[i], o[j]] = [o[j], o[i]]; return save({ order: o.join(',') }, true); }
            if (b.dataset.open) { openMod = openMod === k ? '' : k; return render(); }
            if (b.dataset.reset) return save({ mod: k, tpl: d.modules[k][3] });
            if (b.dataset.ph) { const inp = r.querySelector('[name=tpl]'); const pos = document.activeElement === inp ? (inp.selectionStart ?? inp.value.length) : inp.value.length; inp.value = inp.value.slice(0, pos) + '{' + b.dataset.ph + '}' + inp.value.slice(pos); inp.focus(); }
        });
        box.addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target, k = f.closest('[data-k]').dataset.k;
            if (f.dataset.f === 'tpl') return save({ mod: k, tpl: f.tpl.value });
            if (f.dataset.f === 'place') return api('cm_place', { city: f.city.value }).then(r => { toast(r.msg); load(); }).catch(er => toast('⚠ ' + er.message, true));
            if (f.dataset.f === 'private') return save({ private: f.private.value });
            if (f.dataset.f === 'twitch') return save({ twitch: f.twitch.value });
            if (f.dataset.f === 'hr') return api('cm_hr_key', { token: f.token.value }).then(r => { toast(r.msg); f.token.value = ''; load(true); }).catch(er => toast('⚠ ' + er.message, true));
            if (f.dataset.f === 'hrosc') return save({ hr_param: f.hr_param.value, hr_osc: f.hr_osc.checked ? '1' : '0' });
        });
    }
    window.NexusChatMods = {
        open(el) {
            if (box !== el) { box = el; bind(); }
            if (!d) box.innerHTML = '<div class="muted">Lade …</div>';
            load(true);
            clearInterval(timer);
            timer = setInterval(() => { if (!document.hidden && box.offsetParent) load(); else if (!box.offsetParent) clearInterval(timer); }, 4000);
        },
    };
})();
