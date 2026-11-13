'use strict';
/*
 * OSC-Log: schreibt ALLES mit, was über OSC hereinkommt (VRChat → Nexus) und hinausgeht (Nexus → VRChat),
 * und ordnet es in Kategorien ein. Gespeichert als Tagesdateien (eine JSON-Zeile pro Ereignis) in
 *   <Datenordner>/osc-log/JJJJ-MM-TT.ndjson
 * – das hält die App-Datenbank klein. Die letzten Einträge liegen zusätzlich im Speicher (Live-Ansicht).
 * Eingehende Werte werden nur bei einer ÄNDERUNG geloggt; Dauer-Werte (Bewegung, Kommazahlen) optional gedrosselt.
 */
const fs = require('fs');
const path = require('path');
const { str, int, UserError } = require('./util');

const CATS = {
    avatar: ['🎭', 'Avatarwechsel'], toggle: ['🔘', 'Schalter (Bool)'], int: ['🔢', 'Zahlen (Int)'], float: ['🎚', 'Regler (Float)'],
    gesture: ['✋', 'Gesten & Stimme'], motion: ['🏃', 'Bewegung & Haltung'], state: ['ℹ️', 'Status (AFK, Stumm …)'], camera: ['📷', 'Kamera'],
    chatbox: ['💬', 'Chatbox'], input: ['🎮', 'Eingaben'], tracking: ['📡', 'Tracking'], other: ['❔', 'Sonstiges'],
};
const GESTURE = new Set(['GestureLeft', 'GestureRight', 'GestureLeftWeight', 'GestureRightWeight', 'Viseme', 'Voice']);
const MOTION = new Set(['VelocityX', 'VelocityY', 'VelocityZ', 'VelocityMagnitude', 'AngularY', 'Upright', 'Grounded', 'Seated', 'InStation', 'TrackingType', 'VRMode',
    'ScaleModified', 'ScaleFactor', 'ScaleFactorInverse', 'EyeHeightAsMeters', 'EyeHeightAsPercent']);
const STATE = new Set(['AFK', 'MuteSelf', 'Earmuffs', 'IsLocal', 'IsOnFriendsList', 'AvatarVersion', 'IsAnimatorEnabled', 'PreviewMode']);
const CONTINUOUS = new Set(['motion', 'float', 'tracking']);
const BUF = 20000;

function category(address, args) {
    if (address === '/avatar/change') return 'avatar';
    if (address.startsWith('/avatar/parameters/')) {
        const n = address.slice(19), v = args[0];
        if (GESTURE.has(n)) return 'gesture';
        if (MOTION.has(n)) return 'motion';
        if (STATE.has(n)) return 'state';
        return typeof v === 'boolean' ? 'toggle' : Number.isInteger(v) ? 'int' : typeof v === 'number' ? 'float' : 'other';
    }
    if (address.startsWith('/usercamera/')) return 'camera';
    if (address.startsWith('/chatbox/')) return 'chatbox';
    if (address.startsWith('/input/')) return 'input';
    if (address.startsWith('/tracking/')) return 'tracking';
    return 'other';
}
const plain = a => (a && typeof a === 'object' && a.float !== undefined ? a.float : a);
const show = args => { const a = args.map(plain); return a.length === 1 ? a[0] : a; };
const round = v => (typeof v === 'number' && !Number.isInteger(v) ? Math.round(v * 1000) / 1000 : v);
const day = t => { const d = new Date(t); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };

class OscLog {
    constructor(app) {
        this.app = app;
        this.buf = [];
        this.last = new Map();       // Adresse → letzter Wert (für alt → neu)
        this.lastAt = new Map();     // Adresse → letzte Speicherzeit (Drosselung)
        this.pending = [];
        this.timer = null;
        this.seq = 0;
    }
    cfg() {
        const s = this.app.settings;
        return { on: s.get('osc.log') !== false, out: s.get('osc.log_out') !== false, days: Math.max(1, int(s.get('osc.log_days')) || 14), throttle: Math.max(0, int(s.get('osc.log_throttle'))) };
    }
    dir() { return path.join(this.app.tools.userData || require('os').tmpdir(), 'osc-log'); }

    attach(osc) {
        if (this.app.mobile) return;
        const prevMsg = osc.onMessage;
        osc.onMessage = (a, args) => { this.add('in', a, args); if (prevMsg) prevMsg(a, args); };
        const send = osc.send.bind(osc);
        osc.send = (a, ...args) => { this.add('out', a, args); return send(a, ...args); };
        this.timer = setInterval(() => this.flush(), 2000);
        if (this.timer.unref) this.timer.unref();
        this.prune();
        this.pruneTimer = setInterval(() => this.prune(), 3600000);
        if (this.pruneTimer.unref) this.pruneTimer.unref();
    }
    stop() { clearInterval(this.timer); clearInterval(this.pruneTimer); this.flush(); }

    add(dir, address, args) {
        const c = this.cfg();
        if (!c.on || (dir === 'out' && !c.out)) return;
        address = str(address);
        const value = round(show(args || []));
        const key = dir + address;
        const prev = this.last.has(key) ? this.last.get(key) : null;
        if (dir === 'in' && prev !== null && JSON.stringify(prev) === JSON.stringify(value)) return;     // keine Änderung
        const cat = category(address, (args || []).map(plain));
        const now = Date.now();
        if (dir === 'in' && c.throttle && CONTINUOUS.has(cat) && now - (this.lastAt.get(key) || 0) < c.throttle) { this.last.set(key, value); return; }
        this.last.set(key, value);
        this.lastAt.set(key, now);
        const osc = this.app.game.osc;
        const e = { i: ++this.seq, t: now, d: dir, c: cat, a: address, v: value, p: prev, av: str(osc.avatarId) };
        this.buf.push(e);
        if (this.buf.length > BUF) this.buf.splice(0, this.buf.length - BUF);
        this.pending.push(e);
    }
    flush() {
        if (!this.pending.length) return;
        const list = this.pending;
        this.pending = [];
        try {
            fs.mkdirSync(this.dir(), { recursive: true });
            const byDay = {};
            for (const e of list) (byDay[day(e.t)] = byDay[day(e.t)] || []).push(JSON.stringify(e));
            for (const [d, lines] of Object.entries(byDay)) fs.appendFileSync(path.join(this.dir(), d + '.ndjson'), lines.join('\n') + '\n');
        } catch (e) { /* Platte voll o. ä. – Live-Ansicht läuft weiter */ }
    }
    prune() {
        const keep = day(Date.now() - this.cfg().days * 86400000);
        try { for (const f of fs.readdirSync(this.dir())) if (/^\d{4}-\d{2}-\d{2}\.ndjson$/.test(f) && f.slice(0, 10) < keep) fs.rmSync(path.join(this.dir(), f), { force: true }); } catch (e) { /* noch kein Ordner */ }
    }
    files() {
        try { return fs.readdirSync(this.dir()).filter(f => /^\d{4}-\d{2}-\d{2}\.ndjson$/.test(f)).sort().map(f => ({ day: f.slice(0, 10), size: fs.statSync(path.join(this.dir(), f)).size })); } catch (e) { return []; }
    }

    /** Einträge holen: range = live | today | 7 | 30 | all; Filter: cats, dir, q, avatar; neueste zuerst */
    query(f = {}) {
        this.flush();
        const cats = str(f.cats).split(',').filter(c => CATS[c]);
        const q = str(f.q).trim().toLowerCase();
        const dir = ['in', 'out'].includes(f.dir) ? f.dir : '';
        const av = str(f.avatar);
        const since = int(f.since);
        const limit = Math.max(1, Math.min(5000, int(f.limit) || 500));
        const range = str(f.range || 'live');
        const match = e => (!cats.length || cats.includes(e.c)) && (!dir || e.d === dir) && (!av || e.av === av) && (!since || e.i > since)
            && (!q || e.a.toLowerCase().includes(q) || String(JSON.stringify(e.v)).toLowerCase().includes(q));
        let src;
        if (range === 'live') src = this.buf;
        else {
            const from = range === 'today' ? day(Date.now()) : range === 'all' ? '0000' : day(Date.now() - (int(range) || 7) * 86400000);
            src = [];
            for (const fl of this.files().filter(x => x.day >= from)) {
                try { for (const line of fs.readFileSync(path.join(this.dir(), fl.day + '.ndjson'), 'utf8').split('\n')) if (line) { try { src.push(JSON.parse(line)); } catch (e) { /* kaputte Zeile */ } } } catch (e) { /* weg */ }
            }
        }
        const counts = {}, top = {};
        const hits = [];
        for (let k = src.length - 1; k >= 0; k--) {
            const e = src[k];
            if (!match(e)) continue;
            counts[e.c] = (counts[e.c] || 0) + 1;
            top[e.a] = (top[e.a] || 0) + 1;
            if (hits.length < limit) hits.push(e);
        }
        const names = this.avatarNames();
        return { items: hits, counts, total: Object.values(counts).reduce((a, b) => a + b, 0), cats: CATS, seq: this.seq,
            top: Object.entries(top).sort((a, b) => b[1] - a[1]).slice(0, 12).map(([a, n]) => ({ a, n })),
            avatars: names, files: this.files(), cfg: this.cfg(), osc_active: this.app.game.osc.active };
    }
    avatarNames() { try { return Object.fromEntries(this.app.params.avatars().map(a => [a.id, a.name])); } catch (e) { return {}; } }
    csv(f) {
        const r = this.query(Object.assign({}, f, { limit: 5000 }));
        const rows = [['Zeit', 'Richtung', 'Kategorie', 'Adresse', 'Wert', 'vorher', 'Avatar']].concat(r.items.slice().reverse().map(e => [new Date(e.t).toISOString(), e.d === 'in' ? 'VRChat → Nexus' : 'Nexus → VRChat',
            (CATS[e.c] || ['', e.c])[1], e.a, JSON.stringify(e.v), e.p === null ? '' : JSON.stringify(e.p), r.avatars[e.av] || e.av]));
        return '﻿' + rows.map(x => x.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(';')).join('\r\n');
    }
    clear() {
        this.buf = []; this.pending = [];
        try { for (const f of fs.readdirSync(this.dir())) if (/\.ndjson$/.test(f)) fs.rmSync(path.join(this.dir(), f), { force: true }); } catch (e) { /* egal */ }
    }
    action(act, input) {
        if (act === 'osclog') return this.query(input);
        if (act === 'osclog_clear') { this.clear(); return { msg: 'OSC-Log gelöscht.' }; }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { OscLog, category, CATS };
