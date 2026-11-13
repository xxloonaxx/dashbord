'use strict';
/*
 * Test-Server für die Android-Version im Desktop-Browser:  node tests/server.js 8120 http://127.0.0.1:8792/api/1
 * Liefert www/ aus und spielt „natives HTTP“ nach: Anfragen an VRChat laufen über /__proxy, Cookies verwaltet der Server
 * (wie der Android-CookieManager) – JavaScript sieht keine Set-Cookie-Kopfzeilen, genau wie in der App.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');

const port = +(process.argv[2] || 8120);
const API = process.argv[3] || 'http://127.0.0.1:8792/api/1';
const WWW = path.join(__dirname, '..', 'www');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.wasm': 'application/wasm' };
const jar = {};

const TEST_JS = `<script>
const realFetch = window.fetch.bind(window);
window.__nexusUpstream = async (input, init) => {
  const req = new Request(input, init);
  const body = ['GET', 'HEAD'].includes(req.method) ? '' : btoa(String.fromCharCode(...new Uint8Array(await req.arrayBuffer())));
  const headers = {}; req.headers.forEach((v, k) => { headers[k] = v; });
  // API-Adresse der Nachbildung statt api.vrchat.cloud
  const url = req.url.replace('https://api.vrchat.cloud/api/1', ${JSON.stringify(API)});
  return realFetch('/__proxy', { method: 'POST', body: JSON.stringify({ url, method: req.method, headers, body }) });
};
</script>`;

http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');
    if (url.pathname === '/__proxy') {
        let raw = '';
        for await (const c of req) raw += c;
        const p = JSON.parse(raw);
        const u = new URL(p.url);
        const h = Object.assign({}, p.headers);
        delete h.cookie;
        const ck = Object.entries(jar[u.host] || {}).map(([k, v]) => k + '=' + v).join('; ');
        if (ck) h.cookie = ck;
        const r = await fetch(p.url, { method: p.method, headers: h, body: p.body ? Buffer.from(p.body, 'base64') : undefined });
        for (const line of r.headers.getSetCookie()) { const m = /^([^=]+)=([^;]*)/.exec(line); if (m) (jar[u.host] = jar[u.host] || {})[m[1]] = m[2]; }
        const out = Buffer.from(await r.arrayBuffer());
        res.writeHead(r.status, { 'Content-Type': r.headers.get('content-type') || 'application/octet-stream' });
        return res.end(out);
    }
    let f = path.normalize(path.join(WWW, url.pathname === '/' ? 'index.html' : url.pathname));
    if (!f.startsWith(WWW) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); }
    let body = fs.readFileSync(f);
    if (f.endsWith('index.html')) body = Buffer.from(body.toString().replace('<script src="bundle.js">', TEST_JS + '<script src="bundle.js">'));
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' });
    res.end(body);
}).listen(port, '127.0.0.1', () => console.log('Android-Test auf http://127.0.0.1:' + port + '/'));
