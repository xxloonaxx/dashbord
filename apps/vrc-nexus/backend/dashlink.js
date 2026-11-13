'use strict';
/*
 * Verbindung zum GHOST-NODE-Dashboard (client.php) mit einem Geräte-Token (gnx_…):
 *  - Spiel-Log und Live-Status hochladen (im Dashboard unter Nexus → Client sichtbar)
 *  - Chatbox-/OSC-Befehle aus dem Dashboard (oder vom Handy über das Dashboard) hier ausführen
 *  - Memos/Notizen in beide Richtungen abgleichen
 * Das Token wird verschlüsselt gespeichert und nur an die eingetragene Dashboard-Adresse geschickt.
 */
const { UserError, str, int, now, cut } = require('./util');
const { UID, VERSION } = require('./vrchat');

class DashLink {
    constructor(app) {
        this.app = app;
        this.db = app.db;
        this.timer = null;
        this.busy = false;
        this.last = { osc: 0, state: 0, events: 0, notes: 0 };
        this.status = { ok: false, msg: 'aus', user: '', device: '', at: 0, sent: 0, received: 0 };
    }
    s(k) { return this.app.settings.get(k); }
    token() { try { return JSON.parse(this.app.vrc.crypt.decrypt(this.db.setting('link_token', '')) || '{}').t || ''; } catch (e) { return ''; } }
    saveToken(t) {
        t = str(t).trim();
        if (t && !/^gnx_[0-9a-f]{40}$/.test(t)) throw new UserError('Das Geräte-Token hat das falsche Format (gnx_ + 40 Zeichen) – im Dashboard unter Nexus → 🖥️ Client → Geräte erstellen.');
        this.db.setSetting('link_token', t ? this.app.vrc.crypt.encrypt(JSON.stringify({ t })) : '');
        this.status = Object.assign(this.status, { ok: false, msg: t ? 'gespeichert – verbinde …' : 'kein Token' });
        return this.info();
    }
    base() {
        let u = str(this.s('link.url')).trim();
        if (!u) return '';
        if (!/^https?:\/\//.test(u)) u = 'https://' + u;
        return u.replace(/\/(client|nexus|index)\.php.*$/, '').replace(/\/+$/, '');
    }
    info() { const t = this.token(); return Object.assign({}, this.status, { url: this.base(), token: t ? t.slice(0, 8) + '…' + t.slice(-4) : '', enabled: !!this.s('link.enabled') }); }

    async req(method, action, body) {
        const base = this.base(), t = this.token();
        if (!base) throw new UserError('Keine Dashboard-Adresse eingetragen.');
        if (!/^https:\/\//.test(base) && !/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?/.test(base)) throw new UserError('Die Dashboard-Adresse muss mit https:// beginnen.');
        if (!t) throw new UserError('Kein Geräte-Token eingetragen.');
        let res;
        try {
            res = await fetch(base + '/client.php?a=' + action, { method, headers: { Authorization: 'Bearer ' + t, 'X-Nexus-Token': t, 'X-Client-Version': 'App ' + VERSION, 'X-Client-Platform': process.platform === 'win32' ? 'Windows (App)' : process.platform,
                'Content-Type': 'application/json', Accept: 'application/json' }, body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(15000) });
        } catch (e) { throw new UserError('Dashboard nicht erreichbar (' + (e.cause && e.cause.code || e.message) + ').'); }
        let d = null;
        try { d = await res.json(); } catch (e) { d = null; }
        if (!res.ok || !d) throw new UserError((d && d.error) || 'Dashboard antwortet mit HTTP ' + res.status + (res.status === 404 ? ' – stimmt die Adresse?' : '.'));
        return d;
    }
    async test() {
        const d = await this.req('GET', 'hello');
        this.status = Object.assign(this.status, { ok: true, msg: 'verbunden', user: str(d.user && d.user.name), device: str(d.device), at: now() });
        return Object.assign(this.info(), { msg: '✔ Verbunden mit „' + str(d.server && d.server.title) + '“ als ' + str(d.user && d.user.name) + ' (Gerät „' + str(d.device) + '“).' });
    }

    start() { this.stop(); this.timer = setInterval(() => this.tick().catch(() => {}), 3000); if (this.timer.unref) this.timer.unref(); }
    stop() { if (this.timer) clearInterval(this.timer); this.timer = null; }
    async tick() {
        if (!this.s('link.enabled') || this.busy || !this.token() || !this.base()) { if (!this.s('link.enabled')) this.status.msg = 'aus'; return; }
        const pc = !this.app.mobile;          // das Handy lädt nichts hoch und führt keine OSC-Befehle aus
        this.busy = true;
        const t = Date.now();
        try {
            if (pc && this.s('link.remote') && t - this.last.osc >= 3000) { this.last.osc = t; await this.pullOsc(); }
            if (pc && this.s('link.upload') && t - this.last.events >= 10000) { this.last.events = t; await this.pushEvents(); }
            if (pc && this.s('link.upload') && t - this.last.state >= 20000) { this.last.state = t; await this.pushState(); }
            if (this.s('link.notes') && t - this.last.notes >= 300000) { this.last.notes = t; await this.syncNotes(); }
            this.status = Object.assign(this.status, { ok: true, msg: 'verbunden', at: now() });
        } catch (e) {
            this.status = Object.assign(this.status, { ok: false, msg: e.message, at: now() });
            if (/Token|401/.test(e.message)) this.last.osc = t + 60000;      // bei falschem Token nicht dauernd anfragen
        } finally { this.busy = false; }
    }
    /** Befehle aus dem Dashboard ausführen (Chatbox, Tippen, Parameter, Eingaben) */
    async pullOsc() {
        const d = await this.req('GET', 'osc');
        const results = [];
        for (const c of d.commands || []) {
            try {
                const p = Object.assign({}, c.payload || {});
                if (c.kind === 'chatbox' && p.translate && this.app.tr.provider()) p.text = (await this.app.tr.chat(str(p.text), str(p.target))).text;
                await this.app.game.oscSend(c.kind, Object.assign(p, { sound: p.sound ? '1' : '', split: p.split ? '1' : '' }));
                results.push({ id: c.id, ok: true });
                this.status.received++;
            } catch (e) { results.push({ id: c.id, ok: false, error: cut(e.message, 190) }); }
        }
        if (results.length) await this.req('POST', 'osc_ack', { results });
    }
    async pushEvents() {
        const from = int(this.db.setting('link_last_id', '0'));
        const rows = this.db.prepare('SELECT id, ts, kind, world_id, instance, world_name, player_id, player_name, detail FROM game_log WHERE user_id = ? AND id > ? ORDER BY id LIMIT 500').all(UID, from);
        if (!rows.length) return;
        await this.req('POST', 'events', { events: rows.map(r => ({ ts: r.ts, kind: r.kind, world_id: r.world_id, instance: r.instance, world_name: r.world_name, player_id: r.player_id, player_name: r.player_name, detail: r.detail })) });
        this.db.setSetting('link_last_id', String(rows[rows.length - 1].id));
        this.status.sent += rows.length;
    }
    async pushState() {
        const st = this.app.game.state.snapshot();
        Object.assign(st, { osc: this.app.game.osc.active, params: Object.fromEntries(Object.entries(this.app.game.osc.params).slice(0, 64)), avatar_id: this.app.game.osc.avatarId });
        await this.req('POST', 'state', { state: st });
    }
    /** Memos abgleichen: neuere Version gewinnt (nach Änderungszeit) */
    async syncNotes() {
        const since = int(this.db.setting('link_notes_at', '0'));
        const mine = this.db.prepare('SELECT vrc_id, note, favorite, tags, watch, updated FROM notes WHERE user_id = ? AND updated > ?').all(UID, since);
        const d = await this.req('POST', 'notes', { since, notes: mine });
        const up = this.db.prepare('INSERT INTO notes (user_id, vrc_id, note, favorite, updated, tags, watch) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(user_id, vrc_id) DO UPDATE SET note = excluded.note, favorite = excluded.favorite, updated = excluded.updated, tags = excluded.tags, watch = excluded.watch WHERE excluded.updated > notes.updated');
        for (const n of d.notes || []) if (/^usr_[0-9a-f-]{36}$/i.test(str(n.vrc_id))) up.run(UID, n.vrc_id, cut(str(n.note), 4000), n.favorite ? 1 : 0, int(n.updated), cut(str(n.tags), 200), n.watch ? 1 : 0);
        this.db.setSetting('link_notes_at', String(int(d.time) || now()));
        return { sent: mine.length, received: (d.notes || []).length };
    }
}

// Fernbedienung (z. B. vom Handy): Befehle über das Dashboard an den PC schicken
DashLink.prototype.remoteState = function () { return this.req('GET', 'remote'); };
DashLink.prototype.remoteSend = function (kind, data) { return this.req('POST', 'send', Object.assign({}, data, { kind })); };

module.exports = { DashLink };
