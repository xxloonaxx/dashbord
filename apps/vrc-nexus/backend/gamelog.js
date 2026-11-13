'use strict';
/*
 * Spiel-Log aus den VRChat-Logdateien + OSC – direkt in der App (vorher: Python-Client + client.php).
 *  - LogParser / LogWatcher: liest %LOCALAPPDATA%Low\VRChat\VRChat\output_log_*.txt mit (Welten, Spieler, Avatare, Videos, Screenshots, Benachrichtigungen)
 *  - Osc: senden an VRChat (UDP 9000), empfangen von VRChat (UDP 9001: Avatar-Parameter)
 *  - GameLog: Speicherung & Auswertung (Getroffen, Besuche, Kennzahlen), OSC-Befehle, Schnellknöpfe, Chatbox-Rotator
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const dgram = require('dgram');
const U = require('./util');
const { UserError, now, str, int, cut, arr } = U;
const { UID } = require('./vrchat');

const KINDS = {
    world_join: ['🌐', 'Welt betreten'], world_leave: ['🚪', 'Welt verlassen'], player_join: ['👋', 'Spieler kam'], player_leave: ['👋', 'Spieler ging'],
    avatar: ['🎭', 'Avatar gewechselt'], video: ['🎬', 'Video'], screenshot: ['📸', 'Screenshot'], notification: ['🔔', 'Benachrichtigung'],
    vrc_start: ['▶️', 'VRChat gestartet'], vrc_quit: ['⏹️', 'VRChat beendet'], note: ['📝', 'Notiz'],
};
const NOTIFY = { invite: 'Einladung', requestInvite: 'Einladungsanfrage', friendRequest: 'Freundschaftsanfrage', inviteResponse: 'Antwort auf Einladung',
    requestInviteResponse: 'Antwort auf Anfrage', votetokick: 'Abstimmung zum Kicken' };
const INPUTS = ['Jump', 'Run', 'Voice', 'QuickMenuToggleLeft', 'MoveForward', 'MoveBackward', 'MoveLeft', 'MoveRight', 'LookLeft', 'LookRight', 'ComfortLeft', 'ComfortRight', 'GrabRight', 'UseRight', 'DropRight', 'PanicButton'];

function defaultLogDir() { return path.join(process.env.USERPROFILE || os.homedir(), 'AppData', 'LocalLow', 'VRChat', 'VRChat'); }
const parseTs = (y, mo, d, h, mi, s) => Math.floor(new Date(+y, +mo - 1, +d, +h, +mi, +s).getTime() / 1000);

// =============================== Log-Parser ===============================
const LINE = /^(\d{4})\.(\d{2})\.(\d{2}) (\d{2}):(\d{2}):(\d{2}) +(\w+) +- +(.*)$/;
const USR = '(usr_[0-9a-fA-F-]{8,40}|[0-9A-Za-z]{10})';
const P = {
    joining: /^\[Behaviour\] Joining ((?:wrld|wld)_[0-9a-fA-F-]{8,40}):(.*)$/,
    room: /^\[Behaviour\] (?:Joining or Creating Room|Entering Room): (.+)$/,
    left_room: /^\[Behaviour\] OnLeftRoom\s*$/,
    player_join: new RegExp('^\\[Behaviour\\] OnPlayerJoined (.+?)(?: \\(' + USR + '\\))?\\s*$'),
    player_leave: new RegExp('^\\[Behaviour\\] OnPlayerLeft (.+?)(?: \\(' + USR + '\\))?\\s*$'),
    avatar: /^\[Behaviour\] Switching (.+?) to avatar (.+?)\s*$/,
    video: /^\[Video Playback\] Attempting to resolve URL '(.+)'\s*$/,
    screenshot: /^\[VRC Camera\] Took screenshot to: (.+?)\s*$/,
    auth: new RegExp('User Authenticated: (.+?) \\(' + USR + '\\)'),
    notification: new RegExp('Received Notification: <Notification from username:(.*?), sender user id:' + USR + '.*?of type:\\s*(\\w+)'),
    quit: /VRCApplication: (?:OnApplicationQuit|HandleApplicationQuit)/,
};

class SessionState {
    constructor() { this.resetWorld(); this.displayName = ''; this.userId = ''; this.avatar = ''; this.running = false; }
    resetWorld() { this.worldId = ''; this.instance = ''; this.worldName = ''; this.since = 0; this.players = new Map(); }
    snapshot() {
        return { running: this.running, world_id: this.worldId, instance: this.instance, world_name: this.worldName, since: this.since,
            players: [...this.players.values()].sort((a, b) => a.name.localeCompare(b.name)), avatar: this.avatar, display_name: this.displayName };
    }
}

class LogParser {
    constructor(state) { this.state = state || new SessionState(); this.pending = null; }
    ev(t, kind, extra = {}) {
        const s = this.state;
        return Object.assign({ ts: t, kind, world_id: s.worldId, instance: s.instance, world_name: s.worldName, player_id: '', player_name: '', detail: '' }, extra);
    }
    feed(line) {
        const m = LINE.exec(String(line).replace(/[\r\n]+$/, ''));
        if (!m) return [];
        const t = parseTs(m[1], m[2], m[3], m[4], m[5], m[6]);
        const msg = m[8].trim();
        const st = this.state;
        let p;
        if ((p = P.joining.exec(msg))) { this.pending = [p[1], p[2].trim()]; return []; }
        if ((p = P.room.exec(msg))) {
            const [wid, inst] = this.pending || ['', ''];
            this.pending = null;
            st.resetWorld(); st.running = true;
            st.worldId = wid; st.instance = inst; st.worldName = p[1].trim(); st.since = t;
            return [this.ev(t, 'world_join')];
        }
        if (P.left_room.test(msg)) {
            if (!st.worldName && !st.worldId) return [];
            const e = this.ev(t, 'world_leave');
            st.resetWorld();
            return [e];
        }
        for (const kind of ['player_join', 'player_leave']) {
            if ((p = P[kind].exec(msg))) {
                const name = p[1].trim(), uid = p[2] || '';
                const key = uid || 'n:' + name;
                if (kind === 'player_join') st.players.set(key, { id: uid, name, since: t });
                else {
                    st.players.delete(key);
                    if (!uid) for (const [k, v] of st.players) if (v.name === name) st.players.delete(k);
                }
                return [this.ev(t, kind, { player_id: uid, player_name: name })];
            }
        }
        if ((p = P.avatar.exec(msg))) {
            const who = p[1].trim(), av = p[2].trim();
            if (st.displayName && who === st.displayName) { st.avatar = av; return [this.ev(t, 'avatar', { player_name: who, detail: av })]; }
            return [];
        }
        if ((p = P.video.exec(msg))) return [this.ev(t, 'video', { detail: p[1].slice(0, 500) })];
        if ((p = P.screenshot.exec(msg))) return [this.ev(t, 'screenshot', { detail: p[1].slice(0, 500) })];
        if ((p = P.auth.exec(msg))) { st.displayName = p[1].trim(); st.userId = p[2]; st.running = true; return []; }
        if ((p = P.notification.exec(msg))) return [this.ev(t, 'notification', { player_id: p[2], player_name: p[1].trim(), detail: NOTIFY[p[3]] || p[3] })];
        if (P.quit.test(msg)) { const e = this.ev(t, 'vrc_quit'); st.resetWorld(); st.running = false; return [e]; }
        return [];
    }
}

const logFiles = dir => {
    let list = [];
    try { list = fs.readdirSync(dir).filter(f => /^output_log_.*\.txt$/.test(f)).map(f => path.join(dir, f)); } catch (e) { return []; }
    return list.map(f => [f, fs.statSync(f).mtimeMs]).sort((a, b) => a[1] - b[1] || a[0].localeCompare(b[0])).map(x => x[0]);
};
function fileStart(file) {
    const m = path.basename(file).match(/output_log_(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/);
    return m ? parseTs(...m.slice(1)) : Math.floor(fs.statSync(file).ctimeMs / 1000);
}

class LogWatcher {
    constructor(dir, parser) { this.dir = dir; this.parser = parser; this.file = null; this.pos = 0; this.buf = Buffer.alloc(0); }
    importAll() {
        const events = [];
        for (const f of logFiles(this.dir)) {
            const p = new LogParser();
            for (const line of fs.readFileSync(f, 'utf8').split('\n')) events.push(...p.feed(line));
        }
        return events;
    }
    poll() {
        const files = logFiles(this.dir);
        if (!files.length) return [];
        const newest = files[files.length - 1];
        const events = [];
        if (newest !== this.file) {
            if (this.file) events.push(...this.read());
            this.file = newest; this.pos = 0; this.buf = Buffer.alloc(0);
            Object.assign(this.parser.state, new SessionState());
            events.push({ ts: fileStart(newest), kind: 'vrc_start', world_id: '', instance: '', world_name: '', player_id: '', player_name: '', detail: path.basename(newest) });
        }
        events.push(...this.read());
        return events;
    }
    read() {
        let size;
        try { size = fs.statSync(this.file).size; } catch (e) { return []; }
        if (size < this.pos) { this.pos = 0; this.buf = Buffer.alloc(0); }
        if (size === this.pos) return [];
        const fd = fs.openSync(this.file, 'r');
        const chunk = Buffer.alloc(Math.min(size - this.pos, 32 * 1024 * 1024));
        fs.readSync(fd, chunk, 0, chunk.length, this.pos);
        fs.closeSync(fd);
        this.pos += chunk.length;
        const data = Buffer.concat([this.buf, chunk]);
        const lastNl = data.lastIndexOf(10);
        if (lastNl < 0) { this.buf = data; return []; }
        this.buf = data.subarray(lastNl + 1);
        const events = [];
        for (const line of data.subarray(0, lastNl).toString('utf8').split('\n')) events.push(...this.parser.feed(line));
        return events;
    }
}

// =============================== OSC ===============================
const pad = b => Buffer.concat([b, Buffer.alloc(4 - (b.length % 4))]);
const oscStr = s => pad(Buffer.from(s, 'utf8'));
function oscEncode(address, ...args) {
    let tags = ',';
    const parts = [];
    for (const a of args) {
        if (a === true) tags += 'T';
        else if (a === false) tags += 'F';
        else if (Number.isInteger(a) && !(typeof a === 'object')) { tags += 'i'; const b = Buffer.alloc(4); b.writeInt32BE(a); parts.push(b); }
        else if (typeof a === 'number') { tags += 'f'; const b = Buffer.alloc(4); b.writeFloatBE(a); parts.push(b); }
        else if (typeof a === 'string') { tags += 's'; parts.push(oscStr(a)); }
        else if (a && a.float !== undefined) { tags += 'f'; const b = Buffer.alloc(4); b.writeFloatBE(a.float); parts.push(b); }
        else throw new Error('Nicht unterstützter OSC-Typ');
    }
    return Buffer.concat([oscStr(address), oscStr(tags), ...parts]);
}
function oscRead(buf, pos) { const end = buf.indexOf(0, pos); const s = buf.toString('utf8', pos, end); pos = end + 1; pos += (4 - (pos % 4)) % 4; return [s, pos]; }
function oscDecode(buf) {
    if (buf.subarray(0, 8).toString('latin1') === '#bundle\0') {
        const out = [];
        let pos = 16;
        while (pos + 4 <= buf.length) { const n = buf.readInt32BE(pos); pos += 4; out.push(...oscDecode(buf.subarray(pos, pos + n))); pos += n; }
        return out;
    }
    let [address, pos] = oscRead(buf, 0);
    if (pos >= buf.length) return [[address, []]];
    let tags;
    [tags, pos] = oscRead(buf, pos);
    const args = [];
    for (const t of tags.slice(1)) {
        if (t === 'i') { args.push(buf.readInt32BE(pos)); pos += 4; }
        else if (t === 'f') { args.push(buf.readFloatBE(pos)); pos += 4; }
        else if (t === 's') { let s; [s, pos] = oscRead(buf, pos); args.push(s); }
        else if (t === 'T') args.push(true);
        else if (t === 'F') args.push(false);
        else break;
    }
    return [[address, args]];
}

/** „/n“ und „\n“ (als Text getippt) werden zu echten Zeilenumbrüchen in der Chatbox */
function chatNewlines(t) { return str(t).replace(/https?:\/\/\S+|\/n|\\n/g, m => (m.length === 2 ? '\n' : m)).replace(/\n{3,}/g, '\n\n'); }
/** Lange Nachricht an Wortgrenzen in Teile ≤ max Zeichen schneiden, mit (1/3)-Markierung */
function splitChat(text, max) {
    const words = Array.from(text).join('').split(/(\s+)/);
    const parts = [];
    let cur = '';
    const lim = max - 6;
    for (const w of words) {
        if (Array.from(cur + w).length > lim) {
            if (cur.trim()) parts.push(cur.trim());
            cur = Array.from(w).length > lim ? '' : w.trimStart();
            if (Array.from(w).length > lim) { const a = Array.from(w); for (let i = 0; i < a.length; i += lim) parts.push(a.slice(i, i + lim).join('')); }
        } else cur += w;
    }
    if (cur.trim()) parts.push(cur.trim());
    return parts.length > 1 ? parts.map((p, i) => p + ' (' + (i + 1) + '/' + parts.length + ')') : parts;
}

class Osc {
    constructor() { this.sock = dgram.createSocket('udp4'); this.sock.unref(); this.host = '127.0.0.1'; this.port = 9000; this.lastChat = 0; this.chain = Promise.resolve(); this.listener = null; this.params = {}; this.avatarId = ''; this.lastPacket = 0; this.listenError = ''; this.cam = {}; this.onMessage = null; }
    configure(host, port) { this.host = host || '127.0.0.1'; this.port = int(port) || 9000; }
    send(address, ...args) { return new Promise((res, rej) => this.sock.send(oscEncode(address, ...args), this.port, this.host, e => (e ? rej(e) : res()))); }
    chatbox(text, sound = true) {
        // VRChat drosselt die Chatbox – Nachrichten nacheinander mit 1,6 s Abstand
        this.chain = this.chain.then(async () => {
            const wait = this.lastChat + 1600 - Date.now();
            if (wait > 0) await U.sleep(wait);
            await this.send('/chatbox/input', str(text).replace(/\r/g, '').slice(0, 144), true, !!sound);
            this.lastChat = Date.now();
        });
        return this.chain;
    }
    async run(kind, p) {
        if (kind === 'chatbox') return this.chatbox(p.text, p.sound !== false);
        if (kind === 'typing') return this.send('/chatbox/typing', !!p.on);
        if (kind === 'param') {
            const v = p.type === 'bool' ? !!p.value : p.type === 'int' ? int(p.value) : { float: Number(p.value) };
            return this.send('/avatar/parameters/' + str(p.name).replace(/^\/+/, ''), v);
        }
        if (kind === 'input') {
            await this.send('/input/' + p.name, int(p.value === undefined ? 1 : p.value));
            if (p.pulse !== false && int(p.value === undefined ? 1 : p.value)) { await U.sleep(120); await this.send('/input/' + p.name, 0); }
            return;
        }
        throw new Error('Unbekannte OSC-Art: ' + kind);
    }
    listen(port) {
        this.stopListen();
        this.listenError = '';
        const s = dgram.createSocket('udp4');
        s.on('error', e => { this.listenError = 'Port ' + port + ' ist belegt (läuft schon ein anderes OSC-Programm?): ' + e.message; try { s.close(); } catch (x) { /* egal */ } this.listener = null; });
        s.on('message', msg => {
            let list = [];
            try { list = oscDecode(msg); } catch (e) { return; }
            this.lastPacket = Date.now();
            for (const [address, args] of list) {
                if (address === '/avatar/change' && args.length) { this.avatarId = str(args[0]); this.params = {}; }
                else if (address.startsWith('/avatar/parameters/') && args.length) {
                    const v = args[0];
                    this.params[address.slice(19)] = typeof v === 'number' && !Number.isInteger(v) ? Math.round(v * 1000) / 1000 : v;
                }
                else if (address.startsWith('/usercamera/') && args.length) this.cam[address.slice(12)] = args.length === 1 ? args[0] : args;
                if (this.onMessage) { try { this.onMessage(address, args); } catch (e) { /* egal */ } }
            }
        });
        s.bind(int(port) || 9001, '127.0.0.1');
        s.unref();
        this.listener = s;
    }
    stopListen() { if (this.listener) { try { this.listener.close(); } catch (e) { /* egal */ } this.listener = null; } }
    get active() { return Date.now() - this.lastPacket < 120000; }
}

// =============================== Speicherung & Auswertung ===============================
class GameLog {
    constructor(db, opts = {}) {
        this.db = db;
        this.state = new SessionState();
        this.osc = new Osc();
        this.timer = null;
        this.watcher = null;
        this.lastRot = 0;
        this.onEvent = opts.onEvent || null;
        this.applySettings();
    }
    cfg() {
        const d = { log_dir: '', osc_host: '127.0.0.1', osc_send_port: 9000, osc_listen_port: 9001, osc_listen: true, logs: true, rotator_enabled: false,
            rotator_text: '🕒 {zeit} · 🌐 {welt} · 👥 {spieler}', rotator_seconds: 30, rotator_texts: '', rotator_mode: 'seq', rotator_only_game: false };
        try { return Object.assign(d, JSON.parse(this.db.setting('client_cfg', '{}'))); } catch (e) { return d; }
    }
    saveCfg(c) {
        const cur = this.cfg();
        const n = Object.assign(cur, {
            log_dir: cut(str(c.log_dir ?? cur.log_dir).trim(), 400), osc_host: /^[\w.-]{1,100}$/.test(str(c.osc_host)) ? c.osc_host : cur.osc_host,
            osc_send_port: Math.min(65535, Math.max(1, int(c.osc_send_port) || cur.osc_send_port)), osc_listen_port: Math.min(65535, Math.max(1, int(c.osc_listen_port) || cur.osc_listen_port)),
            osc_listen: c.osc_listen !== undefined ? !!c.osc_listen && c.osc_listen !== '0' : cur.osc_listen, logs: c.logs !== undefined ? !!c.logs && c.logs !== '0' : cur.logs,
            rotator_enabled: c.rotator_enabled !== undefined ? !!c.rotator_enabled && c.rotator_enabled !== '0' : cur.rotator_enabled,
            rotator_text: c.rotator_text !== undefined ? cut(str(c.rotator_text), 200) : cur.rotator_text, rotator_seconds: Math.max(5, Math.min(3600, int(c.rotator_seconds) || cur.rotator_seconds)),
            rotator_texts: c.rotator_texts !== undefined ? cut(str(c.rotator_texts).replace(/\r/g, ''), 4000) : cur.rotator_texts,
            rotator_mode: c.rotator_mode !== undefined ? (['seq', 'random'].includes(str(c.rotator_mode)) ? str(c.rotator_mode) : 'seq') : cur.rotator_mode,
            rotator_only_game: c.rotator_only_game !== undefined ? !!c.rotator_only_game && c.rotator_only_game !== '0' : cur.rotator_only_game });
        this.db.setSetting('client_cfg', JSON.stringify(n));
        this.applySettings();
        return n;
    }
    logDir() { return this.cfg().log_dir || defaultLogDir(); }
    applySettings() {
        const c = this.cfg();
        this.osc.configure(c.osc_host, c.osc_send_port);
        if (c.osc_listen) this.osc.listen(c.osc_listen_port); else this.osc.stopListen();
        this.watcher = c.logs ? new LogWatcher(this.logDir(), new LogParser(this.state)) : null;
    }
    start(intervalMs = 1000) {
        this.stop();
        this.timer = setInterval(() => { try { this.tick(); } catch (e) { /* nie abstürzen */ } }, intervalMs);
        if (this.timer.unref) this.timer.unref();
        this.tick();
    }
    stop() { if (this.timer) clearInterval(this.timer); this.timer = null; }
    tick() {
        if (this.watcher) {
            const ev = this.watcher.poll();
            if (ev.length) { this.store(ev); if (this.onEvent) ev.forEach(e => this.onEvent(e)); }
        }
        this.saveState();
        const c = this.cfg();
        if (c.rotator_enabled && Date.now() - this.lastRot >= Math.max(5, c.rotator_seconds) * 1000 && (!c.rotator_only_game || this.state.running)) {
            this.lastRot = Date.now();
            this.osc.chatbox(this.rotatorText(this.rotatorNext(c)), false).catch(() => {});
        }
    }
    /** Platzhalter in normalen Chatbox-Nachrichten (nur wenn {…} vorkommt) */
    fillVars(text, more) { return /\{\w+(:[^}]*)?\}/.test(text) ? this.fill(text, more) : text; }
    /** Nächster Rotator-Text: mehrere Texte (eine Zeile = ein Text) der Reihe nach oder zufällig */
    rotatorNext(c) {
        const list = str(c.rotator_texts).split('\n').map(x => x.trim()).filter(Boolean);
        if (!list.length) return c.rotator_text;
        if (c.rotator_mode === 'random') { let i; do { i = Math.floor(Math.random() * list.length); } while (list.length > 1 && i === this.rotIdx); this.rotIdx = i; return list[i]; }
        this.rotIdx = ((this.rotIdx ?? -1) + 1) % list.length;
        return list[this.rotIdx];
    }
    /** Rotator: Platzhalter füllen, /n bzw. \n als Zeilenumbruch, max. 144 Zeichen */
    rotatorText(tpl) { return Array.from(chatNewlines(this.fill(tpl))).slice(0, 144).join(''); }
    /** Platzhalter füllen: {zeit} {welt} {freunde} {countdown:2026-12-24 18:00} {zufall:a|b} … */
    fill(tpl, more) {
        const s = this.state, d = new Date(), dur = s.since ? now() - s.since : 0;
        const extra = this.vars ? this.vars() : {};
        const loc = s.worldId ? require('./util').location(s.worldId + ':' + s.instance) : null;
        const vals = Object.assign({ zeit: d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }), zeit_s: d.toLocaleTimeString('de-DE'), datum: d.toLocaleDateString('de-DE'),
            wochentag: d.toLocaleDateString('de-DE', { weekday: 'long' }), welt: s.worldName || '–', spieler: String(s.players.size), avatar: s.avatar || '–',
            dauer: Math.floor(dur / 3600) + ':' + String(Math.floor(dur / 60) % 60).padStart(2, '0'), name: s.displayName || '', zugang: loc ? loc.access : '–', region: loc ? loc.region : '–',
            uhr: ['🕛', '🕐', '🕑', '🕒', '🕓', '🕔', '🕕', '🕖', '🕗', '🕘', '🕙', '🕚'][d.getHours() % 12] }, extra, more || {});
        let out = str(tpl).replace(/\{(\w+)(?::([^}]*))?\}/g, (m, k, arg) => {
            if (k === 'zufall' && arg) { const o = arg.split('|'); return o[Math.floor(Math.random() * o.length)]; }
            if (k === 'countdown' && arg) {
                const t = new Date(arg.replace(' ', 'T')).getTime();
                if (isNaN(t)) return m;
                let r = Math.max(0, Math.floor((t - Date.now()) / 1000));
                const dd = Math.floor(r / 86400); r %= 86400;
                return (dd ? dd + ' T ' : '') + Math.floor(r / 3600) + ' h ' + String(Math.floor(r / 60) % 60).padStart(2, '0') + ' min';
            }
            return k in vals ? String(vals[k]) : m;
        });
        return out;
    }
    importAll() { if (!this.watcher) return 0; const ev = this.watcher.importAll(); this.store(ev); return ev.length; }

    store(events) {
        const ins = this.db.prepare('INSERT OR IGNORE INTO game_log (user_id, ts, kind, world_id, instance, world_name, player_id, player_name, detail, hash, client_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');
        let stored = 0;
        this.db.tx(() => {
            for (const e of events) {
                if (!KINDS[e.kind]) continue;
                const row = [int(e.ts), e.kind, cut(e.world_id, 64), cut(e.instance, 160), cut(e.world_name, 120), cut(e.player_id, 64), cut(e.player_name, 64), cut(e.detail, 500)];
                const hash = crypto.createHash('sha1').update(row.join('\x1f')).digest('hex');
                stored += ins.run(UID, ...row, hash).changes;
            }
        });
        return stored;
    }
    saveState() {
        const snap = this.state.snapshot();
        Object.assign(snap, { osc: this.osc.active, params: Object.fromEntries(Object.entries(this.osc.params).slice(0, 64)), avatar_id: this.osc.avatarId });
        this.db.prepare('INSERT OR REPLACE INTO client_state (user_id, client_id, updated, state) VALUES (?, 0, ?, ?)').run(UID, now(), JSON.stringify(snap));
    }
    stateRow() {
        const r = this.db.prepare('SELECT * FROM client_state WHERE user_id = ?').get(UID);
        if (!r) return null;
        let s = {};
        try { s = JSON.parse(r.state) || {}; } catch (e) { s = {}; }
        return Object.assign(s, { updated: int(r.updated), fresh: int(r.updated) > now() - 90, osc_error: this.osc.listenError });
    }
    list(f) {
        const where = ['user_id = ?'], args = [UID];
        if (f.kind && KINDS[f.kind]) { where.push('kind = ?'); args.push(f.kind); }
        const q = str(f.q).trim();
        if (q) { where.push('(world_name LIKE ? OR player_name LIKE ? OR detail LIKE ?)'); args.push('%' + q + '%', '%' + q + '%', '%' + q + '%'); }
        if (f.player) { where.push('player_id = ?'); args.push(str(f.player)); }
        if (int(f.before)) { where.push('id < ?'); args.push(int(f.before)); }
        if (/^\d{4}-\d{2}-\d{2}$/.test(str(f.day))) { const d = Math.floor(new Date(f.day + 'T00:00:00').getTime() / 1000); where.push('ts >= ? AND ts < ?'); args.push(d, d + 86400); }
        return this.db.prepare('SELECT id, ts, kind, world_id, instance, world_name, player_id, player_name, detail FROM game_log WHERE ' + where.join(' AND ') + ' ORDER BY ts DESC, id DESC LIMIT 100').all(...args);
    }
    players(days, q = '') {
        const args = [UID, now() - days * 86400];
        let extra = '';
        if (q) { extra = ' AND player_name LIKE ?'; args.push('%' + q + '%'); }
        const rows = this.db.prepare(`SELECT player_id, MAX(player_name) AS name, COUNT(*) AS meets, MAX(ts) AS last, MIN(ts) AS first, COUNT(DISTINCT world_id) AS worlds
                                      FROM game_log WHERE user_id = ? AND kind = 'player_join' AND ts >= ? AND player_name <> ''${extra}
                                      GROUP BY player_id, CASE WHEN player_id = '' THEN player_name ELSE '' END ORDER BY meets DESC, last DESC LIMIT 300`).all(...args);
        const friends = new Set(this.db.prepare('SELECT vrc_id FROM friends WHERE user_id = ?').column(UID));
        const time = {}, open = {};
        for (const e of this.db.prepare("SELECT ts, kind, player_id, player_name FROM game_log WHERE user_id = ? AND ts >= ? AND kind IN ('player_join', 'player_leave', 'world_leave', 'vrc_quit') ORDER BY ts, id").all(UID, now() - days * 86400)) {
            const k = e.player_id || 'n:' + e.player_name;
            if (e.kind === 'player_join') open[k] = e.ts;
            else if (e.kind === 'player_leave') { if (k in open) { time[k] = (time[k] || 0) + Math.min(43200, e.ts - open[k]); delete open[k]; } }
            else { for (const [ok, t0] of Object.entries(open)) time[ok] = (time[ok] || 0) + Math.min(43200, e.ts - t0); for (const ok of Object.keys(open)) delete open[ok]; }
        }
        return rows.map(r => { const k = r.player_id || 'n:' + r.name; return { id: r.player_id, name: r.name, meets: int(r.meets), last: int(r.last), first: int(r.first), worlds: int(r.worlds), friend: friends.has(r.player_id), seconds: int(time[k]) }; });
    }
    visits(days) {
        const visits = [];
        let cur = null;
        for (const e of this.db.prepare("SELECT ts, kind, world_id, instance, world_name FROM game_log WHERE user_id = ? AND ts >= ? AND kind IN ('world_join', 'world_leave', 'vrc_quit', 'player_join') ORDER BY ts, id").all(UID, now() - days * 86400)) {
            if (e.kind === 'player_join') { if (cur !== null) visits[cur].players++; continue; }
            if (cur !== null && visits[cur].end === 0) visits[cur].end = e.ts;
            cur = null;
            if (e.kind === 'world_join') { visits.push({ world_id: e.world_id, instance: e.instance, name: e.world_name, start: e.ts, end: 0, players: 0 }); cur = visits.length - 1; }
        }
        for (const v of visits) v.seconds = (v.end || Math.min(now(), v.start + 43200)) - v.start;
        return visits.reverse();
    }
    stats() {
        const since = U.startOfToday();
        const today = {};
        for (const r of this.db.prepare('SELECT kind, COUNT(*) AS n FROM game_log WHERE user_id = ? AND ts >= ? GROUP BY kind').all(UID, since)) today[r.kind] = int(r.n);
        const t = this.db.prepare('SELECT COUNT(*) AS n, MIN(ts) AS first FROM game_log WHERE user_id = ?').get(UID);
        let play = 0;
        for (const v of this.visits(1)) if (v.start >= since || v.end >= since) play += v.seconds - Math.max(0, since - v.start);
        return { today: { worlds: today.world_join || 0, players: today.player_join || 0, screenshots: today.screenshot || 0, avatars: today.avatar || 0, videos: today.video || 0, playtime: Math.max(0, play) },
            total: int(t.n), since: int(t.first), people: int(this.db.prepare("SELECT COUNT(DISTINCT CASE WHEN player_id = '' THEN player_name ELSE player_id END) FROM game_log WHERE user_id = ? AND kind = 'player_join'").value(UID)) };
    }
    clear() { return this.db.prepare('DELETE FROM game_log WHERE user_id = ?').run(UID).changes; }
    exportCsv() {
        const q = v => { v = str(v); return /[";\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const lines = ['Zeit;Art;Welt;Welt-ID;Instanz;Spieler;Spieler-ID;Details'];
        for (const r of this.db.prepare('SELECT * FROM game_log WHERE user_id = ? ORDER BY ts, id').all(UID)) {
            const d = new Date(r.ts * 1000);
            lines.push([d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE'), (KINDS[r.kind] || [0, r.kind])[1], r.world_name, r.world_id, r.instance, r.player_name, r.player_id, r.detail].map(q).join(';'));
        }
        return '﻿' + lines.join('\r\n') + '\r\n';
    }

    // ---- OSC-Befehle (sofort ausgeführt, Verlauf in osc_queue) ----
    async oscSend(kind, input) {
        let payload;
        switch (kind) {
            case 'chatbox': {
                const on = v => !!v && v !== '0' && v !== 'false';
                if (on(input.clear)) { payload = { text: '', sound: false }; break; }
                let text = str(input.text).replace(/\r/g, '').replace(/\t/g, ' ').trim();
                if (!text) throw new UserError('Text fehlt.');
                if (input.vars !== false && input.vars !== '0') text = this.fillVars(text, input.vars && typeof input.vars === 'object' ? input.vars : null);
                text = chatNewlines(text);
                const sound = on(input.sound);
                if (Array.from(text).length > 144 && on(input.split)) {
                    const parts = splitChat(text, 144);
                    let last = 0;
                    for (const [i, p] of parts.entries()) last = await this.oscSend('chatbox', { text: p, sound: i === 0 && sound, vars: false, delay: (int(input.delay) || 0) + i * 4 });
                    return last;
                }
                payload = { text: Array.from(text).slice(0, 144).join(''), sound };
                break;
            }
            case 'typing': payload = { on: !!input.on && input.on !== '0' }; break;
            case 'param': {
                const name = str(input.name).trim();
                if (!/^[\w/\-. ]{1,64}$/u.test(name)) throw new UserError('Ungültiger Parametername.');
                const type = str(input.type || 'bool');
                let v = input.value;
                if (type === 'bool') v = ['1', 'true', 'an', 'on', 'ja'].includes(str(v).toLowerCase()) || v === true;
                else if (type === 'int') { v = int(v); if (v < 0 || v > 255) throw new UserError('Ganzzahl-Parameter: 0 bis 255.'); }
                else if (type === 'float') { v = parseFloat(str(v).replace(',', '.')) || 0; if (v < -1 || v > 1) throw new UserError('Kommazahl-Parameter: -1 bis 1.'); }
                else throw new UserError('Typ: bool, int oder float.');
                payload = { name, type, value: v };
                break;
            }
            case 'input':
                if (!INPUTS.includes(str(input.name))) throw new UserError('Diese Eingabe wird nicht unterstützt.');
                payload = { name: input.name, value: int(input.value === undefined ? 1 : input.value) ? 1 : 0, pulse: input.pulse !== false && input.pulse !== '0' && input.pulse !== '' };
                break;
            default: throw new UserError('Unbekannte OSC-Art.');
        }
        const delay = Math.max(0, Math.min(3600, int(input.delay) || 0));
        const id = this.db.prepare('INSERT INTO osc_queue (user_id, created, kind, payload, status) VALUES (?, ?, ?, ?, ?)').run(UID, now(), kind, JSON.stringify(payload), delay ? 'pending' : 'sent').lastId;
        const exec = async () => {
            try { await this.osc.run(kind, payload); this.db.prepare("UPDATE osc_queue SET status = 'done', done = ? WHERE id = ?").run(now(), id); }
            catch (e) { this.db.prepare("UPDATE osc_queue SET status = 'failed', done = ?, result = ? WHERE id = ?").run(now(), cut(e.message, 200), id); }
        };
        if (delay) { const t = setTimeout(() => { this.db.prepare("UPDATE osc_queue SET status = 'sent' WHERE id = ?").run(id); exec(); }, delay * 1000); if (t.unref) t.unref(); }
        else await exec();
        this.db.prepare('DELETE FROM osc_queue WHERE user_id = ? AND created < ?').run(UID, now() - 7 * 86400);
        return id;
    }
    oscRecent() {
        return this.db.prepare('SELECT id, created, kind, payload, status, result FROM osc_queue WHERE user_id = ? ORDER BY id DESC LIMIT 15').all(UID)
            .map(r => ({ id: r.id, created: r.created, kind: r.kind, payload: JSON.parse(r.payload), status: r.status, result: r.result }));
    }
    presets() { return this.db.prepare('SELECT id, label, kind, payload FROM osc_presets WHERE user_id = ? ORDER BY sort, id').all(UID).map(r => ({ id: r.id, label: r.label, kind: r.kind, payload: JSON.parse(r.payload) || {} })); }
    presetSave(label, kind, input) {
        label = cut(str(label).trim(), 30);
        if (!label) throw new UserError('Bitte eine Beschriftung angeben.');
        if (!['chatbox', 'param', 'input'].includes(kind)) throw new UserError('Unbekannte Art.');
        if (int(this.db.prepare('SELECT COUNT(*) FROM osc_presets WHERE user_id = ?').value(UID)) >= 40) throw new UserError('Maximal 40 Schnellknöpfe.');
        const payload = {};
        for (const k of ['text', 'name', 'type', 'value', 'sound', 'pulse']) if (input[k] !== undefined) payload[k] = input[k];
        this.db.prepare('INSERT INTO osc_presets (user_id, label, kind, payload, sort) VALUES (?, ?, ?, ?, ?)').run(UID, label, kind, JSON.stringify(payload), now());
    }
    presetDelete(id) { this.db.prepare('DELETE FROM osc_presets WHERE id = ? AND user_id = ?').run(int(id), UID); }
}

module.exports = { chatNewlines, splitChat, GameLog, LogParser, LogWatcher, SessionState, Osc, oscEncode, oscDecode, KINDS, defaultLogDir };
