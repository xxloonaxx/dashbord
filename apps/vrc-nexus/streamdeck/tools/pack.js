'use strict';
/*
 * Packt das Stream-Deck-Plugin zu streamdeck/VRC-Nexus.streamDeckPlugin (ein ZIP mit dem .sdPlugin-Ordner).
 * Doppelklick auf die Datei installiert das Plugin in der Stream-Deck-App.   npm run streamdeck
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = path.join(__dirname, '..');
const DIR = 'de.ghostnode.vrcnexus.sdPlugin';
const OUT = path.join(ROOT, 'VRC-Nexus.streamDeckPlugin');

const CRC = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
const crc32 = b => { let c = 0xffffffff; for (const x of b) c = CRC[(c ^ x) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };

function files(dir, base = '') {
    return fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name)).flatMap(e => {
        const rel = base ? base + '/' + e.name : e.name;
        return e.isDirectory() ? files(path.join(dir, e.name), rel) : [rel];
    });
}

const entries = files(path.join(ROOT, DIR)).map(rel => ({ name: DIR + '/' + rel, data: fs.readFileSync(path.join(ROOT, DIR, rel)) }));
const parts = [], central = [];
let offset = 0;
const DOS_TIME = 0, DOS_DATE = (2026 - 1980) << 9 | 1 << 5 | 1;     // feste Zeit → gleiche Datei bei gleichem Inhalt
for (const e of entries) {
    const name = Buffer.from(e.name, 'utf8'), comp = zlib.deflateRawSync(e.data, { level: 9 }), crc = crc32(e.data);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0); local.writeUInt16LE(20, 4); local.writeUInt16LE(0x0800, 6); local.writeUInt16LE(8, 8);
    local.writeUInt16LE(DOS_TIME, 10); local.writeUInt16LE(DOS_DATE, 12); local.writeUInt32LE(crc, 14); local.writeUInt32LE(comp.length, 18); local.writeUInt32LE(e.data.length, 22);
    local.writeUInt16LE(name.length, 26); local.writeUInt16LE(0, 28);
    parts.push(local, name, comp);
    const c = Buffer.alloc(46);
    c.writeUInt32LE(0x02014b50, 0); c.writeUInt16LE(20, 4); c.writeUInt16LE(20, 6); c.writeUInt16LE(0x0800, 8); c.writeUInt16LE(8, 10);
    c.writeUInt16LE(DOS_TIME, 12); c.writeUInt16LE(DOS_DATE, 14); c.writeUInt32LE(crc, 16); c.writeUInt32LE(comp.length, 20); c.writeUInt32LE(e.data.length, 24);
    c.writeUInt16LE(name.length, 28); c.writeUInt32LE(offset, 42);
    central.push(c, name);
    offset += 30 + name.length + comp.length;
}
const cd = Buffer.concat(central);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(entries.length, 8); end.writeUInt16LE(entries.length, 10); end.writeUInt32LE(cd.length, 12); end.writeUInt32LE(offset, 16);
fs.writeFileSync(OUT, Buffer.concat([...parts, cd, end]));
console.log('✔ ' + path.relative(process.cwd(), OUT) + ' (' + entries.length + ' Dateien, ' + Math.round(fs.statSync(OUT).size / 1024) + ' KB)');
