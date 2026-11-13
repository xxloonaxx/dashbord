'use strict';
/*
 * Discord Rich Presence über die lokale Discord-IPC (Named Pipe \\?\pipe\discord-ipc-N bzw. Unix-Socket).
 * Kein Discord-Konto-Zugriff: Discord zeigt nur den Status an, den diese App setzt.
 */
const net = require('net');
const os = require('os');
const path = require('path');

function pipePath(i) {
    if (process.platform === 'win32') return '\\\\?\\pipe\\discord-ipc-' + i;
    const base = process.env.DISCORD_IPC_DIR || process.env.XDG_RUNTIME_DIR || process.env.TMPDIR || process.env.TMP || process.env.TEMP || os.tmpdir();
    return path.join(base, 'discord-ipc-' + i);
}

function frame(op, data) {
    const json = Buffer.from(JSON.stringify(data));
    const h = Buffer.alloc(8);
    h.writeInt32LE(op, 0);
    h.writeInt32LE(json.length, 4);
    return Buffer.concat([h, json]);
}

class DiscordRpc {
    constructor() { this.sock = null; this.clientId = ''; this.ready = false; this.buf = Buffer.alloc(0); this.last = ''; this.connecting = false; this.error = ''; this.nextTry = 0; }

    async connect(clientId) {
        if (this.connecting || (this.sock && this.clientId === clientId)) return;
        if (Date.now() < this.nextTry) return;
        this.close();
        this.clientId = clientId;
        this.connecting = true;
        for (let i = 0; i < 10; i++) {
            const ok = await new Promise(resolve => {
                const s = net.createConnection(pipePath(i));
                s.once('connect', () => { this.attach(s); resolve(true); });
                s.once('error', () => { s.destroy(); resolve(false); });
            });
            if (ok) break;
        }
        this.connecting = false;
        if (!this.sock) { this.error = 'Discord läuft nicht (oder blockiert die Verbindung).'; this.nextTry = Date.now() + 30000; }
    }
    attach(s) {
        this.sock = s;
        this.error = '';
        s.on('data', d => this.onData(d));
        s.on('close', () => { if (this.sock === s) { this.sock = null; this.ready = false; this.last = ''; } });
        s.on('error', () => {});
        s.write(frame(0, { v: 1, client_id: this.clientId }));
    }
    onData(d) {
        this.buf = Buffer.concat([this.buf, d]);
        while (this.buf.length >= 8) {
            const op = this.buf.readInt32LE(0), len = this.buf.readInt32LE(4);
            if (this.buf.length < 8 + len) break;
            let msg = null;
            try { msg = JSON.parse(this.buf.slice(8, 8 + len).toString()); } catch (e) { msg = null; }
            this.buf = this.buf.slice(8 + len);
            if (op === 2) { this.error = (msg && msg.message) || 'Discord hat die Verbindung beendet.'; this.close(); return; }
            if (msg && msg.evt === 'READY') { this.ready = true; if (this.pending) { const p = this.pending; this.pending = null; this.setActivity(p); } }
            if (msg && msg.evt === 'ERROR') this.error = (msg.data && msg.data.message) || 'Fehler';
        }
    }
    /** activity = null löscht den Status */
    setActivity(activity) {
        const key = JSON.stringify(activity);
        if (!this.sock || !this.ready) { this.pending = activity; return false; }
        if (key === this.last) return true;
        this.last = key;
        this.sock.write(frame(1, { cmd: 'SET_ACTIVITY', args: { pid: process.pid, activity }, nonce: String(Date.now()) + Math.random() }));
        return true;
    }
    close() { if (this.sock) { try { this.sock.destroy(); } catch (e) { /* egal */ } } this.sock = null; this.ready = false; this.last = ''; }
    status() { return this.sock && this.ready ? 'verbunden' : this.error || 'nicht verbunden'; }
}

module.exports = { DiscordRpc, pipePath, frame };
