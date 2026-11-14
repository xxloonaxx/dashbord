'use strict';
/*
 * Stream-Bot für Twitch und YouTube:
 *  - Twitch-Chat lesen + schreiben (IRC über WebSocket, OAuth-Token)
 *  - YouTube-Livechat lesen (Data API v3 mit API-Schlüssel; Antworten gehen an Overlay/Chatbox, weil Schreiben OAuth bräuchte)
 *  - Punkte: pro Nachricht (mit Abklingzeit) + regelmäßig für aktive Zuschauer; !punkte !top !geben, Mods: !addpunkte !setpunkte
 *  - Info-Befehle: !welt !spieler !avatar !song !zeit !befehle !belohnungen + eigene Befehle mit Platzhaltern
 *  - Belohnungen: Zuschauer lösen mit Punkten VRC-Nexus-Makros aus (Avatar-Parameter, Chatbox, Kamera, Webhook …)
 *  - Stream-Chat optional in die VRChat-Chatbox, Chat + Einlösungen als OBS-Overlay (/chat)
 */
const { str, int, cut, now, UserError } = require('./util');

const DEF = {
    twitch: { on: false, channel: '', bot: '' },
    youtube: { on: false, video: '', interval: 10 },
    points: { name: 'Punkte', per_msg: 1, msg_cooldown: 60, per_watch: 5, watch_every: 5, start: 0 },
    prefix: '!', chat_to_vrc: false, vrc_prefix: '💬', vrc_only_game: true, greet: false, greet_text: 'Willkommen {user}! 👋',
    commands: [
        { name: 'discord', response: 'Komm auf unseren Discord! (Link in der Beschreibung)', mod: false, cooldown: 30 },
    ],
    rewards: [
        { name: 'winken', cost: 50, script: 'chatbox 👋 {user} winkt!', cooldown: 30, response: '{user} hat „winken“ für {kosten} {punkte_name} eingelöst!' },
    ],
};
const lc = s => str(s).toLowerCase();
const clean = s => str(s).replace(/[\r\n]+/g, ' ').trim();

class StreamBot {
    constructor(app, crypt) {
        this.app = app;
        this.crypt = crypt;
        this.buf = [];                // letzte Nachrichten/Ereignisse (UI + OBS)
        this.active = new Map();      // Schlüssel → letzte Aktivität (für Zuschauer-Punkte)
        this.lastMsgPts = new Map();
        this.cool = new Map();        // Befehl/Belohnung → letzte Nutzung
        this.tw = { ws: null, state: 'aus', error: '', queue: [], retry: 0, timer: null };
        this.yt = { state: 'aus', error: '', chatId: '', page: '', timer: null, first: true };
        this.lastVrc = 0;
        this.started = 0;
        this.seq = 0;
        this.watchTimer = null;
        this.pts = null;
        this.saveT = null;
    }
    // ---------- Speicher ----------
    cfg() {
        let c = {};
        try { c = JSON.parse(this.app.db.setting('stream_cfg', '{}')) || {}; } catch (e) { c = {}; }
        return { ...DEF, ...c, twitch: { ...DEF.twitch, ...(c.twitch || {}) }, youtube: { ...DEF.youtube, ...(c.youtube || {}) }, points: { ...DEF.points, ...(c.points || {}) },
            commands: Array.isArray(c.commands) ? c.commands : DEF.commands, rewards: Array.isArray(c.rewards) ? c.rewards : DEF.rewards };
    }
    saveCfg(input) {
        const c = this.cfg(), b = v => v === true || v === '1' || v === 'true' || v === 'on';
        const n = (v, d, lo, hi) => (v === undefined || v === '' ? d : Math.max(lo, Math.min(hi, int(v))));
        if (input.tw_channel !== undefined) c.twitch.channel = lc(input.tw_channel).replace(/^#|^https?:\/\/(www\.)?twitch\.tv\//, '').replace(/[^a-z0-9_]/g, '').slice(0, 25);
        if (input.tw_bot !== undefined) c.twitch.bot = lc(input.tw_bot).replace(/[^a-z0-9_]/g, '').slice(0, 25);
        if (input.tw_on !== undefined) c.twitch.on = b(input.tw_on);
        if (input.yt_video !== undefined) { const v = str(input.yt_video).trim(); const m = /(?:v=|youtu\.be\/|live\/|^)([\w-]{11})(?:[?&#]|$)/.exec(v); c.youtube.video = m ? m[1] : ''; }
        if (input.yt_on !== undefined) c.youtube.on = b(input.yt_on);
        if (input.yt_interval !== undefined) c.youtube.interval = n(input.yt_interval, 10, 5, 120);
        if (input.p_name !== undefined) c.points.name = cut(clean(input.p_name), 20) || 'Punkte';
        for (const [k, lo, hi] of [['per_msg', 0, 1000], ['msg_cooldown', 0, 3600], ['per_watch', 0, 10000], ['watch_every', 1, 120], ['start', 0, 100000]]) if (input['p_' + k] !== undefined) c.points[k] = n(input['p_' + k], c.points[k], lo, hi);
        if (input.prefix !== undefined) c.prefix = cut(clean(input.prefix), 2) || '!';
        for (const k of ['chat_to_vrc', 'vrc_only_game', 'greet']) if (input[k] !== undefined) c[k] = b(input[k]);
        if (input.vrc_prefix !== undefined) c.vrc_prefix = cut(str(input.vrc_prefix), 8);
        if (input.greet_text !== undefined) c.greet_text = cut(clean(input.greet_text), 200);
        this.writeCfg(c);
        this.apply();
        return c;
    }
    writeCfg(c) { this.app.db.setSetting('stream_cfg', JSON.stringify(c)); }
    keys() { try { return JSON.parse(this.crypt.decrypt(this.app.db.setting('stream_keys', '')) || '{}') || {}; } catch (e) { return {}; } }
    setKey(kind, value) {
        const k = this.keys();
        value = str(value).trim();
        if (kind === 'twitch') { if (value && !/^(oauth:)?[a-z0-9]{20,40}$/i.test(value)) throw new UserError('Das sieht nicht wie ein Twitch-Token aus (30 Zeichen, evtl. mit „oauth:“ davor).'); k.twitch = value.replace(/^oauth:/i, ''); }
        else if (kind === 'youtube') { if (value && !/^[\w-]{30,45}$/.test(value)) throw new UserError('Das sieht nicht wie ein Google-API-Schlüssel aus.'); k.youtube = value; }
        else throw new UserError('Unbekannt.');
        if (!k[kind]) delete k[kind];
        this.app.db.setSetting('stream_keys', Object.keys(k).length ? this.crypt.encrypt(JSON.stringify(k)) : '');
        this.apply();
    }
    points() {
        if (!this.pts) { try { this.pts = JSON.parse(this.app.db.setting('stream_points', '{}')) || {}; } catch (e) { this.pts = {}; } }
        return this.pts;
    }
    savePoints() { clearTimeout(this.saveT); this.saveT = setTimeout(() => this.app.db.setSetting('stream_points', JSON.stringify(this.points())), 2000); if (this.saveT.unref) this.saveT.unref(); }
    account(platform, id, name) {
        const all = this.points(), key = platform + ':' + lc(id);
        if (!all[key]) all[key] = { name: cut(clean(name), 40), p: this.cfg().points.start, msgs: 0, first: now(), last: 0 };
        if (name) all[key].name = cut(clean(name), 40);
        return all[key];
    }
    findUser(q) {
        q = lc(q).replace(/^@/, '');
        const all = this.points();
        const k = Object.keys(all).find(k => k.slice(k.indexOf(':') + 1) === q) || Object.keys(all).find(k => lc(all[k].name) === q);
        return k ? { key: k, a: all[k] } : null;
    }

    // ---------- Verbindungen ----------
    apply() {
        const c = this.cfg(), k = this.keys();
        if (c.twitch.on && c.twitch.channel && k.twitch) { if (!this.tw.ws) this.twConnect(); } else this.twClose('aus');
        if (c.youtube.on && c.youtube.video && k.youtube) { if (!this.yt.timer) this.ytStart(); } else this.ytStop('aus');
        if ((c.twitch.on || c.youtube.on) && !this.watchTimer) { this.watchTimer = setInterval(() => this.watchTick(), 60000); if (this.watchTimer.unref) this.watchTimer.unref(); }
    }
    stop() { this.twClose('aus'); this.ytStop('aus'); clearInterval(this.watchTimer); this.watchTimer = null; if (this.pts) this.app.db.setSetting('stream_points', JSON.stringify(this.pts)); }

    twConnect() {
        const c = this.cfg(), token = this.keys().twitch;
        const W = globalThis.WebSocket;
        if (!W) { this.tw.state = 'fehler'; this.tw.error = 'WebSocket fehlt'; return; }
        this.tw.state = 'verbinde …';
        const ws = new W(process.env.NEXUS_TWITCH_WS || 'wss://irc-ws.chat.twitch.tv:443');
        this.tw.ws = ws;
        const nick = c.twitch.bot || c.twitch.channel;
        ws.onopen = () => {
            ws.send('CAP REQ :twitch.tv/tags twitch.tv/commands');
            ws.send('PASS oauth:' + token);
            ws.send('NICK ' + nick);
            ws.send('JOIN #' + c.twitch.channel);
        };
        ws.onmessage = ev => { for (const line of String(ev.data).split('\r\n')) if (line) this.twLine(line); };
        ws.onclose = () => {
            if (this.tw.ws !== ws) return;
            this.tw.ws = null;
            if (this.tw.state === 'aus') return;
            if (this.tw.state !== 'fehler') this.tw.state = 'getrennt – verbinde neu …';
            const wait = Math.min(60000, 2000 * Math.pow(2, this.tw.retry++));
            this.tw.timer = setTimeout(() => { if (this.cfg().twitch.on) this.twConnect(); }, wait);
        };
        ws.onerror = () => { this.tw.error = 'Verbindung fehlgeschlagen'; };
    }
    twClose(state) { clearTimeout(this.tw.timer); this.tw.state = state; const ws = this.tw.ws; this.tw.ws = null; if (ws) try { ws.close(); } catch (e) { /* egal */ } }
    twLine(line) {
        if (line.startsWith('PING')) { this.tw.ws && this.tw.ws.send('PONG' + line.slice(4)); return; }
        let tags = {}, rest = line;
        if (rest[0] === '@') { const i = rest.indexOf(' '); for (const kv of rest.slice(1, i).split(';')) { const [k, v = ''] = kv.split('='); tags[k] = v.replace(/\\s/g, ' ').replace(/\\:/g, ';').replace(/\\\\/g, '\\'); } rest = rest.slice(i + 1); }
        const m = /^:(\S+)\s+(\S+)(?:\s+(\S+))?(?:\s+:(.*))?$/.exec(rest);
        if (!m) return;
        const [, prefix, cmd, , text] = m;
        if (cmd === '001') { this.tw.state = 'verbunden'; this.tw.error = ''; this.tw.retry = 0; return; }
        if (cmd === 'JOIN') { this.tw.state = 'verbunden'; this.event('system', 'twitch', '', 'Twitch-Chat verbunden (#' + this.cfg().twitch.channel + ')'); return; }
        if (cmd === 'NOTICE' && /authentication failed|improperly formatted auth|login unsuccessful/i.test(text || '')) { this.tw.state = 'fehler'; this.tw.error = 'Anmeldung fehlgeschlagen – Token prüfen'; this.twClose('fehler'); return; }
        if (cmd === 'USERNOTICE') { const s = tags['system-msg'] || tags['msg-id'] || ''; if (s) this.event('alert', 'twitch', tags['display-name'] || '', s); return; }
        if (cmd !== 'PRIVMSG') return;
        const login = prefix.split('!')[0];
        const badges = str(tags.badges);
        this.message({ platform: 'twitch', id: tags['user-id'] || login, login, name: tags['display-name'] || login, text: text || '',
            mod: tags.mod === '1' || /broadcaster\//.test(badges), owner: /broadcaster\//.test(badges), color: tags.color || '' });
    }
    twSend(text) {
        const c = this.cfg();
        if (!this.tw.ws || this.tw.state !== 'verbunden') return false;
        this.tw.queue.push(cut(clean(text), 480));
        if (!this.tw.qTimer) this.tw.qTimer = setInterval(() => {
            const t = this.tw.queue.shift();
            if (t && this.tw.ws) this.tw.ws.send('PRIVMSG #' + c.twitch.channel + ' :' + t);
            if (!this.tw.queue.length) { clearInterval(this.tw.qTimer); this.tw.qTimer = null; }
        }, 1600);
        return true;
    }

    // YouTube: Livechat per Data API v3 abfragen
    ytBase() { return (process.env.NEXUS_YT_BASE || 'https://www.googleapis.com/youtube/v3').replace(/\/+$/, ''); }
    ytStart() { this.yt.first = true; this.yt.page = ''; this.yt.chatId = ''; this.yt.state = 'verbinde …'; this.ytPoll(); }
    ytStop(state) { clearTimeout(this.yt.timer); this.yt.timer = null; this.yt.state = state; }
    async ytPoll() {
        const c = this.cfg(), key = this.keys().youtube;
        let wait = Math.max(5, c.youtube.interval) * 1000;
        try {
            if (!this.yt.chatId) {
                const r = await this.ytGet('/videos', { part: 'liveStreamingDetails,snippet', id: c.youtube.video, key });
                const v = (r.items || [])[0];
                if (!v) throw new Error('Video nicht gefunden');
                this.yt.chatId = str(v.liveStreamingDetails && v.liveStreamingDetails.activeLiveChatId);
                this.yt.title = str(v.snippet && v.snippet.title);
                if (!this.yt.chatId) { this.yt.state = 'kein aktiver Livechat (Stream noch nicht live?)'; wait = 60000; throw null; }
                this.event('system', 'youtube', '', 'YouTube-Chat verbunden: ' + this.yt.title);
            }
            const r = await this.ytGet('/liveChat/messages', { liveChatId: this.yt.chatId, part: 'snippet,authorDetails', maxResults: 200, pageToken: this.yt.page || undefined, key });
            this.yt.page = str(r.nextPageToken);
            wait = Math.max(wait, int(r.pollingIntervalMillis) || 0);
            if (!this.yt.first) {
                for (const it of r.items || []) {
                    const a = it.authorDetails || {}, s = it.snippet || {};
                    if (s.type === 'superChatEvent' || s.type === 'newSponsorEvent' || s.type === 'memberMilestoneChatEvent') { this.event('alert', 'youtube', a.displayName, clean(s.displayMessage)); continue; }
                    if (s.type && s.type !== 'textMessageEvent') continue;
                    this.message({ platform: 'youtube', id: str(a.channelId), login: str(a.channelId), name: str(a.displayName), text: str(s.displayMessage || (s.textMessageDetails || {}).messageText),
                        mod: !!(a.isChatModerator || a.isChatOwner), owner: !!a.isChatOwner });
                }
            }
            this.yt.first = false;
            this.yt.state = 'verbunden';
            this.yt.error = '';
        } catch (e) {
            if (e) { this.yt.error = e.message; this.yt.state = 'fehler'; if (/quota/i.test(e.message)) wait = 15 * 60000; else wait = Math.max(wait, 30000); if (/liveChat|chat.*(ended|disabled|not found)/i.test(e.message)) this.yt.chatId = ''; }
        }
        if (this.cfg().youtube.on) { this.yt.timer = setTimeout(() => this.ytPoll(), wait); if (this.yt.timer.unref) this.yt.timer.unref(); } else this.yt.timer = null;
    }
    async ytGet(path, q) {
        const u = new URL(this.ytBase() + path);
        for (const [k, v] of Object.entries(q)) if (v !== undefined && v !== '') u.searchParams.set(k, v);
        const r = await fetch(u, { signal: AbortSignal.timeout(15000) });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error('YouTube: ' + ((j.error && j.error.message) || 'HTTP ' + r.status));
        return j;
    }

    // ---------- Nachrichten, Punkte, Befehle ----------
    event(kind, platform, user, text) {
        this.buf.push({ i: ++this.seq, t: Date.now(), kind, platform, user: cut(clean(user), 40), text: cut(clean(text), 500) });
        if (this.buf.length > 300) this.buf.splice(0, this.buf.length - 300);
    }
    message(m) {
        const c = this.cfg();
        const key = m.platform + ':' + lc(m.id);
        const isNew = !this.points()[key];
        const acc = this.account(m.platform, m.id, m.name);
        acc.msgs++;
        acc.last = now();
        this.active.set(key, Date.now());
        this.buf.push({ i: ++this.seq, t: Date.now(), kind: 'chat', platform: m.platform, user: acc.name, text: cut(clean(m.text), 500), mod: !!m.mod, color: m.color || '' });
        if (this.buf.length > 300) this.buf.splice(0, this.buf.length - 300);
        if (c.points.per_msg && Date.now() - (this.lastMsgPts.get(key) || 0) >= c.points.msg_cooldown * 1000) { acc.p += c.points.per_msg; this.lastMsgPts.set(key, Date.now()); }
        this.savePoints();
        if (isNew && c.greet && c.greet_text) this.reply(m.platform, this.fill(c.greet_text, m, acc));
        const t = clean(m.text);
        if (t.startsWith(c.prefix)) { this.command(m, acc, t.slice(c.prefix.length)).catch(e => this.reply(m.platform, '⚠ ' + e.message)); return; }
        if (c.chat_to_vrc && Date.now() - this.lastVrc > 3000 && (!c.vrc_only_game || this.app.game.state.running)) {
            this.lastVrc = Date.now();
            this.app.game.oscSend('chatbox', { text: (c.vrc_prefix ? c.vrc_prefix + ' ' : '') + acc.name + ': ' + t, sound: '', vars: false }).catch(() => {});
        }
    }
    fill(tpl, m, acc, extra = {}) {
        const c = this.cfg(), st = this.app.game.state;
        const v = Object.assign({ user: m.name, punkte: String(acc ? acc.p : 0), punkte_name: c.points.name, welt: st.worldName || '–', spieler: String(st.players ? st.players.size : 0), avatar: st.avatar || '–',
            musik: this.app.auto ? this.app.auto.music || '–' : '–', zeit: new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }), plattform: m.platform === 'twitch' ? 'Twitch' : 'YouTube' }, extra);
        return str(tpl).replace(/\{(\w+)\}/g, (all, k) => (k in v ? String(v[k]) : all));
    }
    reply(platform, text) {
        if (!text) return;
        this.event('bot', platform, 'Bot', text);
        if (platform === 'twitch') this.twSend(text);
        // YouTube: Schreiben bräuchte OAuth – Antworten erscheinen im Overlay (/chat) und in der App
    }
    async command(m, acc, line) {
        const c = this.cfg();
        const [raw, ...args] = line.split(/\s+/);
        const cmd = lc(raw);
        const pn = c.points.name;
        const cd = (k, s) => { const t = this.cool.get(k) || 0; if (Date.now() - t < s * 1000) return true; this.cool.set(k, Date.now()); return false; };
        switch (cmd) {
            case 'punkte': case 'points': {
                const who = args[0] ? this.findUser(args[0]) : { a: acc };
                return this.reply(m.platform, who ? who.a.name + ' hat ' + who.a.p + ' ' + pn + '.' : 'Unbekannt: ' + args[0]);
            }
            case 'top': {
                const top = Object.values(this.points()).sort((a, b) => b.p - a.p).slice(0, 5);
                return this.reply(m.platform, '🏆 ' + top.map((a, i) => (i + 1) + '. ' + a.name + ' (' + a.p + ')').join(' · '));
            }
            case 'geben': case 'give': {
                const to = this.findUser(args[0] || ''), n = int(args[1]);
                if (!to || n <= 0) return this.reply(m.platform, 'So geht\'s: ' + c.prefix + 'geben Name Anzahl');
                if (acc.p < n) return this.reply(m.platform, m.name + ', du hast nur ' + acc.p + ' ' + pn + '.');
                if (to.a === acc) return;
                acc.p -= n; to.a.p += n; this.savePoints();
                return this.reply(m.platform, m.name + ' schenkt ' + to.a.name + ' ' + n + ' ' + pn + '. 🎁');
            }
            case 'addpunkte': case 'setpunkte': {
                if (!m.mod) return;
                const to = this.findUser(args[0] || ''), n = int(args[1]);
                if (!to) return this.reply(m.platform, 'Unbekannt: ' + (args[0] || '?'));
                to.a.p = Math.max(0, cmd === 'addpunkte' ? to.a.p + n : n); this.savePoints();
                return this.reply(m.platform, to.a.name + ' hat jetzt ' + to.a.p + ' ' + pn + '.');
            }
            case 'welt': return this.reply(m.platform, this.fill('🌐 Gerade in: {welt} ({spieler} Spieler)', m, acc));
            case 'spieler': return this.reply(m.platform, this.fill('👥 {spieler} Spieler in der Instanz', m, acc));
            case 'avatar': return this.reply(m.platform, this.fill('🎭 Avatar: {avatar}', m, acc));
            case 'song': case 'musik': return this.reply(m.platform, this.fill('🎵 {musik}', m, acc));
            case 'befehle': case 'commands':
                return this.reply(m.platform, 'Befehle: ' + ['punkte', 'top', 'geben', 'welt', 'spieler', 'song', 'belohnungen'].concat(c.commands.map(x => x.name)).map(x => c.prefix + x).join(' '));
            case 'belohnungen': case 'rewards': case 'shop':
                return this.reply(m.platform, c.rewards.length ? '🎁 ' + c.rewards.map(r => c.prefix + r.name + ' (' + r.cost + ')').join(' · ') : 'Noch keine Belohnungen.');
        }
        const own = c.commands.find(x => lc(x.name) === cmd);
        if (own) {
            if (own.mod && !m.mod) return;
            if (cd('c:' + own.name, int(own.cooldown))) return;
            return this.reply(m.platform, this.fill(own.response, m, acc, { args: args.join(' ') }));
        }
        const rw = c.rewards.find(x => lc(x.name) === cmd);
        if (rw) {
            if (acc.p < rw.cost) return this.reply(m.platform, m.name + ', „' + rw.name + '“ kostet ' + rw.cost + ' ' + pn + ' – du hast ' + acc.p + '.');
            if (cd('r:' + rw.name, int(rw.cooldown))) return this.reply(m.platform, '⏳ „' + rw.name + '“ ist gleich wieder verfügbar.');
            acc.p -= rw.cost; this.savePoints();
            this.event('reward', m.platform, m.name, rw.name + ' (' + rw.cost + ' ' + pn + ')');
            try { await this.app.macros.run(rw.script, { user: m.name, args: args.join(' '), kosten: String(rw.cost), punkte_name: pn }); }
            catch (e) { acc.p += rw.cost; this.savePoints(); throw new Error('„' + rw.name + '“ ging nicht: ' + e.message); }
            return this.reply(m.platform, this.fill(rw.response || '{user} hat „' + rw.name + '“ eingelöst!', m, acc, { kosten: String(rw.cost) }));
        }
    }
    watchTick() {
        const c = this.cfg();
        if (!c.points.per_watch) return;
        this.wt = (this.wt || 0) + 1;
        if (this.wt % Math.max(1, c.points.watch_every)) return;
        const lim = Date.now() - 15 * 60000;
        let n = 0;
        for (const [k, t] of this.active) { if (t < lim) { this.active.delete(k); continue; } const a = this.points()[k]; if (a) { a.p += c.points.per_watch; n++; } }
        if (n) this.savePoints();
    }

    // ---------- Oberfläche / Overlay ----------
    status() {
        const c = this.cfg(), k = this.keys();
        return { cfg: c, has: { twitch: !!k.twitch, youtube: !!k.youtube }, twitch: { state: this.tw.state, error: this.tw.error }, youtube: { state: this.yt.state, error: this.yt.error, title: this.yt.title || '' },
            seq: this.seq, users: Object.keys(this.points()).length };
    }
    feed(since) { return this.buf.filter(e => e.i > int(since)).slice(-200); }
    leaderboard(q, limit = 100) {
        const all = this.points();
        q = lc(q);
        return Object.entries(all).filter(([k, a]) => !q || lc(a.name).includes(q)).sort((a, b) => b[1].p - a[1].p).slice(0, limit)
            .map(([k, a]) => ({ key: k, platform: k.split(':')[0], name: a.name, p: a.p, msgs: a.msgs, last: a.last }));
    }
    async action(act, input) {
        const c = this.cfg();
        switch (act) {
            case 'stream_status': return Object.assign(this.status(), { feed: this.feed(input.since), top: this.leaderboard('', 10) });
            case 'stream_cfg': return Object.assign(this.status(), { cfg: this.saveCfg(input), msg: 'Gespeichert.' });
            case 'stream_key': this.setKey(str(input.kind), input.value); return Object.assign(this.status(), { msg: input.value ? 'Gespeichert (verschlüsselt).' : 'Entfernt.' });
            case 'stream_send': {
                const text = cut(clean(input.text), 480);
                if (!text) throw new UserError('Text fehlt.');
                if (input.platform !== 'youtube') this.twSend(text);
                this.event('bot', input.platform === 'youtube' ? 'youtube' : 'twitch', 'Du', text);
                return { msg: 'Gesendet.' };
            }
            case 'stream_points': return { list: this.leaderboard(str(input.q), 300), name: c.points.name };
            case 'stream_points_set': {
                const a = this.points()[str(input.key)];
                if (!a) throw new UserError('Unbekannt.');
                a.p = Math.max(0, input.add !== undefined && input.add !== '' ? a.p + int(input.add) : int(input.p));
                this.savePoints();
                return { list: this.leaderboard(str(input.q), 300), name: c.points.name };
            }
            case 'stream_cmd_save': case 'stream_reward_save': {
                const reward = act === 'stream_reward_save';
                const name = lc(input.name).replace(/^[!]/, '').replace(/[^a-z0-9äöüß_-]/g, '').slice(0, 24);
                if (!name) throw new UserError('Name fehlt.');
                const list = reward ? c.rewards : c.commands;
                const item = reward ? { name, cost: Math.max(1, Math.min(1000000, int(input.cost) || 100)), script: cut(str(input.script), 3000), cooldown: Math.max(0, Math.min(86400, int(input.cooldown))), response: cut(clean(input.response), 300) }
                    : { name, response: cut(clean(input.response), 450), mod: input.mod === '1' || input.mod === true, cooldown: Math.max(0, Math.min(86400, int(input.cooldown))) };
                if (reward) { if (!item.script.trim()) throw new UserError('Die Belohnung braucht eine Aktion (Makro-Schritte).'); require('./macros').parse(item.script); }
                else if (!item.response) throw new UserError('Antwort fehlt.');
                const i = list.findIndex(x => x.name === (lc(input.old) || name));
                if (i >= 0) list[i] = item; else list.push(item);
                this.writeCfg(c);
                return Object.assign(this.status(), { msg: 'Gespeichert.' });
            }
            case 'stream_cmd_delete': case 'stream_reward_delete': {
                const k = act === 'stream_reward_delete' ? 'rewards' : 'commands';
                c[k] = c[k].filter(x => x.name !== str(input.name));
                this.writeCfg(c);
                return this.status();
            }
            case 'stream_test': {
                // Testnachricht so verarbeiten, als käme sie aus dem Chat (ohne Plattform)
                this.message({ platform: str(input.platform) === 'youtube' ? 'youtube' : 'twitch', id: 'test_' + lc(input.user || 'tester'), name: str(input.user || 'Tester'), text: str(input.text), mod: input.mod === '1' });
                await new Promise(r => setTimeout(r, 150));
                return { feed: this.feed(input.since) };
            }
            case 'stream_reconnect': this.twClose('aus'); this.ytStop('aus'); this.apply(); return this.status();
        }
        throw new UserError('Unbekannte Aktion.');
    }
    overlay() { return { items: this.buf.filter(e => e.kind !== 'system').slice(-30), seq: this.seq }; }
}

module.exports = { StreamBot };
