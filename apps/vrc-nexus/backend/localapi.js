'use strict';
/*
 * Lokale Schnittstelle (nur 127.0.0.1) für das Stream-Deck-Plugin und eigene Skripte.
 *   GET  /api/state            → Status, Freunde online, Instanz, OSC, Rotator
 *   POST /api/action  {action} → status, chatbox, preset, param, toggle, input, rotator, launch, open, rejoin, typing
 * Jede Anfrage braucht den Zugangsschlüssel im Kopf „X-Nexus-Token“ (oder ?token=).
 */
const http = require('http');
const crypto = require('crypto');
const { str, int, UserError } = require('./util');

class LocalApi {
    constructor(tools) { this.tools = tools; this.server = null; this.port = 0; this.error = ''; }

    apply() {
        const s = this.tools.settings;
        const want = s.get('int.api') ? s.get('int.api_port') : 0;
        if (want === this.port && (this.server || !want)) return;
        this.stop();
        if (!want) return;
        this.server = http.createServer((req, res) => this.handle(req, res).catch(e => this.send(res, e instanceof UserError ? 400 : 500, { error: e.message || 'Fehler' })));
        this.server.on('error', e => { this.error = e.code === 'EADDRINUSE' ? 'Port ' + want + ' ist schon belegt.' : e.message; this.server = null; this.port = 0; });
        this.server.listen(want, '127.0.0.1', () => { this.error = ''; });
        this.port = want;
    }
    stop() { if (this.server) this.server.close(); this.server = null; this.port = 0; }
    status() { return this.server ? 'läuft auf 127.0.0.1:' + this.port : this.error || 'aus'; }

    send(res, code, data) {
        res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
        res.end(JSON.stringify(data));
    }
    async handle(req, res) {
        const url = new URL(req.url, 'http://127.0.0.1');
        // Nur lokale Programme, keine Webseiten (Browser schicken immer Origin mit)
        if (req.headers.origin) return this.send(res, 403, { error: 'Nicht erlaubt.' });
        const token = str(req.headers['x-nexus-token'] || url.searchParams.get('token'));
        const want = Buffer.from(str(this.tools.settings.get('int.api_token')));
        const got = Buffer.from(token);
        if (!want.length || got.length !== want.length || !crypto.timingSafeEqual(got, want)) return this.send(res, 401, { error: 'Falscher Zugangsschlüssel.' });
        if (req.method === 'GET' && url.pathname === '/api/state') return this.send(res, 200, this.tools.apiState());
        if (req.method === 'POST' && url.pathname === '/api/action') {
            let body = '';
            for await (const c of req) { body += c; if (body.length > 20000) throw new UserError('Zu groß.'); }
            let data = {};
            try { data = JSON.parse(body || '{}'); } catch (e) { throw new UserError('Ungültiges JSON.'); }
            return this.send(res, 200, await this.tools.apiAction(str(data.action), data));
        }
        return this.send(res, 404, { error: 'Unbekannt.' });
    }
}

module.exports = { LocalApi, int };
