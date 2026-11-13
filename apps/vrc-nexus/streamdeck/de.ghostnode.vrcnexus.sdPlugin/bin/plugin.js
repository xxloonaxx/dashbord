'use strict';
/*
 * VRC Nexus – Stream-Deck-Plugin (ohne Fremdpakete).
 * Spricht mit der Stream-Deck-App (WebSocket) und mit VRC Nexus über die lokale Schnittstelle (http://127.0.0.1:<Port>/api, Kopf X-Nexus-Token).
 * Start durch Stream Deck:  node plugin.js -port 12345 -pluginUUID … -registerEvent registerPlugin -info {…}
 */
const http = require('http');
const crypto = require('crypto');

const PREFIX = 'de.ghostnode.vrcnexus.';
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

// ---------------- Tasten ----------------
async function keyDown(ctx, action, s) {
    const kind = action.slice(PREFIX.length);
    switch (kind) {
        case 'status': return act('status', { status: s.status || 'join me', desc: s.desc || '' });
        case 'chatbox':
            if (s.mode === 'preset') return act('preset', { id: s.preset });
            return act('chatbox', { text: s.text || '', sound: !!s.sound });
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
    }
    return null;
}
async function keyUp(ctx, action, s) {
    const kind = action.slice(PREFIX.length);
    if (kind === 'param' && s.mode === 'hold' && s.name) return act('param', { name: s.name, type: 'bool', value: false });
    if (kind === 'input' && s.hold) return act('input', { name: s.input || 'Jump', value: 0 });
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
            keyDown(context, action, settings).then(r => { if (r && !['param', 'rotator'].includes(action.slice(PREFIX.length))) send('showOk', context); setTimeout(poll, 400); })
                .catch(e => { lastError = e.message; send('showAlert', context); send('logMessage', undefined, { message: 'VRC Nexus: ' + e.message }); });
            break;
        case 'keyUp': keyUp(context, action, settings).catch(() => send('showAlert', context)); break;
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
module.exports = { MiniWs, onMessage, keyDown, render, _test: { contexts, setGlobals: g => { globals = g; }, get state() { return lastState; }, poll } };
