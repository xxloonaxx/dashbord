'use strict';
/*
 * Chatbox-Module: mehrere Bausteine (Status, Fenster, Musik, Zeit, Wetter, Rechner, Netzwerk, Twitch, Puls, VRChat,
 *   Countdown, Freunde, Laufschrift, Animation, Akku, Ping)
 * werden zu einer Chatbox-Nachricht zusammengesetzt und regelmäßig an VRChat geschickt.
 * Jedes Modul: an/aus, eigene Vorlage mit Platzhaltern, nur Desktop / nur VR / beides, Reihenfolge.
 * Alle Werte stehen zusätzlich als Platzhalter im Rotator, in Makros und in der Automatik zur Verfügung.
 * Läuft nur in der Windows-App (Android hat keine Prozess-/Systemwerte).
 */
const os = require('os');
const fs = require('fs');
const { execFile, spawn } = require('child_process');
const { str, int, cut, UserError } = require('./util');

/* Schlüssel → [Symbol, Titel, Beschreibung, Standard-Vorlage, wo (both|desktop|vr)] */
const MODULES = {
    status: ['💬', 'Persönlicher Status', 'Deine Texte der Reihe nach (wie der Rotator) – bei AFK automatisch „💤 AFK seit …“.', '{status_text}', 'both'],
    window: ['🪟', 'Fenster-Aktivität', 'Was gerade im Vordergrund läuft – oder „🥽 In VR“. Programme lassen sich als privat markieren.', '🪟 {fenster}', 'desktop'],
    media: ['🎵', 'Musik', 'Was auf dem PC läuft – über die Windows-Mediensteuerung (Spotify, Browser/YouTube, Apple Music, TIDAL, VLC …). Platzhalter: {musik} {musik_titel} {musik_kuenstler} {musik_app} {musik_zeit}.', '🎵 {musik}', 'both'],
    vrchat: ['🌐', 'VRChat-Radar', 'Welt, Spieler und Zeit in der Instanz – aus dem Spiel-Log.', '🌐 {welt} · 👥 {spieler}', 'both'],
    time: ['🕒', 'Uhrzeit', 'Deine Uhrzeit und Zeitzone – Schluss mit „wie spät ist es bei dir?“.', '🕒 {zeit} {zone}', 'both'],
    weather: ['⛅', 'Wetter', 'Temperatur, gefühlt, Wind und Luftfeuchte an deinem Ort (Open-Meteo, ohne Konto).', '{wetter_symbol} {temp}°C · gefühlt {temp_gefuehlt}°C', 'both'],
    system: ['🖥', 'Rechner', 'CPU, GPU, RAM und VRAM – Auslastung und GPU-Temperatur (NVIDIA über nvidia-smi, AMD/Intel über Windows).', '💻 CPU {cpu}% · GPU {gpu}% · RAM {ram}', 'both'],
    network: ['📶', 'Netzwerk', 'Download/Upload gerade, Spitze und Summe der Sitzung – Beweis, dass die Welt laggt, nicht du.', '📶 ↓{netz_down} ↑{netz_up}', 'desktop'],
    twitch: ['🟣', 'Twitch', 'Live-Status, Zuschauer und Kategorie deines Kanals (öffentliche Daten über decapi.me).', '🔴 LIVE · 👀 {twitch_zuschauer} · {twitch_spiel}', 'both'],
    heart: ['💓', 'Herzfrequenz', 'Dein Puls in der Chatbox und als Avatar-Parameter – über Pulsoid (eigenes Token).', '💓 {puls} bpm', 'both'],
    countdown: ['⏳', 'Countdown', 'Zeit bis zu einem Termin, den du festlegst – Stream, Geburtstag, Event.', '⏳ {countdown_name} in {countdown_rest}', 'both'],
    friends: ['👥', 'Freunde', 'Wie viele deiner Freunde gerade im Spiel und online sind.', '👥 {freunde_im_spiel} im Spiel · {freunde_online_web} online', 'both'],
    marquee: ['📜', 'Laufschrift', 'Ein längerer Text läuft Stück für Stück durch – jede Nachricht zeigt den nächsten Ausschnitt.', '📜 {laufschrift}', 'both'],
    anim: ['✨', 'Animation', 'Jede Nachricht zeigt das nächste „Bild“ – Emojis, Mini-Animationen oder Sprüche (eine Zeile = ein Bild).', '{animation}', 'both'],
    battery: ['🔋', 'Akku', 'Akkustand deines Laptops mit Ladeanzeige (Windows).', '{akku_symbol} {akku}%', 'both'],
    ping: ['📡', 'Ping', 'Antwortzeit zu den VRChat-Servern – zeigt, ob deine Verbindung gerade hakt.', '📡 {ping} ms', 'desktop'],
};
const ORDER = Object.keys(MODULES);
const DEF = { on: false, seconds: 10, sep: 'line', only_game: true, order: ORDER, mods: { status: { on: true }, time: { on: true }, media: { on: true } },
    city: '', lat: null, lon: null, place: '', private: 'discord, chrome, msedge, firefox, whatsapp, telegram, signal', twitch: '', hr_param: 'HeartRate', hr_osc: true,
    cd_name: '', cd_at: '', mq_text: '', mq_width: 30, an_frames: '🌑\n🌒\n🌓\n🌔\n🌕\n🌖\n🌗\n🌘' };
// WMO-Wettercodes (Open-Meteo) → Symbol + Text
const WMO = { 0: ['☀️', 'klar'], 1: ['🌤', 'heiter'], 2: ['⛅', 'wolkig'], 3: ['☁️', 'bedeckt'], 45: ['🌫', 'Nebel'], 48: ['🌫', 'Reifnebel'], 51: ['🌦', 'Niesel'], 53: ['🌦', 'Niesel'], 55: ['🌧', 'Niesel'],
    61: ['🌧', 'Regen'], 63: ['🌧', 'Regen'], 65: ['🌧', 'Starkregen'], 66: ['🌧', 'Eisregen'], 67: ['🌧', 'Eisregen'], 71: ['🌨', 'Schnee'], 73: ['🌨', 'Schnee'], 75: ['❄️', 'Starker Schnee'], 77: ['🌨', 'Schneegriesel'],
    80: ['🌦', 'Schauer'], 81: ['🌧', 'Schauer'], 82: ['⛈', 'Starke Schauer'], 85: ['🌨', 'Schneeschauer'], 86: ['🌨', 'Schneeschauer'], 95: ['⛈', 'Gewitter'], 96: ['⛈', 'Gewitter'], 99: ['⛈', 'Gewitter'] };

const bytes = n => n >= 1e9 ? (n / 1e9).toFixed(1) + ' GB' : n >= 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.round(n / 1e3) + ' KB';
const rate = n => n >= 1e6 ? (n / 1e6).toFixed(1) + ' MB/s' : Math.round(n / 1e3) + ' KB/s';
const run = (cmd, args, ms = 5000) => new Promise(res => execFile(cmd, args, { windowsHide: true, timeout: ms, maxBuffer: 1024 * 1024 }, (e, out) => res(e ? null : String(out))));
const base = (env, def) => (process.env[env] || def).replace(/\/+$/, '');

class ChatMods {
    constructor(app, crypt) {
        this.app = app;
        this.crypt = crypt;
        this.v = {};               // aktuelle Werte (Platzhalter)
        this.last = { send: 0, text: '', sys: 0, net: 0, gpu: 0, weather: 0, twitch: 0, heart: 0, vr: 0, bat: 0, ping: 0 };
        this.mqPos = 0; this.anIdx = -1;
        this.cpuPrev = null;
        this.net = null;           // { rx, tx, at, peakDown, peakUp, rx0, tx0 }
        this.gpuMissing = false;
        this.vr = false;
        this.win = null;           // PowerShell-Prozess für das Vordergrundfenster
        this.statusIdx = -1;
        this.timer = null;
        // Werte auch als Platzhalter für Rotator, Makros und Automatik
        const prev = app.game.vars;
        app.game.vars = () => Object.assign({}, prev ? prev() : {}, this.vars());
    }
    cfg() {
        let c = {};
        try { c = JSON.parse(this.app.db.setting('chatmods', '{}')) || {}; } catch (e) { c = {}; }
        const out = Object.assign({}, DEF, c);
        out.order = [...new Set((Array.isArray(c.order) ? c.order : []).filter(k => MODULES[k]).concat(ORDER))];
        out.mods = {};
        for (const k of ORDER) {
            const m = (c.mods || DEF.mods)[k] || {};
            out.mods[k] = { on: !!m.on, tpl: str(m.tpl) || MODULES[k][3], where: ['both', 'desktop', 'vr'].includes(m.where) ? m.where : MODULES[k][4] };
        }
        return out;
    }
    save(input) {
        const c = this.cfg();
        const b = v => v === true || v === '1' || v === 'true' || v === 'on';
        if (input.on !== undefined) c.on = b(input.on);
        if (input.seconds !== undefined) c.seconds = Math.max(3, Math.min(600, int(input.seconds) || 10));
        if (input.sep !== undefined) c.sep = ['line', 'dot', 'bar', 'space'].includes(str(input.sep)) ? str(input.sep) : 'line';
        if (input.only_game !== undefined) c.only_game = b(input.only_game);
        if (input.private !== undefined) c.private = cut(str(input.private), 500);
        if (input.twitch !== undefined) c.twitch = str(input.twitch).trim().replace(/^.*twitch\.tv\//i, '').replace(/[^\w]/g, '').slice(0, 30);
        if (input.hr_param !== undefined) c.hr_param = str(input.hr_param).replace(/[^\w/\-.]/g, '').slice(0, 64) || 'HeartRate';
        if (input.hr_osc !== undefined) c.hr_osc = b(input.hr_osc);
        if (input.cd_name !== undefined) c.cd_name = cut(str(input.cd_name).trim(), 40);
        if (input.cd_at !== undefined) { const v = str(input.cd_at).trim(); if (v && isNaN(Date.parse(v))) throw new UserError('Datum/Uhrzeit ungültig.'); c.cd_at = v; }
        if (input.mq_text !== undefined) { c.mq_text = cut(str(input.mq_text).replace(/\s+/g, ' ').trim(), 500); this.mqPos = 0; }
        if (input.mq_width !== undefined) c.mq_width = Math.max(10, Math.min(120, int(input.mq_width) || 30));
        if (input.an_frames !== undefined) { c.an_frames = str(input.an_frames).replace(/\r/g, '').split('\n').map(x => x.trim()).filter(Boolean).slice(0, 60).map(x => cut(x, 140)).join('\n'); this.anIdx = -1; }
        if (input.order !== undefined) { const o = (Array.isArray(input.order) ? input.order : str(input.order).split(',')).filter(k => MODULES[k]); c.order = [...new Set(o.concat(ORDER))]; }
        if (input.mod && MODULES[input.mod]) {
            const m = c.mods[input.mod];
            if (input.mod_on !== undefined) m.on = b(input.mod_on);
            if (input.tpl !== undefined) m.tpl = cut(str(input.tpl).replace(/\r/g, ''), 200) || MODULES[input.mod][3];
            if (input.where !== undefined && ['both', 'desktop', 'vr'].includes(str(input.where))) m.where = str(input.where);
        }
        this.app.db.setSetting('chatmods', JSON.stringify(c));
        this.apply();
        return c;
    }
    // ---------- Pulsoid-Token (verschlüsselt) ----------
    hrToken() { try { return this.crypt.decrypt(this.app.db.setting('chatmods_hr', '')) || ''; } catch (e) { return ''; } }
    setHrToken(t) {
        t = str(t).trim();
        if (t && !/^[\w-]{20,200}$/.test(t)) throw new UserError('Das sieht nicht wie ein Pulsoid-Token aus.');
        this.app.db.setSetting('chatmods_hr', t ? this.crypt.encrypt(t) : '');
    }

    start() { this.apply(); }
    apply() {
        const c = this.cfg();
        this.app.game.suppressRotator = c.on;            // Module ersetzen den Rotator, solange sie an sind
        const need = c.on || this.app.settings.get('ui.chatmods_live');
        if (need && !this.timer) { this.timer = setInterval(() => this.tick().catch(() => {}), 1000); if (this.timer.unref) this.timer.unref(); }
        if (!c.on && this.timer && !need) { clearInterval(this.timer); this.timer = null; }
        if (!(c.on && c.mods.window.on) && this.win) this.stopWin();
    }
    stop() { clearInterval(this.timer); this.timer = null; this.stopWin(); }

    // ---------- Datenquellen ----------
    async refresh(c, force) {
        const t = Date.now(), on = k => c.mods[k].on || force;
        const jobs = [];
        if (on('system') && t - this.last.sys >= 2000) { this.last.sys = t; this.readCpu(); if (!this.gpuMissing && t - this.last.gpu >= 5000) { this.last.gpu = t; jobs.push(this.readGpu()); } }
        if (on('network') && t - this.last.net >= 2000) { this.last.net = t; jobs.push(this.readNet()); }
        if (on('weather') && c.lat !== null && t - this.last.weather >= 15 * 60000) { this.last.weather = t; jobs.push(this.readWeather(c)); }
        if (on('twitch') && (c.twitch || this.twitchChannel()) && t - this.last.twitch >= 60000) { this.last.twitch = t; jobs.push(this.readTwitch(c)); }
        if (on('heart') && t - this.last.heart >= 3000 && this.hrToken()) { this.last.heart = t; jobs.push(this.readHeart(c)); }
        if (on('battery') && t - this.last.bat >= 60000) { this.last.bat = t; jobs.push(this.readBattery()); }
        if (on('ping') && t - this.last.ping >= 30000) { this.last.ping = t; jobs.push(this.readPing()); }
        if (t - this.last.vr >= 15000) { this.last.vr = t; jobs.push(this.readVr()); }
        if (on('window') && process.platform === 'win32' && !this.win) this.startWin();
        await Promise.all(jobs.map(p => p.catch(() => {})));
    }
    readCpu() {
        const cpus = os.cpus();
        const sum = cpus.reduce((a, x) => { const tm = x.times; a.idle += tm.idle; a.total += tm.user + tm.nice + tm.sys + tm.idle + tm.irq; return a; }, { idle: 0, total: 0 });
        if (this.cpuPrev) { const di = sum.idle - this.cpuPrev.idle, dt = sum.total - this.cpuPrev.total; if (dt > 0) this.v.cpu = String(Math.max(0, Math.min(100, Math.round(100 - di / dt * 100)))); }
        this.cpuPrev = sum;
        const tot = os.totalmem(), used = tot - os.freemem();
        Object.assign(this.v, { ram: (used / 1073741824).toFixed(1) + '/' + Math.round(tot / 1073741824) + ' GB', ram_pct: String(Math.round(used / tot * 100)), cpu_name: str((os.cpus()[0] || {}).model).replace(/\s+/g, ' ').trim() });
    }
    async readGpu() {
        const q = ['--query-gpu=utilization.gpu,temperature.gpu,memory.used,memory.total,power.draw,name', '--format=csv,noheader,nounits'];
        const fake = process.env.NEXUS_NVIDIA_SMI;      // Test: Ersatzprogramm (JS-Datei, läuft überall)
        const out = this.gpuWin ? null : fake ? await run(process.execPath, [fake].concat(q)) : await run('nvidia-smi', q);
        if (!out) { if (!fake && !await this.readGpuWin()) this.gpuMissing = true; return; }
        const [u, temp, mu, mt, pw, name] = out.split('\n')[0].split(',').map(x => x.trim());
        Object.assign(this.v, { gpu: String(int(u)), gpu_temp: String(int(temp)), vram: (Number(mu) / 1024).toFixed(1) + '/' + Math.round(Number(mt) / 1024) + ' GB', gpu_watt: String(Math.round(Number(pw) || 0)), gpu_name: name || '' });
    }
    /** AMD/Intel (und NVIDIA ohne nvidia-smi): Windows-Leistungsdaten über CIM – Klassennamen sind (anders als Leistungsindikatoren) nicht übersetzt */
    async readGpuWin() {
        if (process.platform !== 'win32' && !process.env.NEXUS_GPU_WIN) return false;
        const ps = "$e=Get-CimInstance Win32_PerfFormattedData_GPUPerformanceCounters_GPUEngine -ErrorAction SilentlyContinue | Where-Object { $_.Name -like '*engtype_3D*' };"
            + "$u=($e | Measure-Object -Property UtilizationPercentage -Sum).Sum;"
            + "$m=(Get-CimInstance Win32_PerfFormattedData_GPUPerformanceCounters_GPUAdapterMemory -ErrorAction SilentlyContinue | Measure-Object -Property DedicatedUsage -Sum).Sum;"
            + "$n=(Get-CimInstance Win32_VideoController | Where-Object { $_.Name -notlike '*Basic*' } | Select-Object -First 1).Name;"
            + "[Console]::Out.WriteLine([string][math]::Round([double]$u) + '|' + [string][math]::Round([double]$m) + '|' + $n)";
        const out = process.env.NEXUS_GPU_WIN !== undefined ? process.env.NEXUS_GPU_WIN : await run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', ps], 8000);
        const m = /^(\d+)\|(\d*)\|(.*)$/m.exec(str(out).trim());
        if (!m || (!m[3] && !int(m[1]))) return false;
        this.gpuWin = true;
        Object.assign(this.v, { gpu: String(Math.min(100, int(m[1]))), gpu_name: m[3].trim() });
        if (int(m[2])) this.v.vram = (int(m[2]) / 1073741824).toFixed(1) + ' GB';
        return true;
    }
    async readBattery() {
        let pct = null, charging = false;
        if (process.env.NEXUS_BATTERY !== undefined) { const [p, c] = process.env.NEXUS_BATTERY.split(','); pct = p === '' ? null : int(p); charging = c === '1'; }
        else if (process.platform === 'win32') {
            const out = await run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', '$b=Get-CimInstance Win32_Battery | Select-Object -First 1; if($b){[Console]::Out.WriteLine([string]$b.EstimatedChargeRemaining + "|" + [string]$b.BatteryStatus)}'], 8000);
            const m = /^(\d+)\|(\d+)/.exec(str(out).trim());
            if (m) { pct = int(m[1]); charging = [2, 6, 7, 8, 9].includes(int(m[2])); }   // 2 = Netzteil, 6–9 = lädt
        } else {
            try { const d = '/sys/class/power_supply/BAT0/'; pct = int(fs.readFileSync(d + 'capacity', 'utf8')); charging = /charg|full/i.test(fs.readFileSync(d + 'status', 'utf8')) && !/dis/i.test(fs.readFileSync(d + 'status', 'utf8')); } catch (e) { pct = null; }
        }
        if (pct === null) { delete this.v.akku; delete this.v.akku_symbol; return; }
        Object.assign(this.v, { akku: String(Math.max(0, Math.min(100, pct))), akku_symbol: charging ? '🔌' : pct <= 20 ? '🪫' : '🔋', akku_status: charging ? 'lädt' : '' });
    }
    async readPing() {
        const [host, port] = (process.env.NEXUS_PING_HOST || 'api.vrchat.cloud:443').split(':');
        const t0 = Date.now();
        const ok = await new Promise(res => {
            const sock = require('net').connect({ host, port: int(port) || 443 });
            const done = v => { try { sock.destroy(); } catch (e) { /* egal */ } res(v); };
            sock.setTimeout(4000, () => done(false));
            sock.once('connect', () => done(true));
            sock.once('error', () => done(false));
        });
        if (ok) this.v.ping = String(Date.now() - t0); else delete this.v.ping;
    }
    /** Countdown, Freunde, Laufschrift, Animation – ohne externe Quelle */
    localVars(c, advance) {
        const out = {};
        if (c.cd_at && !isNaN(Date.parse(c.cd_at))) {
            const ms = Date.parse(c.cd_at) - Date.now(), mins = Math.max(0, Math.round(ms / 60000));
            out.countdown_name = c.cd_name || 'Termin';
            out.countdown_rest = ms <= 0 ? 'jetzt! 🎉' : mins >= 2880 ? Math.floor(mins / 1440) + ' T ' + Math.floor(mins % 1440 / 60) + ' h' : mins >= 60 ? Math.floor(mins / 60) + ' h ' + (mins % 60) + ' min' : mins + ' min';
            out.countdown_tage = String(Math.max(0, Math.ceil(ms / 86400000)));
        }
        try {
            const fr = this.app.vrc.friendList();
            if (fr && fr.length) { out.freunde_im_spiel = String(fr.filter(f => f.state === 'online').length); out.freunde_online_web = String(fr.filter(f => f.state === 'active').length); out.freunde_gesamt = String(fr.length); }
        } catch (e) { /* kein Konto */ }
        if (c.mq_text) {
            const txt = Array.from(c.mq_text + '   ·   '), w = Math.min(c.mq_width, txt.length);
            if (advance) this.mqPos = (this.mqPos + Math.max(1, Math.round(w / 2))) % txt.length;
            out.laufschrift = (txt.concat(txt)).slice(this.mqPos, this.mqPos + w).join('').trim();
        }
        const frames = str(c.an_frames).split('\n').filter(Boolean);
        if (frames.length) {
            if (advance || this.anIdx < 0) this.anIdx = (this.anIdx + 1) % frames.length;
            out.animation = frames[this.anIdx % frames.length];
        }
        return out;
    }
    async readNet() {
        let rx = 0, tx = 0;
        if (process.platform === 'win32' && !process.env.NEXUS_NETDEV) {
            const out = await run('netstat', ['-e']);
            const m = out && /(\d+)\s+(\d+)\s*$/m.exec(out.split('\n').find(l => /^\s*(Bytes|Byte|Octets)/i.test(l)) || '');
            if (!m) return;
            rx = Number(m[1]); tx = Number(m[2]);
        } else {
            try {
                for (const l of fs.readFileSync(process.env.NEXUS_NETDEV || '/proc/net/dev', 'utf8').split('\n').slice(2)) {
                    const [name, rest] = l.split(':'); if (!rest || name.trim() === 'lo') continue;
                    const f = rest.trim().split(/\s+/).map(Number); rx += f[0]; tx += f[8];
                }
            } catch (e) { return; }
        }
        const t = Date.now(), n = this.net;
        if (n && t > n.at) {
            const down = Math.max(0, (rx - n.rx) / ((t - n.at) / 1000)), up = Math.max(0, (tx - n.tx) / ((t - n.at) / 1000));
            n.peakDown = Math.max(n.peakDown, down); n.peakUp = Math.max(n.peakUp, up);
            Object.assign(this.v, { netz_down: rate(down), netz_up: rate(up), netz_spitze_down: rate(n.peakDown), netz_spitze_up: rate(n.peakUp), netz_summe_down: bytes(rx - n.rx0), netz_summe_up: bytes(tx - n.tx0) });
            Object.assign(n, { rx, tx, at: t });
        } else this.net = { rx, tx, at: t, rx0: rx, tx0: tx, peakDown: 0, peakUp: 0 };
    }
    async readWeather(c) {
        const u = base('NEXUS_OPENMETEO_BASE', 'https://api.open-meteo.com') + '/v1/forecast?latitude=' + c.lat + '&longitude=' + c.lon + '&current=temperature_2m,apparent_temperature,relative_humidity_2m,wind_speed_10m,weather_code&timezone=auto';
        const j = await (await fetch(u, { signal: AbortSignal.timeout(10000) })).json();
        const cur = j.current || {};
        const w = WMO[int(cur.weather_code)] || ['🌡', ''];
        Object.assign(this.v, { temp: String(Math.round(cur.temperature_2m)), temp_gefuehlt: String(Math.round(cur.apparent_temperature)), wind: String(Math.round(cur.wind_speed_10m)) + ' km/h',
            luftfeuchte: String(Math.round(cur.relative_humidity_2m)) + '%', wetter_symbol: w[0], wetter: w[1], ort: c.place || c.city });
        this.v.wetter_zeit = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    }
    async geocode(name) {
        name = str(name).trim();
        if (!name) throw new UserError('Ort fehlt.');
        const j = await (await fetch(base('NEXUS_GEO_BASE', 'https://geocoding-api.open-meteo.com') + '/v1/search?count=1&language=de&name=' + encodeURIComponent(name), { signal: AbortSignal.timeout(10000) })).json().catch(() => ({}));
        const r = (j.results || [])[0];
        if (!r) throw new UserError('Ort „' + name + '“ nicht gefunden.');
        return { lat: Number(r.latitude), lon: Number(r.longitude), place: [r.name, r.admin1, r.country_code].filter(Boolean).join(', ') };
    }
    twitchChannel() { try { return this.app.stream ? str(this.app.stream.cfg().twitch.channel) : ''; } catch (e) { return ''; } }
    async readTwitch(c) {
        const ch = c.twitch || this.twitchChannel();
        if (!ch) return;
        const b = base('NEXUS_DECAPI_BASE', 'https://decapi.me');
        const get = async p => str(await (await fetch(b + '/twitch/' + p + '/' + encodeURIComponent(ch), { signal: AbortSignal.timeout(10000) })).text()).trim();
        const up = await get('uptime');
        const live = !/offline|not live|error/i.test(up);
        Object.assign(this.v, { twitch_live: live ? 'LIVE' : '', twitch_dauer: live ? up : '', twitch_kanal: ch });
        if (live) { this.v.twitch_zuschauer = await get('viewercount'); this.v.twitch_spiel = await get('game'); }
        else { this.v.twitch_zuschauer = ''; this.v.twitch_spiel = ''; }
    }
    async readHeart(c) {
        const r = await fetch(base('NEXUS_PULSOID_BASE', 'https://dev.pulsoid.net') + '/api/v1/data/heart_rate/latest', { headers: { Authorization: 'Bearer ' + this.hrToken() }, signal: AbortSignal.timeout(8000) });
        if (!r.ok) { this.v.puls = ''; this.hrError = r.status === 401 || r.status === 403 ? 'Pulsoid-Token ungültig' : 'Pulsoid: HTTP ' + r.status; return; }
        const j = await r.json();
        const bpm = int((j.data || {}).heart_rate);
        this.hrError = '';
        if (!bpm || Date.now() - int(j.measured_at) > 60000 && j.measured_at) { this.v.puls = ''; return; }
        this.v.puls = String(bpm);
        if (c.hr_osc && c.hr_param) {
            this.app.game.osc.send('/avatar/parameters/' + c.hr_param, bpm).catch(() => {});
            this.app.game.osc.send('/avatar/parameters/' + c.hr_param + 'Float', { float: Math.min(1, bpm / 255) }).catch(() => {});
        }
    }
    async readVr() {
        if (process.env.NEXUS_FAKE_VR !== undefined) { this.vr = process.env.NEXUS_FAKE_VR === '1'; return; }
        if (process.platform !== 'win32') { this.vr = false; return; }
        const out = await run('tasklist', ['/FI', 'IMAGENAME eq vrserver.exe', '/NH']);
        this.vr = !!out && /vrserver\.exe/i.test(out);
    }
    // Vordergrundfenster: kleiner PowerShell-Dauerprozess, meldet alle 2 s „Prozess|Titel“
    startWin() {
        const ps = 'Add-Type @"\nusing System;using System.Runtime.InteropServices;using System.Text;\npublic class NxW{[DllImport("user32.dll")]public static extern IntPtr GetForegroundWindow();[DllImport("user32.dll")]public static extern int GetWindowText(IntPtr h,StringBuilder s,int n);[DllImport("user32.dll")]public static extern uint GetWindowThreadProcessId(IntPtr h,out uint p);}\n"@\n'
            + 'while($true){$h=[NxW]::GetForegroundWindow();$s=New-Object System.Text.StringBuilder 256;[void][NxW]::GetWindowText($h,$s,256);$p=0;[void][NxW]::GetWindowThreadProcessId($h,[ref]$p);$n="";try{$n=(Get-Process -Id $p -ErrorAction Stop).ProcessName}catch{};[Console]::Out.WriteLine($n+"|"+$s.ToString());[Console]::Out.Flush();Start-Sleep -Seconds 2}';
        try {
            this.win = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', ps], { windowsHide: true, stdio: ['ignore', 'pipe', 'ignore'] });
            let buf = '';
            this.win.stdout.on('data', d => { buf += d; const lines = buf.split(/\r?\n/); buf = lines.pop(); const l = lines.pop(); if (l !== undefined) this.setWindow(l); });
            this.win.on('exit', () => { this.win = null; });
        } catch (e) { this.win = null; }
    }
    stopWin() { if (this.win) try { this.win.kill(); } catch (e) { /* egal */ } this.win = null; }
    setWindow(line) {
        const i = line.indexOf('|');
        const proc = (i < 0 ? line : line.slice(0, i)).trim(), title = i < 0 ? '' : line.slice(i + 1).trim();
        const priv = str(this.cfg().private).toLowerCase().split(/[,\n]/).map(x => x.trim()).filter(Boolean);
        const isPriv = priv.some(p => proc.toLowerCase() === p || proc.toLowerCase() === p.replace(/\.exe$/, ''));
        const nice = proc ? proc.charAt(0).toUpperCase() + proc.slice(1) : '';
        this.v.fenster_prozess = nice;
        this.v.fenster_titel = isPriv ? '' : cut(title, 60);
        this.v.fenster = !proc ? '' : /^vrchat$/i.test(proc) ? 'VRChat' : isPriv ? '🔒 ' + nice : cut(title && title.toLowerCase() !== proc.toLowerCase() ? nice + ' – ' + title : nice, 60);
    }

    // ---------- Platzhalter & Zusammensetzen ----------
    vars() {
        const d = new Date();
        const zone = (() => { try { return new Intl.DateTimeFormat('de-DE', { timeZoneName: 'short' }).formatToParts(d).find(p => p.type === 'timeZoneName').value; } catch (e) { return ''; } })();
        const a = this.app.auto;
        const afk = a && a.afkSince ? Math.round((Date.now() - a.afkSince) / 60000) : 0;
        return Object.assign({ zone, zeitzone: (Intl.DateTimeFormat().resolvedOptions().timeZone || ''), modus: this.vr ? 'VR' : 'Desktop', vr: this.vr ? '🥽' : '🖥',
            afk_text: a && a.afkSince ? '💤 AFK seit ' + afk + ' min' : '' }, (() => { try { return this.localVars(this.cfg(), false); } catch (e) { return {}; } })(), this.v);
    }
    statusText() {
        const a = this.app.auto;
        if (a && a.afkSince) return '💤 AFK seit ' + Math.round((Date.now() - a.afkSince) / 60000) + ' min';
        const c = this.app.game.cfg();
        const list = str(c.rotator_texts).split('\n').map(x => x.trim()).filter(Boolean);
        if (!list.length) return str(c.rotator_text);
        this.statusIdx = (this.statusIdx + 1) % list.length;
        return list[this.statusIdx];
    }
    /** Module der Reihe nach füllen; leere Zeilen (z. B. keine Musik) fallen weg */
    compose(c, advance = true) {
        c = c || this.cfg();
        const g = this.app.game, parts = [], per = {};
        Object.assign(this.v, this.localVars(c, advance));
        for (const k of c.order) {
            const m = c.mods[k];
            if (!m.on || (m.where === 'vr' && !this.vr) || (m.where === 'desktop' && this.vr)) continue;
            let tpl = m.tpl;
            if (k === 'status') tpl = tpl.replace('{status_text}', advance ? this.statusText() : (this.lastStatus || this.statusText()));
            if (k === 'window' && this.vr) tpl = '🥽 In VR';
            // Teile mit fehlenden Werten (z. B. {gpu} ohne NVIDIA-Karte) weglassen
            const filled = g.fill(tpl, this.vars()).split(/( · | \| |\n)/).reduce((a, x, i, all) => {
                if (i % 2) return a;
                if (/\{\w+\}/.test(x)) return a;
                return a.concat(a.length ? [all[i - 1] || ' · ', x] : [x]);
            }, []).join('').trim();
            // Modul weglassen, wenn sein Hauptwert fehlt (z. B. keine Musik, Twitch offline)
            const key = { media: 'musik', weather: 'temp', twitch: 'twitch_live', heart: 'puls', window: 'fenster', network: 'netz_down', system: 'cpu',
                countdown: 'countdown_rest', friends: 'freunde_im_spiel', marquee: 'laufschrift', anim: 'animation', battery: 'akku', ping: 'ping' }[k];
            const vars = Object.assign({}, g.vars ? g.vars() : {});
            if (key && k !== 'window' && (!vars[key] || vars[key] === '–')) continue;
            if (k === 'window' && !this.vr && !vars.fenster) continue;
            if (!filled) continue;
            per[k] = filled;
            parts.push(filled);
        }
        const sep = { line: '\n', dot: ' · ', bar: ' | ', space: '  ' }[c.sep] || '\n';
        const len = l => Array.from(l.join(sep)).length;
        const full = len(parts);
        // VRChat erlaubt 144 Zeichen: lieber ganze Module am Ende weglassen als mitten im Text abschneiden
        const keys = Object.keys(per), dropped = [];
        while (parts.length > 1 && len(parts) > 144) { parts.pop(); dropped.unshift(keys.pop()); }
        const text = Array.from(parts.join(sep)).slice(0, 144).join('');
        return { text, per, length: full, dropped };
    }
    async tick() {
        const c = this.cfg();
        if (!c.on) return;
        await this.refresh(c);
        if (Date.now() - this.last.send < c.seconds * 1000) return;
        if (c.only_game && !this.app.game.state.running) return;
        this.last.send = Date.now();
        const r = this.compose(c);
        if (!r.text || r.text === this.last.text && Date.now() - this.last.textAt < 25000) return;   // gleicher Text: nur alle 25 s auffrischen
        this.last.text = r.text; this.last.textAt = Date.now();
        await this.app.game.osc.chatbox(r.text, false);
    }

    async action(act, input) {
        switch (act) {
            case 'cm_get': {
                const c = this.cfg();
                await this.refresh(c, input.refresh === '1');
                const prev = this.compose(c, false);
                return { cfg: c, modules: MODULES, values: this.vars(), preview: prev, vr: this.vr, gpu: !this.gpuMissing, gpu_win: !!this.gpuWin, battery: this.v.akku !== undefined, has_hr: !!this.hrToken(), hr_error: this.hrError || '',
                    twitch_default: this.twitchChannel(), windows: process.platform === 'win32', rotator_on: !!this.app.game.cfg().rotator_enabled };
            }
            case 'cm_save': { const c = this.save(input); return { cfg: c, msg: input.quiet ? '' : 'Gespeichert.' }; }
            case 'cm_place': {
                const g = await this.geocode(input.city);
                const c = this.cfg();
                Object.assign(c, { city: str(input.city).trim(), lat: g.lat, lon: g.lon, place: g.place });
                this.app.db.setSetting('chatmods', JSON.stringify(c));
                this.last.weather = 0;
                await this.readWeather(c).catch(() => {});
                return { cfg: c, msg: '📍 ' + g.place };
            }
            case 'cm_hr_key': this.setHrToken(input.token); this.last.heart = 0; return { has_hr: !!this.hrToken(), msg: input.token ? 'Pulsoid-Token gespeichert (verschlüsselt).' : 'Entfernt.' };
            case 'cm_send': {
                const c = this.cfg();
                await this.refresh(c, true);
                const r = this.compose(c);
                if (!r.text) throw new UserError('Nichts zu senden – erst Module einschalten.');
                await this.app.game.osc.chatbox(r.text, false);
                return { msg: 'An die Chatbox gesendet.', text: r.text };
            }
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { ChatMods, MODULES, WMO };
