'use strict';
/*
 * Favoriten (VRChat-Gruppen + lokale Listen + Verlauf), Benachrichtigungen (alt + V2, mit Verlauf),
 * Shop, weltweite Spielerzahl und Avatar-Suche über VRCX-kompatible Datenbanken.
 * Portierung von inc/modules/vrchat_favs.php und inc/modules/vrchat_extra.php.
 */
const U = require('./util');
const { VrcAuthError, UserError, now, str, int, cut, arr } = U;
const { UID } = require('./vrchat');

const FAV_KINDS = { friend: 'friends', world: 'worlds', avatar: 'avatars' };
const FAV_LIMIT_DEFAULT = { friend: 150, world: 100, avatar: 50 };

const NOTIF_TYPES = {
    friendRequest: ['🤝', 'Freundschaftsanfrage'], invite: ['📨', 'Einladung'], requestInvite: ['🙋', 'Einladungsanfrage'], inviteResponse: ['↩️', 'Antwort auf Einladung'],
    requestInviteResponse: ['↩️', 'Antwort auf Anfrage'], votetokick: ['🗳️', 'Abstimmung zum Kicken'], message: ['💬', 'Nachricht'], halp: ['🆘', 'Hilfe'],
    'group.announcement': ['📢', 'Gruppen-Ankündigung'], 'group.invite': ['✉️', 'Gruppen-Einladung'], 'group.joinRequest': ['🚪', 'Beitrittsanfrage'],
    'group.informative': ['ℹ️', 'Gruppen-Info'], 'group.post': ['📝', 'Gruppen-Beitrag'], 'group.event.created': ['📅', 'Neues Gruppen-Event'],
    'group.event.starting': ['⏰', 'Event beginnt'], 'group.transfer': ['🔁', 'Gruppen-Übergabe'], 'event.announcement': ['🎉', 'Event'], boop: ['👃', 'Boop'],
    'badge.earned': ['🏅', 'Abzeichen'], 'economy.alert': ['💰', 'Shop-Hinweis'], 'economy.received.gift': ['🎁', 'Geschenk erhalten'], 'vrcplus.gift': ['💎', 'VRC+ geschenkt'],
    'promo.redeem': ['🎟️', 'Gutschein'], 'avatarreview.success': ['✅', 'Avatar geprüft'], 'avatarreview.failure': ['❌', 'Avatar abgelehnt'],
    'moderation.warning.group': ['⚠️', 'Gruppen-Verwarnung'], 'moderation.notice': ['🛡️', 'Moderations-Hinweis'], 'moderation.report.closed': ['📋', 'Meldung abgeschlossen'],
    'moderation.contentrestriction': ['🚫', 'Inhaltsbeschränkung'], 'invite.instance.contentGated': ['🔒', 'Einladung (eingeschränkt)'], 'text.adventure': ['📖', 'Textabenteuer'],
};
const NOTIF_RESPONSES = { accept: '✓ Annehmen', decline: '✕ Ablehnen', block: '⛔ Blockieren', unsubscribe: '🔕 Abbestellen', delete: '🗑 Löschen', boop: '👃 Zurück-Boopen' };
const AVATAR_PROVIDERS_DEFAULT = ['https://api.avtrdb.com/v3/avatar/search/vrcx', 'https://paw-api.amelia.fun/vrcx_search'];

class Extra {
    constructor(vrc) { this.vrc = vrc; this.db = vrc.db; }

    // =========================== Favoriten ===========================
    favKind(k) { if (!FAV_KINDS[k]) throw new UserError('Unbekannte Favoriten-Art.'); return k; }

    async favLimits(acc) {
        let c = null;
        try { c = JSON.parse(this.db.setting('vrc_fav_limits', '')); } catch (e) { c = null; }
        if (c && c.t > now() - 3600) return c.l;
        const l = Object.assign({}, FAV_LIMIT_DEFAULT);
        try {
            const r = await this.vrc.call(acc, 'GET', '/auth/user/favoritelimits');
            for (const k of ['friend', 'world', 'avatar']) if (r.maxFavoritesPerGroup && r.maxFavoritesPerGroup[k]) l[k] = int(r.maxFavoritesPerGroup[k]);
        } catch (e) { if (e instanceof VrcAuthError) throw e; }
        this.db.setSetting('vrc_fav_limits', JSON.stringify({ t: now(), l }));
        return l;
    }

    async favGroups(acc, kind) {
        const out = [];
        for (const g of arr(await this.vrc.call(acc, 'GET', '/favorite/groups', { type: kind, n: 50 }))) {
            if (!g || !g.name) continue;
            out.push({ name: g.name, display: str(g.displayName || g.name), visibility: str(g.visibility || 'private'), id: str(g.id) });
        }
        return out.sort((a, b) => a.name.localeCompare(b.name, 'en', { numeric: true }));
    }

    async favRemote(acc, kind) {
        const items = [];
        if (kind === 'friend') {
            const favs = await this.vrc.favoritesAll(acc, 'friend');
            const friends = {};
            for (const f of this.vrc.friendList()) friends[f.id] = f;
            for (const f of favs) {
                if (!f.favoriteId) continue;
                const fr = friends[f.favoriteId];
                let where = '';
                if (fr) where = fr.state === 'offline' ? '' : ((fr.loc.world_name || '') !== '' ? fr.loc.world_name + ' · ' + (fr.loc.access || '') : (fr.state === 'active' ? 'Web/App' : str(fr.loc.kind).replace(/^./, c => c.toUpperCase())));
                items.push({ id: f.favoriteId, fav_id: f.id, group: str(arr(f.tags)[0]), name: fr ? fr.name : f.favoriteId, image: fr ? fr.image : '', sub: fr ? (fr.status_desc || where) : 'kein Freund mehr?',
                    where, state: fr ? fr.state : 'offline', status: fr ? fr.status : 'offline', color: fr ? fr.trust[1] : '', locked: false });
            }
            return items;
        }
        const path = kind === 'world' ? '/worlds/favorites' : '/avatars/favorites';
        for (let off = 0; off < 1000; off += 100) {
            const page = arr(await this.vrc.call(acc, 'GET', path, { n: 100, offset: off }));
            for (const x of page) {
                if (!x || !x.id) continue;
                items.push({ id: x.id, fav_id: str(x.favoriteId), group: str(x.favoriteGroup), name: str(x.name || '?'), image: str(x.thumbnailImageUrl || x.imageUrl),
                    sub: str(x.authorName) + (kind === 'world' && x.occupants !== undefined ? ' (' + int(x.occupants) + ')' : ''), author_id: str(x.authorId),
                    locked: (x.releaseStatus || 'public') !== 'public', occupants: int(x.occupants) });
            }
            if (page.length < 100) break;
        }
        return items;
    }

    localGroups(kind) {
        let rows = this.db.prepare(`SELECT g.name, (SELECT COUNT(*) FROM local_favs f WHERE f.user_id = g.user_id AND f.kind = g.kind AND f.grp = g.name) AS n
                                    FROM local_fav_groups g WHERE g.user_id = ? AND g.kind = ? ORDER BY g.sort, g.name COLLATE NOCASE`).all(UID, kind);
        if (!rows.length) {
            this.db.prepare('INSERT OR IGNORE INTO local_fav_groups (user_id, kind, name, sort) VALUES (?, ?, ?, 0)').run(UID, kind, 'Favoriten');
            rows = [{ name: 'Favoriten', n: 0 }];
        }
        return rows.map(r => ({ name: r.name, count: int(r.n) }));
    }
    localItems(kind) {
        return this.db.prepare('SELECT grp, vrc_id, name, image, sub, added FROM local_favs WHERE user_id = ? AND kind = ? ORDER BY added DESC').all(UID, kind)
            .map(r => ({ id: r.vrc_id, group: r.grp, name: r.name, image: r.image, sub: r.sub, added: int(r.added) }));
    }
    localGroupAdd(kind, name) {
        const sort = int(this.db.prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM local_fav_groups WHERE user_id = ? AND kind = ?').value(UID, kind));
        this.db.prepare('INSERT OR IGNORE INTO local_fav_groups (user_id, kind, name, sort) VALUES (?, ?, ?, ?)').run(UID, kind, name, sort);
    }
    localAdd(kind, grp, item) {
        grp = cut(str(grp).trim(), 40) || 'Favoriten';
        this.localGroupAdd(kind, grp);
        this.db.prepare('INSERT OR REPLACE INTO local_favs (user_id, kind, grp, vrc_id, name, image, sub, added) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            .run(UID, kind, grp, item.id, cut(item.name, 120), cut(item.image, 500), cut(item.sub, 120), now());
    }
    localRemove(kind, grp, id) { this.db.prepare('DELETE FROM local_favs WHERE user_id = ? AND kind = ? AND grp = ? AND vrc_id = ?').run(UID, kind, grp, id); }
    localOf(kind, id) { return this.db.prepare('SELECT grp FROM local_favs WHERE user_id = ? AND kind = ? AND vrc_id = ?').column(UID, kind, id); }

    historyAdd(kind, id, name, image, sub) {
        this.db.prepare('INSERT OR REPLACE INTO vrc_history (user_id, kind, vrc_id, name, image, sub, ts) VALUES (?, ?, ?, ?, ?, ?, ?)').run(UID, kind, id, cut(name, 120), cut(image, 500), cut(sub, 120), now());
        const t = this.db.prepare('SELECT ts FROM vrc_history WHERE user_id = ? AND kind = ? ORDER BY ts DESC LIMIT 1 OFFSET 99').value(UID, kind);
        if (t !== undefined) this.db.prepare('DELETE FROM vrc_history WHERE user_id = ? AND kind = ? AND ts < ?').run(UID, kind, int(t));
    }
    history(kind) {
        return this.db.prepare('SELECT vrc_id, name, image, sub, ts FROM vrc_history WHERE user_id = ? AND kind = ? ORDER BY ts DESC LIMIT 100').all(UID, kind)
            .map(r => ({ id: r.vrc_id, name: r.name, image: r.image, sub: r.sub, ts: int(r.ts) }));
    }

    // =========================== Benachrichtigungen ===========================
    normV1(n) {
        let d = n.details || {};
        if (typeof d === 'string') { try { d = JSON.parse(d) || {}; } catch (e) { d = {}; } }
        let msg = str(n.message);
        if (d.worldName) msg = ('🌐 ' + d.worldName + (msg ? ' · ' + msg : '')).trim();
        for (const k of ['inviteMessage', 'requestMessage', 'responseMessage']) if (d[k]) msg = (msg + ' „' + d[k] + '“').trim();
        return { id: str(n.id), src: 'v1', type: str(n.type), created: U.ts(n.created_at) || now(), sender_id: str(n.senderUserId), sender_name: str(n.senderUsername),
            group_id: '', group_name: '', image: '', title: '', message: cut(msg, 1000), link: '', responses: [], seen: !!n.seen, can_delete: true };
    }
    normV2(n) {
        const data = n.data && typeof n.data === 'object' ? n.data : {};
        const resp = arr(n.responses).filter(r => r && NOTIF_RESPONSES[r.type]).map(r => ({ type: r.type, data: str(r.data), text: str(r.text) }));
        return { id: str(n.id), src: 'v2', type: str(n.type), created: U.ts(n.createdAt) || now(), sender_id: str(n.senderUserId), sender_name: str(n.senderUsername),
            group_id: str(data.groupId), group_name: str(data.groupName), image: U.https(n.imageUrl), title: cut(n.title || data.announcementTitle, 300),
            message: cut(n.message, 1000), link: cut(n.link, 300), responses: resp, seen: !!n.seen, can_delete: n.canDelete !== false };
    }
    async notifSync(acc) {
        const items = [], errors = [];
        try { for (const n of arr(await this.vrc.call(acc, 'GET', '/auth/user/notifications', { n: 100 }))) if (n && n.id) items.push(this.normV1(n)); }
        catch (e) { if (e instanceof VrcAuthError) throw e; errors.push('Benachrichtigungen: ' + e.message); }
        try { for (const n of arr(await this.vrc.call(acc, 'GET', '/notifications', { limit: 100 }))) if (n && n.id) items.push(this.normV2(n)); }
        catch (e) { if (e instanceof VrcAuthError) throw e; errors.push('Gruppen-Benachrichtigungen: ' + e.message); }
        const db = this.db;
        db.tx(() => {
            if (!errors.length) db.prepare('UPDATE notif_log SET active = 0 WHERE user_id = ?').run(UID);
            const up = db.prepare(`INSERT INTO notif_log (user_id, id, src, type, created, sender_id, sender_name, group_id, group_name, image, title, message, link, responses, seen, active, can_delete)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                                   ON CONFLICT(user_id, id) DO UPDATE SET seen = excluded.seen, active = 1, responses = excluded.responses, message = excluded.message, title = excluded.title, image = excluded.image`);
            for (const n of items) up.run(UID, n.id, n.src, n.type, n.created, n.sender_id, n.sender_name, n.group_id, n.group_name, n.image, n.title, n.message, n.link,
                JSON.stringify(n.responses), n.seen ? 1 : 0, n.can_delete ? 1 : 0);
        });
        return errors;
    }
    notifList(f) {
        const where = ['user_id = ?'], args = [UID];
        if ((f.view || 'open') === 'open') where.push('active = 1');
        if (f.type) { where.push('type = ?'); args.push(str(f.type)); }
        const q = str(f.q).trim();
        if (q) { where.push('(sender_name LIKE ? OR group_name LIKE ? OR message LIKE ? OR title LIKE ?)'); args.push('%' + q + '%', '%' + q + '%', '%' + q + '%', '%' + q + '%'); }
        const items = this.db.prepare('SELECT * FROM notif_log WHERE ' + where.join(' AND ') + ' ORDER BY created DESC LIMIT 300').all(...args).map(r => ({
            id: r.id, src: r.src, type: r.type, created: int(r.created), sender_id: r.sender_id, sender_name: r.sender_name, group_id: r.group_id, group_name: r.group_name,
            image: r.image, title: r.title, message: r.message, link: r.link, responses: (() => { try { return JSON.parse(r.responses) || []; } catch (e) { return []; } })(),
            seen: !!r.seen, active: !!r.active, can_delete: !!r.can_delete }));
        const types = {};
        for (const r of this.db.prepare('SELECT type, COUNT(*) AS n FROM notif_log WHERE user_id = ? GROUP BY type ORDER BY n DESC').all(UID)) types[r.type] = int(r.n);
        return { items, types, unseen: int(this.db.prepare('SELECT COUNT(*) FROM notif_log WHERE user_id = ? AND active = 1 AND seen = 0').value(UID)) };
    }
    async notifAction(acc, id, op, rtype = '') {
        if (!/^[a-z]{2,6}_[0-9a-f-]{8,40}$/i.test(id)) throw new UserError('Ungültige Benachrichtigung.');
        const n = this.db.prepare('SELECT * FROM notif_log WHERE user_id = ? AND id = ?').get(UID, id);
        if (!n) throw new UserError('Benachrichtigung nicht gefunden – bitte neu laden.');
        const done = set => this.db.prepare('UPDATE notif_log SET ' + Object.keys(set).map(k => k + ' = ?').join(', ') + ' WHERE user_id = ? AND id = ?').run(...Object.values(set), UID, id);
        const enc = encodeURIComponent(id);
        switch (op) {
            case 'accept':
                if (n.src !== 'v1' || n.type !== 'friendRequest') throw new UserError('Nur Freundschaftsanfragen lassen sich so annehmen.');
                await this.vrc.call(acc, 'PUT', '/auth/user/notifications/' + enc + '/accept');
                done({ active: 0, seen: 1 });
                return 'Angenommen – ' + n.sender_name + ' ist jetzt befreundet.';
            case 'respond': {
                if (n.src !== 'v2') throw new UserError('Diese Benachrichtigung hat keine Antwortmöglichkeiten.');
                let allowed = [];
                try { allowed = JSON.parse(n.responses) || []; } catch (e) { allowed = []; }
                const r = allowed.find(x => x.type === rtype);
                if (!r) throw new UserError('Diese Antwort ist hier nicht möglich.');
                await this.vrc.call(acc, 'POST', '/notifications/' + enc + '/respond', {}, { responseType: rtype, responseData: str(r.data) });
                done({ active: rtype === 'unsubscribe' ? 1 : 0, seen: 1 });
                return { accept: 'Angenommen.', decline: 'Abgelehnt.', block: 'Blockiert.', unsubscribe: 'Abbestellt – davon kommt nichts mehr.', delete: 'Gelöscht.', boop: 'Zurück-gebooped! 👃' }[rtype];
            }
            case 'see':
                if (n.src === 'v2') await this.vrc.call(acc, 'POST', '/notifications/' + enc + '/see');
                else await this.vrc.call(acc, 'PUT', '/auth/user/notifications/' + enc + '/see');
                done({ seen: 1 });
                return 'Als gelesen markiert.';
            case 'delete':
                if (n.active) {
                    if (n.src === 'v2') await this.vrc.call(acc, 'DELETE', '/notifications/' + enc);
                    else await this.vrc.call(acc, 'PUT', '/auth/user/notifications/' + enc + '/hide');
                }
                done({ active: 0, seen: 1 });
                return 'Bei VRChat entfernt (bleibt im Verlauf).';
            case 'forget':
                this.db.prepare('DELETE FROM notif_log WHERE user_id = ? AND id = ?').run(UID, id);
                return 'Aus dem Verlauf gelöscht.';
        }
        throw new UserError('Unbekannte Aktion.');
    }
    notifClear() { return this.db.prepare('DELETE FROM notif_log WHERE user_id = ? AND active = 0').run(UID).changes; }

    // =========================== Shop ===========================
    listingSlim(l) {
        const kinds = [l.hasAvatar && 'Avatar', l.hasUdon && 'Welt-Inhalt', l.hasInventory && 'Inventar', l.hasCompanion && 'Begleiter'].filter(Boolean);
        return { id: str(l.id), name: str(l.displayName), subtitle: str(l.subtitle), desc: cut(l.description, 600), price: int(l.priceTokens),
            vrcplus_price: l.vrcPlusDiscountPrice !== undefined && l.vrcPlusDiscountPrice !== null ? int(l.vrcPlusDiscountPrice) : null,
            image: U.https(l.imageUrl), image_id: /^file_[0-9a-f-]{36}$/i.test(str(l.imageId)) ? l.imageId : '', type: str(l.listingType), kinds, seller: str(l.sellerDisplayName),
            seller_id: str(l.sellerId), group: str(l.groupName), duration: l.duration === undefined ? null : l.duration, duration_type: str(l.durationType), active: !!l.active,
            sold: int(l.purchaseCount), by_vrc: !!l.soldByVrc, permanent: !!l.permanent };
    }
    async shopOverview(acc) {
        const me = acc.vrc_id;
        const out = { balance: null, subs: [], licenses: 0, purchases: [], listings: [], errors: [] };
        const tryIt = async (label, fn) => { try { await fn(); } catch (e) { if (e instanceof VrcAuthError) throw e; out.errors.push(label + ': ' + e.message); } };
        await tryIt('Guthaben', async () => { const b = await this.vrc.call(acc, 'GET', '/user/' + encodeURIComponent(me) + '/balance'); out.balance = int(b.balance); });
        await tryIt('Abos', async () => {
            for (const s of arr(await this.vrc.call(acc, 'GET', '/auth/user/subscription'))) if (s) out.subs.push({ name: str(s.description || s.id), active: !!s.active, status: str(s.status),
                expires: str(s.expires), starts: str(s.starts), period: str(s.period), store: str(s.store), gift: !!s.isGift });
        });
        await tryIt('Lizenzen', async () => { out.licenses = arr(await this.vrc.call(acc, 'GET', '/economy/licenses/active')).length; });
        await tryIt('Käufe', async () => {
            for (const p of arr(await this.vrc.call(acc, 'GET', '/economy/purchases', { buyerId: me, n: 100, mostRecent: 'true' }))) {
                if (!p) continue;
                out.purchases.push({ id: str(p.purchaseId), name: str(p.listingDisplayName), subtitle: str(p.listingSubtitle), seller: str(p.sellerDisplayName), seller_id: str(p.sellerId),
                    price: int(p.purchasePrice), date: str(p.purchaseDate), end: str(p.purchaseEndDate), active: !!p.purchaseActive, gift: !!p.isGift, receiver: str(p.receiverDisplayName),
                    type: str(p.listingType), image_id: /^file_[0-9a-f-]{36}$/i.test(str(p.listingImageId)) ? p.listingImageId : '', recurs: !!p.willRecur });
            }
        });
        await tryIt('Eigene Angebote', async () => {
            for (const l of arr(await this.vrc.call(acc, 'GET', '/user/' + encodeURIComponent(me) + '/listings', { n: 100, hydrate: 'true' }))) if (l) out.listings.push(this.listingSlim(l));
        });
        return out;
    }
    async shopBrowse(acc, target) {
        target = str(target).trim();
        const m = target.match(/(usr_[0-9a-f-]{36}|esto_[0-9a-f-]{36})/i);
        if (m) target = m[1];
        if (target.startsWith('usr_')) {
            const u = await this.vrc.call(acc, 'GET', '/users/' + encodeURIComponent(target));
            const list = [];
            for (let off = 0; off < 300; off += 100) {
                const page = arr(await this.vrc.call(acc, 'GET', '/user/' + encodeURIComponent(target) + '/listings', { n: 100, offset: off, hydrate: 'true', active: 'true' }));
                for (const l of page) if (l) list.push(this.listingSlim(l));
                if (page.length < 100) break;
            }
            return { title: 'Shop von ' + (u.displayName || target), shelves: [{ title: 'Angebote', desc: '', listings: list }] };
        }
        if (target.startsWith('esto_')) {
            const s = await this.vrc.call(acc, 'GET', '/economy/store', { storeId: target, hydrateListings: 'true' });
            const shelves = [];
            if (arr(s.listings).length) shelves.push({ title: 'Angebote', desc: '', listings: arr(s.listings).map(l => this.listingSlim(l)) });
            try {
                for (const sh of arr(await this.vrc.call(acc, 'GET', '/economy/store/shelves', { storeId: target, hydrateListings: 'true' }))) {
                    if (sh) shelves.push({ title: str(sh.shelfTitle), desc: str(sh.shelfDescription), listings: arr(sh.listings).map(l => this.listingSlim(l)) });
                }
            } catch (e) { if (e instanceof VrcAuthError) throw e; }
            return { title: str(s.displayName || 'Store'), seller: str(s.sellerDisplayName), shelves };
        }
        throw new UserError('Bitte eine Benutzer-ID (usr_…), einen Profil-Link oder eine Store-ID (esto_…) angeben.');
    }

    // =========================== Weltweite Statistik ===========================
    async visitsSample(force = false) {
        const last = this.db.prepare('SELECT ts, online FROM global_stats ORDER BY ts DESC LIMIT 1').get();
        if (!force && last && int(last.ts) > now() - 600) return int(last.online);
        let code, json;
        try { [code, json] = await this.vrc.http({}, 'GET', '/visits'); } catch (e) { return last ? int(last.online) : null; }
        if (code !== 200 || typeof json !== 'number') return last ? int(last.online) : null;
        this.db.prepare('INSERT OR REPLACE INTO global_stats (ts, online) VALUES (?, ?)').run(now(), json);
        this.db.prepare('DELETE FROM global_stats WHERE ts < ?').run(now() - 400 * 86400);
        return json;
    }
    async globalStats(days) {
        const nowCount = await this.visitsSample();
        const rows = this.db.prepare('SELECT ts, online FROM global_stats WHERE ts >= ? ORDER BY ts').all(now() - days * 86400).map(r => [int(r.ts), int(r.online)]);
        const vals = rows.map(r => r[1]);
        const heat = Array.from({ length: 7 }, () => Array.from({ length: 24 }, () => [0, 0]));
        for (const r of this.db.prepare('SELECT ts, online FROM global_stats').all()) { const c = heat[U.isoDay(r.ts) - 1][U.hourOf(r.ts)]; c[0] += r.online; c[1]++; }
        const rec = this.db.prepare('SELECT ts, online FROM global_stats ORDER BY online DESC LIMIT 1').get();
        const y = this.db.prepare('SELECT online FROM global_stats WHERE ts BETWEEN ? AND ? ORDER BY ABS(ts - ?) LIMIT 1').value(now() - 86400 - 1800, now() - 86400 + 1800, now() - 86400);
        return { now: nowCount, series: rows, min: vals.length ? Math.min(...vals) : null, max: vals.length ? Math.max(...vals) : null,
            avg: vals.length ? Math.round(vals.reduce((a, b) => a + b, 0) / vals.length) : null,
            heat: heat.map(row => row.map(c => (c[1] ? Math.round(c[0] / c[1]) : null))), record: rec ? { ts: int(rec.ts), online: int(rec.online) } : null,
            since: int(this.db.prepare('SELECT MIN(ts) FROM global_stats').value()), samples: rows.length, yesterday: y === undefined ? null : int(y) };
    }
    async hotWorlds(acc) {
        const out = [];
        for (const w of arr(await this.vrc.call(acc, 'GET', '/worlds', { sort: 'heat', order: 'descending', n: 24, featured: 'false' }))) {
            if (!w || !w.id) continue;
            out.push({ id: w.id, name: str(w.name), author: str(w.authorName), thumb: U.https(w.thumbnailImageUrl), occupants: int(w.occupants), favorites: int(w.favorites), heat: int(w.heat), visits: int(w.visits) });
        }
        return out.sort((a, b) => b.occupants - a.occupants);
    }

    // =========================== Avatar-Suche ===========================
    avatarProviders() {
        let list = null;
        try { list = JSON.parse(this.db.setting('vrc_avatar_providers', '')); } catch (e) { list = null; }
        return Array.isArray(list) && list.length ? list : AVATAR_PROVIDERS_DEFAULT;
    }
    avatarProvidersSave(text) {
        const out = [];
        for (const u of str(text).trim().split(/\s+/)) {
            if (!u) continue;
            if (!/^https:\/\/[a-z0-9.-]+(:\d+)?(\/[^\s?#]*)?$/i.test(u)) throw new UserError('Nur https-Adressen ohne ?-Teil erlaubt: ' + u);
            const host = new URL(u).hostname.toLowerCase();
            if (/^(localhost|.*\.local|.*\.internal)$/.test(host) || /^[\d.]+$/.test(host) || host.includes(':')) throw new UserError('Interne Adressen sind nicht erlaubt: ' + host);
            out.push(u);
        }
        this.db.setSetting('vrc_avatar_providers', JSON.stringify([...new Set(out)].slice(0, 8).length ? [...new Set(out)].slice(0, 8) : AVATAR_PROVIDERS_DEFAULT));
        return this.avatarProviders();
    }
    /** Suche läuft in der App direkt von deinem PC aus (wie VRCX) */
    async avatarDbSearch(provider, q) {
        const list = this.avatarProviders();
        if (!list[provider]) throw new UserError('Unbekannter Provider.');
        q = str(q).trim();
        if (Array.from(q).length < 2) throw new UserError('Bitte mindestens 2 Zeichen eingeben.');
        let params;
        let m;
        if ((m = q.match(/(usr_[0-9a-f-]{36})/i))) params = { authorId: m[1], n: 500 };
        else if ((m = q.match(/(avtr_[0-9a-f-]{36})/i))) params = { search: m[1], n: 50 };
        else params = { search: cut(q, 100), n: 500 };
        let res;
        try { res = await fetch(list[provider] + '?' + new URLSearchParams(params), { headers: { Accept: 'application/json', 'User-Agent': this.vrc.userAgent() }, redirect: 'error', signal: AbortSignal.timeout(20000) }); }
        catch (e) { throw new UserError('Provider nicht erreichbar: ' + (e.cause && e.cause.code || e.message)); }
        if (res.status === 404) return [];
        if (res.status !== 200) throw new UserError('Provider antwortet mit HTTP ' + res.status + '.');
        let data;
        try { data = await res.json(); } catch (e) { throw new UserError('Provider liefert kein gültiges Ergebnis.'); }
        if (!Array.isArray(data)) throw new UserError('Provider liefert kein gültiges Ergebnis.');
        const rated = r => r && r !== 'None';
        return data.slice(0, 500).filter(a => a && /^avtr_[0-9a-f-]{36}$/i.test(str(a.id))).map(a => {
            const p = a.performance && typeof a.performance === 'object' ? a.performance : {};
            return { id: a.id, name: cut(a.name, 120), author: cut(a.authorName, 64), author_id: /^usr_[0-9a-f-]{36}$/i.test(str(a.authorId)) ? a.authorId : '',
                thumb: U.https(a.thumbnailImageUrl) || U.https(a.imageUrl), desc: cut(a.description, 300), status: str(a.releaseStatus), updated: str(a.updated_at), created: str(a.created_at),
                platforms: [rated(p.pc_rating) && 'PC', rated(p.android_rating) && 'Quest / Android', rated(p.ios_rating) && 'iOS'].filter(Boolean),
                perf: Object.fromEntries([['PC', p.pc_rating], ['Quest', p.android_rating], ['iOS', p.ios_rating]].filter(x => x[1])) };
        });
    }
}

module.exports = { Extra, NOTIF_TYPES, NOTIF_RESPONSES, FAV_KINDS };
