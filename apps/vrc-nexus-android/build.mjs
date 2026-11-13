// Baut www/ für Capacitor: Backend + Shims als bundle.js, Oberfläche aus apps/vrc-nexus/renderer, sql.js-WASM.
import { build } from 'esbuild';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const R = path.join(here, '..', 'vrc-nexus', 'renderer');
const W = path.join(here, 'www');
const shim = f => path.join(here, 'src', 'shims', f);

fs.rmSync(W, { recursive: true, force: true });
fs.mkdirSync(W, { recursive: true });
for (const d of ['css', 'js']) fs.cpSync(path.join(R, d), path.join(W, d), { recursive: true });
fs.copyFileSync(path.join(R, 'icon.png'), path.join(W, 'icon.png'));
fs.copyFileSync(path.join(here, 'node_modules', 'sql.js', 'dist', 'sql-wasm.wasm'), path.join(W, 'sql-wasm.wasm'));
fs.appendFileSync(path.join(W, 'css', 'app.css'), '\n' + fs.readFileSync(path.join(here, 'src', 'mobile.css'), 'utf8'));

await build({
    entryPoints: [path.join(here, 'src', 'boot.js')],
    bundle: true, outfile: path.join(W, 'bundle.js'), platform: 'browser', format: 'iife', target: ['chrome100'], minify: true, sourcemap: false, logLevel: 'warning',
    alias: { fs: shim('fs.js'), os: shim('os.js'), crypto: shim('crypto.js'), dgram: shim('dgram.js'), net: shim('stub.js'), http: shim('stub.js'), https: shim('stub.js'),
        child_process: shim('stub.js'), zlib: shim('stub.js'), path: 'path-browserify' },
    inject: [shim('globals.js')],
    define: { __dirname: '"/backend"', __filename: '"/backend/app.js"' },
    loader: { '.html': 'text' },
});

fs.writeFileSync(path.join(W, 'index.html'), `<!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VRC Nexus</title><style>html,body{background:#040609;color:#38d5e6;font-family:system-ui,sans-serif;margin:0;height:100%;display:flex;align-items:center;justify-content:center}
#bootMsg{font-size:15px;letter-spacing:1px}</style></head>
<body><div><img src="icon.png" width="96" height="96" alt="" style="display:block;margin:0 auto 14px"><div id="bootMsg">Starte VRC Nexus …</div></div>
<script src="bundle.js"></script></body></html>
`);
console.log('✔ www/ gebaut (' + Math.round(fs.statSync(path.join(W, 'bundle.js')).size / 1024) + ' KB bundle.js)');
