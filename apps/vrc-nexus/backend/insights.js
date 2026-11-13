'use strict';
/*
 * Auswertungen & Erinnerungen:
 *  - Rückblick (Woche/Monat/Jahr = „VRChat Wrapped“): Spielzeit, Welten, Leute, Avatare, Fotos, längste Sitzung, Lieblingstag/-uhrzeit
 *  - Freundschafts-Jahrestage + Geburtstage (aus der Notiz: „Geburtstag 24.12.“ oder „🎂 24.12.“)
 *  - Inaktive Freunde, Namensverlauf
 *  - Erinnerung an Gruppen-Events N Minuten vorher, Jahrestage einmal am Tag
 */
const { str, int, now, UserError } = require('./util');
const UID = 1;

const DAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
const BDAY = /(?:geburtstag|geb\.?|birthday|🎂)\s*:?\s*(\d{1,2})\.(\d{1,2})\.?/i;

class Insights {
    constructor(app) { this.app = app; this.timer = null; this.reminded = new Set(); this.annivDay = ''; }
    get db() { return this.app.db; }

    recap(days) {
        days = Math.max(1, Math.min(3650, int(days) || 7));
        const g = this.app.game, since = now() - days * 86400;
        const visits = g.visits(days);
        const playtime = visits.reduce((a, v) => a + v.seconds, 0);
        const worlds = {};
        for (const v of visits) { const k = v.world_id || v.name; const w = worlds[k] || (worlds[k] = { id: v.world_id, name: v.name || v.world_id, seconds: 0, visits: 0 }); w.seconds += v.seconds; w.visits++; }
        const longest = visits.slice().sort((a, b) => b.seconds - a.seconds)[0] || null;
        const people = g.players(days).slice(0, 300);
        const q = (sql, ...a) => this.db.prepare(sql).all(UID, since, ...a);
        const avatars = q("SELECT detail AS name, COUNT(*) AS n, MAX(ts) AS last FROM game_log WHERE user_id = ? AND ts >= ? AND kind = 'avatar' AND detail <> '' GROUP BY detail ORDER BY n DESC LIMIT 10");
        const shots = int(this.db.prepare("SELECT COUNT(*) FROM game_log WHERE user_id = ? AND ts >= ? AND kind = 'screenshot'").value(UID, since));
        const byDay = Array(7).fill(0), byHour = Array(24).fill(0);
        for (const v of visits) {
            // Spielzeit grob auf Wochentag/Stunde des Starts verteilen
            const d = new Date(v.start * 1000);
            byDay[d.getDay()] += v.seconds;
            byHour[d.getHours()] += v.seconds;
        }
        const activeDays = new Set(visits.map(v => new Date(v.start * 1000).toDateString())).size;
        let newFriends = [];
        try { newFriends = this.db.prepare("SELECT display_name AS name, vrc_id AS id, ts FROM feed WHERE user_id = ? AND ts >= ? AND type = 'friend_add' ORDER BY ts DESC LIMIT 30").all(UID, since); } catch (e) { newFriends = []; }
        // AFK/Schlaf-Verdacht: Sitzungen > 3 h ohne neue Spieler
        const afk = visits.filter(v => v.seconds > 3 * 3600 && v.players === 0).slice(0, 10).map(v => ({ name: v.name, start: v.start, seconds: v.seconds }));
        return {
            days, playtime, sessions: visits.length, active_days: activeDays, worlds_count: Object.keys(worlds).length, shots,
            top_worlds: Object.values(worlds).sort((a, b) => b.seconds - a.seconds).slice(0, 10),
            top_people: people.slice().sort((a, b) => (b.seconds || 0) - (a.seconds || 0) || b.meets - a.meets).slice(0, 10).map(p => ({ id: p.id || '', name: p.name, meets: p.meets, seconds: p.seconds || 0, friend: !!p.friend })),
            people_count: people.length, avatars, new_friends: newFriends,
            longest: longest ? { name: longest.name, start: longest.start, seconds: longest.seconds } : null,
            best_day: playtime ? DAYS[byDay.indexOf(Math.max(...byDay))] : '', best_hour: playtime ? byHour.indexOf(Math.max(...byHour)) : null, by_day: byDay, by_hour: byHour, afk,
        };
    }

    anniversaries(ahead = 30) {
        const out = [];
        let fr = [];
        try { fr = this.app.vrc.friendList(); } catch (e) { return out; }
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const inDays = (m, d) => { let t = new Date(today.getFullYear(), m - 1, d); if (t < today) t = new Date(today.getFullYear() + 1, m - 1, d); return Math.round((t - today) / 86400000); };
        for (const f of fr) {
            if (f.friend_since > 0) {
                const s = new Date(f.friend_since * 1000);
                const n = inDays(s.getMonth() + 1, s.getDate());
                const years = today.getFullYear() + (n > 0 && new Date(today.getFullYear(), s.getMonth(), s.getDate()) < today ? 1 : 0) - s.getFullYear();
                if (n <= ahead && years >= 1) out.push({ id: f.id, name: f.name, kind: 'friend', in_days: n, years, date: s.getDate() + '.' + (s.getMonth() + 1) + '.' });
            }
            const b = BDAY.exec(f.note || '');
            if (b) { const n = inDays(int(b[2]), int(b[1])); if (n <= ahead) out.push({ id: f.id, name: f.name, kind: 'birthday', in_days: n, date: int(b[1]) + '.' + int(b[2]) + '.' }); }
        }
        return out.sort((a, b) => a.in_days - b.in_days);
    }
    inactive(days) {
        days = Math.max(14, Math.min(3650, int(days) || 90));
        let fr = [];
        try { fr = this.app.vrc.friendList(); } catch (e) { return []; }
        const lim = Date.now() - days * 86400000;
        return fr.filter(f => f.state === 'offline' && f.last_login && Date.parse(f.last_login) < lim)
            .map(f => ({ id: f.id, name: f.name, last_login: f.last_login, days: Math.floor((Date.now() - Date.parse(f.last_login)) / 86400000), note: f.note, favorite: f.favorite }))
            .sort((a, b) => b.days - a.days);
    }
    names(q) {
        try {
            return this.db.prepare("SELECT vrc_id AS id, old, new, ts FROM feed WHERE user_id = ? AND type = 'name'" + (q ? ' AND (old LIKE ? OR new LIKE ?)' : '') + ' ORDER BY ts DESC LIMIT 300')
                .all(...(q ? [UID, '%' + q + '%', '%' + q + '%'] : [UID]));
        } catch (e) { return []; }
    }

    /** Foto-Verlauf: Screenshots je Weltbesuch (mit wem du dort warst) */
    photos(days) {
        days = Math.max(1, Math.min(3650, int(days) || 30));
        const path = require('path'), fs = require('fs');
        let base = '';
        try { base = path.resolve(this.app.tools.photoDir()); } catch (e) { base = ''; }
        const rows = this.db.prepare("SELECT ts, kind, world_id, world_name, player_name, detail FROM game_log WHERE user_id = ? AND ts >= ? AND kind IN ('world_join', 'player_join', 'screenshot') ORDER BY ts, id").all(UID, now() - days * 86400);
        const visits = [];
        let cur = null;
        for (const r of rows) {
            if (r.kind === 'world_join') { cur = { world: r.world_name || r.world_id, world_id: r.world_id, start: r.ts, people: new Set(), photos: [] }; visits.push(cur); continue; }
            if (!cur) { if (r.kind !== 'screenshot') continue; cur = { world: r.world_name || 'Unbekannte Welt', world_id: r.world_id || '', start: r.ts, people: new Set(), photos: [] }; visits.push(cur); }
            if (r.kind === 'player_join' && r.player_name) cur.people.add(r.player_name);
            if (r.kind === 'screenshot') {
                const f = path.resolve(str(r.detail));
                const rel = base && f.startsWith(base + path.sep) ? path.relative(base, f) : '';
                cur.photos.push({ ts: r.ts, rel, name: path.basename(f), exists: !!rel && fs.existsSync(f) });
            }
        }
        return visits.filter(v => v.photos.length).reverse().slice(0, 100).map(v => Object.assign(v, { people: [...v.people].slice(0, 30) }));
    }

    // ---------- Erinnerungen ----------
    tick() {
        if (this.app.mod) this.app.mod.tick().catch(() => {});
        const s = this.app.settings;
        const t = this.app.tools;
        if (s.get('notify.events')) {
            const min = Math.max(1, int(s.get('notify.event_minutes')) || 15);
            let ev = [];
            try { ev = this.app.vrc.calendarList(); } catch (e) { ev = []; }
            const n = now();
            for (const e of ev) {
                const st = int(e.starts);
                if (st > n && st - n <= min * 60 && !this.reminded.has(e.id)) {
                    this.reminded.add(e.id);
                    t.alert('📅 ' + str(e.title), 'beginnt in ' + Math.max(1, Math.round((st - n) / 60)) + ' min' + (e.group_name ? ' · ' + e.group_name : ''), 'event');
                }
            }
        }
        const day = new Date().toDateString();
        if (s.get('notify.anniversary') && day !== this.annivDay && new Date().getHours() >= 9) {
            this.annivDay = day;
            for (const a of this.anniversaries(0)) t.alert(a.kind === 'birthday' ? '🎂 ' + a.name + ' hat heute Geburtstag!' : '💛 ' + a.years + ' Jahre Freunde mit ' + a.name, 'Heute, ' + a.date);
        }
    }
    start() { if (this.app.mobile) return; this.timer = setInterval(() => { try { this.tick(); } catch (e) { /* egal */ } }, 60000); if (this.timer.unref) this.timer.unref(); }
    stop() { clearInterval(this.timer); }

    action(act, input) {
        switch (act) {
            case 'ins_recap': return this.recap(input.days);
            case 'ins_photos': return { visits: this.photos(input.days) };
            case 'ins_friends': return { anniversaries: this.anniversaries(int(input.ahead) || 30), inactive: this.inactive(input.days), names: this.names(str(input.q).trim()) };
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { Insights };
