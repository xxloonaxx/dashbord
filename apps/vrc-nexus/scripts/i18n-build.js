#!/usr/bin/env node
/* Baut renderer/js/i18n-dict.js aus renderer/i18n/*.tsv (Spalten: Deutsch, English, Français, 日本語).
 * „#“ steht für eine Zahl (wird beim Übersetzen wieder eingesetzt). Aufruf: node scripts/i18n-build.js */
'use strict';
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, '..', 'renderer', 'i18n');
const out = { en: {}, fr: {}, ja: {} };
let n = 0, bad = 0;
for (const f of fs.readdirSync(dir).filter(x => x.endsWith('.tsv')).sort()) {
    fs.readFileSync(path.join(dir, f), 'utf8').split(/\r?\n/).forEach((line, i) => {
        if (!line.trim() || line.startsWith('//')) return;
        const c = line.split('\t');
        if (c.length !== 4 || c.some(x => !x.trim())) { bad++; console.warn(f + ':' + (i + 1) + ' übersprungen (' + c.length + ' Spalten)'); return; }
        const de = c[0].trim();
        const hashes = (de.match(/#/g) || []).length;
        ['en', 'fr', 'ja'].forEach((l, k) => {
            const v = c[k + 1].trim();
            if ((v.match(/#/g) || []).length !== hashes) console.warn(f + ':' + (i + 1) + ' ' + l + ': Anzahl # passt nicht');
            out[l][de] = v;
        });
        n++;
    });
}
const js = '/* automatisch erzeugt von scripts/i18n-build.js – nicht von Hand bearbeiten, sondern renderer/i18n/*.tsv */\n'
    + 'window.NexusDict = ' + JSON.stringify(Object.assign(out, { rx: {} })) + ';\n';
fs.writeFileSync(path.join(__dirname, '..', 'renderer', 'js', 'i18n-dict.js'), js);
console.log('✔ ' + n + ' Einträge' + (bad ? ', ' + bad + ' übersprungen' : '') + ' → renderer/js/i18n-dict.js (' + Math.round(js.length / 1024) + ' KB)');
