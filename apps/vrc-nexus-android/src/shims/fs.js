'use strict';
/* Dateisystem-Ersatz: Dateien im Speicher; alles unter /data wird in IndexedDB gesichert (bleibt nach Neustart erhalten). */
const files = new Map();
const DB_NAME = 'vrc-nexus', STORE = 'files';
let idb = null;
function open() {
    if (idb) return idb;
    idb = new Promise((resolve, reject) => {
        const r = indexedDB.open(DB_NAME, 1);
        r.onupgradeneeded = () => r.result.createObjectStore(STORE);
        r.onsuccess = () => resolve(r.result);
        r.onerror = () => reject(r.error);
    });
    return idb;
}
const timers = {};
const pending = new Set();
async function write(keys) {
    const db = await open();
    const tx = db.transaction(STORE, 'readwrite');
    for (const p of keys) { pending.delete(p); clearTimeout(timers[p]); if (files.has(p)) tx.objectStore(STORE).put(files.get(p), p); else tx.objectStore(STORE).delete(p); }
    await new Promise(r => { tx.oncomplete = r; tx.onerror = r; tx.onabort = r; });
}
function persist(p) {
    if (!p.startsWith('/data/')) return;
    pending.add(p);
    clearTimeout(timers[p]);
    timers[p] = setTimeout(() => { write([p]).catch(() => {}); }, 300);
}
const enoent = p => Object.assign(new Error('ENOENT: no such file or directory, ' + p), { code: 'ENOENT' });
const toBuf = v => (typeof v === 'string' ? Buffer.from(v, 'utf8') : Buffer.from(v));
module.exports = {
    __files: files,
    /** beim Start: gesicherte Dateien aus IndexedDB laden */
    async __load() {
        const db = await open();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly'), st = tx.objectStore(STORE), req = st.openCursor();
            req.onsuccess = () => { const c = req.result; if (c) { files.set(String(c.key), c.value instanceof Uint8Array ? c.value : new Uint8Array(c.value)); c.continue(); } else resolve(); };
            req.onerror = () => reject(req.error);
        });
    },
    /** ausstehende Änderungen sofort sichern (nach Aktionen, beim Wechsel in den Hintergrund) */
    async __flush() { if (pending.size) await write([...pending]); },
    existsSync: p => files.has(String(p)),
    readFileSync(p, enc) {
        p = String(p);
        if (!files.has(p)) throw enoent(p);
        const v = files.get(p);
        if (enc) return typeof v === 'string' ? v : new TextDecoder().decode(v);
        return toBuf(v);
    },
    writeFileSync(p, d) { p = String(p); files.set(p, typeof d === 'string' ? d : new Uint8Array(d)); persist(p); },
    appendFileSync(p, d) { p = String(p); const old = files.has(p) ? module.exports.readFileSync(p, 'utf8') : ''; module.exports.writeFileSync(p, old + d); },
    renameSync(a, b) { a = String(a); b = String(b); if (!files.has(a)) throw enoent(a); files.set(b, files.get(a)); files.delete(a); persist(a); persist(b); },
    copyFileSync(a, b) { files.set(String(b), files.get(String(a))); persist(String(b)); },
    unlinkSync(p) { files.delete(String(p)); persist(String(p)); },
    mkdirSync() {}, mkdtempSync: p => p + Date.now(), rmSync() {},
    readdirSync() { return []; },
    statSync(p) { if (!files.has(String(p))) throw enoent(p); const v = files.get(String(p)); return { size: v.length, mtimeMs: Date.now(), isDirectory: () => false, isFile: () => true }; },
    openSync() { throw enoent('fd'); }, readSync() { return 0; }, closeSync() {}, fstatSync() { return { size: 0 }; }, utimesSync() {},
};
