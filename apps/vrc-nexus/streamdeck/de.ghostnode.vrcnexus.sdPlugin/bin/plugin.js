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
            if (!s.title) setTitle(ctx, f ? f.name.slice(0, 10) + '\n' + (f.state === 'online' ? (f.world || 'im Spiel').slice(0, 10) : f.state === 'active' ? 'online' : 'offline') : '?');
            break;
        }
        case 'overlay': setState(ctx, st.overlay && st.overlay.visible ? 1 : 0); break;
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
        case 'friend': return act('friend', { id: s.id || '', op: s.op || 'join' });
        case 'overlay': return act('overlay', {});
        case 'speech': return act('speech', { op: s.mode === 'hold' ? 'start' : 'toggle' });
        case 'dial': return dialPress(ctx, s);
    }
    return null;
}
async function keyUp(ctx, action, s) {
    const kind = action.slice(PREFIX.length);
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
        case 'didReceiveSettings': if (contexts.has(context)) { contexts.get(context).settings = settings; render(context); } break;
        case 'didReceiveGlobalSettings': globals = Object.assign({ port: 17865, token: '' }, settings); poll(); break;
        case 'keyDown':
            keyDown(context, action, settings).then(r => { if (r && !['param', 'rotator', 'macro', 'speech', 'overlay'].includes(action.slice(PREFIX.length))) send('showOk', context); setTimeout(poll, 400); })
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
module.exports = { MiniWs, onMessage, keyDown, render, dialRotate, _test: { contexts, setGlobals: g => { globals = g; }, get state() { return lastState; }, poll } };
