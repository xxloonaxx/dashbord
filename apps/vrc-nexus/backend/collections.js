'use strict';
/*
 * Eigene Sammlungen für Welten und Avatare – unabhängig von den VRChat-Favoriten (ohne deren Begrenzung),
 * mit Tags („zum Chillen“, „Spiele“, „Quest-tauglich“ …), Notiz und Zufallsauswahl aus einem Tag.
 * Avatare bekommen einen Hinweis, wenn sie für Quest/Android „Very Poor“ sind.
 */
const { str, cut, now, UserError, VrcAuthError } = require('./util');

const TAG = t => cut(str(t).trim().toLowerCase().replace(/[^\p{L}\p{N} _+-]/gu, ''), 30);

class Collections {
    constructor(app) { this.app = app; }
    all() { try { return JSON.parse(this.app.db.setting('collections', '[]')) || []; } catch (e) { return []; } }
    write(l) { this.app.db.setSetting('collections', JSON.stringify(l.slice(0, 5000))); }
    list(kind, tag, q) {
        let l = this.all().filter(x => !kind || x.kind === kind);
        if (tag) l = l.filter(x => x.tags.includes(tag));
        if (q) { q = q.toLowerCase(); l = l.filter(x => (x.name + ' ' + x.author + ' ' + x.note + ' ' + x.tags.join(' ')).toLowerCase().includes(q)); }
        const tags = {};
        for (const x of this.all().filter(y => !kind || y.kind === kind)) for (const t of x.tags) tags[t] = (tags[t] || 0) + 1;
        return { items: l.sort((a, b) => b.added - a.added), tags: Object.entries(tags).sort((a, b) => b[1] - a[1]).map(([t, n]) => ({ tag: t, n })), total: this.all().length };
    }
    async add(id, tags, note) {
        id = str(id).trim();
        const m = /(wrld|avtr)_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i.exec(id);
        if (!m) throw new UserError('Bitte eine Welt- oder Avatar-ID bzw. einen Link einfügen.');
        id = m[0];
        const kind = m[1] === 'wrld' ? 'world' : 'avatar';
        const acc = this.app.vrc.account();
        if (!acc) throw new VrcAuthError('Kein VRChat-Konto verbunden.');
        const d = await this.app.vrc.call(acc, 'GET', '/' + (kind === 'world' ? 'worlds' : 'avatars') + '/' + id);
        const perf = (d.unityPackages || []).filter(p => p.performanceRating).map(p => ({ platform: str(p.platform), rating: str(p.performanceRating) }));
        const l = this.all().filter(x => x.id !== id);
        const old = this.all().find(x => x.id === id);
        l.push({ id, kind, name: str(d.name), author: str(d.authorName), thumb: str(d.thumbnailImageUrl), status: str(d.releaseStatus),
            tags: [...new Set((old ? old.tags : []).concat(str(tags).split(',').map(TAG).filter(Boolean)))].slice(0, 20), note: cut(str(note || (old && old.note) || ''), 300),
            perf, quest_poor: perf.some(p => /android|quest/i.test(p.platform) && /verypoor|very poor/i.test(p.rating)), added: old ? old.added : now() });
        this.write(l);
        return l.find(x => x.id === id);
    }
    update(id, tags, note) {
        const l = this.all();
        const x = l.find(y => y.id === str(id));
        if (!x) throw new UserError('Nicht in der Sammlung.');
        if (tags !== undefined) x.tags = [...new Set(str(tags).split(',').map(TAG).filter(Boolean))].slice(0, 20);
        if (note !== undefined) x.note = cut(str(note), 300);
        this.write(l);
        return x;
    }
    remove(id) { this.write(this.all().filter(x => x.id !== str(id))); }
    random(kind, tag) {
        const l = this.list(kind, tag).items;
        if (!l.length) throw new UserError('Nichts in dieser Auswahl.');
        return l[Math.floor(Math.random() * l.length)];
    }
    async action(act, input) {
        switch (act) {
            case 'coll_list': return this.list(str(input.kind), TAG(input.tag), str(input.q).trim());
            case 'coll_add': return { item: await this.add(input.id, input.tags, input.note), msg: 'Zur Sammlung hinzugefügt.' };
            case 'coll_update': return { item: this.update(input.id, input.tags, input.note), msg: 'Gespeichert.' };
            case 'coll_remove': this.remove(input.id); return { msg: 'Entfernt.' };
            case 'coll_random': return { item: this.random(str(input.kind), TAG(input.tag)) };
            case 'coll_has': return { item: this.all().find(x => x.id === str(input.id)) || null };
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { Collections };
