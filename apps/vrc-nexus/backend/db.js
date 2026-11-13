'use strict';
/*
 * Lokale Datenbank: SQLite als WebAssembly (sql.js) – kein Zusatzprogramm nötig.
 * Gleiches Schema wie die Server-Version (Spalte user_id ist hier immer 1), gespeichert in <Datenordner>/nexus.sqlite.
 * Änderungen werden gebündelt (spätestens nach 1,5 s) und beim Beenden auf die Platte geschrieben.
 */
const fs = require('fs');
const path = require('path');

const SCHEMA_VERSION = 7;

const SCHEMA = `
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS accounts (user_id INTEGER PRIMARY KEY, vrc_id TEXT NOT NULL, display_name TEXT NOT NULL DEFAULT '', avatar TEXT NOT NULL DEFAULT '',
    cookies TEXT NOT NULL, me_json TEXT NOT NULL DEFAULT '{}', connected_at INTEGER NOT NULL, last_sync INTEGER NOT NULL DEFAULT 0, last_offline INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '', my_location TEXT NOT NULL DEFAULT '', fav_groups TEXT NOT NULL DEFAULT '{}');
CREATE TABLE IF NOT EXISTS friends (user_id INTEGER NOT NULL, vrc_id TEXT NOT NULL, display_name TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT 'offline',
    status TEXT NOT NULL DEFAULT '', status_desc TEXT NOT NULL DEFAULT '', location TEXT NOT NULL DEFAULT '', platform TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
    avatar_thumb TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', bio TEXT NOT NULL DEFAULT '', last_login TEXT NOT NULL DEFAULT '', since INTEGER NOT NULL DEFAULT 0,
    updated INTEGER NOT NULL DEFAULT 0, fav_group TEXT NOT NULL DEFAULT '', friend_since INTEGER NOT NULL DEFAULT 0, pronouns TEXT NOT NULL DEFAULT '', PRIMARY KEY (user_id, vrc_id));
CREATE TABLE IF NOT EXISTS feed (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ts INTEGER NOT NULL, type TEXT NOT NULL, vrc_id TEXT NOT NULL,
    display_name TEXT NOT NULL DEFAULT '', old TEXT NOT NULL DEFAULT '', new TEXT NOT NULL DEFAULT '');
CREATE INDEX IF NOT EXISTS idx_feed ON feed(user_id, ts);
CREATE INDEX IF NOT EXISTS idx_feed_friend ON feed(user_id, vrc_id, ts);
CREATE TABLE IF NOT EXISTS notes (user_id INTEGER NOT NULL, vrc_id TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', favorite INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL DEFAULT 0,
    tags TEXT NOT NULL DEFAULT '', watch INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id, vrc_id));
CREATE TABLE IF NOT EXISTS worlds (world_id TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', thumb TEXT NOT NULL DEFAULT '', author TEXT NOT NULL DEFAULT '', capacity INTEGER NOT NULL DEFAULT 0, fetched INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS stats (user_id INTEGER NOT NULL, ts INTEGER NOT NULL, online INTEGER NOT NULL, active INTEGER NOT NULL, total INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_stats ON stats(user_id, ts);
CREATE TABLE IF NOT EXISTS vrc_events (user_id INTEGER NOT NULL, id TEXT NOT NULL, group_id TEXT NOT NULL DEFAULT '', group_name TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '', starts INTEGER NOT NULL, ends INTEGER NOT NULL, category TEXT NOT NULL DEFAULT '', access TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
    interested INTEGER NOT NULL DEFAULT 0, following INTEGER NOT NULL DEFAULT 0, platforms TEXT NOT NULL DEFAULT '', languages TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '',
    updated INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id, id));
CREATE TABLE IF NOT EXISTS local_fav_groups (user_id INTEGER NOT NULL, kind TEXT NOT NULL, name TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id, kind, name));
CREATE TABLE IF NOT EXISTS local_favs (user_id INTEGER NOT NULL, kind TEXT NOT NULL, grp TEXT NOT NULL, vrc_id TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
    sub TEXT NOT NULL DEFAULT '', added INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id, kind, grp, vrc_id));
CREATE TABLE IF NOT EXISTS vrc_history (user_id INTEGER NOT NULL, kind TEXT NOT NULL, vrc_id TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
    sub TEXT NOT NULL DEFAULT '', ts INTEGER NOT NULL, PRIMARY KEY (user_id, kind, vrc_id));
CREATE TABLE IF NOT EXISTS game_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ts INTEGER NOT NULL, kind TEXT NOT NULL, world_id TEXT NOT NULL DEFAULT '',
    instance TEXT NOT NULL DEFAULT '', world_name TEXT NOT NULL DEFAULT '', player_id TEXT NOT NULL DEFAULT '', player_name TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '',
    hash TEXT NOT NULL, client_id INTEGER NOT NULL DEFAULT 0, UNIQUE (user_id, hash));
CREATE INDEX IF NOT EXISTS idx_gl_user_ts ON game_log(user_id, ts);
CREATE INDEX IF NOT EXISTS idx_gl_kind ON game_log(user_id, kind, ts);
CREATE TABLE IF NOT EXISTS client_state (user_id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL, state TEXT NOT NULL DEFAULT '{}');
CREATE TABLE IF NOT EXISTS osc_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, created INTEGER NOT NULL, kind TEXT NOT NULL, payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending', client_id INTEGER NOT NULL DEFAULT 0, done INTEGER NOT NULL DEFAULT 0, result TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS osc_presets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, label TEXT NOT NULL, kind TEXT NOT NULL, payload TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS notif_log (user_id INTEGER NOT NULL, id TEXT NOT NULL, src TEXT NOT NULL DEFAULT 'v1', type TEXT NOT NULL, created INTEGER NOT NULL,
    sender_id TEXT NOT NULL DEFAULT '', sender_name TEXT NOT NULL DEFAULT '', group_id TEXT NOT NULL DEFAULT '', group_name TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '', message TEXT NOT NULL DEFAULT '', link TEXT NOT NULL DEFAULT '', responses TEXT NOT NULL DEFAULT '[]', seen INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1, can_delete INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (user_id, id));
CREATE INDEX IF NOT EXISTS idx_notif_created ON notif_log(user_id, created);
CREATE TABLE IF NOT EXISTS global_stats (ts INTEGER PRIMARY KEY, online INTEGER NOT NULL);
`;

class Statement {
    constructor(db, sql) { this.db = db; this.sql = sql; }
    _bind(params) { return params.length === 1 && params[0] && typeof params[0] === 'object' && !Array.isArray(params[0]) ? params[0] : params.map(p => (typeof p === 'boolean' ? (p ? 1 : 0) : p === undefined ? null : p)); }
    /** Alle Zeilen als Objekte */
    all(...params) {
        const st = this.db.raw.prepare(this.sql);
        try {
            st.bind(this._bind(params));
            const rows = [];
            while (st.step()) rows.push(st.getAsObject());
            return rows;
        } finally { st.free(); }
    }
    get(...params) { return this.all(...params)[0]; }
    /** Erster Wert der ersten Zeile */
    value(...params) { const r = this.get(...params); return r ? Object.values(r)[0] : undefined; }
    column(...params) { return this.all(...params).map(r => Object.values(r)[0]); }
    run(...params) {
        const st = this.db.raw.prepare(this.sql);
        try { st.run(this._bind(params)); } finally { st.free(); }
        const changes = this.db.raw.getRowsModified();
        this.db.dirty();
        return { changes, lastId: this.db.lastId() };
    }
}

class Database {
    constructor(SQL, file) {
        this.file = file;
        const data = file && fs.existsSync(file) ? fs.readFileSync(file) : null;
        this.raw = data ? new SQL.Database(data) : new SQL.Database();
        this.timer = null;
        this.depth = 0;
        this.raw.exec(SCHEMA);
        this.raw.exec('PRAGMA user_version = ' + SCHEMA_VERSION);
        this.dirty();
    }
    prepare(sql) { return new Statement(this, sql); }
    exec(sql) { this.raw.exec(sql); this.dirty(); }
    lastId() { const r = this.raw.exec('SELECT last_insert_rowid()'); return r.length ? r[0].values[0][0] : 0; }
    /** Transaktion (verschachtelt erlaubt) */
    tx(fn) {
        if (this.depth++ === 0) this.raw.exec('BEGIN');
        try { const r = fn(); if (--this.depth === 0) this.raw.exec('COMMIT'); return r; }
        catch (e) { if (--this.depth === 0) this.raw.exec('ROLLBACK'); throw e; }
    }
    dirty() {
        if (!this.file || this.timer) return;
        this.timer = setTimeout(() => { this.timer = null; this.save(); }, 1500);
    }
    save() {
        if (!this.file) return;
        if (this.timer) { clearTimeout(this.timer); this.timer = null; }
        fs.mkdirSync(path.dirname(this.file), { recursive: true });
        const tmp = this.file + '.tmp';
        fs.writeFileSync(tmp, Buffer.from(this.raw.export()));
        fs.renameSync(tmp, this.file);
    }
    close() { this.save(); this.raw.close(); }
    // ---- Einstellungen ----
    setting(key, def = '') { const v = this.prepare('SELECT value FROM settings WHERE key = ?').value(key); return v === undefined ? def : v; }
    setSetting(key, value) { this.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)').run(key, String(value)); }
}

async function openDatabase(file) {
    const initSqlJs = require('sql.js');
    const SQL = await initSqlJs({ locateFile: f => path.join(path.dirname(require.resolve('sql.js')), f) });
    return new Database(SQL, file);
}

module.exports = { openDatabase, Database };
