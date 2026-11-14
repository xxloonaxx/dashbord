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
        { name: 'discord', response: 'Komm auf unseren Discord! (Link in der Beschreibung)', perm: 'all', cooldown: 30 },
        { name: 'farbe', response: '🎨 {user} hat die Farbe auf {1} gestellt!', script: 'param Color = {1}', perm: 'all', cost: 20, cooldown: 10, user_cooldown: 60, aliases: 'color', on: false },
    ],
    timers: [{ id: 't1', text: '💜 Gefällt dir der Stream? Folge gern! Befehle: !befehle', every: 20, min_msgs: 5, on: false }],
    events: {
        sub: { on: true, response: '🎉 Danke für das Abo, {user}!', script: '' },
        resub: { on: true, response: '🎉 {user} ist seit {monate} Monaten dabei – danke!', script: '' },
        subgift: { on: true, response: '🎁 {user} verschenkt {menge} Abo(s)!', script: '' },
        raid: { on: true, response: '🚀 Raid von {user} mit {menge} Leuten – willkommen!', script: '', min: 1 },
        bits: { on: true, response: '💎 Danke für {menge} Bits, {user}!', script: '', min: 100 },
        superchat: { on: true, response: '💰 Danke für den Super Chat ({betrag}), {user}!', script: '' },
        member: { on: true, response: '⭐ Willkommen als Mitglied, {user}!', script: '' },
        first: { on: false, response: '🥇 {user} ist heute als Erstes im Chat! +{bonus} {punkte_name}', script: '', bonus: 50 },
    },
    games: { gamble: { on: true, min: 10, max: 1000, cooldown: 30 }, coinflip: { on: true, min: 5, max: 500, cooldown: 20 }, slots: { on: true, cost: 20, cooldown: 30 }, duel: { on: true, max: 1000, cooldown: 60 } },
    tts: { on: false, cost: 100, max: 150 },
    quotes: [],
    counters: [{ name: 'tode', value: 0, text: '💀 Bisher {wert} Tode' }],
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
        this.firstSeen = new Set();   // „Erster im Chat“ + Zuschauer dieser Sitzung
        this.firstDone = false;
        this.msgCount = 0;            // für Timer (Nachrichten seit dem letzten Timer)
        this.timerLast = new Map();
        this.giveaway = null;         // { keyword, cost, entries: Map, started }
        this.poll = null;             // { q, options: [], votes: Map, started }
        this.duels = new Map();       // Herausgeforderter → { from, amount, at }
        this.connectedAt = 0;
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
            commands: Array.isArray(c.commands) ? c.commands : DEF.commands, rewards: Array.isArray(c.rewards) ? c.rewards : DEF.rewards,
            timers: Array.isArray(c.timers) ? c.timers : DEF.timers, quotes: Array.isArray(c.quotes) ? c.quotes : [], counters: Array.isArray(c.counters) ? c.counters : DEF.counters,
            events: Object.fromEntries(Object.entries(DEF.events).map(([k, v]) => [k, { ...v, ...((c.events || {})[k] || {}) }])),
            games: Object.fromEntries(Object.entries(DEF.games).map(([k, v]) => [k, { ...v, ...((c.games || {})[k] || {}) }])), tts: { ...DEF.tts, ...(c.tts || {}) } };
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
        if (cmd === '001') { this.tw.state = 'verbunden'; this.tw.error = ''; this.tw.retry = 0; if (!this.connectedAt) this.connectedAt = Date.now(); return; }
        if (cmd === 'JOIN') { this.tw.state = 'verbunden'; this.event('system', 'twitch', '', 'Twitch-Chat verbunden (#' + this.cfg().twitch.channel + ')'); return; }
        if (cmd === 'NOTICE' && /authentication failed|improperly formatted auth|login unsuccessful/i.test(text || '')) { this.tw.state = 'fehler'; this.tw.error = 'Anmeldung fehlgeschlagen – Token prüfen'; this.twClose('fehler'); return; }
        if (cmd === 'USERNOTICE') {
            const id = tags['msg-id'] || '', who = tags['display-name'] || tags.login || '';
            const kind = { sub: 'sub', resub: 'resub', subgift: 'subgift', submysterygift: 'subgift', anonsubgift: 'subgift', raid: 'raid' }[id];
            if (kind) this.onEvent(kind, 'twitch', who, { monate: tags['msg-param-cumulative-months'] || '1', menge: tags['msg-param-viewerCount'] || tags['msg-param-mass-gift-count'] || '1', text: text || '' });
            else { const s2 = tags['system-msg'] || id; if (s2) this.event('alert', 'twitch', who, s2); }
            return;
        }
        if (cmd !== 'PRIVMSG') return;
        const login = prefix.split('!')[0];
        const badges = str(tags.badges);
        const name = tags['display-name'] || login;
        if (int(tags.bits) > 0) this.onEvent('bits', 'twitch', name, { menge: String(int(tags.bits)), text: text || '' });
        this.message({ platform: 'twitch', id: tags['user-id'] || login, login, name, text: text || '',
            mod: tags.mod === '1' || /broadcaster\//.test(badges), owner: /broadcaster\//.test(badges), sub: /(subscriber|founder)\//.test(badges), vip: /vip\//.test(badges), color: tags.color || '' });
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
                    if (s.type === 'superChatEvent' || s.type === 'superStickerEvent') { this.onEvent('superchat', 'youtube', str(a.displayName), { betrag: str((s.superChatDetails || s.superStickerDetails || {}).amountDisplayString), text: clean((s.superChatDetails || {}).userComment || '') }); continue; }
                    if (s.type === 'newSponsorEvent' || s.type === 'memberMilestoneChatEvent') { this.onEvent('member', 'youtube', str(a.displayName), { monate: str((s.memberMilestoneChatDetails || {}).memberMonth || '1') }); continue; }
                    if (s.type && s.type !== 'textMessageEvent') continue;
                    this.message({ platform: 'youtube', id: str(a.channelId), login: str(a.channelId), name: str(a.displayName), text: str(s.displayMessage || (s.textMessageDetails || {}).messageText),
                        mod: !!(a.isChatModerator || a.isChatOwner), owner: !!a.isChatOwner, sub: !!a.isChatSponsor });
                }
            }
            this.yt.first = false;
            this.yt.state = 'verbunden';
            if (!this.connectedAt) this.connectedAt = Date.now();
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
        this.msgCount++;
        if (!this.firstSeen.has(key)) {
            this.firstSeen.add(key);
            const fe = c.events.first;
            if (!this.firstDone && fe.on) { this.firstDone = true; acc.p += int(fe.bonus); this.onEvent('first', m.platform, m.name, { bonus: String(int(fe.bonus)) }); }
        }
        if (this.giveaway && this.giveaway.keyword && lc(clean(m.text)) === lc(this.giveaway.keyword)) this.joinGiveaway(m, acc);
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
    /** Rechte: all | sub | vip | mod | owner */
    allowed(m, perm) {
        if (!perm || perm === 'all') return true;
        if (m.owner) return true;
        if (perm === 'mod') return !!m.mod;
        if (perm === 'vip') return !!(m.vip || m.mod);
        if (perm === 'sub') return !!(m.sub || m.vip || m.mod);
        return false;
    }
    /** Platzhalter im Makro-Skript sicher füllen: Übergaben aus dem Chat ohne Zeilenumbrüche/Steuerzeichen */
    fillScript(script, vars) {
        const safe = v => str(v).replace(/[\r\n\t]+/g, ' ').replace(/[^\p{L}\p{N} _.,:!?+\-#@'"()❤️-🫶]/gu, '').slice(0, 80);
        return str(script).replace(/\{(\w+)\}/g, (all, k) => (k in vars ? safe(vars[k]) : all));
    }
    async runScript(script, vars) {
        if (!str(script).trim()) return;
        await this.app.macros.run(this.fillScript(script, vars), vars);
    }
    async command(m, acc, line) {
        const c = this.cfg();
        const [raw, ...args] = line.split(/\s+/);
        const cmd = lc(raw);
        const pn = c.points.name;
        const cd = (k, sec) => { const t = this.cool.get(k) || 0; if (Date.now() - t < sec * 1000) return true; this.cool.set(k, Date.now()); return false; };
        const say = t => this.reply(m.platform, t);
        const amount = (a, max) => (lc(a) === 'alles' || lc(a) === 'all' ? Math.min(acc.p, max) : int(a));
        // ----- eigene Befehle (inkl. Aliase) zuerst – so lassen sich auch eingebaute überschreiben -----
        const own = c.commands.find(x => x.on !== false && (lc(x.name) === cmd || str(x.aliases).split(/[,\s]+/).map(lc).includes(cmd)));
        if (own) {
            if (!this.allowed(m, own.perm || (own.mod ? 'mod' : 'all'))) return;
            if (int(own.cost) > 0 && acc.p < int(own.cost)) return say(m.name + ', ' + c.prefix + own.name + ' kostet ' + own.cost + ' ' + pn + ' – du hast ' + acc.p + '.');
            // fehlt ein Wert, erst Hilfe zeigen – ohne die Pause zu verbrauchen
            if (/\{1\}|\{args\}/.test(str(own.script)) && !args.length) return say('So geht\'s: ' + c.prefix + own.name + ' ' + (own.usage || '<Wert>'));
            if (int(own.user_cooldown) && cd('u:' + own.name + ':' + acc.name, int(own.user_cooldown))) return;
            if (cd('c:' + own.name, int(own.cooldown))) return;
            const vars = { user: m.name, args: args.join(' '), kosten: String(int(own.cost)) };
            args.slice(0, 9).forEach((a, k) => { vars[String(k + 1)] = a; });
            if (int(own.cost) > 0) { acc.p -= int(own.cost); this.savePoints(); }
            try { await this.runScript(own.script, vars); }
            catch (e) { if (int(own.cost) > 0) { acc.p += int(own.cost); this.savePoints(); } throw new Error(c.prefix + own.name + ' ging nicht: ' + e.message); }
            own.uses = int(own.uses) + 1;
            this.countUse(own.name);
            return own.response ? say(this.fill(own.response, m, acc, vars)) : undefined;
        }
        // ----- Zähler: !tode  ·  Mods: !tode + / - / 5 / reset -----
        const ctr = c.counters.find(x => lc(x.name) === cmd);
        if (ctr) {
            const op = args[0];
            if (op !== undefined && m.mod) {
                ctr.value = op === '+' ? int(ctr.value) + 1 : op === '-' ? Math.max(0, int(ctr.value) - 1) : /^(reset|0)$/i.test(op) ? 0 : /^-?\d+$/.test(op) ? int(op) : int(ctr.value);
                this.writeCfg(c);
            }
            return say(this.fill(ctr.text || '{name}: {wert}', m, acc, { wert: String(int(ctr.value)), name: ctr.name }));
        }
        switch (cmd) {
            case 'punkte': case 'points': {
                const who = args[0] ? this.findUser(args[0]) : { a: acc };
                return say(who ? who.a.name + ' hat ' + who.a.p + ' ' + pn + '.' : 'Unbekannt: ' + args[0]);
            }
            case 'top': {
                const top = Object.values(this.points()).sort((a, b) => b.p - a.p).slice(0, 5);
                return say('🏆 ' + top.map((a, i) => (i + 1) + '. ' + a.name + ' (' + a.p + ')').join(' · '));
            }
            case 'geben': case 'give': {
                const to = this.findUser(args[0] || ''), n = int(args[1]);
                if (!to || n <= 0) return say('So geht\'s: ' + c.prefix + 'geben Name Anzahl');
                if (acc.p < n) return say(m.name + ', du hast nur ' + acc.p + ' ' + pn + '.');
                if (to.a === acc) return;
                acc.p -= n; to.a.p += n; this.savePoints();
                return say(m.name + ' schenkt ' + to.a.name + ' ' + n + ' ' + pn + '. 🎁');
            }
            case 'addpunkte': case 'setpunkte': {
                if (!m.mod) return;
                if (lc(args[0]) === 'alle' && cmd === 'addpunkte') { const n = int(args[1]); let k = 0; for (const key of this.firstSeen) { const a = this.points()[key]; if (a) { a.p = Math.max(0, a.p + n); k++; } } this.savePoints(); return say('🎁 ' + k + ' Zuschauer bekommen ' + n + ' ' + pn + '!'); }
                const to = this.findUser(args[0] || ''), n = int(args[1]);
                if (!to) return say('Unbekannt: ' + (args[0] || '?'));
                to.a.p = Math.max(0, cmd === 'addpunkte' ? to.a.p + n : n); this.savePoints();
                return say(to.a.name + ' hat jetzt ' + to.a.p + ' ' + pn + '.');
            }
            case 'zuschauzeit': case 'watchtime': {
                const who = args[0] ? this.findUser(args[0]) : { a: acc };
                if (!who) return say('Unbekannt: ' + args[0]);
                const mins = int(who.a.watch);
                return say('⏱ ' + who.a.name + ': ' + (mins >= 60 ? Math.floor(mins / 60) + ' h ' : '') + (mins % 60) + ' min Zuschauzeit · dabei seit ' + new Date(who.a.first * 1000).toLocaleDateString('de-DE'));
            }
            case 'uptime': {
                if (!this.connectedAt) return say('Der Bot ist gerade nicht verbunden.');
                const mins = Math.floor((Date.now() - this.connectedAt) / 60000);
                return say('⏱ Live seit ' + (mins >= 60 ? Math.floor(mins / 60) + ' h ' : '') + (mins % 60) + ' min (seit der Bot verbunden ist)');
            }
            case 'so': case 'shoutout': {
                if (!m.mod || !args[0]) return;
                const n = args[0].replace(/^@/, '');
                return say('📣 Schaut bei ' + n + ' vorbei' + (m.platform === 'twitch' ? ': https://twitch.tv/' + lc(n).replace(/[^a-z0-9_]/g, '') : '') + ' – große Empfehlung!');
            }
            case 'welt': return say(this.fill('🌐 Gerade in: {welt} ({spieler} Spieler)', m, acc));
            case 'spieler': return say(this.fill('👥 {spieler} Spieler in der Instanz', m, acc));
            case 'avatar': return say(this.fill('🎭 Avatar: {avatar}', m, acc));
            case 'song': case 'musik': return say(this.fill('🎵 {musik}', m, acc));
            case 'befehle': case 'commands':
                return say('Befehle: ' + this.publicCommands(c).map(x => c.prefix + x).join(' '));
            case 'belohnungen': case 'rewards': case 'shop':
                return say(c.rewards.length ? '🎁 ' + c.rewards.map(r => c.prefix + r.name + ' (' + r.cost + ')').join(' · ') : 'Noch keine Belohnungen.');
            // ----- Spiele -----
            case 'gamble': case 'zocken': {
                const g = c.games.gamble;
                if (!g.on) return;
                const n = amount(args[0], g.max);
                if (n < g.min || n > g.max) return say('Einsatz zwischen ' + g.min + ' und ' + g.max + ' ' + pn + ' (z. B. ' + c.prefix + 'gamble 50 oder alles).');
                if (acc.p < n) return say(m.name + ', du hast nur ' + acc.p + ' ' + pn + '.');
                if (cd('g:' + acc.name, g.cooldown)) return;
                const roll = 1 + Math.floor(Math.random() * 100);
                const win = roll >= 98 ? n * 3 : roll >= 61 ? n * 2 : 0;
                acc.p += win - n; this.savePoints();
                return say('🎲 ' + m.name + ' würfelt ' + roll + ' – ' + (win ? 'gewinnt ' + win + ' ' + pn + '!' + (roll >= 98 ? ' 🎉 JACKPOT!' : '') : 'verliert ' + n + ' ' + pn + '.') + ' Jetzt: ' + acc.p);
            }
            case 'münze': case 'muenze': case 'coinflip': case 'flip': {
                const g = c.games.coinflip;
                if (!g.on) return;
                const pick = lc(args[0]), n = amount(args[1], g.max);
                if (!['kopf', 'zahl'].includes(pick) || n < g.min || n > g.max) return say('So geht\'s: ' + c.prefix + 'münze kopf|zahl Einsatz (' + g.min + '–' + g.max + ')');
                if (acc.p < n) return say(m.name + ', du hast nur ' + acc.p + ' ' + pn + '.');
                if (cd('f:' + acc.name, g.cooldown)) return;
                const res = Math.random() < 0.5 ? 'kopf' : 'zahl';
                acc.p += res === pick ? n : -n; this.savePoints();
                return say('🪙 ' + (res === 'kopf' ? 'Kopf' : 'Zahl') + '! ' + m.name + (res === pick ? ' gewinnt ' + n : ' verliert ' + n) + ' ' + pn + '. Jetzt: ' + acc.p);
            }
            case 'slots': {
                const g = c.games.slots;
                if (!g.on) return;
                if (acc.p < g.cost) return say(m.name + ', ' + c.prefix + 'slots kostet ' + g.cost + ' ' + pn + '.');
                if (cd('s:' + acc.name, g.cooldown)) return;
                const sym = ['🍒', '🍋', '🔔', '⭐', '💎', '🦊'];
                const r = [0, 0, 0].map(() => sym[Math.floor(Math.random() * sym.length)]);
                const win = r[0] === r[1] && r[1] === r[2] ? g.cost * (r[0] === '🦊' ? 25 : r[0] === '💎' ? 15 : 8) : r[0] === r[1] || r[1] === r[2] || r[0] === r[2] ? g.cost * 2 : 0;
                acc.p += win - g.cost; this.savePoints();
                return say('🎰 [ ' + r.join(' | ') + ' ] ' + m.name + (win ? ' gewinnt ' + win + ' ' + pn + '!' : ' – leider nichts.') + ' Jetzt: ' + acc.p);
            }
            case 'duell': case 'duel': {
                const g = c.games.duel;
                if (!g.on) return;
                const to = this.findUser(args[0] || ''), n = amount(args[1], g.max);
                if (!to || to.a === acc || n <= 0 || n > g.max) return say('So geht\'s: ' + c.prefix + 'duell Name Einsatz (max. ' + g.max + ')');
                if (acc.p < n) return say(m.name + ', du hast nur ' + acc.p + ' ' + pn + '.');
                if (cd('d:' + acc.name, g.cooldown)) return;
                this.duels.set(lc(to.a.name), { from: acc, amount: n, at: Date.now() });
                return say('⚔️ ' + m.name + ' fordert ' + to.a.name + ' zum Duell um ' + n + ' ' + pn + '! Annehmen mit ' + c.prefix + 'annehmen (60 s).');
            }
            case 'annehmen': case 'accept': {
                const d = this.duels.get(lc(acc.name));
                if (!d || Date.now() - d.at > 60000) return;
                this.duels.delete(lc(acc.name));
                if (acc.p < d.amount || d.from.p < d.amount) return say('⚔️ Duell abgesagt – nicht genug ' + pn + '.');
                const winA = Math.random() < 0.5, w = winA ? d.from : acc, l = winA ? acc : d.from;
                w.p += d.amount; l.p -= d.amount; this.savePoints();
                return say('⚔️ ' + w.name + ' gewinnt das Duell gegen ' + l.name + ' und bekommt ' + d.amount + ' ' + pn + '!');
            }
            // ----- Zitate -----
            case 'zitat': case 'quote': {
                if (lc(args[0]) === 'add' || lc(args[0]) === 'neu') {
                    if (!m.mod) return;
                    const text = args.slice(1).join(' ').slice(0, 300);
                    if (!text) return;
                    c.quotes.push({ text, by: m.name, at: now() }); this.writeCfg(c);
                    return say('📝 Zitat #' + c.quotes.length + ' gespeichert.');
                }
                if (!c.quotes.length) return say('Noch keine Zitate – Mods: ' + c.prefix + 'zitat add Text');
                const k = /^\d+$/.test(args[0] || '') ? int(args[0]) - 1 : Math.floor(Math.random() * c.quotes.length);
                const q = c.quotes[k];
                return q ? say('📝 #' + (k + 1) + ': „' + q.text + '“') : say('Zitat #' + (k + 1) + ' gibt es nicht.');
            }
            // ----- Verlosung -----
            case 'verlosung': case 'giveaway': {
                if (!m.mod) return;
                const op = lc(args[0]);
                if (op === 'start') { this.giveawayStart(args[1] || c.prefix + 'mitmachen', int(args[2])); return say('🎁 Verlosung gestartet! Mitmachen mit „' + this.giveaway.keyword + '“' + (this.giveaway.cost ? ' (' + this.giveaway.cost + ' ' + pn + ')' : '') + '.'); }
                if (op === 'ziehen' || op === 'draw') { const w = this.giveawayDraw(); return say(w ? '🎉 Gewonnen hat: ' + w + '!' : 'Niemand macht mit.'); }
                if (op === 'stop' || op === 'ende') { this.giveaway = null; return say('🎁 Verlosung beendet.'); }
                return say(this.giveaway ? '🎁 ' + this.giveaway.entries.size + ' Teilnehmer.' : 'So geht\'s: ' + c.prefix + 'verlosung start Stichwort [Kosten] · ziehen · stop');
            }
            case 'mitmachen': case 'join':
                if (this.giveaway && lc(this.giveaway.keyword) === lc(c.prefix + cmd)) this.joinGiveaway(m, acc);
                return;
            // ----- Umfrage -----
            case 'umfrage': case 'poll': {
                if (!m.mod) return;
                if (/^(ende|stop|end)$/i.test(args[0] || '')) { const r = this.pollEnd(); return say(r || 'Keine Umfrage aktiv.'); }
                const parts = args.join(' ').split('|').map(x => x.trim()).filter(Boolean);
                if (parts.length < 3) return say('So geht\'s: ' + c.prefix + 'umfrage Frage | Antwort 1 | Antwort 2 [| …]');
                this.pollStart(parts[0], parts.slice(1, 7));
                return say('📊 ' + this.poll.q + ' – ' + this.poll.options.map((o, k) => (k + 1) + ') ' + o).join(' · ') + ' → abstimmen mit ' + c.prefix + 'stimme Nummer');
            }
            case 'stimme': case 'vote': {
                if (!this.poll) return;
                const k = int(args[0]) - 1;
                if (k >= 0 && k < this.poll.options.length) this.poll.votes.set(lc(acc.name), k);
                return;
            }
            // ----- Vorlesen -----
            case 'tts': case 'vorlesen': {
                const t = c.tts;
                if (!t.on) return;
                const text = args.join(' ').slice(0, t.max);
                if (!text) return say('So geht\'s: ' + c.prefix + 'tts Text (' + t.cost + ' ' + pn + ')');
                if (acc.p < t.cost) return say(m.name + ', ' + c.prefix + 'tts kostet ' + t.cost + ' ' + pn + '.');
                if (cd('tts', 10)) return say('⏳ Kurz warten …');
                acc.p -= t.cost; this.savePoints();
                if (this.app.tools.host.speak) this.app.tools.host.speak(m.name + ' sagt: ' + text);
                this.event('reward', m.platform, m.name, '🔊 ' + text);
                return;
            }
        }
        const rw = c.rewards.find(x => lc(x.name) === cmd);
        if (rw) {
            if (acc.p < rw.cost) return say(m.name + ', „' + rw.name + '“ kostet ' + rw.cost + ' ' + pn + ' – du hast ' + acc.p + '.');
            if (cd('r:' + rw.name, int(rw.cooldown))) return say('⏳ „' + rw.name + '“ ist gleich wieder verfügbar.');
            acc.p -= rw.cost; this.savePoints();
            this.event('reward', m.platform, m.name, rw.name + ' (' + rw.cost + ' ' + pn + ')');
            const vars = { user: m.name, args: args.join(' '), kosten: String(rw.cost), punkte_name: pn };
            args.slice(0, 9).forEach((a, k) => { vars[String(k + 1)] = a; });
            try { await this.runScript(rw.script, vars); }
            catch (e) { acc.p += rw.cost; this.savePoints(); throw new Error('„' + rw.name + '“ ging nicht: ' + e.message); }
            return say(this.fill(rw.response || '{user} hat „' + rw.name + '“ eingelöst!', m, acc, { kosten: String(rw.cost) }));
        }
    }
    publicCommands(c) {
        const b = ['punkte', 'top', 'geben', 'zuschauzeit', 'welt', 'spieler', 'song', 'belohnungen', 'zitat'];
        if (c.games.gamble.on) b.push('gamble'); if (c.games.coinflip.on) b.push('münze'); if (c.games.slots.on) b.push('slots'); if (c.games.duel.on) b.push('duell'); if (c.tts.on) b.push('tts');
        return b.concat(c.counters.map(x => x.name), c.commands.filter(x => x.on !== false && (!x.perm || x.perm === 'all')).map(x => x.name));
    }
    countUse(name) { const c = this.cfg(); const x = c.commands.find(y => y.name === name); if (x) { x.uses = int(x.uses) + 1; this.writeCfg(c); } }
    // ----- Ereignisse (Abo, Raid, Bits, Super Chat, Mitglied, Erster) -----
    onEvent(kind, platform, user, data = {}) {
        const c = this.cfg(), e = c.events[kind];
        const label = { sub: 'Abo', resub: 'Abo-Verlängerung', subgift: 'Geschenk-Abo', raid: 'Raid', bits: 'Bits', superchat: 'Super Chat', member: 'Mitglied', first: 'Erster im Chat' }[kind] || kind;
        this.event('alert', platform, user, label + (data.menge ? ' · ' + data.menge : '') + (data.betrag ? ' · ' + data.betrag : '') + (data.text ? ' – ' + data.text : ''));
        if (!e || !e.on) return;
        if ((kind === 'raid' || kind === 'bits') && int(data.menge) < int(e.min)) return;
        const acc = this.account(platform, 'ev_' + lc(user), user);
        const vars = Object.assign({ user, menge: '', monate: '', betrag: '', bonus: '' }, data);
        const m = { platform, name: user };
        if (e.response) this.reply(platform, this.fill(e.response, m, acc, vars));
        this.runScript(e.script, vars).catch(err => this.event('system', platform, '', '⚠ Ereignis-Aktion „' + label + '“: ' + err.message));
    }
    // ----- Verlosung / Umfrage -----
    giveawayStart(keyword, cost) { this.giveaway = { keyword: str(keyword).slice(0, 30), cost: Math.max(0, int(cost)), entries: new Map(), started: Date.now() }; }
    joinGiveaway(m, acc) {
        const g = this.giveaway;
        if (!g || g.entries.has(lc(acc.name))) return;
        if (g.cost) { if (acc.p < g.cost) return this.reply(m.platform, m.name + ', Mitmachen kostet ' + g.cost + ' ' + this.cfg().points.name + '.'); acc.p -= g.cost; this.savePoints(); }
        g.entries.set(lc(acc.name), acc.name);
    }
    giveawayDraw() {
        const g = this.giveaway;
        if (!g || !g.entries.size) return '';
        const names = [...g.entries.values()];
        const w = names[Math.floor(Math.random() * names.length)];
        g.entries.delete(lc(w));
        this.event('reward', 'twitch', w, '🎉 gewinnt die Verlosung');
        return w;
    }
    pollStart(q, options) { this.poll = { q: str(q).slice(0, 120), options: options.map(o => str(o).slice(0, 60)), votes: new Map(), started: Date.now() }; }
    pollResult() {
        if (!this.poll) return null;
        const n = this.poll.options.map(() => 0);
        for (const v of this.poll.votes.values()) n[v]++;
        return { q: this.poll.q, options: this.poll.options.map((o, k) => ({ text: o, votes: n[k] })), total: this.poll.votes.size };
    }
    pollEnd() {
        const r = this.pollResult();
        if (!r) return '';
        this.poll = null;
        const best = r.options.slice().sort((a, b) => b.votes - a.votes)[0];
        return '📊 Ergebnis „' + r.q + '“: ' + r.options.map(o => o.text + ' ' + o.votes).join(' · ') + (r.total ? ' → 🏆 ' + best.text : ' (keine Stimmen)');
    }
    watchTick() {
        const c = this.cfg();
        // Zuschauzeit (Minuten) für alle, die in den letzten 15 min aktiv waren
        const lim0 = Date.now() - 15 * 60000;
        for (const [k, t] of this.active) if (t >= lim0) { const a = this.points()[k]; if (a) a.watch = int(a.watch) + 1; }
        // Timer-Nachrichten
        for (const t of c.timers) {
            if (!t.on || !t.text) continue;
            const last = this.timerLast.get(t.id) || { at: Date.now(), msgs: this.msgCount };
            if (!this.timerLast.has(t.id)) { this.timerLast.set(t.id, last); continue; }
            if (Date.now() - last.at >= Math.max(1, int(t.every)) * 60000 && this.msgCount - last.msgs >= int(t.min_msgs)) {
                this.timerLast.set(t.id, { at: Date.now(), msgs: this.msgCount });
                if (this.cfg().twitch.on) this.reply('twitch', t.text); else if (this.cfg().youtube.on) this.reply('youtube', t.text);
            }
        }
        if (this.duels.size) for (const [k, d] of this.duels) if (Date.now() - d.at > 60000) this.duels.delete(k);
        if (!c.points.per_watch) { this.savePoints(); return; }
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
            seq: this.seq, users: Object.keys(this.points()).length, poll: this.pollResult(),
            giveaway: this.giveaway ? { keyword: this.giveaway.keyword, cost: this.giveaway.cost, entries: [...this.giveaway.entries.values()].slice(0, 200) } : null };
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
                const testScript = sc => { if (str(sc).trim()) require('./macros').parse(this.fillScript(sc, { user: 'Test', args: '1', 1: '1', 2: '1', 3: '1', kosten: '1', menge: '1', monate: '1', betrag: '1', bonus: '1' })); };
                const old = list.find(x => x.name === (lc(input.old) || name));
                const item = reward ? { name, cost: Math.max(1, Math.min(1000000, int(input.cost) || 100)), script: cut(str(input.script), 3000), cooldown: Math.max(0, Math.min(86400, int(input.cooldown))), response: cut(clean(input.response), 300) }
                    : { name, response: cut(clean(input.response), 450), script: cut(str(input.script || ''), 3000), perm: ['all', 'sub', 'vip', 'mod', 'owner'].includes(input.perm) ? input.perm : 'all',
                        cost: Math.max(0, Math.min(1000000, int(input.cost))), cooldown: Math.max(0, Math.min(86400, int(input.cooldown))), user_cooldown: Math.max(0, Math.min(86400, int(input.user_cooldown))),
                        aliases: cut(lc(input.aliases).replace(/[^a-z0-9äöüß_, -]/g, ''), 80), usage: cut(clean(input.usage), 60), on: input.on === undefined ? true : input.on === '1' || input.on === true, uses: old ? int(old.uses) : 0 };
                if (reward) { if (!item.script.trim()) throw new UserError('Die Belohnung braucht eine Aktion (Makro-Schritte).'); testScript(item.script); }
                else { if (!item.response && !item.script.trim()) throw new UserError('Der Befehl braucht eine Antwort oder eine Aktion.'); testScript(item.script); }
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
            case 'stream_timer_save': {
                const id = str(input.id) || 't' + Date.now().toString(36);
                const t = { id, text: cut(clean(input.text), 450), every: Math.max(1, Math.min(600, int(input.every) || 15)), min_msgs: Math.max(0, Math.min(1000, int(input.min_msgs))), on: input.on === '1' || input.on === true };
                if (!t.text) throw new UserError('Text fehlt.');
                const k = c.timers.findIndex(x => x.id === id);
                if (k >= 0) c.timers[k] = t; else c.timers.push(t);
                this.writeCfg(c);
                return Object.assign(this.status(), { msg: 'Gespeichert.' });
            }
            case 'stream_timer_delete': c.timers = c.timers.filter(x => x.id !== str(input.id)); this.writeCfg(c); return this.status();
            case 'stream_event_save': {
                const k = str(input.kind);
                if (!c.events[k]) throw new UserError('Unbekanntes Ereignis.');
                if (str(input.script).trim()) require('./macros').parse(this.fillScript(str(input.script), { user: 'Test', menge: '1', monate: '1', betrag: '1', bonus: '1', text: 'x' }));
                c.events[k] = Object.assign(c.events[k], { on: input.on === '1' || input.on === true, response: cut(clean(input.response), 300), script: cut(str(input.script), 3000) },
                    input.min !== undefined ? { min: Math.max(0, int(input.min)) } : {}, input.bonus !== undefined ? { bonus: Math.max(0, int(input.bonus)) } : {});
                this.writeCfg(c);
                return Object.assign(this.status(), { msg: 'Gespeichert.' });
            }
            case 'stream_event_test': this.onEvent(str(input.kind), 'twitch', 'Testperson', { menge: str(input.menge || '5'), monate: '3', betrag: '5,00 €', bonus: '50', text: 'Test' }); return { msg: 'Ereignis ausgelöst.' };
            case 'stream_games_save': {
                for (const g of ['gamble', 'coinflip', 'slots', 'duel']) {
                    const cur = c.games[g];
                    for (const k of Object.keys(cur)) if (input[g + '_' + k] !== undefined) cur[k] = k === 'on' ? input[g + '_' + k] === '1' : Math.max(0, int(input[g + '_' + k]));
                }
                for (const k of ['on', 'cost', 'max']) if (input['tts_' + k] !== undefined) c.tts[k] = k === 'on' ? input['tts_' + k] === '1' : Math.max(0, int(input['tts_' + k]));
                this.writeCfg(c);
                return Object.assign(this.status(), { msg: 'Gespeichert.' });
            }
            case 'stream_counter_save': {
                const name = lc(input.name).replace(/[^a-z0-9äöüß_-]/g, '').slice(0, 24);
                if (!name) throw new UserError('Name fehlt.');
                const k = c.counters.findIndex(x => x.name === (lc(input.old) || name));
                const x = { name, value: int(input.value), text: cut(clean(input.text), 200) || name + ': {wert}' };
                if (k >= 0) c.counters[k] = x; else c.counters.push(x);
                this.writeCfg(c);
                return Object.assign(this.status(), { msg: 'Gespeichert.' });
            }
            case 'stream_counter_step': { const x = c.counters.find(y => y.name === str(input.name)); if (x) { x.value = Math.max(0, int(x.value) + int(input.step)); this.writeCfg(c); } return this.status(); }
            case 'stream_counter_delete': c.counters = c.counters.filter(x => x.name !== str(input.name)); this.writeCfg(c); return this.status();
            case 'stream_quote_delete': c.quotes.splice(int(input.index), 1); this.writeCfg(c); return this.status();
            case 'stream_quote_add': { const t = cut(clean(input.text), 300); if (!t) throw new UserError('Text fehlt.'); c.quotes.push({ text: t, by: 'App', at: now() }); this.writeCfg(c); return this.status(); }
            case 'stream_giveaway': {
                const op = str(input.op);
                if (op === 'start') this.giveawayStart(str(input.keyword) || c.prefix + 'mitmachen', int(input.cost));
                if (op === 'stop') this.giveaway = null;
                let msg = '';
                if (op === 'draw') { const w = this.giveawayDraw(); msg = w ? '🎉 Gewonnen: ' + w : 'Niemand macht mit.'; if (w) this.reply(c.twitch.on ? 'twitch' : 'youtube', '🎉 Gewonnen hat: ' + w + '!'); }
                if (op === 'start') this.reply(c.twitch.on ? 'twitch' : 'youtube', '🎁 Verlosung gestartet! Mitmachen mit „' + this.giveaway.keyword + '“' + (this.giveaway.cost ? ' (' + this.giveaway.cost + ' ' + c.points.name + ')' : '') + '.');
                return Object.assign(this.status(), { msg });
            }
            case 'stream_poll': {
                const op = str(input.op);
                if (op === 'start') { const opts = str(input.options).split('\n').map(x => x.trim()).filter(Boolean); if (!str(input.q).trim() || opts.length < 2) throw new UserError('Frage und mindestens 2 Antworten.'); this.pollStart(str(input.q), opts.slice(0, 6)); this.reply(c.twitch.on ? 'twitch' : 'youtube', '📊 ' + this.poll.q + ' – ' + this.poll.options.map((o, k) => (k + 1) + ') ' + o).join(' · ') + ' → ' + c.prefix + 'stimme Nummer'); }
                let msg = '';
                if (op === 'end') { msg = this.pollEnd(); if (msg) this.reply(c.twitch.on ? 'twitch' : 'youtube', msg); }
                return Object.assign(this.status(), { msg });
            }
            case 'stream_reconnect': this.twClose('aus'); this.ytStop('aus'); this.apply(); return this.status();
        }
        throw new UserError('Unbekannte Aktion.');
    }
    overlay() { return { items: this.buf.filter(e => e.kind !== 'system').slice(-30), seq: this.seq, poll: this.pollResult() }; }
}

module.exports = { StreamBot };
