#!/usr/bin/env node
/* Baut backend/changelog.json aus den „### Neu in X.Y.Z …“-Abschnitten der README (für die Seite „Über VRC Nexus“).
 * Aufruf: node scripts/changelog-build.js  (läuft auch im Test – meldet, wenn die Datei veraltet ist) */
'use strict';
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..');
function build() {
    const md = fs.readFileSync(path.join(root, 'README.md'), 'utf8').split(/\r?\n/);
    const out = [];
    let cur = null;
    for (const line of md) {
        const m = /^### Neu in (\d+\.\d+\.\d+)\s*(?:[–-]\s*(.*))?$/.exec(line);
        if (m) { cur = { version: m[1], title: (m[2] || '').trim(), body: [] }; out.push(cur); continue; }
        if (cur && /^#{1,3} /.test(line)) cur = null;          // nächster Abschnitt, der kein Changelog ist
        if (cur) cur.body.push(line);
    }
    return out.map(e => ({ version: e.version, title: e.title, body: e.body.join('\n').trim() }));
}
if (require.main === module) {
    const list = build();
    fs.writeFileSync(path.join(root, 'backend', 'changelog.json'), JSON.stringify(list, null, 1) + '\n');
    console.log('✔ ' + list.length + ' Versionen → backend/changelog.json');
}
module.exports = { build };
