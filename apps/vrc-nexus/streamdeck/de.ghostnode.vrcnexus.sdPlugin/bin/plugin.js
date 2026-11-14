'use strict';
/*
 * VRC Nexus – Stream-Deck-Plugin (ohne Fremdpakete).
 * Spricht mit der Stream-Deck-App (WebSocket) und mit VRC Nexus über die lokale Schnittstelle (http://127.0.0.1:<Port>/api, Kopf X-Nexus-Token).
 * Start durch Stream Deck:  node plugin.js -port 12345 -pluginUUID … -registerEvent registerPlugin -info {…}
 */
const http = require('http');
const crypto = require('crypto');

const PREFIX = 'de.ghostnode.vrcnexus.';
const CAM_TITLE = { Capture: 'Foto', CaptureDelayed: 'Timer', Close: 'Kamera\nzu', burst: 'Serie', preset: 'Vorlage', Mode: 'Modus' };
const STATUS = { 'join me': 'Join Me', active: 'Online', 'ask me': 'Ask Me', busy: 'Beschäftigt' };
const MOD_NAME = { status: 'Status', window: 'Fenster', media: 'Musik', vrchat: 'Radar', time: 'Uhrzeit', weather: 'Wetter', system: 'Rechner', network: 'Netzwerk', twitch: 'Twitch', heart: 'Puls',
    countdown: 'Countdown', friends: 'Freunde', marquee: 'Laufschrift', anim: 'Animation', battery: 'Akku', ping: 'Ping' };

// ---------------- kleiner WebSocket-Client (RFC 6455, nur Text) ----------------
class MiniWs {
    constructor(url) {
        this.handlers = {};
        const u = new URL(url);
        const key = crypto.randomBytes(16).toString('base64');
        const req = http.request({ host: u.hostname, port: u.port, path: u.pathname || '/', headers: { Connection: 'Upgrade', Upgrade: 'websocket', 'Sec-WebSocket-Version': '13', 'Sec-WebSocket-Key': key } });
        req.on('upgrade', (res, sock, head) => {
            this.sock = sock;
            this.buf = head && head.length ? Buffer.from(head) : Buffer.alloc(0);
            sock.on('data', d => { this.buf = Buffer.concat([this.buf, d]); this.parse(); });
            sock.on('close', () => this.emit('close'));
            sock.on('error', () => this.emit('close'));
            this.emit('open');
            if (this.buf.length) this.parse();
        });
        req.on('error', e => this.emit('close', e));
        req.end();
    }
    on(ev, fn) { this.handlers[ev] = fn; }
    emit(ev, x) { if (this.handlers[ev]) this.handlers[ev](x); }
    parse() {
        while (this.buf.length >= 2) {
            const op = this.buf[0] & 0x0f, masked = this.buf[1] & 0x80;
            let len = this.buf[1] & 0x7f, p = 2;
            if (len === 126) { if (this.buf.length < 4) return; len = this.buf.readUInt16BE(2); p = 4; }
            else if (len === 127) { if (this.buf.length < 10) return; len = Number(this.buf.readBigUInt64BE(2)); p = 10; }
            const mk = masked ? this.buf.slice(p, p + 4) : null;
            if (masked) p += 4;
            if (this.buf.length < p + len) return;
            let data = this.buf.slice(p, p + len);
            if (mk) data = Buffer.from(data.map((b, i) => b ^ mk[i % 4]));
            this.buf = this.buf.slice(p + len);
            if (op === 1) this.emit('message', data.toString('utf8'));
            else if (op === 9) this.frame(10, data);
            else if (op === 8) { try { this.sock.end(); } catch (e) { /* egal */ } }
        }
    }
    frame(op, payload) {
        const len = payload.length;
        const head = len < 126 ? Buffer.from([0x80 | op, 0x80 | len]) : len < 65536 ? Buffer.from([0x80 | op, 0x80 | 126, len >> 8, len & 255]) : (() => { const b = Buffer.alloc(10); b[0] = 0x80 | op; b[1] = 0x80 | 127; b.writeBigUInt64BE(BigInt(len), 2); return b; })();
        const mask = crypto.randomBytes(4);
        const body = Buffer.from(payload.map((b, i) => b ^ mask[i % 4]));
        this.sock.write(Buffer.concat([head, mask, body]));
    }
    send(text) { if (this.sock) this.frame(1, Buffer.from(text, 'utf8')); }
}

// ---------------- Argumente ----------------
const args = {};
for (let i = 2; i < process.argv.length; i += 2) args[process.argv[i].replace(/^-+/, '')] = process.argv[i + 1];
const PORT = args.port, UUID = args.pluginUUID, REGISTER = args.registerEvent || 'registerPlugin';

let ws = null;
let globals = { port: 17865, token: '' };
const contexts = new Map();                   // context → { action, settings }
let lastState = null, lastError = '', pollTimer = null;

function send(event, context, payload) { if (ws) ws.send(JSON.stringify(Object.assign({ event, context }, payload !== undefined ? { payload } : {}))); }
const setTitle = (ctx, title) => send('setTitle', ctx, { title: String(title), target: 0 });
const setState = (ctx, state) => send('setState', ctx, { state });

/** Anfrage an VRC Nexus */
async function nexus(method, path, body) {
    if (!globals.token) throw new Error('Kein Zugangsschlüssel – in den Tasten-Einstellungen eintragen.');
    const res = await fetch('http://127.0.0.1:' + (Number(globals.port) || 17865) + '/api/' + path, {
        method, headers: { 'X-Nexus-Token': globals.token, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(8000),
    }).catch(() => { throw new Error('VRC Nexus läuft nicht oder die lokale Schnittstelle ist aus (Einstellungen → Integrationen).'); });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Fehler ' + res.status);
    return data;
}
const act = (action, extra) => nexus('POST', 'action', Object.assign({ action }, extra || {}));

// Text für die kleine Taste: Wörter umbrechen, höchstens `lines` Zeilen à `w` Zeichen
function wrapKey(text, w = 9, lines = 3) {
    const out = [];
    for (const para of String(text || '').split('\n')) {
        let cur = '';
        for (let word of para.split(/\s+/).filter(Boolean)) {
            while (Array.from(word).length > w) { if (cur) { out.push(cur); cur = ''; } out.push(Array.from(word).slice(0, w).join('')); word = Array.from(word).slice(w).join(''); }
            if (!cur) cur = word; else if (Array.from(cur + ' ' + word).length <= w) cur += ' ' + word; else { out.push(cur); cur = word; }
        }
        out.push(cur);
    }
    const l = out.filter((x, i) => x || i === 0);
    if (l.length > lines) { l.length = lines; l[lines - 1] = Array.from(l[lines - 1]).slice(0, w - 1).join('') + '…'; }
    return l.join('\n');
}
const fillVars = (tpl, vars) => String(tpl || '').replace(/\/n/g, '\n').replace(/\{(\w+)\}/g, (m, k) => vars && vars[k] !== undefined && vars[k] !== '' ? vars[k] : '–');

// ---------------- Anzeige aktualisieren ----------------
function render(ctx) {
    const c = contexts.get(ctx);
    if (!c) return;
    const s = c.settings || {}, st = lastState, kind = c.action.slice(PREFIX.length);
    if (!st) { if (['friends', 'players'].includes(kind)) setTitle(ctx, '–'); return; }
    switch (kind) {
        case 'friends': {
            const f = st.friends || {};
            setTitle(ctx, s.show === 'online' ? f.online + '\nonline' : s.show === 'both' ? f.in_game + ' / ' + f.online + '\nim Spiel / on' : f.in_game + '\nim Spiel');
            break;
        }
        case 'players': setTitle(ctx, st.game && st.game.running ? st.game.players + '\nSpieler' : 'VRChat\naus'); break;
        case 'status': {
            const cur = st.me && st.me.status;
            setState(ctx, cur === (s.status || 'join me') ? 1 : 0);
            if (!s.title) setTitle(ctx, STATUS[s.status || 'join me']);
            break;
        }
        case 'rotator': setState(ctx, st.rotator ? 1 : 0); break;
        case 'notifs': setTitle(ctx, (st.notifs || 0) + '\nneu'); setState(ctx, st.notifs ? 1 : 0); break;
        case 'camera': if (!s.title) setTitle(ctx, CAM_TITLE[s.cmd || 'Capture'] || ''); setState(ctx, s.cmd === 'burst' && st.cam && st.cam.burst ? 1 : 0); break;
        case 'macro': setState(ctx, (st.running_macros || []).includes(s.id) ? 1 : 0); if (!s.title) setTitle(ctx, ((st.macros || []).find(m => m.id === s.id) || {}).name || 'Makro'); break;
        case 'friend': {
            const f = (st.friend_list || []).find(x => x.id === s.id);
            setState(ctx, f && f.state === 'online' ? 1 : 0);
            if (!s.title) {
                const sub = f ? (f.state === 'online' ? (f.world || (f.access ? f.access : 'privat')) : f.state === 'active' ? 'online' : 'offline') : '';
                setTitle(ctx, !f ? '?' : s.label === 'none' ? '' : s.label === 'name' ? f.name.slice(0, 11) : f.name.slice(0, 11) + '\n' + sub.slice(0, 11));
            }
            if (f && !s.nopic) friendIcon(ctx, f);
            else if (c.img) { c.img = ''; send('setImage', ctx, { image: null, target: 0 }); }
            break;
        }
        case 'overlay': setState(ctx, st.overlay && st.overlay.visible ? 1 : 0); break;
        case 'media': {
            const m = st.media;
            setState(ctx, m && m.status === 'playing' ? 1 : 0);
            if (!s.title) setTitle(ctx, !m ? (s.cmd && s.cmd !== 'toggle' ? (s.cmd === 'next' ? '⏭' : '⏮') : '–') : (s.cmd === 'next' ? '⏭\n' : s.cmd === 'prev' ? '⏮\n' : '') + wrapKey(s.show === 'artist' ? m.artist || m.title : m.title, 9, s.cmd && s.cmd !== 'toggle' ? 2 : 3));
            break;
        }
        case 'quicktext': {
            if (s.title) break;
            const q = st.quick;
            setTitle(ctx, !q ? '–' : s.mode === 'last' ? '↻\n' + wrapKey(q.last || 'Verlauf', 9, 2) : wrapKey(((q.list || []).find(x => x.id === s.id) || {}).name || 'Schnelltext', 9, 3));
            break;
        }
        case 'scene': if (!s.title) setTitle(ctx, wrapKey(((st.scenes || []).find(x => x.id === s.id) || {}).name || 'Szene', 9, 3)); break;
        case 'record': {
            const r = st.recs || {};
            if ((s.op || 'record') === 'play') { const p = r.playing && r.playing.id === s.id; setState(ctx, p ? 1 : 0); if (!s.title) setTitle(ctx, p ? '▶ ' + r.playing.left + ' s' : wrapKey(((r.list || []).find(x => x.id === s.id) || {}).name || 'Aufnahme', 9, 2)); }
            else { setState(ctx, r.recording ? 1 : 0); if (!s.title) setTitle(ctx, r.recording ? '⏺ ' + r.recording.sec + ' s' : 'Aufnahme'); }
            break;
        }
        case 'chatmods': {
            const c = st.chatmods;
            const on = !!c && (s.mod ? !!c.mods[s.mod] : c.on);
            setState(ctx, on ? 1 : 0);
            if (!s.title) setTitle(ctx, (s.mod ? (MOD_NAME[s.mod] || s.mod) : 'Module') + '\n' + (on ? 'an' : 'aus'));
            break;
        }
        case 'info': setTitle(ctx, wrapKey(fillVars(s.text || '{zeit}', st.vars), Number(s.width) || 9, 4)); break;
        case 'speech': setState(ctx, st.speech && st.speech.recording ? 1 : 0); break;
        case 'dial': {
            const k = s.target || 'param';
            let v = null, label = s.name || '', min = -1, max = 1;
            if (k === 'zoom') { v = st.cam && st.cam.zoom; label = 'Zoom'; min = 20; max = 150; }
            else if (k === 'param') { v = (st.osc && st.osc.params || {})[s.name]; if ((s.type || 'float') === 'int') { min = 0; max = 255; } }
            else if (k === 'friends') { v = st.friends ? st.friends.in_game : null; label = 'Freunde'; min = 0; max = Math.max(1, st.friends ? st.friends.total : 1); }
            const n = typeof v === 'boolean' ? (v ? 1 : 0) : Number(v);
            send('setFeedback', ctx, { title: label || 'VRC Nexus', value: v === null || v === undefined ? '–' : String(Math.round(n * 100) / 100), indicator: { value: Number.isFinite(n) ? Math.round((n - min) / (max - min) * 100) : 0 } });
            break;
        }
        case 'param': {
            if ((s.mode || 'toggle') === 'toggle' && s.name) { const v = (st.osc && st.osc.params || {})[s.name]; setState(ctx, v === true || v === 1 ? 1 : 0); }
            break;
        }
    }
}
// ---------- Profilbild als Tastensymbol (mit Statusring) ----------
const pics = new Map();                       // Freund-ID → { b64, mime, at } bzw. { fail, at }
const RING = { online: '#3ddc84', active: '#42a5f5', offline: '#6b7280' };
async function friendPic(id) {
    const c = pics.get(id);
    if (c && Date.now() - c.at < (c.fail ? 120000 : 600000)) return c.fail ? null : c;
    if (c && c.loading) return c.b64 ? c : null;
    pics.set(id, Object.assign({}, c || {}, { loading: true, at: (c && c.at) || 0 }));
    try { const r = await nexus('GET', 'friend_image?id=' + encodeURIComponent(id)); pics.set(id, { b64: r.b64, mime: r.mime, at: Date.now() }); }
    catch (e) { pics.set(id, Object.assign({}, c && c.b64 ? c : {}, { fail: !(c && c.b64), at: Date.now() })); }
    const n = pics.get(id);
    return n && n.b64 ? n : null;
}
function friendSvg(pic, f) {
    const off = f.state === 'offline', col = RING[f.state] || RING.offline;
    const STC = { 'join me': '#42a5f5', active: '#3ddc84', 'ask me': '#ffa726', busy: '#ef5350' };
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="144" height="144" viewBox="0 0 144 144">'
        + '<defs><clipPath id="c"><rect x="8" y="8" width="128" height="128" rx="22"/></clipPath><filter id="g"><feColorMatrix type="saturate" values="0"/></filter>'
        + '<linearGradient id="d" x1="0" y1="0" x2="0" y2="1"><stop offset="0.45" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity="0.8"/></linearGradient></defs>'
        + '<rect width="144" height="144" fill="#0b0f15"/>'
        + '<image x="8" y="8" width="128" height="128" preserveAspectRatio="xMidYMid slice" clip-path="url(#c)"' + (off ? ' filter="url(#g)" opacity="0.55"' : '') + ' xlink:href="data:' + pic.mime + ';base64,' + pic.b64 + '"/>'
        + '<rect x="8" y="8" width="128" height="128" rx="22" fill="url(#d)"/>'
        + '<rect x="5" y="5" width="134" height="134" rx="25" fill="none" stroke="' + col + '" stroke-width="7"/>'
        + (!off && STC[f.status] ? '<circle cx="122" cy="22" r="11" fill="' + STC[f.status] + '" stroke="#0b0f15" stroke-width="4"/>' : '')
        + '</svg>';
}
async function friendIcon(ctx, f) {
    const pic = await friendPic(f.id);
    const c = contexts.get(ctx);
    if (!c || !pic) return;
    const key = f.id + '|' + f.state + '|' + f.status + '|' + pic.at;
    if (c.img === key) return;                // nur bei Änderung neu senden
    c.img = key;
    send('setImage', ctx, { image: 'data:image/svg+xml;base64,' + Buffer.from(friendSvg(pic, f)).toString('base64'), target: 0 });
}

async function poll() {
    if (!contexts.size) return;
    try { lastState = await nexus('GET', 'state'); lastError = ''; }
    catch (e) { lastState = null; lastError = e.message; }
    for (const ctx of contexts.keys()) render(ctx);
}
function schedule() { clearInterval(pollTimer); pollTimer = setInterval(poll, 3000); poll(); }

// ---------------- Drehregler (Stream Deck +) ----------------
async function dialRotate(ctx, s, ticks) {
    const k = s.target || 'param';
    if (k === 'zoom') return act('camera', { cmd: 'step', name: 'Zoom', step: ticks * (Number(s.step) || 5), base: 45 });
    if (k === 'param') { if (!s.name) throw new Error('Parametername fehlt.'); return act('param_step', { name: s.name, type: s.type || 'float', step: ticks * (Number(s.step) || ((s.type || 'float') === 'int' ? 1 : 0.05)) }); }
    if (k === 'exposure') return act('camera', { cmd: 'step', name: 'Exposure', step: ticks * (Number(s.step) || 0.2), base: 0 });
    return null;
}
async function dialPress(ctx, s) {
    const k = s.target || 'param';
    if (k === 'zoom' || k === 'exposure') return act('camera', { cmd: 'Capture' });
    if (k === 'param' && s.name) return act('param', { name: s.name, type: s.type || 'float', value: s.reset ?? '0' });
    if (k === 'friends') return act('open', { tab: 'locations' });
    return null;
}

// ---------------- Tasten ----------------
async function keyDown(ctx, action, s) {
    const kind = action.slice(PREFIX.length);
    switch (kind) {
        case 'status': return act('status', { status: s.status || 'join me', desc: s.desc || '' });
        case 'chatbox':
            if (s.mode === 'preset') return act('preset', { id: s.preset });
            return act('chatbox', { text: s.text || '', sound: !!s.sound, translate: !!s.translate, split: true });
        case 'param': {
            const mode = s.mode || 'toggle';
            if (!s.name) throw new Error('Parametername fehlt.');
            if (mode === 'toggle') { const r = await act('toggle', { name: s.name }); setState(ctx, r.value ? 1 : 0); return r; }
            if (mode === 'hold') return act('param', { name: s.name, type: 'bool', value: true });
            return act('param', { name: s.name, type: s.type || 'bool', value: s.value ?? '1' });
        }
        case 'input': return s.hold ? act('input', { name: s.input || 'Jump', value: 1 }) : act('input', { name: s.input || 'Jump' });
        case 'rotator': { const r = await act('rotator'); setState(ctx, r.value ? 1 : 0); return r; }
        case 'friends': return act('open', { tab: s.tab || 'locations' });
        case 'players': return act('open', { tab: 'players' });
        case 'launch': return act('launch');
        case 'rejoin': return act('rejoin');
        case 'open': return act('open', { tab: s.tab || '' });
        case 'notifs': return act('open', { tab: 'notifs' });
        case 'camera': {
            const cmd = s.cmd || 'Capture';
            if (cmd === 'burst') return act('camera', { cmd, count: s.count || 5, seconds: s.seconds || 2, delay: s.delay || 0 });
            if (cmd === 'preset') return act('camera', { cmd, preset: s.preset || '' });
            if (cmd === 'step') return act('camera', { cmd, name: s.name || 'Zoom', step: s.step || 10 });
            return act('camera', { cmd, value: s.value ?? '' });
        }
        case 'macro': {
            if (!s.id && !s.script) throw new Error('Kein Makro gewählt.');
            const r = act('macro', s.id ? { id: s.id } : { script: s.script });
            setState(ctx, 1);
            return r;
        }
        case 'avatar': return act('avatar', { id: (s.id || '').trim() });
        case 'friend': return null;      // kurz/halten wird in keyDown/keyUp unten unterschieden
        case 'overlay': return act('overlay', {});
        case 'speech': return act('speech', { op: s.mode === 'hold' ? 'start' : 'toggle' });
        case 'dial': return dialPress(ctx, s);
        case 'media': return act('media', { cmd: s.cmd || 'toggle' });
        case 'quicktext': return act('quicktext', s.mode === 'last' ? { mode: 'last' } : { id: s.id || '', index: s.id ? undefined : 0, sound: s.sound !== false });
        case 'scene': if (!s.id) throw new Error('Keine Szene gewählt.'); return act('scene', { id: s.id });
        case 'record': { const r = await act('record', (s.op || 'record') === 'play' ? { op: 'play', id: s.id, speed: s.speed || 1 } : { name: s.name || '', max: s.max || 60 }); setState(ctx, r.value ? 1 : 0); return r; }
        case 'chatmods': { const r = await act('chatmods', s.mod ? { mod: s.mod } : {}); setState(ctx, r.value ? 1 : 0); return r; }
        case 'tts': return act('tts', { text: s.text || '', chatbox: !!s.chatbox });
        case 'info': return s.tab ? act('open', { tab: s.tab }) : null;
    }
    return null;
}
const HOLD_MS = 500;
const holds = new Map();                      // context → { timer, done }
async function friendOp(op, s) {
    if (!s.id) throw new Error('Kein Freund gewählt.');
    if (op === 'none') return null;
    if (op === 'profile') return act('open', { tab: 'friends', user: s.id });
    return act('friend', { id: s.id, op });
}
function friendDown(ctx, s) {
    const h = { done: false };
    h.timer = setTimeout(() => { h.done = true; runFeedback(ctx, friendOp(s.hold_op || 'invite', s)); }, HOLD_MS);
    holds.set(ctx, h);
}
function friendUp(ctx, s) {
    const h = holds.get(ctx);
    holds.delete(ctx);
    if (!h) return;
    clearTimeout(h.timer);
    if (!h.done) runFeedback(ctx, friendOp(s.op || 'smart', s));
}
function runFeedback(ctx, p) {
    Promise.resolve(p).then(r => { if (r) send('showOk', ctx); setTimeout(poll, 400); })
        .catch(e => { lastError = e.message; send('showAlert', ctx); send('logMessage', undefined, { message: 'VRC Nexus: ' + e.message }); });
}
async function keyUp(ctx, action, s) {
    const kind = action.slice(PREFIX.length);
    if (kind === 'friend') return friendUp(ctx, s);
    if (kind === 'param' && s.mode === 'hold' && s.name) return act('param', { name: s.name, type: 'bool', value: false });
    if (kind === 'input' && s.hold) return act('input', { name: s.input || 'Jump', value: 0 });
    if (kind === 'speech' && s.mode === 'hold') return act('speech', { op: 'stop' });
    return null;
}

function onMessage(raw) {
    let m;
    try { m = JSON.parse(raw); } catch (e) { return; }
    const { event, context, action, payload } = m;
    const settings = (payload && payload.settings) || {};
    switch (event) {
        case 'willAppear': contexts.set(context, { action, settings }); render(context); if (contexts.size === 1) schedule(); break;
        case 'willDisappear': contexts.delete(context); if (!contexts.size) clearInterval(pollTimer); break;
        case 'didReceiveSettings': if (contexts.has(context)) { contexts.get(context).settings = settings; contexts.get(context).img = ''; render(context); } break;
        case 'didReceiveGlobalSettings': globals = Object.assign({ port: 17865, token: '' }, settings); poll(); break;
        case 'keyDown':
            if (action === PREFIX + 'friend') { friendDown(context, settings); break; }
            keyDown(context, action, settings).then(r => { if (r && !['param', 'rotator', 'macro', 'speech', 'overlay', 'media', 'record', 'chatmods'].includes(action.slice(PREFIX.length))) send('showOk', context); setTimeout(poll, 400); })
                .catch(e => { lastError = e.message; send('showAlert', context); send('logMessage', undefined, { message: 'VRC Nexus: ' + e.message }); });
            break;
        case 'keyUp': keyUp(context, action, settings).catch(() => send('showAlert', context)); break;
        case 'dialRotate': dialRotate(context, settings, (payload && payload.ticks) || 0).then(() => setTimeout(poll, 250)).catch(e => { send('showAlert', context); send('logMessage', undefined, { message: 'VRC Nexus: ' + e.message }); }); break;
        case 'dialDown': case 'touchTap': dialPress(context, settings).then(() => setTimeout(poll, 300)).catch(() => send('showAlert', context)); break;
        case 'sendToPlugin':
            if (payload && payload.cmd === 'test') {
                nexus('GET', 'state').then(st => send('sendToPropertyInspector', context, { cmd: 'test', ok: true, state: st }))
                    .catch(e => send('sendToPropertyInspector', context, { cmd: 'test', ok: false, error: e.message }));
            }
            break;
        case 'systemDidWakeUp': poll(); break;
    }
}

function connect() {
    const W = globalThis.WebSocket && !process.env.NEXUS_MINIWS ? globalThis.WebSocket : null;
    const sock = W ? new W('ws://127.0.0.1:' + PORT) : new MiniWs('ws://127.0.0.1:' + PORT);
    const onOpen = () => { ws = sock; sock.send(JSON.stringify({ event: REGISTER, uuid: UUID })); send('getGlobalSettings', UUID); };
    if (W) { sock.onopen = onOpen; sock.onmessage = e => onMessage(e.data); sock.onclose = () => process.exit(0); }
    else { sock.on('open', onOpen); sock.on('message', onMessage); sock.on('close', () => process.exit(0)); }
}

if (require.main === module) connect();
module.exports = { MiniWs, onMessage, keyDown, render, dialRotate, friendSvg, wrapKey, fillVars, _test: { pics, holds, contexts, setGlobals: g => { globals = g; }, get state() { return lastState; }, poll } };
