'use strict';
/*
 * Mod-Zentrale für eigene Gruppen:
 *  - Beitrittsanfragen-Assistent: Vertrauensstufe, Kontoalter, 18+, Freund?, schon getroffen?, Warnliste → Vorschlag
 *  - Warnliste mit Notizen pro Person (lokal)
 *  - Geplante Gruppen-Beiträge (zu einer festen Zeit veröffentlichen)
 *  - Export: Moderations-Protokoll + Warnliste als CSV
 */
const { str, int, cut, now, valid, trust, UserError, VrcAuthError } = require('./util');
const UID = 1;

const csv = rows => '﻿' + rows.map(r => r.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(';')).join('\r\n');

class ModTools {
    constructor(app) { this.app = app; this.userCache = new Map(); this.posting = false; }
    get db() { return this.app.db; }
    acc() { const a = this.app.vrc.account(); if (!a) throw new VrcAuthError('Kein VRChat-Konto verbunden.'); return a; }
    call(m, p, q, b) { return this.app.vrc.call(this.acc(), m, p, q || {}, b || null); }
    get(key, def) { try { return JSON.parse(this.db.setting(key, def)) || JSON.parse(def); } catch (e) { return JSON.parse(def); } }
    set(key, v) { this.db.setSetting(key, JSON.stringify(v)); }

    // ---------- Warnliste ----------
    warns() { return this.get('mod_warn', '[]'); }
    warnAdd(input) {
        const user = valid(str(input.user), 'usr');
        const note = cut(str(input.note).trim(), 500);
        if (!note) throw new UserError('Bitte einen Grund eintragen.');
        const l = this.warns();
        l.unshift({ id: 'w' + Date.now().toString(36), user, name: cut(str(input.name).trim(), 64) || user, note, level: ['info', 'warn', 'ban'].includes(input.level) ? input.level : 'warn', group: cut(str(input.group), 64), ts: now() });
        this.set('mod_warn', l.slice(0, 2000));
        return this.warns();
    }
    warnDelete(id) { this.set('mod_warn', this.warns().filter(w => w.id !== str(id))); return this.warns(); }
    warnFor(user) { return this.warns().filter(w => w.user === user); }

    // ---------- Beitrittsanfragen-Assistent ----------
    async userInfo(id) {
        const c = this.userCache.get(id);
        if (c && c.t > Date.now() - 600000) return c.u;
        const u = await this.call('GET', '/users/' + encodeURIComponent(id));
        this.userCache.set(id, { t: Date.now(), u });
        return u;
    }
    async requests(group) {
        valid(group, 'grp');
        const reqs = await this.call('GET', '/groups/' + group + '/requests', { n: 100 });
        const out = [];
        const friends = new Set((() => { try { return this.app.vrc.friendList().map(f => f.id); } catch (e) { return []; } })());
        let n = 0;
        for (const r of (Array.isArray(reqs) ? reqs : [])) {
            const id = str(r.userId || (r.user && r.user.id));
            if (!id) continue;
            let u = r.user || {};
            if (n < 15) { try { u = Object.assign({}, u, await this.userInfo(id)); n++; } catch (e) { /* Grunddaten reichen */ } }
            const tr = trust(u.tags || []);
            const joined = Date.parse(str(u.date_joined));
            const age = isNaN(joined) ? null : Math.floor((Date.now() - joined) / 86400000);
            const met = int(this.db.prepare("SELECT COUNT(*) FROM game_log WHERE user_id = ? AND kind = 'player_join' AND player_id = ?").value(UID, id));
            const warn = this.warnFor(id);
            // einfache Einschätzung
            let score = 0;
            const why = [];
            const rank = ['Visitor', 'New User', 'User', 'Known User', 'Trusted User', 'VRChat-Team'].indexOf(tr[0]);
            if (rank >= 3) { score += 2; why.push('hohe Vertrauensstufe'); } else if (rank <= 1) { score -= 2; why.push('neues Konto/Besucher'); }
            if (age !== null) { if (age < 30) { score -= 2; why.push('Konto jünger als 30 Tage'); } else if (age > 365) { score += 1; why.push('Konto älter als 1 Jahr'); } }
            if (friends.has(id)) { score += 3; why.push('ist dein Freund'); }
            if (met) { score += 1; why.push(met + '× getroffen'); }
            if ((u.tags || []).includes('system_trust_legend') || (u.tags || []).includes('system_supporter')) score += 0;
            if (warn.length) { score -= warn.some(w => w.level === 'ban') ? 10 : 4; why.push('⚠ auf deiner Warnliste'); }
            if ((u.tags || []).some(t => t === 'system_probable_troll' || t === 'system_troll')) { score -= 6; why.push('⚠ von VRChat als Troll markiert'); }
            out.push({ id, name: str(u.displayName || '?'), image: str(u.userIcon || u.currentAvatarThumbnailImageUrl || ''), trust: tr[0], age_days: age, age_verified: str(u.ageVerificationStatus) === '18+',
                friend: friends.has(id), met, warn, bio: cut(str(u.bio), 300), pronouns: str(u.pronouns), created: str(r.createdAt), score,
                suggestion: score >= 2 ? 'accept' : score <= -3 ? 'reject' : 'check', why });
        }
        return out.sort((a, b) => b.score - a.score);
    }

    // ---------- Geplante Beiträge ----------
    scheduled() { return this.get('sched_posts', '[]'); }
    schedule(input) {
        const group = valid(str(input.group), 'grp');
        const title = cut(str(input.title).trim(), 120), text = cut(str(input.text).trim(), 2000);
        const at = int(input.at);
        if (!title || !text) throw new UserError('Bitte Titel und Text eingeben.');
        if (at < now() + 30) throw new UserError('Der Zeitpunkt muss in der Zukunft liegen.');
        const l = this.scheduled();
        l.push({ id: 's' + Date.now().toString(36), group, group_name: cut(str(input.group_name), 80), title, text, at, visibility: input.visibility === 'public' ? 'public' : 'group', notify: input.notify === '1' || input.notify === true, status: 'wartet', error: '' });
        this.set('sched_posts', l.slice(-200));
        return this.scheduled();
    }
    unschedule(id) { this.set('sched_posts', this.scheduled().filter(p => p.id !== str(id))); return this.scheduled(); }
    async tick() {
        if (this.posting || !this.app.vrc.account()) return;
        const l = this.scheduled();
        const due = l.filter(p => p.status === 'wartet' && p.at <= now());
        if (!due.length) return;
        this.posting = true;
        try {
            for (const p of due) {
                try {
                    await this.call('POST', '/groups/' + p.group + '/posts', {}, { title: p.title, text: p.text, visibility: p.visibility, sendNotification: !!p.notify, roleIds: [] });
                    p.status = 'veröffentlicht';
                    this.app.tools.alert('📢 Beitrag veröffentlicht', p.title + (p.group_name ? ' · ' + p.group_name : ''));
                } catch (e) { p.status = 'fehlgeschlagen'; p.error = cut(e.message, 200); }
            }
            this.set('sched_posts', l);
        } finally { this.posting = false; }
    }

    // ---------- Export ----------
    async exportCsv(group) {
        const rows = [['Quelle', 'Zeit', 'Wer', 'Art', 'Beschreibung']];
        if (group) {
            valid(group, 'grp');
            const r = await this.call('GET', '/groups/' + group + '/auditLogs', { n: 100 });
            for (const l of (r && r.results) || []) rows.push(['Gruppen-Protokoll', str(l.created_at), str(l.actorDisplayName), str(l.eventType), str(l.description)]);
        }
        for (const w of this.warns()) if (!group || !w.group || w.group === group) rows.push(['Warnliste', new Date(w.ts * 1000).toISOString(), w.name + ' (' + w.user + ')', w.level, w.note]);
        return csv(rows);
    }

    async action(act, input) {
        switch (act) {
            case 'mod_requests': return { items: await this.requests(str(input.group)), warns: this.warns().length };
            case 'mod_warns': return { warns: this.warns() };
            case 'mod_warn_add': return { warns: this.warnAdd(input), msg: 'Auf die Warnliste gesetzt.' };
            case 'mod_warn_delete': return { warns: this.warnDelete(input.id) };
            case 'mod_sched': return { posts: this.scheduled() };
            case 'mod_sched_add': return { posts: this.schedule(input), msg: 'Beitrag eingeplant.' };
            case 'mod_sched_delete': return { posts: this.unschedule(input.id) };
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { ModTools, csv };
