'use strict';
/*
 * VRChat-Anbindung (Portierung von inc/modules/vrchat.php für die eigenständige App):
 * Anmeldung mit 2FA, Freundes-Abgleich mit Verlauf, Statistik, Begegnungen, Zeitleiste, Welten-Zwischenspeicher,
 * Gruppenrechte, Kalender (Gruppen-Events), Dateien (Galerie) und Bilder-Proxy.
 *
 * Das Passwort wird nie gespeichert – nur das Sitzungs-Cookie, verschlüsselt über den Windows-Schlüsselspeicher (Electron safeStorage).
 */
const U = require('./util');
const { VrcAuthError, UserError, now, str, int, cut, arr, obj } = U;

const VRC_API_DEFAULT = 'https://api.vrchat.cloud/api/1';
const SYNC_MIN = 45;
const OFFLINE_SYNC = 900;
const FEED_KEEP_DAYS = 180;
const UID = 1;   // die App hat genau ein Konto
const VERSION = '1.3.7';

class Vrc {
    /**
     * @param db      Database (db.js)
     * @param crypt   { encrypt(string) → string, decrypt(string) → string }
     */
    constructor(db, crypt) {
        this.db = db;
        this.crypt = crypt;
        this.pending = null;   // laufende Anmeldung (2FA): { cookies, types, until }
    }

    // ---------- HTTP ----------
    apiBase() { return (process.env.NEXUS_API_BASE || this.db.setting('vrc_api_base', VRC_API_DEFAULT)).replace(/\/+$/, ''); }
    userAgent() { const ua = this.db.setting('vrc_user_agent', '').trim(); return ua || 'VRC-Nexus-Desktop/' + VERSION; }

    /** Anfrage an VRChat; cookies (Objekt) wird um neue Set-Cookie-Werte ergänzt. → [Status, JSON|null] */
    async http(cookies, method, path, query = {}, body = null, headers = {}) {
        const qs = new URLSearchParams();
        for (const [k, v] of Object.entries(query || {})) {
            if (Array.isArray(v)) v.forEach(x => qs.append(k + '[]', x)); else if (v !== undefined && v !== null) qs.append(k, String(v));
        }
        const url = this.apiBase() + path + (qs.toString() ? '?' + qs : '');
        const h = Object.assign({ Accept: 'application/json', 'User-Agent': this.userAgent() }, headers);
        const jar = Object.entries(cookies).filter(([, v]) => v).map(([k, v]) => k + '=' + v);
        if (jar.length) h.Cookie = jar.join('; ');
        if (body !== null) h['Content-Type'] = 'application/json';
        let res;
        try {
            res = await fetch(url, { method, headers: h, body: body !== null ? JSON.stringify(body) : undefined, redirect: 'follow', signal: AbortSignal.timeout(25000) });
        } catch (e) {
            throw new UserError('VRChat ist nicht erreichbar (' + (e.cause && e.cause.code || e.message) + '). Besteht eine Internetverbindung?');
        }
        const setc = typeof res.headers.getSetCookie === 'function' ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
        // auch zusammengefasste Kopfzeilen („a=1; Path=/, b=2; …“) wie bei Android/Capacitor
        for (const line of setc) { const re = /(?:^|,\s*)(auth|twoFactorAuth)=([^;,]*)/gi; let m; while ((m = re.exec(String(line).trim()))) cookies[m[1]] = m[2]; }
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch (e) { json = null; }
        return [res.status, json];
    }

    errorText(code, json) {
        let msg = json && (json.error && json.error.message || json.message) || '';
        msg = (typeof msg === 'string' ? msg : JSON.stringify(msg)).replace(/^["\s]+|["\s]+$/g, '');
        const hints = { 401: 'Nicht (mehr) angemeldet.', 403: 'Keine Berechtigung dafür.', 404: 'Nicht gefunden.', 429: 'Zu viele Anfragen – VRChat bremst gerade. Bitte kurz warten.',
            500: 'Fehler bei VRChat.', 502: 'VRChat ist gerade überlastet.', 503: 'VRChat ist gerade nicht verfügbar.' };
        return (hints[code] || 'Fehler ' + code + '.') + (msg ? ' (' + msg + ')' : '');
    }

    // ---------- Anmeldung ----------
    async login(username, password) {
        const cookies = {};
        const basic = Buffer.from(encodeURIComponent(username) + ':' + encodeURIComponent(password)).toString('base64');
        const [code, json] = await this.http(cookies, 'GET', '/auth/user', {}, null, { Authorization: 'Basic ' + basic });
        if (code === 401) throw new UserError('VRChat: Benutzername/E-Mail oder Passwort falsch.');
        if (code === 429) throw new UserError('VRChat: Zu viele Anmeldeversuche – bitte einige Minuten warten.');
        if (code !== 200 || !json) throw new UserError('VRChat-Anmeldung fehlgeschlagen: ' + this.errorText(code, json));
        // Android: Cookies verwaltet das native Netz (JavaScript sieht sie nicht)
        if (!cookies.auth && !this.nativeCookies) throw new UserError('VRChat hat kein Sitzungs-Cookie geschickt – bitte später erneut versuchen.');
        if (json.requiresTwoFactorAuth) return { need2fa: arr(json.requiresTwoFactorAuth), cookies };
        return { user: json, cookies };
    }

    async verify2fa(cookies, type, code) {
        const paths = { totp: '/auth/twofactorauth/totp/verify', otp: '/auth/twofactorauth/otp/verify', emailOtp: '/auth/twofactorauth/emailotp/verify' };
        if (!paths[type]) throw new UserError('Unbekannte 2FA-Art.');
        const [st, json] = await this.http(cookies, 'POST', paths[type], {}, { code: str(code).replace(/\s+/g, '') });
        if (st === 429) throw new UserError('Zu viele Versuche – bitte kurz warten.');
        if (st !== 200 || !json || !json.verified) throw new UserError('Der Code stimmt nicht (oder ist abgelaufen). Bitte erneut versuchen.');
        const [st2, user] = await this.http(cookies, 'GET', '/auth/user');
        if (st2 !== 200 || !user || !user.id) throw new UserError('Anmeldung nach 2FA fehlgeschlagen: ' + this.errorText(st2, user));
        return user;
    }

    saveAccount(user, cookies) {
        const db = this.db;
        const prev = db.prepare('SELECT vrc_id FROM accounts WHERE user_id = ?').value(UID);
        const known = db.prepare('SELECT COUNT(*) FROM friends WHERE user_id = ?').value(UID);
        if ((prev && prev !== user.id) || (!prev && known > 0 && db.setting('vrc_last') !== user.id)) {
            for (const t of ['friends', 'feed', 'notes']) db.prepare('DELETE FROM ' + t + ' WHERE user_id = ?').run(UID);
        }
        db.setSetting('vrc_last', user.id);
        db.prepare(`INSERT INTO accounts (user_id, vrc_id, display_name, avatar, cookies, me_json, connected_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(user_id) DO UPDATE SET vrc_id = excluded.vrc_id, display_name = excluded.display_name, avatar = excluded.avatar,
                    cookies = excluded.cookies, me_json = excluded.me_json, connected_at = excluded.connected_at, last_error = ''`)
          .run(UID, user.id, str(user.displayName), U.userImage(user), this.crypt.encrypt(JSON.stringify(cookies)), JSON.stringify(U.meSlim(user)), now());
        db.save();
    }

    account() {
        const a = this.db.prepare('SELECT * FROM accounts WHERE user_id = ?').get(UID);
        if (!a) return null;
        let jar = {};
        try { jar = JSON.parse(this.crypt.decrypt(a.cookies)) || {}; } catch (e) { jar = {}; }
        a.cookie_jar = jar;
        try { a.me = JSON.parse(a.me_json) || {}; } catch (e) { a.me = {}; }
        return a;
    }

    async disconnect(remote = true) {
        const a = this.account();
        if (a && remote && (Object.keys(a.cookie_jar).length || this.nativeCookies)) { try { await this.http(a.cookie_jar, 'PUT', '/logout'); } catch (e) { /* egal */ } }
        this.db.prepare('DELETE FROM accounts WHERE user_id = ?').run(UID);
        this.db.save();
    }

    /** API-Aufruf im Namen des Kontos; bei abgelaufener Sitzung VrcAuthError */
    async call(acc, method, path, query = {}, body = null) {
        const before = JSON.stringify(acc.cookie_jar);
        const [code, json] = await this.http(acc.cookie_jar, method, path, query, body);
        if (JSON.stringify(acc.cookie_jar) !== before) this.db.prepare('UPDATE accounts SET cookies = ? WHERE user_id = ?').run(this.crypt.encrypt(JSON.stringify(acc.cookie_jar)), UID);
        if (code === 401 && path.startsWith('/auth/user')) {
            this.db.prepare("UPDATE accounts SET last_error = 'Sitzung abgelaufen' WHERE user_id = ?").run(UID);
            throw new VrcAuthError('Die VRChat-Sitzung ist abgelaufen – bitte neu anmelden.');
        }
        if (code < 200 || code >= 300) throw new UserError(this.errorText(code, json));
        return json === null ? {} : json;
    }

    async favoritesAll(acc, type) {
        let all = [];
        for (let off = 0; off < 1000; off += 100) {
            const page = arr(await this.call(acc, 'GET', '/favorites', { type, n: 100, offset: off }));
            all = all.concat(page);
            if (page.length < 100) break;
        }
        return all;
    }

    // ---------- Welten ----------
    worlds(ids) {
        ids = [...new Set(ids.filter(Boolean))];
        if (!ids.length) return {};
        const out = {};
        for (const w of this.db.prepare('SELECT * FROM worlds WHERE world_id IN (' + ids.map(() => '?').join(',') + ')').all(...ids)) {
            out[w.world_id] = { name: w.name, thumb: w.thumb, author: w.author, capacity: int(w.capacity) };
        }
        return out;
    }
    saveWorld(w) {
        if (w && w.id) this.db.prepare('INSERT OR REPLACE INTO worlds (world_id, name, thumb, author, capacity, fetched) VALUES (?, ?, ?, ?, ?, ?)')
            .run(w.id, str(w.name) || '?', str(w.thumbnailImageUrl), str(w.authorName), int(w.capacity), now());
    }
    async fetchWorlds(acc, ids, max = 12) {
        const known = this.worlds(ids);
        for (const id of [...new Set(ids)]) {
            if (max <= 0) break;
            if (!/^wrld_[\w-]+$/.test(id) || known[id]) continue;
            max--;
            try { this.saveWorld(await this.call(acc, 'GET', '/worlds/' + encodeURIComponent(id))); }
            catch (e) {
                if (e instanceof VrcAuthError) throw e;
                this.db.prepare('INSERT OR REPLACE INTO worlds (world_id, name, thumb, author, capacity, fetched) VALUES (?, ?, ?, ?, ?, ?)').run(id, '(unbekannte Welt)', '', '', 0, now());
            }
        }
    }

    // ---------- Abgleich der Freundesliste + Verlauf ----------
    async fetchFriends(acc, offline, maxPages) {
        const all = {};
        for (let p = 0; p < maxPages; p++) {
            const page = arr(await this.call(acc, 'GET', '/auth/user/friends', { offline: offline ? 'true' : 'false', n: 100, offset: p * 100 }));
            if (!page.length) break;
            for (const f of page) if (f && f.id) all[f.id] = f;
            if (page.length < 100) break;
        }
        return all;
    }

    async sync(force = false) {
        const acc = this.account();
        if (!acc) throw new VrcAuthError('Kein VRChat-Konto verbunden.');
        const age = now() - int(acc.last_sync);
        const minWait = force ? 15 : SYNC_MIN;
        if (age < minWait) return { synced: false, wait: minWait - age };
        const db = this.db;
        db.prepare('UPDATE accounts SET last_sync = ? WHERE user_id = ?').run(now(), UID);

        const me = await this.call(acc, 'GET', '/auth/user');
        if (!me.id) throw new VrcAuthError('Die VRChat-Sitzung ist abgelaufen – bitte neu anmelden.');
        const set = a => new Set(arr(a));
        const onlineIds = set(me.onlineFriends), activeIds = set(me.activeFriends), offlineIds = set(me.offlineFriends), allIds = set(me.friends);

        const old = {};
        for (const r of db.prepare('SELECT * FROM friends WHERE user_id = ?').all(UID)) old[r.vrc_id] = r;
        const first = !Object.keys(old).length;
        const fetched = await this.fetchFriends(acc, false, 10);
        const unknown = [...allIds].some(id => !old[id] && !fetched[id]);
        const needOffline = first || unknown || now() - int(acc.last_offline) > OFFLINE_SYNC;
        if (needOffline) {
            Object.assign(fetched, await this.fetchFriends(acc, true, 30), fetched);
            db.prepare('UPDATE accounts SET last_offline = ? WHERE user_id = ?').run(now(), UID);
        }
        const t = now();
        const feed = db.prepare('INSERT INTO feed (user_id, ts, type, vrc_id, display_name, old, new) VALUES (?, ?, ?, ?, ?, ?, ?)');
        const up = db.prepare(`INSERT OR REPLACE INTO friends (user_id, vrc_id, display_name, state, status, status_desc, location, platform, image, avatar_thumb, tags, bio, last_login, since, updated, fav_group, friend_since, pronouns)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
        const log = (type, id, name, o = '', n = '') => { if (!first) feed.run(UID, t, type, id, name, str(o), str(n)); };
        const worlds = [];
        const watchHits = [], watchEvents = [];
        db.tx(() => {
            for (const [id, f] of Object.entries(fetched)) {
                let loc = str(f.location);
                let state;
                if (onlineIds.has(id)) state = 'online';
                else if (activeIds.has(id)) state = 'active';
                else if (offlineIds.has(id)) state = 'offline';
                else state = loc !== '' && loc !== 'offline' ? 'online' : ((f.status || 'offline') === 'offline' ? 'offline' : 'active');
                if (state === 'online' && (loc === '' || loc === 'offline')) loc = 'private';
                if (state !== 'online') loc = 'offline';
                const row = { name: str(f.displayName), state, status: str(f.status), sd: str(f.statusDescription), loc, pf: str(f.last_platform || f.platform), img: U.userImage(f),
                    av: str(f.currentAvatarThumbnailImageUrl), tags: arr(f.tags).join(' '), bio: cut(f.bio, 2000), ll: str(f.last_login) };
                const o = old[id];
                if (o && loc.startsWith('traveling') && o.state === 'online') row.loc = loc = o.location;
                let since = o ? int(o.since) : t;
                if (o) {
                    if (o.state !== state) {
                        log(state === 'online' ? 'online' : state === 'offline' ? 'offline' : 'active', id, row.name, o.state === 'online' ? o.location : o.state, state === 'online' ? loc : state);
                        since = t;
                        if (state === 'online' && o.state !== 'online') watchHits.push(id);
                    } else if (state === 'online' && o.location !== loc && !loc.startsWith('traveling')) {
                        log('gps', id, row.name, o.location, loc);
                        since = t;
                        if (/^wrld_[^:]+:[^~]+(~region\([a-z]+\))?$/.test(loc)) watchEvents.push([id, 'public', loc]);
                    }
                    if (row.status === 'join me' && o.status !== 'join me' && state !== 'offline') watchEvents.push([id, 'joinme', loc]);
                    const bothOn = o.state !== 'offline' && state !== 'offline';
                    if (o.status !== row.status && row.status !== 'offline' && o.status !== '' && bothOn) log('status', id, row.name, o.status + '|' + o.status_desc, row.status + '|' + row.sd);
                    else if (o.status_desc !== row.sd && bothOn) log('status', id, row.name, o.status + '|' + o.status_desc, row.status + '|' + row.sd);
                    if (o.avatar_thumb !== '' && row.av !== '' && o.avatar_thumb !== row.av) log('avatar', id, row.name, o.avatar_thumb, row.av);
                    if (o.display_name !== row.name && o.display_name !== '') log('name', id, row.name, o.display_name, row.name);
                    if (o.bio !== row.bio && o.bio !== '' && row.bio !== '') log('bio', id, row.name, o.bio, row.bio);
                    const ot = U.trust(o.tags !== '' ? o.tags.split(' ') : [])[0], nt = U.trust(f.tags)[0];
                    if (o.tags !== '' && ot !== nt) log('trust', id, row.name, ot, nt);
                } else if (!first) {
                    log('friend_add', id, row.name);
                }
                if (state === 'online') { const l = U.location(loc); if (l.world) worlds.push(l.world); }
                up.run(UID, id, row.name, row.state, row.status, row.sd, row.loc, row.pf, row.img, row.av, row.tags, row.bio, row.ll, since, t,
                    o ? o.fav_group : '', o ? int(o.friend_since) : (first ? 0 : t), cut(f.pronouns || (o && o.pronouns) || '', 32));
            }
            for (const [id, o] of Object.entries(old)) {
                if (fetched[id]) continue;
                if (allIds.size && !allIds.has(id)) {
                    log('friend_remove', id, o.display_name);
                    db.prepare('DELETE FROM friends WHERE user_id = ? AND vrc_id = ?').run(UID, id);
                    continue;
                }
                if (o.state !== 'offline' && offlineIds.has(id)) {
                    log('offline', id, o.display_name, o.state === 'online' ? o.location : o.state, 'offline');
                    db.prepare("UPDATE friends SET state = 'offline', location = 'offline', since = ?, updated = ? WHERE user_id = ? AND vrc_id = ?").run(t, t, UID, id);
                }
            }
            const myloc = str(me.location);
            if (!myloc.startsWith('traveling') && myloc !== str(acc.my_location)) {
                if (acc.my_location !== '' && !first && (myloc !== '' || acc.my_location !== 'offline')) feed.run(UID, t, 'self', me.id, str(me.displayName), str(acc.my_location), myloc || 'offline');
                db.prepare('UPDATE accounts SET my_location = ? WHERE user_id = ?').run(myloc || 'offline', UID);
            }
            let cOn = 0, cAct = 0;
            for (const id of Object.keys(fetched)) { if (onlineIds.has(id)) cOn++; else if (activeIds.has(id)) cAct++; }
            db.prepare('INSERT INTO stats (user_id, ts, online, active, total) VALUES (?, ?, ?, ?, ?)').run(UID, t, onlineIds.size || cOn, activeIds.size || cAct, allIds.size || Object.keys(fetched).length);
            db.prepare('DELETE FROM stats WHERE user_id = ? AND ts < ?').run(UID, t - 90 * 86400);
            db.prepare('DELETE FROM feed WHERE user_id = ? AND ts < ?').run(UID, t - FEED_KEEP_DAYS * 86400);
            db.prepare("UPDATE accounts SET me_json = ?, display_name = ?, avatar = ?, last_error = '' WHERE user_id = ?").run(JSON.stringify(U.meSlim(me)), str(me.displayName), U.userImage(me), UID);
        });
        const l = U.location(me.location);
        if (l.world) worlds.push(l.world);
        if (needOffline) {
            await this.syncFavorites(acc);
            try { await this.calendarRefresh(acc); } catch (e) { if (e instanceof VrcAuthError) throw e; }
        }
        await this.fetchWorlds(acc, worlds);
        // Beobachtete Freunde: „Join Me“ gesetzt / öffentliche Instanz betreten
        if (watchEvents.length && this.onWatchEvent) {
            const w = this.db.prepare('SELECT vrc_id FROM notes WHERE user_id = ? AND watch = 1').column(UID);
            for (const [id, kind, l] of watchEvents) if (w.includes(id)) {
                const f = this.db.prepare('SELECT display_name FROM friends WHERE user_id = ? AND vrc_id = ?').get(UID, id);
                const fl = U.location(l);
                if (f) this.onWatchEvent(f.display_name, kind, fl.world ? (this.worlds([fl.world])[fl.world] || {}).name || '' : '', l);
            }
        }
        // Beobachtete Freunde: Desktop-Benachrichtigung
        if (watchHits.length && this.onWatch) {
            const w = this.db.prepare('SELECT vrc_id FROM notes WHERE user_id = ? AND watch = 1').column(UID);
            for (const id of watchHits) if (w.includes(id)) {
                const f = this.db.prepare('SELECT display_name, location FROM friends WHERE user_id = ? AND vrc_id = ?').get(UID, id);
                if (f) { const fl = U.location(f.location); this.onWatch(f.display_name, fl.world ? (this.worlds([fl.world])[fl.world] || {}).name || '' : ''); }
            }
        }
        return { synced: true, wait: SYNC_MIN };
    }

    async syncFavorites(acc) {
        let favs, groups;
        try {
            favs = await this.favoritesAll(acc, 'friend');
            groups = await this.call(acc, 'GET', '/favorite/groups', { type: 'friend', n: 50 });
        } catch (e) { if (e instanceof VrcAuthError) throw e; return; }
        const names = {};
        for (const g of arr(groups)) if (g && g.name) names[g.name] = str(g.displayName || g.name);
        this.db.tx(() => {
            this.db.prepare("UPDATE friends SET fav_group = '' WHERE user_id = ?").run(UID);
            const up = this.db.prepare('UPDATE friends SET fav_group = ? WHERE user_id = ? AND vrc_id = ?');
            for (const f of favs) if (f && f.favoriteId) up.run(str(arr(f.tags)[0] || 'group_0'), UID, f.favoriteId);
            this.db.prepare('UPDATE accounts SET fav_groups = ? WHERE user_id = ?').run(JSON.stringify(names), UID);
        });
    }

    friendList() {
        const rows = this.db.prepare(`SELECT f.*, n.note, n.favorite, n.tags AS ltags, n.watch FROM friends f LEFT JOIN notes n ON n.user_id = f.user_id AND n.vrc_id = f.vrc_id
                                      WHERE f.user_id = ? ORDER BY f.display_name COLLATE NOCASE`).all(UID);
        const worlds = this.worlds(rows.map(r => U.location(r.location).world || ''));
        return rows.map(r => {
            const loc = U.location(r.location);
            if (loc.world) { loc.world_name = (worlds[loc.world] || {}).name || ''; loc.world_thumb = (worlds[loc.world] || {}).thumb || ''; }
            const tags = r.tags !== '' ? r.tags.split(' ') : [];
            return { id: r.vrc_id, name: r.display_name, state: r.state, status: r.status, status_desc: r.status_desc, loc, platform: U.VRC_PLATFORM[r.platform] || r.platform,
                image: r.image, trust: U.trust(tags), since: int(r.since), note: str(r.note), favorite: !!r.favorite, last_login: r.last_login, pf: r.platform, fav_group: r.fav_group,
                friend_since: int(r.friend_since), status_key: r.status, lists: str(r.ltags).split(',').map(s => s.trim()).filter(Boolean), watch: !!r.watch, pronouns: str(r.pronouns) };
        });
    }

    feed(f = {}) {
        let sql = 'SELECT * FROM feed WHERE user_id = ?';
        const p = [UID];
        const types = arr(f.type).filter(Boolean);
        if (types.length) { sql += ' AND type IN (' + types.map(() => '?').join(',') + ')'; p.push(...types); }
        if (f.friend) { sql += ' AND vrc_id = ?'; p.push(f.friend); }
        if (f.q) { sql += ' AND (display_name LIKE ? OR new LIKE ? OR old LIKE ?)'; p.push('%' + f.q + '%', '%' + f.q + '%', '%' + f.q + '%'); }
        if (f.before) { sql += ' AND id < ?'; p.push(int(f.before)); }
        if (f.favorites) { sql += ' AND vrc_id IN (SELECT vrc_id FROM notes WHERE user_id = ? AND favorite = 1)'; p.push(UID); }
        sql += ' ORDER BY id DESC LIMIT ' + Math.max(1, Math.min(200, int(f.limit) || 80));
        const rows = this.db.prepare(sql).all(...p);
        const wids = [];
        for (const r of rows) for (const k of ['old', 'new']) { const l = U.location(r[k]); if (l.world) wids.push(l.world); }
        const worlds = this.worlds(wids);
        return rows.map(r => {
            const out = { id: int(r.id), ts: int(r.ts), type: r.type, vrc_id: r.vrc_id, name: r.display_name, old: r.old, new: r.new };
            for (const k of ['old', 'new']) {
                if (r[k].startsWith('wrld_') || r[k] === 'private') { const l = U.location(r[k]); l.world_name = (worlds[l.world || ''] || {}).name || ''; out[k + '_loc'] = l; }
            }
            return out;
        });
    }

    note(id) { return this.db.prepare('SELECT note, favorite, tags, watch FROM notes WHERE user_id = ? AND vrc_id = ?').get(UID, id) || { note: '', favorite: 0, tags: '', watch: 0 }; }
    noteSave(id, note, fav, tags = null, watch = null) {
        const old = this.note(id);
        tags = tags === null ? str(old.tags) : [...new Set(str(tags).split(',').map(t => cut(t.trim(), 30)).filter(Boolean))].slice(0, 10).join(', ');
        this.db.prepare('INSERT OR REPLACE INTO notes (user_id, vrc_id, note, favorite, updated, tags, watch) VALUES (?, ?, ?, ?, ?, ?, ?)')
            .run(UID, id, cut(note, 4000), fav ? 1 : 0, now(), tags, watch === null ? int(old.watch) : (watch ? 1 : 0));
    }

    recentInstances(limit = 30) {
        const seen = new Map();
        for (const r of this.db.prepare("SELECT ts, new FROM feed WHERE user_id = ? AND type = 'self' AND new LIKE 'wrld_%' ORDER BY id DESC LIMIT 300").all(UID)) {
            if (seen.has(r.new)) { seen.get(r.new).visits++; continue; }
            if (seen.size >= limit) continue;
            seen.set(r.new, { location: r.new, ts: int(r.ts), visits: 1 });
        }
        const list = [...seen.values()];
        const worlds = this.worlds(list.map(x => x.location.split(':')[0]));
        return list.map(x => { const l = U.location(x.location); return Object.assign(x, { loc: l, world_name: (worlds[l.world] || {}).name || 'Welt', thumb: (worlds[l.world] || {}).thumb || '' }); });
    }

    // ---------- Statistik ----------
    sessions(from, friend = null) {
        const rows = this.db.prepare("SELECT vrc_id, ts, type FROM feed WHERE user_id = ? AND ts >= ? AND type IN ('online', 'offline', 'active', 'gps')" + (friend ? ' AND vrc_id = ?' : '') + ' ORDER BY vrc_id, ts')
            .all(...(friend ? [UID, from - 86400, friend] : [UID, from - 86400]));
        const open = {}, out = {};
        for (const r of rows) {
            const id = r.vrc_id, t = int(r.ts);
            if (r.type === 'online' || (r.type === 'gps' && !(id in open))) { if (!(id in open)) open[id] = t; }
            else if (id in open) { if (t > from) (out[id] = out[id] || []).push([Math.max(from, open[id]), t]); delete open[id]; }
        }
        const nowT = now();
        for (const r of this.db.prepare("SELECT vrc_id, since FROM friends WHERE user_id = ? AND state = 'online'" + (friend ? ' AND vrc_id = ?' : '')).all(...(friend ? [UID, friend] : [UID]))) {
            const start = r.vrc_id in open ? open[r.vrc_id] : int(r.since);
            if (start && start < nowT) (out[r.vrc_id] = out[r.vrc_id] || []).push([Math.max(from, start), nowT]);
        }
        return out;
    }

    friendStats(id) {
        const sum = (list, from) => list.reduce((t, [a, b]) => t + (b > from ? b - Math.max(a, from) : 0), 0);
        const nowT = now();
        const s = this.sessions(nowT - 30 * 86400, id)[id] || [];
        const lastWorld = this.db.prepare("SELECT ts, new FROM feed WHERE user_id = ? AND vrc_id = ? AND type IN ('gps', 'online') AND new LIKE 'wrld_%' ORDER BY id DESC LIMIT 1").get(UID, id);
        const lastSeen = int(this.db.prepare("SELECT MAX(ts) FROM feed WHERE user_id = ? AND vrc_id = ? AND type IN ('online', 'offline')").value(UID, id));
        const out = { week: sum(s, nowT - 7 * 86400), month: sum(s, nowT - 30 * 86400), sessions: s.length, last_seen: lastSeen,
            friend_since: int(this.db.prepare('SELECT friend_since FROM friends WHERE user_id = ? AND vrc_id = ?').value(UID, id)) };
        const heat = Array.from({ length: 7 }, () => Array(24).fill(0));
        for (const [a, b] of s) {
            for (let t = a; t < b;) {
                const end = Math.min(b, (Math.floor(t / 3600) + 1) * 3600);
                heat[U.isoDay(t) - 1][U.hourOf(t)] += (end - t) / 60;
                t = end;
            }
        }
        out.heat = heat.map(row => row.map(v => Math.floor(v)));
        const hours = Array(24).fill(0);
        heat.forEach(row => row.forEach((m, h) => { hours[h] += m; }));
        out.best_hours = hours.map((m, h) => [h, m]).filter(x => x[1] > 0).sort((a, b) => b[1] - a[1]).slice(0, 3).map(x => x[0]);
        out.best_days = heat.map((row, d) => [d, row.reduce((a, b) => a + b, 0)]).filter(x => x[1] > 0).sort((a, b) => b[1] - a[1]).slice(0, 2).map(x => x[0]);
        const all = this.sessions(nowT - 30 * 86400);
        const ov = [];
        for (const [other, list] of Object.entries(all)) {
            if (other === id) continue;
            let t = 0;
            for (const [a1, b1] of s) for (const [a2, b2] of list) { const o = Math.min(b1, b2) - Math.max(a1, a2); if (o > 0) t += o; }
            if (t >= 600) ov.push([other, t]);
        }
        ov.sort((a, b) => b[1] - a[1]);
        out.together = this.encounters(30, id)[0] || null;
        out.buddies = [];
        const names = this.db.prepare('SELECT vrc_id, display_name, image FROM friends WHERE user_id = ? AND vrc_id = ?');
        for (const [other, t] of ov.slice(0, 6)) { const r = names.get(UID, other); if (r) out.buddies.push({ id: other, name: r.display_name, image: r.image, seconds: t }); }
        if (lastWorld) {
            const l = U.location(lastWorld.new);
            l.world_name = (this.worlds([l.world || ''])[l.world || ''] || {}).name || '';
            out.last_world = Object.assign(l, { ts: int(lastWorld.ts) });
        }
        return out;
    }

    encounters(days = 30, friend = null) {
        const db = this.db, from = now() - days * 86400, cap = 6 * 3600, nowT = now();
        const mine = {};
        let prev = null;
        for (const r of db.prepare("SELECT ts, type, new FROM feed WHERE user_id = ? AND ts >= ? AND type = 'self' ORDER BY ts").all(UID, from)) {
            if (prev && prev.new.startsWith('wrld_')) (mine[prev.new] = mine[prev.new] || []).push([int(prev.ts), Math.min(int(r.ts), int(prev.ts) + cap)]);
            prev = r;
        }
        if (prev && prev.new.startsWith('wrld_')) (mine[prev.new] = mine[prev.new] || []).push([int(prev.ts), Math.min(nowT, int(prev.ts) + cap)]);
        if (!Object.keys(mine).length) return [];
        const rows = db.prepare("SELECT vrc_id, ts, type, new FROM feed WHERE user_id = ? AND ts >= ? AND type IN ('gps', 'online', 'offline', 'active')" + (friend ? ' AND vrc_id = ?' : '') + ' ORDER BY vrc_id, ts')
            .all(...(friend ? [UID, from, friend] : [UID, from]));
        const res = {};
        const add = (id, loc, a, b) => {
            if (!mine[loc] || b <= a) return;
            for (const [ma, mb] of mine[loc]) {
                const o = Math.min(b, mb) - Math.max(a, ma);
                if (o < 120) continue;
                const r = res[id] = res[id] || { seconds: 0, instances: {}, last: 0, loc: '' };
                r.seconds += o;
                r.instances[loc] = true;
                if (r.last < Math.min(b, mb)) { r.last = Math.min(b, mb); r.loc = loc; }
            }
        };
        const cur = {};
        for (const r of rows) {
            const id = r.vrc_id;
            if (cur[id]) { add(id, cur[id][1], cur[id][0], Math.min(int(r.ts), cur[id][0] + cap)); delete cur[id]; }
            if ((r.type === 'gps' || r.type === 'online') && r.new.startsWith('wrld_')) cur[id] = [int(r.ts), r.new];
        }
        for (const [id, [a, loc]] of Object.entries(cur)) add(id, loc, a, Math.min(nowT, a + cap));
        if (!Object.keys(res).length) return [];
        const names = {};
        for (const r of db.prepare('SELECT vrc_id, display_name, image FROM friends WHERE user_id = ?').all(UID)) names[r.vrc_id] = r;
        const worlds = this.worlds(Object.values(res).map(x => x.loc.split(':')[0]));
        const out = [];
        for (const [id, r] of Object.entries(res)) {
            if (!names[id]) continue;
            const l = U.location(r.loc);
            out.push({ id, name: names[id].display_name, image: names[id].image, seconds: r.seconds, instances: Object.keys(r.instances).length, last: r.last,
                world: l.world || '', world_name: (worlds[l.world || ''] || {}).name || 'Welt', access: l.access || '' });
        }
        return out.sort((a, b) => b.last - a.last);
    }

    timeline(offset = 0) {
        const start = U.startOfToday() - Math.max(0, Math.min(6, offset)) * 86400;
        const end = Math.min(now(), start + 86400);
        const names = {};
        for (const r of this.db.prepare('SELECT vrc_id, display_name, image FROM friends WHERE user_id = ?').all(UID)) names[r.vrc_id] = r;
        const rows = [];
        for (const [id, list] of Object.entries(this.sessions(start))) {
            if (!names[id]) continue;
            const seg = [];
            let total = 0;
            for (let [a, b] of list) { a = Math.max(a, start); b = Math.min(b, end); if (b - a < 60) continue; seg.push([a, b]); total += b - a; }
            if (seg.length) rows.push({ id, name: names[id].display_name, image: names[id].image, seg, total, first: seg[0][0] });
        }
        rows.sort((x, y) => x.first - y.first);
        return { start, end: start + 86400, now: now(), rows: rows.slice(0, 120) };
    }

    stats() {
        const db = this.db, nowT = now(), from = nowT - 30 * 86400;
        let rows = db.prepare('SELECT ts, online, active, total FROM stats WHERE user_id = ? AND ts > ? ORDER BY ts').all(UID, nowT - 7 * 86400);
        const span = rows.length ? int(rows[rows.length - 1].ts) - int(rows[0].ts) : 0;
        const bucket = span > 2 * 86400 ? 3600 : span > 6 * 3600 ? 900 : 300;
        const buckets = new Map();
        for (const r of rows) { const h = Math.floor(int(r.ts) / bucket) * bucket; if (!buckets.has(h)) buckets.set(h, []); buckets.get(h).push(r); }
        const history = [...buckets.entries()].map(([h, rs]) => ({ ts: h, online: Math.round(rs.reduce((a, r) => a + r.online, 0) / rs.length * 10) / 10,
            active: Math.round(rs.reduce((a, r) => a + r.active, 0) / rs.length * 10) / 10 }));
        const peak = history.length ? Math.max(...history.map(h => h.online)) : 0;
        const names = {};
        for (const r of db.prepare('SELECT vrc_id, display_name, image FROM friends WHERE user_id = ?').all(UID)) names[r.vrc_id] = r;
        const play = [];
        for (const [id, list] of Object.entries(this.sessions(from))) {
            const t = list.reduce((s, [a, b]) => s + b - a, 0);
            if (t > 0 && names[id]) play.push({ id, name: names[id].display_name, image: names[id].image, seconds: t, sessions: list.length });
        }
        play.sort((a, b) => b.seconds - a.seconds);
        const top = types => {
            const c = {};
            for (const r of db.prepare('SELECT new FROM feed WHERE user_id = ? AND ts >= ? AND type IN (' + types + ") AND new LIKE 'wrld_%'").all(UID, from)) { const w = r.new.split(':')[0]; c[w] = (c[w] || 0) + 1; }
            const list = Object.entries(c).sort((a, b) => b[1] - a[1]).slice(0, 10);
            const info = this.worlds(list.map(x => x[0]));
            return list.map(([w, n]) => ({ id: w, name: (info[w] || {}).name || w, thumb: (info[w] || {}).thumb || '', count: n }));
        };
        const heat = Array.from({ length: 7 }, () => Array(24).fill(0));
        for (const r of db.prepare("SELECT ts FROM feed WHERE user_id = ? AND ts >= ? AND type = 'online'").all(UID, from)) heat[U.isoDay(int(r.ts)) - 1][U.hourOf(int(r.ts))]++;
        const trust = {}, platform = {}, state = { online: 0, active: 0, offline: 0 };
        for (const r of db.prepare('SELECT tags, platform, state FROM friends WHERE user_id = ?').all(UID)) {
            const [t, col] = U.trust(r.tags !== '' ? r.tags.split(' ') : []);
            trust[t] = { name: t, color: col, count: ((trust[t] || {}).count || 0) + 1 };
            const p = U.VRC_PLATFORM[r.platform] || r.platform || 'unbekannt';
            platform[p] = (platform[p] || 0) + 1;
            state[r.state] = (state[r.state] || 0) + 1;
        }
        const platformSorted = Object.fromEntries(Object.entries(platform).sort((a, b) => b[1] - a[1]));
        const types = {};
        for (const r of db.prepare('SELECT type, COUNT(*) AS n FROM feed WHERE user_id = ? AND ts >= ? GROUP BY type').all(UID, from)) types[r.type] = int(r.n);
        return { history, peak, play: play.slice(0, 15), worlds: top("'gps', 'online'"), my_worlds: top("'self'"), heat, trust: Object.values(trust).sort((a, b) => b.count - a.count),
            platform: platformSorted, state, types, recording_since: int(db.prepare('SELECT MIN(ts) FROM feed WHERE user_id = ?').value(UID)),
            total_play: play.reduce((a, p) => a + p.seconds, 0) };
    }

    /** CSV (Freunde oder Verlauf) */
    exportCsv(what) {
        const q = v => { v = str(v); return /[";\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const d = t => { const x = new Date(t * 1000); return x.toLocaleDateString('de-DE') + ' ' + x.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }); };
        const lines = [];
        if (what === 'feed') {
            lines.push(['Zeit', 'Ereignis', 'Name', 'VRChat-ID', 'Vorher', 'Nachher'].join(';'));
            for (const r of this.db.prepare('SELECT * FROM feed WHERE user_id = ? ORDER BY id DESC LIMIT 20000').all(UID)) lines.push([d(r.ts), r.type, r.display_name, r.vrc_id, r.old, r.new].map(q).join(';'));
        } else {
            lines.push(['Name', 'VRChat-ID', 'Status', 'Statustext', 'Ort', 'Plattform', 'Vertrauen', 'Favorit', 'Notiz', 'Letzter Login'].join(';'));
            for (const f of this.friendList()) lines.push([f.name, f.id, f.state, f.status_desc, f.loc.kind === 'world' ? (f.loc.world_name || '') + ' (' + f.loc.access + ')' : f.loc.label,
                f.platform, f.trust[0], f.favorite ? 'ja' : '', f.note, f.last_login].map(q).join(';'));
        }
        return '\ufeff' + lines.join('\r\n') + '\r\n';
    }

    // ---------- Gruppenrechte ----------
    static get MOD_PERMS() { return ['*', 'group-members-manage', 'group-members-remove', 'group-bans-manage', 'group-invites-manage', 'group-roles-assign', 'group-audit-view',
        'group-announcement-manage', 'group-calendar-manage', 'group-instance-moderate', 'group-members-viewall']; }
    async groupPerms(acc, groups) {
        const out = {};
        try {
            const res = await this.call(acc, 'GET', '/users/' + encodeURIComponent(acc.vrc_id) + '/groups/permissions');
            for (const [k, v] of Object.entries(res && typeof res === 'object' ? res : {})) {
                if (typeof k === 'string' && k.startsWith('grp_')) out[k] = arr(v);
                else if (v && typeof v === 'object' && v.groupId) out[v.groupId] = arr(v.permissions);
            }
        } catch (e) {
            if (e instanceof VrcAuthError) throw e;
            for (const g of groups.slice(0, 25)) {
                try { const d = await this.call(acc, 'GET', '/groups/' + encodeURIComponent(g.groupId || g.id)); out[d.id] = arr((d.myMember || {}).permissions); }
                catch (e2) { if (e2 instanceof VrcAuthError) throw e2; }
            }
        }
        return out;
    }
    canModerate(perms) { return arr(perms).some(p => Vrc.MOD_PERMS.includes(p)); }
    /** Mindestens Kick-Rechte – Voraussetzung für die Moderation im Profil */
    canKick(perms) { return arr(perms).some(p => ['*', 'group-members-remove', 'group-members-manage'].includes(p)); }

    // ---------- Kalender ----------
    eventRow(e, gnames) {
        const id = str(e.id), start = U.ts(e.startsAt);
        if (!id || !start) return null;
        const gid = str(e.ownerId || e.groupId);
        return { id, group_id: gid, group_name: gnames[gid] || str(e.groupName), title: cut(e.title, 200), description: cut(e.description, 4000), starts: start,
            ends: U.ts(e.endsAt) || start + 3600, category: str(e.category), access: str(e.accessType), image: U.https(e.imageUrl), interested: int(e.interestedUserCount),
            following: e.userInterest && e.userInterest.isFollowing ? 1 : 0, platforms: arr(e.platforms).join(','), languages: arr(e.languages).join(','), tags: arr(e.tags).join(',') };
    }
    async calendarRefresh(acc) {
        const groups = arr(await this.call(acc, 'GET', '/users/' + encodeURIComponent(acc.vrc_id) + '/groups'));
        const gnames = {};
        for (const g of groups) if (g && g.groupId) gnames[g.groupId] = str(g.name);
        const events = {};
        const list = r => (r && r.results ? arr(r.results) : arr(r));
        for (const m of [0, 1, 2]) {
            const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + m);
            const date = d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-01T00:00:00Z';
            for (const e of list(await this.call(acc, 'GET', '/calendar', { date, n: 100 }))) if (e && e.id) events[e.id] = e;
        }
        try { for (const e of list(await this.call(acc, 'GET', '/calendar/following', { n: 100 }))) if (e && e.id) events[e.id] = Object.assign({ userInterest: { isFollowing: true } }, e, events[e.id] || {}); }
        catch (e) { if (e instanceof VrcAuthError) throw e; }
        const db = this.db;
        const monthStart = (() => { const d = new Date(); d.setDate(1); d.setHours(0, 0, 0, 0); return Math.floor(d / 1000); })();
        let n = 0;
        db.tx(() => {
            db.prepare('DELETE FROM vrc_events WHERE user_id = ? AND (ends < ? OR starts >= ?)').run(UID, now() - 30 * 86400, monthStart);
            const ins = db.prepare(`INSERT OR REPLACE INTO vrc_events (user_id, id, group_id, group_name, title, description, starts, ends, category, access, image, interested, following, platforms, languages, tags, updated)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
            for (const e of Object.values(events)) {
                if (e.deletedAt || e.isDraft) continue;
                const r = this.eventRow(e, gnames);
                if (!r) continue;
                ins.run(UID, r.id, r.group_id, r.group_name, r.title, r.description, r.starts, r.ends, r.category, r.access, r.image, r.interested, r.following, r.platforms, r.languages, r.tags, now());
                n++;
            }
        });
        db.setSetting('vrc_cal_refresh', now());
        return n;
    }
    calendarList() { return this.db.prepare('SELECT * FROM vrc_events WHERE user_id = ? AND ends >= ? ORDER BY starts').all(UID, now() - 6 * 3600); }
    calendarIcs() {
        const esc = s => str(s).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
        const z = t => new Date(t * 1000).toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z');
        const out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//VRC Nexus Desktop//DE', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:VRChat-Gruppen-Events'];
        for (const e of this.calendarList()) {
            out.push('BEGIN:VEVENT', 'UID:' + e.id + '@vrchat', 'DTSTAMP:' + z(now()), 'DTSTART:' + z(e.starts), 'DTEND:' + z(e.ends), 'SUMMARY:' + esc((e.following ? '★ ' : '') + e.title),
                'LOCATION:' + esc('VRChat' + (e.group_name ? ' – ' + e.group_name : '')), 'CATEGORIES:' + esc(e.group_name || 'VRChat'),
                'URL:https://vrchat.com/home/group/' + encodeURIComponent(e.group_id) + '/calendar/' + encodeURIComponent(e.id),
                'DESCRIPTION:' + esc((e.description + '\n\n' + (e.interested ? e.interested + ' Interessierte · ' : '') + 'aus VRC Nexus').trim()), 'END:VEVENT');
        }
        out.push('END:VCALENDAR');
        return out.join('\r\n') + '\r\n';
    }

    // ---------- Dateien ----------
    async upload(acc, path, fields, buffer, mime, name) {
        const form = new FormData();
        for (const [k, v] of Object.entries(fields)) form.append(k, v);
        form.append('file', new Blob([buffer], { type: mime }), name);
        const jar = Object.entries(acc.cookie_jar).filter(([, v]) => v).map(([k, v]) => k + '=' + v).join('; ');
        const res = await fetch(this.apiBase() + path, { method: 'POST', body: form, headers: { Accept: 'application/json', Cookie: jar, 'User-Agent': this.userAgent() }, signal: AbortSignal.timeout(60000) });
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        if (res.status < 200 || res.status >= 300) throw new UserError('Upload abgelehnt: ' + this.errorText(res.status, json) + (res.status === 403 ? ' (für viele Uploads ist VRC+ nötig)' : ''));
        return json || {};
    }
    /** Bild einer VRChat-Datei (braucht die Anmeldung) → { type, body } oder null */
    async proxyFile(acc, fileId, version) {
        const jar = Object.entries(acc.cookie_jar).filter(([, v]) => v).map(([k, v]) => k + '=' + v).join('; ');
        try {
            const res = await fetch(this.apiBase() + '/file/' + encodeURIComponent(fileId) + '/' + version + '/file', { headers: { Cookie: jar, 'User-Agent': this.userAgent() }, redirect: 'follow', signal: AbortSignal.timeout(30000) });
            const type = res.headers.get('content-type') || '';
            if (res.status !== 200 || !/^image\/(png|jpe?g|gif|webp)/i.test(type)) return null;
            return { type, body: Buffer.from(await res.arrayBuffer()) };
        } catch (e) { return null; }
    }
    static fileVersion(f) {
        let v = 0;
        for (const ver of arr(f.versions)) if ((ver.status || 'complete') === 'complete' && int(ver.version) > v && ver.file) v = int(ver.version);
        return v || Math.max(1, arr(f.versions).length - 1);
    }
}

module.exports = { Vrc, UID, SYNC_MIN, OFFLINE_SYNC, FEED_KEEP_DAYS, VERSION };
