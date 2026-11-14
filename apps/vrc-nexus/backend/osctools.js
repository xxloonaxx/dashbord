'use strict';
/*
 * Chatbox-Extras & OSC-Werkzeuge (eigene Umsetzung)
 *  Chatbox: Schnelltexte mit Platzhaltern, Verlauf (erneut senden), geplante Nachrichten, Vorschau mit 144-Zeichen-Zähler
 *  OSC:     Port-Test (Selbsttest + „kommt etwas von VRChat?“), Weiterleitung an weitere Programme,
 *           Parameter-Szenen (aktuelle Werte speichern/anwenden), Aufnahme & Wiedergabe von Parameter-Bewegungen
 */
const dgram = require('dgram');
const { str, int, cut, arr, UserError } = require('./util');
const { chatNewlines } = require('./gamelog');
const { BUILTIN } = require('./avatarparams');

const HOST_RE = /^(?:localhost|127\.\d{1,3}\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3}|172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})$/;
const id = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
const typeOf = v => typeof v === 'boolean' ? 'bool' : Number.isInteger(v) ? 'int' : 'float';

class OscTools {
    constructor(app) {
        this.app = app;
        this.rec = null;           // laufende Aufnahme { name, start, events }
        this.playing = null;       // { id, timers }
        this.timer = null;
        this.fwdSock = null;
        this.tapsOn = false;
    }
    get osc() { return this.app.game.osc; }
    get db() { return this.app.db; }
    load(k, d) { try { const v = JSON.parse(this.db.setting(k, '')); return v === null || v === undefined ? d : v; } catch (e) { return d; } }
    store(k, v) { this.db.setSetting(k, JSON.stringify(v)); }

    start() {
        if (this.app.mobile || this.tapsOn) return;
        this.tapsOn = true;
        const o = this.osc;
        o.taps = o.taps || new Set();
        o.rawTaps = o.rawTaps || new Set();
        o.taps.add((a, args) => this.onMsg(a, args));
        o.rawTaps.add(buf => this.forward(buf));
        this.timer = setInterval(() => this.tick(), 15000);
        if (this.timer.unref) this.timer.unref();
    }
    stop() { clearInterval(this.timer); this.timer = null; this.stopPlay(); if (this.fwdSock) { try { this.fwdSock.close(); } catch (e) { /* egal */ } this.fwdSock = null; } }

    // ============================ Chatbox ============================
    fill(text) { return Array.from(chatNewlines(this.app.game.fillVars(str(text)))).join(''); }
    cbx() { return { quick: this.load('cbx_quick', []), hist: this.load('cbx_hist', []), sched: this.load('cbx_sched', []).sort((a, b) => a.at - b.at) }; }
    addHist(text) {
        text = cut(str(text).trim(), 400);
        if (!text) return;
        const h = this.load('cbx_hist', []).filter(x => x.text !== text);
        h.unshift({ text, at: Date.now() });
        this.store('cbx_hist', h.slice(0, 50));
    }
    async send(text, sound) {
        const t = str(text).trim();
        if (!t) throw new UserError('Text fehlt.');
        await this.app.game.oscSend('chatbox', { text: t, sound: sound === false || sound === '0' ? '' : '1', split: '1' });
        this.addHist(t);
    }
    quickSave(text, label) {
        text = cut(str(text).trim(), 400);
        if (!text) throw new UserError('Text fehlt.');
        const q = this.load('cbx_quick', []);
        if (q.length >= 40) throw new UserError('Höchstens 40 Schnelltexte.');
        q.push({ id: id(), text, label: cut(str(label).trim(), 30) });
        this.store('cbx_quick', q);
    }
    quickDel(qid) { this.store('cbx_quick', this.load('cbx_quick', []).filter(x => x.id !== str(qid))); }
    /** at: „HH:MM“ (heute, sonst morgen) oder Minuten ab jetzt */
    schedAdd(text, at, mins) {
        text = cut(str(text).trim(), 400);
        if (!text) throw new UserError('Text fehlt.');
        let when;
        if (str(at).trim()) {
            const m = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(str(at).trim());
            if (!m) throw new UserError('Uhrzeit als HH:MM angeben.');
            const d = new Date(); d.setHours(+m[1], +m[2], 0, 0);
            if (d.getTime() <= Date.now()) d.setDate(d.getDate() + 1);
            when = d.getTime();
        } else {
            const n = int(mins);
            if (n < 1 || n > 1440) throw new UserError('1 bis 1440 Minuten.');
            when = Date.now() + n * 60000;
        }
        const l = this.load('cbx_sched', []);
        if (l.length >= 30) throw new UserError('Höchstens 30 geplante Nachrichten.');
        l.push({ id: id(), text, at: when });
        this.store('cbx_sched', l);
        return when;
    }
    schedDel(sid) { this.store('cbx_sched', this.load('cbx_sched', []).filter(x => x.id !== str(sid))); }
    tick() {
        const l = this.load('cbx_sched', []), due = l.filter(x => x.at <= Date.now());
        if (!due.length) return;
        this.store('cbx_sched', l.filter(x => x.at > Date.now()));
        for (const x of due) if (Date.now() - x.at < 3600000) this.send(x.text, true).catch(() => {});   // älter als 1 h (App war aus) → verwerfen
    }

    // ============================ OSC ============================
    onMsg(address, args) {
        if (this.selftest && address === '/nexus/selftest') { this.selftest(true); return; }
        if (this.rec && address.startsWith('/avatar/parameters/') && args.length && !BUILTIN.has(address.slice(19))) {
            if (this.rec.events.length < 20000) this.rec.events.push([Date.now() - this.rec.start, address.slice(19), args[0]]);
            if (Date.now() - this.rec.start > this.rec.max * 1000) this.recStop().catch(() => {});
        }
    }
    fwdCfg() { const c = this.load('osc_fwd', {}); return { on: !!c.on, targets: arr(c.targets).filter(t => t && HOST_RE.test(str(t.host)) && int(t.port) > 0) }; }
    fwdSave(on, text) {
        const targets = [];
        for (const line of str(text).split(/[\n,;]+/).map(s => s.trim()).filter(Boolean)) {
            const m = /^([\w.]+):(\d{2,5})$/.exec(line);
            if (!m || !HOST_RE.test(m[1]) || int(m[2]) < 1 || int(m[2]) > 65535) throw new UserError('„' + cut(line, 40) + '“ – bitte als Host:Port im eigenen Netz angeben, z. B. 127.0.0.1:9002.');
            const lp = int(this.app.game.cfg().osc_listen_port) || 9001;
            if (/^(127\.|localhost)/.test(m[1]) && int(m[2]) === lp) throw new UserError('Port ' + lp + ' ist der Empfangsport von VRC Nexus – das wäre eine Schleife.');
            targets.push({ host: m[1] === 'localhost' ? '127.0.0.1' : m[1], port: int(m[2]) });
        }
        if (targets.length > 8) throw new UserError('Höchstens 8 Ziele.');
        this.store('osc_fwd', { on: !!on && on !== '0', targets });
        return this.fwdCfg();
    }
    forward(buf) {
        const c = this.fwdCfg();
        if (!c.on || !c.targets.length) return;
        if (!this.fwdSock) { this.fwdSock = dgram.createSocket('udp4'); this.fwdSock.on('error', () => {}); this.fwdSock.unref(); }
        for (const t of c.targets) { try { this.fwdSock.send(buf, t.port, t.host); } catch (e) { /* egal */ } }
        this.fwdCount = (this.fwdCount || 0) + 1;
    }
    /** Port-Test: 1) Empfangsport offen? (Paket an uns selbst)  2) kommt etwas von VRChat?  3) Senden an VRChat */
    async test() {
        const o = this.osc, c = this.app.game.cfg(), lp = int(c.osc_listen_port) || 9001;
        const out = { send: { host: o.host, port: o.port, ok: false, error: '' }, listen: { port: lp, open: !!o.listener, error: o.listenError || '', self: false }, vrchat: { last: o.lastPacket || 0, active: o.active } };
        if (o.listener) {
            out.listen.self = await new Promise(res => {
                const t = setTimeout(() => { this.selftest = null; res(false); }, 1500);
                this.selftest = ok => { clearTimeout(t); this.selftest = null; res(ok); };
                const s = dgram.createSocket('udp4');
                s.send(require('./gamelog').oscEncode('/nexus/selftest', 1), lp, '127.0.0.1', () => s.close());
            });
        }
        try { await o.send('/chatbox/typing', false); out.send.ok = true; } catch (e) { out.send.error = e.message; }
        return out;
    }
    // ---- Szenen: aktuelle Avatar-Parameter (ohne VRChat-eigene) speichern & anwenden ----
    scenes() { return this.load('osc_scenes', []); }
    sceneSave(name, only) {
        const params = {};
        const want = arr(only).map(str).filter(Boolean);
        for (const [k, v] of Object.entries(this.osc.params || {})) {
            if (BUILTIN.has(k) || (want.length && !want.includes(k))) continue;
            if (typeof v !== 'boolean' && typeof v !== 'number') continue;
            params[k] = { type: typeOf(v), value: v };
        }
        if (!Object.keys(params).length) throw new UserError('Noch keine Avatar-Parameter empfangen – OSC in VRChat einschalten und kurz warten.');
        const l = this.scenes();
        if (l.length >= 50) throw new UserError('Höchstens 50 Szenen.');
        l.push({ id: id(), name: cut(str(name).trim(), 40) || 'Szene ' + (l.length + 1), avatar: str(this.osc.avatarId), params, at: Date.now() });
        this.store('osc_scenes', l);
        return l;
    }
    async sceneApply(sid) {
        const s = this.scenes().find(x => x.id === str(sid));
        if (!s) throw new UserError('Szene nicht gefunden.');
        let n = 0;
        for (const [k, p] of Object.entries(s.params)) {
            const v = p.type === 'bool' ? !!p.value : p.type === 'int' ? int(p.value) : { float: Number(p.value) };
            await this.osc.send('/avatar/parameters/' + k, v); n++;
        }
        return { n, other: !!(s.avatar && this.osc.avatarId && s.avatar !== this.osc.avatarId) };
    }
    sceneDel(sid) { this.store('osc_scenes', this.scenes().filter(x => x.id !== str(sid))); }
    // ---- Aufnahme & Wiedergabe ----
    recs() { return this.load('osc_recs', []).map(r => ({ id: r.id, name: r.name, dur: r.dur, n: r.events.length, at: r.at })); }
    recStart(name, max) {
        if (this.app.mobile) throw new UserError('Nur in der Windows-App.');
        if (this.rec) throw new UserError('Aufnahme läuft schon.');
        this.rec = { name: cut(str(name).trim(), 40), start: Date.now(), max: Math.max(5, Math.min(300, int(max) || 60)), events: [] };
        // Startzustand mitnehmen, damit die Wiedergabe von der gleichen Ausgangslage beginnt
        for (const [k, v] of Object.entries(this.osc.params || {})) if (!BUILTIN.has(k) && (typeof v === 'boolean' || typeof v === 'number')) this.rec.events.push([0, k, v]);
        return { recording: true, max: this.rec.max };
    }
    async recStop() {
        const r = this.rec;
        if (!r) throw new UserError('Es läuft keine Aufnahme.');
        this.rec = null;
        if (!r.events.some(e => e[0] > 0) && r.events.length === 0) throw new UserError('Nichts aufgenommen – es kamen keine Avatar-Parameter von VRChat (OSC an?).');
        const dur = Math.round((Date.now() - r.start) / 100) / 10;
        const l = this.load('osc_recs', []);
        if (l.length >= 20) l.shift();
        const rec = { id: id(), name: r.name || 'Aufnahme ' + new Date().toLocaleTimeString('de-DE'), dur, events: r.events, at: Date.now() };
        l.push(rec);
        this.store('osc_recs', l);
        return { id: rec.id, n: r.events.length, dur };
    }
    recPlay(rid, speed) {
        const r = this.load('osc_recs', []).find(x => x.id === str(rid));
        if (!r) throw new UserError('Aufnahme nicht gefunden.');
        this.stopPlay();
        const f = Math.max(0.25, Math.min(4, Number(speed) || 1));
        const timers = r.events.map(([t, k, v]) => setTimeout(() => {
            this.osc.send('/avatar/parameters/' + k, typeof v === 'boolean' ? v : Number.isInteger(v) ? v : { float: v }).catch(() => {});
        }, t / f));
        timers.push(setTimeout(() => { this.playing = null; }, r.dur * 1000 / f + 50));
        this.playing = { id: r.id, timers, until: Date.now() + r.dur * 1000 / f };
        return { playing: r.id, dur: r.dur / f };
    }
    stopPlay() { if (this.playing) { this.playing.timers.forEach(clearTimeout); this.playing = null; } }
    recDel(rid) { this.store('osc_recs', this.load('osc_recs', []).filter(x => x.id !== str(rid))); }

    status() {
        const o = this.osc;
        return { fwd: Object.assign(this.fwdCfg(), { count: this.fwdCount || 0 }), scenes: this.scenes().map(s => ({ id: s.id, name: s.name, n: Object.keys(s.params).length, avatar: s.avatar, at: s.at })),
            recs: this.recs(), recording: this.rec ? { name: this.rec.name, sec: Math.round((Date.now() - this.rec.start) / 1000), max: this.rec.max, n: this.rec.events.length } : null,
            playing: this.playing ? { id: this.playing.id, left: Math.max(0, Math.round((this.playing.until - Date.now()) / 1000)) } : null,
            avatar: str(o.avatarId), live: Object.keys(o.params || {}).filter(k => !BUILTIN.has(k)).length, active: o.active };
    }

    async action(act, input) {
        switch (act) {
            case 'cbx_get': return this.cbx();
            case 'cbx_preview': { const t = this.fill(input.text); return { text: t, len: Array.from(t).length }; }
            case 'cbx_send': await this.send(input.text, input.sound); return Object.assign(this.cbx(), { msg: '💬 gesendet' });
            case 'cbx_quick_save': this.quickSave(input.text, input.label); return Object.assign(this.cbx(), { msg: 'Schnelltext gespeichert.' });
            case 'cbx_quick_del': this.quickDel(input.id); return this.cbx();
            case 'cbx_hist_clear': this.store('cbx_hist', []); return this.cbx();
            case 'cbx_sched_add': { const w = this.schedAdd(input.text, input.at, input.mins); return Object.assign(this.cbx(), { msg: 'Geplant für ' + new Date(w).toLocaleString('de-DE', { weekday: 'short', hour: '2-digit', minute: '2-digit' }) + '.' }); }
            case 'cbx_sched_del': this.schedDel(input.id); return this.cbx();
            case 'osct_status': return this.status();
            case 'osct_test': return this.test();
            case 'osct_fwd': return Object.assign(this.status(), { fwd: this.fwdSave(input.on, input.targets), msg: 'Weiterleitung gespeichert.' });
            case 'osct_scene_save': this.sceneSave(input.name, input.only); return Object.assign(this.status(), { msg: 'Szene gespeichert.' });
            case 'osct_scene_apply': { const r = await this.sceneApply(input.id); return Object.assign(this.status(), { msg: r.n + ' Parameter gesetzt' + (r.other ? ' (Szene stammt von einem anderen Avatar)' : '') + '.' }); }
            case 'osct_scene_del': this.sceneDel(input.id); return this.status();
            case 'osct_rec_start': this.recStart(input.name, input.max); return Object.assign(this.status(), { msg: '⏺ Aufnahme läuft …' });
            case 'osct_rec_stop': { const r = await this.recStop(); return Object.assign(this.status(), { msg: 'Aufnahme gespeichert: ' + r.n + ' Änderungen in ' + r.dur + ' s.' }); }
            case 'osct_rec_play': this.recPlay(input.id, input.speed); return Object.assign(this.status(), { msg: '▶ Wiedergabe läuft …' });
            case 'osct_rec_stopplay': this.stopPlay(); return this.status();
            case 'osct_rec_del': this.recDel(input.id); return this.status();
        }
        throw new UserError('Unbekannte Aktion.');
    }
}
module.exports = { OscTools };
