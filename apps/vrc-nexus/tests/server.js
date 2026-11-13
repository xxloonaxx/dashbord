'use strict';
/*
 * Test-/Entwicklungs-Server: stellt Oberfläche + Backend über http://127.0.0.1:<port>/ bereit (ohne Electron).
 *   NEXUS_API_BASE=http://127.0.0.1:8792/api/1 NEXUS_DATA=/tmp/nexus.sqlite node tests/server.js 8110
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { openDatabase } = require('../backend/db');
const { NexusApp, handleWebRequest } = require('../backend/app');

const RENDERER = path.join(__dirname, '..', 'renderer');
const MIME = { '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png' };
const port = +(process.argv[2] || 8110);
const crypt = { encrypt: s => 'plain:' + Buffer.from(s).toString('base64'), decrypt: s => (s.startsWith('plain:') ? Buffer.from(s.slice(6), 'base64').toString() : '{}') };

(async () => {
    const db = await openDatabase(process.env.NEXUS_DATA || path.join(__dirname, 'test.sqlite'));
    const host = {
        userData: process.env.NEXUS_USERDATA || path.join(__dirname, 'userdata'),
        pluginFile: path.join(__dirname, '..', 'streamdeck', 'VRC-Nexus.streamDeckPlugin'),
        openPath: p => console.log('[öffnen]', p), openExternal: u => console.log('[extern]', u), spawn: (e, a) => console.log('[starten]', e, a.join(' ')),
        speak: t => console.log('[vorlesen]', t), showTab: t => console.log('[reiter]', t), setTray: t => { host.tray = t; }, setLoginItem: on => console.log('[autostart]', on),
    };
    const nexus = new NexusApp({ db, crypt, host, notify: (t, b) => console.log('[Benachrichtigung]', t, b) });
    nexus.game.start(1000);
    nexus.tools.start();
    nexus.link.start();
    http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1:' + port);
        if (url.pathname === '/' || url.pathname === '/nexus.php') {
            const chunks = [];
            for await (const c of req) chunks.push(c);
            const body = chunks.length ? Buffer.concat(chunks) : undefined;
            const request = new Request(url, { method: req.method, headers: req.headers, body: req.method === 'POST' ? body : undefined, duplex: 'half' });
            const r = await handleWebRequest(nexus, request);
            res.writeHead(r.status, r.headers);
            return res.end(r.body);
        }
        const file = path.normalize(path.join(RENDERER, url.pathname));
        if (!file.startsWith(RENDERER) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); return res.end(); }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
        res.end(fs.readFileSync(file));
    }).listen(port, '127.0.0.1', () => console.log('VRC Nexus Test-Server auf http://127.0.0.1:' + port + '/'));
    process.on('SIGTERM', () => { db.close(); process.exit(0); });
    process.on('SIGINT', () => { db.close(); process.exit(0); });
})();
