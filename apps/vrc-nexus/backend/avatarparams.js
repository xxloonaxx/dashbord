'use strict';
/*
 * Avatar-Parameter erkennen: VRChat legt für jeden Avatar eine OSC-Konfiguration ab
 *   %LocalLow%\VRChat\VRChat\OSC\usr_…\Avatars\avtr_….json
 * mit allen Parametern (Name, Typ, Ein-/Ausgang). Zusammen mit den per OSC empfangenen Werten ergibt das
 * eine vollständige Liste – auch Parameter, die sich noch nie geändert haben.
 */
const fs = require('fs');
const path = require('path');
const { str, cut, UserError } = require('./util');

/* Von VRChat selbst gesetzte Parameter – nicht steuerbar oder für die Bedienung uninteressant */
const BUILTIN = new Set(['IsLocal', 'PreviewMode', 'Viseme', 'Voice', 'GestureLeft', 'GestureRight', 'GestureLeftWeight', 'GestureRightWeight', 'AngularY', 'VelocityX', 'VelocityY',
    'VelocityZ', 'VelocityMagnitude', 'Upright', 'Grounded', 'Seated', 'AFK', 'TrackingType', 'VRMode', 'MuteSelf', 'InStation', 'Earmuffs', 'IsOnFriendsList', 'AvatarVersion',
    'ScaleModified', 'ScaleFactor', 'ScaleFactorInverse', 'EyeHeightAsMeters', 'EyeHeightAsPercent', 'IsAnimatorEnabled']);

class AvatarParams {
    constructor(app) { this.app = app; this.cache = new Map(); }
    root() { return path.join(this.app.tools.vrchatDir(), 'OSC'); }

    /** alle Avatar-Konfigurationen: [{ id, name, file, user, mtime }] */
    avatars() {
        const out = [];
        let users = [];
        try { users = fs.readdirSync(this.root()).filter(u => /^usr_[0-9a-f-]{36}$/i.test(u)); } catch (e) { return out; }
        for (const u of users) {
            const dir = path.join(this.root(), u, 'Avatars');
            let files = [];
            try { files = fs.readdirSync(dir).filter(f => /^avtr_[0-9a-f-]{36}\.json$/i.test(f)); } catch (e) { continue; }
            for (const f of files) {
                const file = path.join(dir, f);
                const cfg = this.read(file);
                let mtime = 0;
                try { mtime = Math.floor(fs.statSync(file).mtimeMs / 1000); } catch (e) { /* egal */ }
                if (cfg) out.push({ id: f.slice(0, -5), name: str(cfg.name) || f.slice(0, -5), user: u, file, mtime, count: (cfg.parameters || []).length });
            }
        }
        return out.sort((a, b) => b.mtime - a.mtime);
    }
    read(file) {
        let mtime = 0;
        try { mtime = fs.statSync(file).mtimeMs; } catch (e) { return null; }
        const c = this.cache.get(file);
        if (c && c.mtime === mtime) return c.cfg;
        let cfg = null;
        try { cfg = JSON.parse(fs.readFileSync(file, 'utf8').replace(/^﻿/, '')); } catch (e) { cfg = null; }
        this.cache.set(file, { mtime, cfg });
        return cfg;
    }
    /** Parameter eines Avatars (Standard: der gerade getragene laut OSC) mit aktuellen Werten */
    list(avatarId) {
        const osc = this.app.game.osc;
        const id = str(avatarId) || str(osc.avatarId);
        const all = this.avatars();
        const av = all.find(a => a.id === id) || (!avatarId && all[0]) || null;
        const favs = this.favs(av ? av.id : id);
        const values = av && av.id === str(osc.avatarId) ? osc.params : {};
        let params = [];
        if (av) {
            for (const p of (this.read(av.file) || {}).parameters || []) {
                const name = str(p.name);
                const t = str((p.input || p.output || {}).type).toLowerCase();
                if (!name) continue;
                params.push({ name, type: t === 'bool' ? 'bool' : t === 'int' ? 'int' : 'float', input: !!p.input, builtin: BUILTIN.has(name), fav: favs.includes(name),
                    value: values[name] === undefined ? null : values[name] });
            }
        }
        // per OSC empfangene, aber nicht in der Datei stehende Parameter ergänzen
        if (!av || av.id === str(osc.avatarId)) {
            for (const [name, v] of Object.entries(osc.params)) {
                if (params.some(p => p.name === name)) continue;
                params.push({ name, type: typeof v === 'boolean' ? 'bool' : Number.isInteger(v) ? 'int' : 'float', input: true, builtin: BUILTIN.has(name), fav: favs.includes(name), value: v, live_only: true });
            }
        }
        params.sort((a, b) => (b.fav - a.fav) || (a.builtin - b.builtin) || a.name.localeCompare(b.name));
        return { avatar: av ? { id: av.id, name: av.name } : (id ? { id, name: '' } : null), current: str(osc.avatarId), osc_active: osc.active, params,
            avatars: all.slice(0, 200).map(a => ({ id: a.id, name: a.name, count: a.count })), dir: this.root() };
    }
    favs(avatarId) { try { return (JSON.parse(this.app.db.setting('param_favs', '{}')) || {})[avatarId] || []; } catch (e) { return []; } }
    toggleFav(avatarId, name) {
        if (!/^avtr_[0-9a-f-]{36}$/i.test(str(avatarId))) throw new UserError('Unbekannter Avatar.');
        let all = {};
        try { all = JSON.parse(this.app.db.setting('param_favs', '{}')) || {}; } catch (e) { all = {}; }
        const l = new Set(all[avatarId] || []);
        if (l.has(name)) l.delete(name); else l.add(cut(str(name), 64));
        all[avatarId] = [...l];
        this.app.db.setSetting('param_favs', JSON.stringify(all));
        return all[avatarId];
    }
}

module.exports = { AvatarParams, BUILTIN };
