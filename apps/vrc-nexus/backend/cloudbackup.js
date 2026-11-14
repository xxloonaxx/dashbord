'use strict';
/*
 * Cloud-Sicherung der App-Datenbank ins eigene Dashboard (über die bestehende Dashboard-Verbindung, client.php):
 *  - vor dem Hochladen gepackt (gzip) und mit AES-256-GCM verschlüsselt – Schlüssel aus deinem Sicherungs-Passwort (scrypt)
 *    → das Dashboard speichert nur unlesbare Daten
 *  - Upload in 1-MB-Stücken mit SHA-256-Prüfung, automatisch täglich möglich, die letzten N Sicherungen bleiben
 *  - Wiederherstellen: herunterladen, entschlüsseln, prüfen → wird beim nächsten Start der App eingespielt
 */
const crypto = require('crypto');
const zlib = require('zlib');
const fs = require('fs');
const { str, int, now, UserError } = require('./util');
const { VERSION } = require('./vrchat');

const MAGIC = Buffer.from('NXB1');
const CHUNK = 1048576;

class CloudBackup {
    constructor(app, crypt) { this.app = app; this.crypt = crypt; this.busy = null; this.lastAuto = 0; this.timer = null; }
    get link() { return this.app.link; }
    pass() { try { return JSON.parse(this.crypt.decrypt(this.app.db.setting('cloud_backup_pass', '')) || '{}').p || ''; } catch (e) { return ''; } }
    setPass(p) {
        p = str(p);
        if (p && p.length < 8) throw new UserError('Das Sicherungs-Passwort braucht mindestens 8 Zeichen.');
        this.app.db.setSetting('cloud_backup_pass', p ? this.crypt.encrypt(JSON.stringify({ p })) : '');
    }
    cfg() { try { return Object.assign({ auto: false, keep: 5, last: 0, last_error: '' }, JSON.parse(this.app.db.setting('cloud_backup_cfg', '{}'))); } catch (e) { return { auto: false, keep: 5, last: 0, last_error: '' }; } }
    saveCfg(c) { const cur = this.cfg(); const n = Object.assign(cur, c); this.app.db.setSetting('cloud_backup_cfg', JSON.stringify(n)); return n; }

    static seal(plain, pass) {
        const salt = crypto.randomBytes(16), iv = crypto.randomBytes(12);
        const key = crypto.scryptSync(pass, salt, 32, { N: 16384, r: 8, p: 1 });
        const c = crypto.createCipheriv('aes-256-gcm', key, iv);
        const body = Buffer.concat([c.update(zlib.gzipSync(plain, { level: 9 })), c.final()]);
        return Buffer.concat([MAGIC, salt, iv, c.getAuthTag(), body]);
    }
    static open(blob, pass) {
        if (blob.length < 48 || !blob.subarray(0, 4).equals(MAGIC)) throw new UserError('Das ist keine VRC-Nexus-Sicherung.');
        const salt = blob.subarray(4, 20), iv = blob.subarray(20, 32), tag = blob.subarray(32, 48);
        const key = crypto.scryptSync(pass, salt, 32, { N: 16384, r: 8, p: 1 });
        const d = crypto.createDecipheriv('aes-256-gcm', key, iv);
        d.setAuthTag(tag);
        let gz;
        try { gz = Buffer.concat([d.update(blob.subarray(48)), d.final()]); } catch (e) { throw new UserError('Falsches Sicherungs-Passwort (oder Datei beschädigt).'); }
        return zlib.gunzipSync(gz);
    }

    status() {
        const c = this.cfg();
        return { cfg: c, has_pass: !!this.pass(), linked: !!(this.link && this.link.base() && this.link.token()), busy: this.busy, size: this.app.db.raw ? null : null };
    }
    async upload(note = '') {
        if (this.app.mobile) throw new UserError('Die Cloud-Sicherung gibt es in der Windows-App.');
        const pass = this.pass();
        if (!pass) throw new UserError('Erst ein Sicherungs-Passwort festlegen.');
        if (this.busy) throw new UserError('Es läuft schon eine Sicherung.');
        this.busy = { kind: 'upload', done: 0, total: 0 };
        try {
            if (this.app.db.timer) this.app.db.save();
            const blob = CloudBackup.seal(Buffer.from(this.app.db.raw.export()), pass);
            this.busy.total = blob.length;
            const b = await this.link.req('POST', 'backup_begin', { size: blob.length, sha256: crypto.createHash('sha256').update(blob).digest('hex'), version: VERSION, note: str(note).slice(0, 80) });
            for (let off = 0; off < blob.length; off += CHUNK) {
                await this.link.req('POST', 'backup_chunk', { id: b.id, offset: off, data: blob.subarray(off, off + CHUNK).toString('base64') });
                this.busy.done = Math.min(blob.length, off + CHUNK);
            }
            const e = await this.link.req('POST', 'backup_end', { id: b.id, keep: this.cfg().keep });
            this.saveCfg({ last: now(), last_error: '' });
            return { msg: 'Sicherung hochgeladen (' + (blob.length / 1048576).toFixed(1).replace('.', ',') + ' MB, verschlüsselt).', backups: e.backups };
        } catch (e) { this.saveCfg({ last_error: e.message }); throw e; }
        finally { this.busy = null; }
    }
    async list() { const d = await this.link.req('GET', 'backup_list'); return d.backups || []; }
    async restore(id) {
        const pass = this.pass();
        if (!pass) throw new UserError('Erst das Sicherungs-Passwort eintragen, mit dem die Sicherung gemacht wurde.');
        if (this.busy) throw new UserError('Es läuft schon etwas.');
        this.busy = { kind: 'restore', done: 0, total: 0 };
        try {
            const parts = [];
            let off = 0, size = 1;
            while (off < size) {
                const d = await this.link.req('GET', 'backup_get&id=' + encodeURIComponent(id) + '&offset=' + off);
                const buf = Buffer.from(d.data, 'base64');
                if (!buf.length) break;
                parts.push(buf); off += buf.length; size = int(d.size);
                this.busy.done = off; this.busy.total = size;
            }
            const plain = CloudBackup.open(Buffer.concat(parts), pass);
            if (plain.subarray(0, 15).toString('latin1') !== 'SQLite format 3') throw new UserError('Die Sicherung enthält keine gültige Datenbank.');
            if (!this.app.db.file) throw new UserError('Wiederherstellen geht nur in der Windows-App.');
            fs.writeFileSync(this.app.db.file + '.restore', plain);
            return { msg: 'Sicherung geprüft ✔ – sie wird beim nächsten Start von VRC Nexus eingespielt. Jetzt VRC Nexus beenden (Infobereich → Beenden) und neu starten.', restart: true };
        } finally { this.busy = null; }
    }
    async remove(id) { const d = await this.link.req('POST', 'backup_delete', { id: str(id) }); return d.backups || []; }

    /** einmal am Tag automatisch (wenn eingeschaltet) */
    start() {
        if (this.app.mobile) return;
        this.timer = setInterval(() => {
            const c = this.cfg();
            if (!c.auto || !this.pass() || this.busy || now() - c.last < 86400 || Date.now() - this.lastAuto < 3600000) return;
            this.lastAuto = Date.now();
            this.upload('automatisch').catch(() => {});
        }, 10 * 60000);
        if (this.timer.unref) this.timer.unref();
    }
    stop() { clearInterval(this.timer); }

    async action(act, input) {
        switch (act) {
            case 'backup_status': { const s = this.status(); if (s.linked) { try { s.backups = await this.list(); } catch (e) { s.error = e.message; } } return s; }
            case 'backup_pass': this.setPass(input.pass); return Object.assign(this.status(), { msg: input.pass ? 'Sicherungs-Passwort gespeichert (verschlüsselt). Merk es dir – ohne kann niemand die Sicherung öffnen, auch du nicht.' : 'Passwort entfernt.' });
            case 'backup_cfg': return Object.assign(this.status(), { cfg: this.saveCfg({ auto: input.auto === '1' || input.auto === true, keep: Math.max(1, Math.min(20, int(input.keep) || 5)) }), msg: 'Gespeichert.' });
            case 'backup_now': return await this.upload(str(input.note));
            case 'backup_restore': return await this.restore(str(input.id));
            case 'backup_delete': return { backups: await this.remove(input.id) };
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { CloudBackup };
