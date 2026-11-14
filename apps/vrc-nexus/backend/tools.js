'use strict';
/*
 * Desktop-Werkzeuge der App (wie in VRCX): Spielerliste, Freunde-Netz, Instanz-Aktivität, Screenshot-Manager,
 * Ordner-Verknüpfungen, config.json, Startoptionen, Registry-Sicherung, Auto-Status & Einladungen, Gruppen-Einladungen,
 * blockierte Gruppen, Einladungsnachrichten, Meldungen, Exporte, Benachrichtigungen (Windows, XSOverlay, Chatbox, Webhook),
 * Discord Rich Presence und die lokale Schnittstelle fürs Stream Deck.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const dgram = require('dgram');
const { execFile } = require('child_process');
const U = require('./util');
const { UserError, VrcAuthError, now, str, int, cut, arr } = U;
const { UID, VERSION } = require('./vrchat');
const { DiscordRpc } = require('./discordrpc');
const { LocalApi } = require('./localapi');

const LANG = { eng: '🇬🇧 Englisch', deu: '🇩🇪 Deutsch', fra: '🇫🇷 Französisch', spa: '🇪🇸 Spanisch', por: '🇵🇹 Portugiesisch', ita: '🇮🇹 Italienisch', nld: '🇳🇱 Niederländisch',
    pol: '🇵🇱 Polnisch', rus: '🇷🇺 Russisch', ukr: '🇺🇦 Ukrainisch', ces: '🇨🇿 Tschechisch', swe: '🇸🇪 Schwedisch', nor: '🇳🇴 Norwegisch', dan: '🇩🇰 Dänisch', fin: '🇫🇮 Finnisch',
    hun: '🇭🇺 Ungarisch', ron: '🇷🇴 Rumänisch', tur: '🇹🇷 Türkisch', ara: '🇸🇦 Arabisch', heb: '🇮🇱 Hebräisch', jpn: '🇯🇵 Japanisch', kor: '🇰🇷 Koreanisch', zho: '🇨🇳 Chinesisch',
    tha: '🇹🇭 Thai', vie: '🇻🇳 Vietnamesisch', ind: '🇮🇩 Indonesisch', msa: '🇲🇾 Malaiisch', fil: '🇵🇭 Filipino', ell: '🇬🇷 Griechisch', ase: '🤟 ASL', bfi: '🤟 BSL',
    dse: '🤟 NGT', fsl: '🤟 LSF', jsl: '🤟 JSL', kvk: '🤟 KSL', gsg: '🤟 DGS' };
const AUTO_DEF = { start_status: '', start_desc: '', quit_status: '', quit_desc: '', alone: false, alone_status: 'join me', company_status: 'ask me', company_min: 1,
    invite: 'off', invite_joinme_only: true, invite_msg: '', private_busy: false };
const LAUNCH_DEF = { mode: 'steam', exe: '', no_vr: false, profile: 0, fps: 0, width: 0, height: 0, fullscreen: '', debug_gui: false, sdk_logs: false, watch_worlds: false, watch_avatars: false, osc: '', extra: '' };
const CONFIG_FIELDS = [
    ['cache_directory', 'text', 'Cache-Ordner', 'leer = Standard'], ['cache_size', 'number', 'Cache-Größe (GB)', 'Standard 30, mind. 30'], ['cache_expiry_delay', 'number', 'Cache aufbewahren (Tage)', 'Standard 30'],
    ['picture_output_folder', 'text', 'Foto-Ordner', 'leer = Bilder\\VRChat'], ['picture_output_split_by_date', 'bool', 'Fotos nach Monat sortieren', ''],
    ['camera_res_width', 'number', 'Kamera-Auflösung Breite', '1280–7680'], ['camera_res_height', 'number', 'Kamera-Auflösung Höhe', '720–4320'],
    ['screenshot_res_width', 'number', 'Screenshot-Auflösung Breite', ''], ['screenshot_res_height', 'number', 'Screenshot-Auflösung Höhe', ''],
    ['fpv_steadycam_fov', 'number', 'Steadycam-Sichtfeld', '30–110'], ['disableRichPresence', 'bool', 'Discord/Steam-Rich-Presence von VRChat aus', ''],
    ['dynamic_bone_max_affected_transform_count', 'number', 'Dynamic Bones: max. Transforms', '0 = unbegrenzt'], ['dynamic_bone_max_collider_check_count', 'number', 'Dynamic Bones: max. Collider-Prüfungen', '0 = unbegrenzt'],
];
const MSG_TYPES = { message: 'Einladung', response: 'Antwort auf Einladung', request: 'Einladungs-Anfrage', requestResponse: 'Antwort auf Anfrage' };

// ---------- PNG-Textblöcke ----------
const CRC_TABLE = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
function crc32(buf) { let c = 0xffffffff; for (const b of buf) c = CRC_TABLE[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; }
const PNG_SIG = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
function pngChunks(buf) {
    if (buf.length < 8 || !buf.slice(0, 8).equals(PNG_SIG)) return null;
    const out = [];
    let p = 8;
    while (p + 12 <= buf.length) {
        const len = buf.readUInt32BE(p), type = buf.toString('latin1', p + 4, p + 8);
        if (p + 12 + len > buf.length) break;
        out.push({ type, start: p, end: p + 12 + len, data: buf.slice(p + 8, p + 8 + len) });
        p += 12 + len;
        if (type === 'IEND') break;
    }
    return out;
}
function pngText(buf) {
    const chunks = pngChunks(buf);
    if (!chunks) return null;
    const text = {};
    let width = 0, height = 0;
    for (const c of chunks) {
        if (c.type === 'IHDR') { width = c.data.readUInt32BE(0); height = c.data.readUInt32BE(4); }
        if (c.type === 'tEXt') { const i = c.data.indexOf(0); if (i > 0) text[c.data.toString('latin1', 0, i)] = c.data.toString('latin1', i + 1); }
        if (c.type === 'iTXt') {
            const i = c.data.indexOf(0);
            if (i <= 0) continue;
            const key = c.data.toString('latin1', 0, i), comp = c.data[i + 1];
            let p = i + 3;
            p = c.data.indexOf(0, p) + 1; if (!p) continue;      // Sprache
            p = c.data.indexOf(0, p) + 1; if (!p) continue;      // übersetztes Stichwort
            let val = c.data.slice(p);
            try { if (comp) val = zlib.inflateSync(val); } catch (e) { continue; }
            text[key] = val.toString('utf8');
        }
    }
    return { width, height, text };
}
function pngSetText(buf, key, value) {
    const chunks = pngChunks(buf);
    if (!chunks) throw new UserError('Keine PNG-Datei.');
    const keep = chunks.filter(c => !((c.type === 'iTXt' || c.type === 'tEXt') && c.data.toString('latin1', 0, c.data.indexOf(0)) === key));
    const iend = keep.findIndex(c => c.type === 'IEND');
    if (iend < 0) throw new UserError('PNG-Datei unvollständig.');
    const parts = [PNG_SIG];
    keep.forEach((c, i) => {
        if (i === iend && value !== null) {
            const data = Buffer.concat([Buffer.from(key, 'latin1'), Buffer.from([0, 0, 0, 0, 0]), Buffer.from(value, 'utf8')]);
            const head = Buffer.alloc(8); head.writeUInt32BE(data.length, 0); head.write('iTXt', 4, 'latin1');
            const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(Buffer.concat([head.slice(4), data])), 0);
            parts.push(head, data, crc);
        }
        parts.push(buf.slice(c.start, c.end));
    });
    return Buffer.concat(parts);
}

const sleep = ms => new Promise(r => setTimeout(r, ms));
const csvQ = v => '"' + str(v).replace(/"/g, '""') + '"';
const ownerOf = inst => { const m = /~(?:hidden|friends|private)\((usr_[\w-]+)\)/.exec(str(inst)); return m ? m[1] : ''; };

class Tools {
    /** app: NexusApp, host: Hilfsfunktionen von Electron (openPath, openExternal, notify, speak, setLoginItem, userData, showTab, setTray, thumbnail) */
    constructor(app, host = {}) {
        this.app = app;
        this.db = app.db;
        this.settings = app.settings;
        this.host = host;
        this.userData = host.userData || path.join(os.tmpdir(), 'vrc-nexus');
        this.queue = [];
        this.fetching = false;
        this.left = [];
        this.lastNotifPoll = 0;
        this.seenNotifs = null;
        this.lastAuto = { key: '', ts: 0 };
        this.rpc = new DiscordRpc();
        this.api = new LocalApi(this);
        this.udp = dgram.createSocket('udp4');
        this.udp.unref();
        this.udp.on('error', () => {});
        this.timer = null;
        this.netBusy = false;
        this.log = [];
        this.settings.onChange(changed => this.applySettings(changed));
        // zusätzliche Chatbox-Platzhalter: {freunde} {freunde_online} {status} {statustext}
        app.game.vars = () => {
            let fr = [];
            try { fr = this.app.vrc.friendList(); } catch (e) { fr = []; }
            const acc = this.acc(), me = acc ? acc.me || {} : {};
            const ST = { 'join me': 'Join Me', active: 'Online', 'ask me': 'Ask Me', busy: 'Beschäftigt' };
            return Object.assign({ freunde: String(fr.filter(f => f.state === 'online').length), freunde_online: String(fr.filter(f => f.state !== 'offline').length), status: ST[me.status] || '', statustext: str(me.statusDescription) }, this.app.auto ? this.app.auto.vars() : {});
        };
    }

    start() {
        this.applySettings(['*']);
        this.timer = setInterval(() => this.tick().catch(e => this.debug('tick', e)), 5000);
        if (this.timer.unref) this.timer.unref();
        if (this.app.auto) this.app.auto.start();
        if (this.app.insights) this.app.insights.start();
        if (this.app.stream) this.app.stream.apply();
        if (this.app.backup) this.app.backup.start();
        if (this.app.osclog && !this.oscLogOn) { this.oscLogOn = true; this.app.osclog.attach(this.app.game.osc); }
    }
    stop() { if (this.timer) clearInterval(this.timer); this.timer = null; if (this.app.auto) this.app.auto.stop(); if (this.app.insights) this.app.insights.stop(); if (this.app.osclog) this.app.osclog.stop(); if (this.app.stream) this.app.stream.stop(); this.api.stop(); this.rpc.close(); }
    applySettings(changed) {
        const all = changed.includes('*');
        if (all || changed.some(k => k.startsWith('int.api') || k === 'int.obs')) this.api.apply();
        if (all || changed.includes('sys.autostart')) { if (this.host.setLoginItem) this.host.setLoginItem(!!this.settings.get('sys.autostart')); }
        if (all || changed.includes('sys.log_poll_ms')) { if (this.app.game.timer) this.app.game.start(int(this.settings.get('sys.log_poll_ms')) || 1000); }
        if (!this.settings.get('int.rpc')) this.rpc.close();
        if (this.host.applySettings) this.host.applySettings(changed, this.settings.all());
    }
    debug(where, e) {
        if (!this.settings.get('adv.debug')) return;
        const line = new Date().toISOString() + ' [' + where + '] ' + (e && e.stack || e) + '\n';
        try { fs.appendFileSync(path.join(this.userData, 'nexus.log'), line); } catch (x) { /* egal */ }
    }
    acc() { return this.app.vrc.account(); }
    call(method, p, q = {}, b = null) { const acc = this.acc(); if (!acc) throw new VrcAuthError('Kein VRChat-Konto verbunden.'); return this.app.vrc.call(acc, method, p, q, b); }

    // ======================= Pfade =======================
    vrchatDir() { return process.env.NEXUS_VRCHAT_DIR || path.join(os.homedir(), 'AppData', 'LocalLow', 'VRChat', 'VRChat'); }
    photoDir() {
        const s = str(this.settings.get('media.photo_dir')).trim();
        if (s) return s;
        try { const c = JSON.parse(fs.readFileSync(path.join(this.vrchatDir(), 'config.json'), 'utf8')); if (c.picture_output_folder) return str(c.picture_output_folder); } catch (e) { /* Standard */ }
        return process.env.NEXUS_PHOTO_DIR || path.join(os.homedir(), 'Pictures', 'VRChat');
    }
    crashDir() { return path.join(process.env.TEMP || os.tmpdir(), 'VRChat', 'VRChat', 'Crashes'); }
    async steamDir() {
        const s = str(this.settings.get('media.steam_dir')).trim();
        if (s) return s;
        let steam = '';
        if (process.platform === 'win32') {
            steam = await new Promise(r => execFile('reg', ['query', 'HKCU\\Software\\Valve\\Steam', '/v', 'SteamPath'], { windowsHide: true }, (e, out) => r(e ? '' : (/SteamPath\s+REG_SZ\s+(.+)/.exec(out) || [])[1] || '')));
            steam = steam.trim().replace(/\//g, '\\') || 'C:\\Program Files (x86)\\Steam';
        } else steam = path.join(os.homedir(), '.steam', 'steam');
        try {
            for (const id of fs.readdirSync(path.join(steam, 'userdata'))) {
                const d = path.join(steam, 'userdata', id, '760', 'remote', '438100', 'screenshots');
                if (fs.existsSync(d)) return d;
            }
        } catch (e) { /* kein Steam */ }
        return '';
    }
    async openFolder(which) {
        const map = { photos: () => this.photoDir(), steam: () => this.steamDir(), nexus: () => this.userData, vrchat: () => this.vrchatDir(), crashes: () => this.crashDir(),
            logs: () => this.app.game.logDir(), backups: () => path.join(this.userData, 'backups'), registry: () => path.join(this.userData, 'registry') };
        if (!map[which]) throw new UserError('Unbekannter Ordner.');
        const dir = await map[which]();
        if (!dir) throw new UserError('Ordner nicht gefunden (Steam-Screenshots: in den Einstellungen → Medien eintragen).');
        if (!fs.existsSync(dir)) {
            if (['backups', 'registry'].includes(which)) fs.mkdirSync(dir, { recursive: true });
            else throw new UserError('Den Ordner gibt es (noch) nicht: ' + dir);
        }
        if (this.host.openPath) await this.host.openPath(dir);
        return { msg: 'Geöffnet: ' + dir, path: dir };
    }

    // ======================= Benachrichtigungen =======================
    quiet() {
        const f = this.settings.get('notify.quiet_from'), t = this.settings.get('notify.quiet_to');
        if (!f || !t) return false;
        const d = new Date(), m = d.getHours() * 60 + d.getMinutes(), a = +f.slice(0, 2) * 60 + +f.slice(3), b = +t.slice(0, 2) * 60 + +t.slice(3);
        return a <= b ? m >= a && m < b : m >= a || m < b;
    }
    /** Eine Meldung über alle eingeschalteten Wege; webhookKind: 'join' | 'vrc' | 'world' | '' */
    alert(title, body, webhookKind = '') {
        const s = this.settings.all();
        this.log.unshift({ ts: now(), title, body });
        this.log = this.log.slice(0, 50);
        if (webhookKind && s['int.webhook'] && s['int.webhook_' + webhookKind]) this.webhook(title + (body ? '\n' + body : ''));
        if (this.quiet()) return;
        if (s['notify.desktop'] && this.host.notify) this.host.notify(title, body, s['notify.silent']);
        if (s['notify.tts'] && this.host.speak) this.host.speak(title + '. ' + body);
        const inGame = this.app.game.state.running;
        if (s['vr.only_in_game'] && !inGame) return;
        if (s['vr.xsoverlay']) this.xsoverlay(title, body);
        if (s['vr.chatbox'] && inGame) this.app.game.osc.chatbox(cut((s['vr.chatbox_prefix'] ? s['vr.chatbox_prefix'] + ' ' : '') + title, 144), false).catch(() => {});
    }
    xsoverlay(title, content) {
        const s = this.settings.all();
        const msg = { messageType: 1, index: 0, timeout: s['vr.xs_timeout'], height: content ? 120 : 90, opacity: s['vr.xs_opacity'] / 100, volume: s['vr.xs_volume'] / 100,
            audioPath: s['vr.xs_volume'] > 0 ? 'default' : '', title, content: content || '', useBase64Icon: false, icon: 'default', sourceApp: 'VRC Nexus' };
        this.udp.send(Buffer.from(JSON.stringify(msg)), s['vr.xs_port'], '127.0.0.1', () => {});
    }
    webhook(text) {
        const url = this.settings.get('int.webhook');
        if (!url) return;
        fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'VRC Nexus', content: cut(text, 1900), allowed_mentions: { parse: [] } }), signal: AbortSignal.timeout(10000) })
            .catch(e => this.debug('webhook', e));
    }
    testAlert() { this.alert('🔔 Test von VRC Nexus', 'So sehen Benachrichtigungen aus.', 'vrc'); return { msg: 'Test gesendet.' }; }

    // ======================= Spiel-Ereignisse =======================
    friendMap() {
        const m = new Map();
        try { for (const f of this.app.vrc.friendList()) m.set(f.id, f); } catch (e) { /* ohne Konto */ }
        return m;
    }
    whoMatches(mode, id) {
        if (mode === 'off') return false;
        if (mode === 'all') return true;
        const f = id ? this.friendMap().get(id) : null;
        if (!f) return false;
        return mode === 'friends' || (mode === 'favorites' && (f.favorite || !!f.fav_group));
    }
    onGameEvent(e) {
        const fresh = int(e.ts) > now() - 90;          // alte Zeilen beim Start nicht melden
        const s = this.settings.all();
        if (e.kind === 'world_join') this.left = [];
        if (e.kind === 'player_leave') {
            const st = this.app.game.state;
            this.left.unshift({ id: e.player_id, name: e.player_name, left: int(e.ts), world: st.worldName });
            this.left = this.left.slice(0, 100);
        }
        if (!fresh) return;
        if (e.kind === 'player_join' && e.player_id !== this.app.game.state.userId) {
            if (this.whoMatches(s['notify.player_join'], e.player_id)) this.alert('➡ ' + e.player_name, 'hat ' + (e.world_name || 'deine Instanz') + ' betreten', this.friendMap().has(e.player_id) ? 'join' : '');
            else if (s['int.webhook_join'] && this.friendMap().has(e.player_id)) this.webhook('➡ ' + e.player_name + ' hat ' + (e.world_name || 'deine Instanz') + ' betreten');
            if (e.player_id) this.enqueue([e.player_id]);
        }
        if (e.kind === 'player_leave' && this.whoMatches(s['notify.player_leave'], e.player_id)) this.alert('⬅ ' + e.player_name, 'hat die Instanz verlassen');
        if (e.kind === 'world_join' && s['int.webhook_world']) this.webhook('🌐 Neue Welt: ' + (e.world_name || e.world_id));
        if (e.kind === 'screenshot') this.onScreenshot(e).catch(x => this.debug('screenshot', x));
        if (e.kind === 'vrc_start' || e.kind === 'world_join' && !this.wasRunning) this.onVrcStart();
        if (e.kind === 'vrc_quit') this.onVrcQuit();
        if (['world_join', 'player_join', 'player_leave'].includes(e.kind)) setTimeout(() => this.autoCompany().catch(x => this.debug('auto', x)), 2000);
    }
    onVrcStart() {
        if (this.wasRunning) return;
        this.wasRunning = true;
        if (this.settings.get('notify.vrc_start')) this.alert('🎮 VRChat gestartet', '');
        const a = this.autoCfg();
        if (a.start_status) this.setStatus(a.start_status, a.start_desc, 'start').catch(x => this.debug('auto', x));
    }
    onVrcQuit() {
        this.wasRunning = false;
        if (this.settings.get('notify.vrc_start')) this.alert('🛑 VRChat beendet', '');
        const a = this.autoCfg();
        if (a.quit_status) this.setStatus(a.quit_status, a.quit_desc, 'quit').catch(x => this.debug('auto', x));
        this.rpc.setActivity(null);
    }
    async onScreenshot(e) {
        const s = this.settings.all();
        const file = str(e.detail);
        if (!/\.png$/i.test(file)) return;
        for (let i = 0; i < 20 && !fs.existsSync(file); i++) await sleep(250);
        if (!fs.existsSync(file)) return;
        await sleep(400);
        if (s['media.meta']) this.writeMeta(file);
        if (s['media.copy'] && s['media.copy_dir']) {
            const st = this.app.game.state;
            const sub = s['media.copy_by_world'] ? (st.worldName || 'Unbekannt').replace(/[<>:"/\\|?*\x00-\x1f]/g, '_').slice(0, 80) : new Date().toISOString().slice(0, 7);
            const dir = path.join(s['media.copy_dir'], sub);
            fs.mkdirSync(dir, { recursive: true });
            fs.copyFileSync(file, path.join(dir, path.basename(file)));
        }
        if (s['notify.screenshot']) this.alert('📷 Screenshot', path.basename(file));
    }
    metaNow() {
        const st = this.app.game.state, acc = this.acc();
        return { application: 'VRC Nexus', version: VERSION, author: { id: st.userId || (acc ? acc.vrc_id : ''), displayName: st.displayName || (acc ? acc.display_name : '') },
            world: { id: st.worldId, name: st.worldName, instanceId: st.worldId ? st.worldId + ':' + st.instance : '' },
            players: [...st.players.values()].map(p => ({ id: p.id, displayName: p.name })), created_at: new Date().toISOString() };
    }
    writeMeta(file, meta) {
        const buf = fs.readFileSync(file);
        fs.writeFileSync(file, pngSetText(buf, 'Description', JSON.stringify(meta || this.metaNow())));
    }

    // ======================= Status-Automatik =======================
    autoCfg() { try { return Object.assign({}, AUTO_DEF, JSON.parse(this.db.setting('auto_cfg', '{}'))); } catch (e) { return Object.assign({}, AUTO_DEF); } }
    autoSave(input) {
        const st = ['', 'join me', 'active', 'ask me', 'busy'];
        const c = this.autoCfg();
        for (const k of ['start_status', 'quit_status', 'alone_status', 'company_status']) if (input[k] !== undefined) c[k] = st.includes(str(input[k])) ? str(input[k]) : c[k];
        for (const k of ['start_desc', 'quit_desc', 'invite_msg']) if (input[k] !== undefined) c[k] = cut(str(input[k]).trim(), k === 'invite_msg' ? 100 : 32);
        for (const k of ['alone', 'invite_joinme_only', 'private_busy']) if (input[k] !== undefined) c[k] = ['1', 'true', 'on'].includes(str(input[k]));
        if (input.invite !== undefined) c.invite = ['off', 'favorites', 'friends'].includes(str(input.invite)) ? str(input.invite) : 'off';
        if (input.company_min !== undefined) c.company_min = Math.max(1, Math.min(80, int(input.company_min) || 1));
        this.db.setSetting('auto_cfg', JSON.stringify(c));
        return c;
    }
    async setStatus(status, desc, why) {
        const acc = this.acc();
        if (!acc || !status) return false;
        const key = status + '|' + (desc || '');
        const cur = str(acc.me && acc.me.status);
        if (key === this.lastAuto.key && now() - this.lastAuto.ts < 600) return false;
        if (cur === status && !desc) { this.lastAuto = { key, ts: now() }; return false; }
        if (now() - this.lastAuto.ts < 20 && why !== 'manual') return false;      // nicht hektisch umschalten
        this.lastAuto = { key, ts: now() };
        const body = { status };
        if (desc) body.statusDescription = cut(desc, 32);
        await this.call('PUT', '/users/' + encodeURIComponent(acc.vrc_id), {}, body);
        this.db.prepare('UPDATE accounts SET last_sync = 0 WHERE user_id = ?').run(UID);
        return true;
    }
    async autoCompany() {
        const a = this.autoCfg();
        const st = this.app.game.state;
        if (!st.running || !st.worldId) return;
        if (a.private_busy && /~private\(/.test(st.instance)) { await this.setStatus('busy', '', 'auto'); return; }
        if (!a.alone) return;
        const others = [...st.players.values()].filter(p => p.id !== st.userId && p.name !== st.displayName).length;
        await this.setStatus(others >= a.company_min ? a.company_status : a.alone_status, '', 'auto');
    }

    // ======================= Regelmäßig =======================
    async tick() {
        const s = this.settings.all();
        const st = this.app.game.state;
        if (st.running && !this.wasRunning) this.onVrcStart();
        if (s['int.rpc'] && s['int.rpc_client']) await this.updateRpc();
        if (this.host.setTray && s['sys.tray_count']) {
            try { const fr = this.app.vrc.friendList(); this.host.setTray('VRC Nexus · ' + fr.filter(f => f.state === 'online').length + ' im Spiel, ' + fr.filter(f => f.state === 'active').length + ' online'); } catch (e) { /* egal */ }
        }
        this.backupTick();
        const a = this.autoCfg();
        const wantNotifs = s['notify.vrc'] || a.invite !== 'off' || s['int.webhook_vrc'];
        if (wantNotifs && this.acc() && now() - this.lastNotifPoll >= 120) { this.lastNotifPoll = now(); await this.pollNotifs(a).catch(e => this.debug('notifs', e)); }
    }
    async updateRpc() {
        const s = this.settings.all();
        await this.rpc.connect(s['int.rpc_client']);
        const st = this.app.game.state;
        if (!st.running || !st.worldId) { this.rpc.setActivity(null); return; }
        const priv = /~(private|friends|hidden)\(/.test(st.instance);
        const hide = s['int.rpc_private'] && priv;
        const access = U.location(st.worldId + ':' + st.instance).access;
        const act = { details: hide || !s['int.rpc_world'] ? 'In VRChat' : cut(st.worldName || 'In einer Welt', 120), state: (hide ? 'Private Instanz' : access) + (s['int.rpc_players'] ? ' · ' + (st.players.size || 1) + ' Spieler' : ''),
            timestamps: { start: (st.since || now()) * 1000 }, assets: { large_image: 'vrchat', large_text: 'VRC Nexus' }, instance: false };
        if (s['int.rpc_join'] && access === 'Public') act.buttons = [{ label: 'Beitreten', url: 'https://vrchat.com/home/launch?worldId=' + encodeURIComponent(st.worldId) + '&instanceId=' + encodeURIComponent(st.instance) }];
        this.rpc.setActivity(act);
    }
    async pollNotifs(a) {
        const s = this.settings.all();
        const list = arr(await this.call('GET', '/auth/user/notifications', { n: 50 }));
        const fresh = this.seenNotifs ? list.filter(n => !this.seenNotifs.has(n.id)) : [];
        this.seenNotifs = new Set(list.map(n => n.id));
        const labels = { invite: ['📨 Einladung', 'vrc_invite'], requestInvite: ['🙋 Einladungs-Anfrage', 'vrc_request'], friendRequest: ['🤝 Freundschaftsanfrage', 'vrc_friend'] };
        for (const n of fresh) {
            const l = labels[n.type];
            if (l && s['notify.vrc'] && s['notify.' + l[1]]) this.alert(l[0] + ' von ' + str(n.senderUsername), n.type === 'invite' ? str((typeof n.details === 'object' ? n.details : {}).worldName) : '', 'vrc');
            else if (l && s['int.webhook_vrc']) this.webhook(l[0] + ' von ' + str(n.senderUsername));
            if (n.type === 'requestInvite' && a.invite !== 'off') await this.autoInvite(n, a).catch(e => this.debug('autoinvite', e));
        }
        if (s['notify.vrc'] && s['notify.vrc_group']) {
            const v2 = arr(await this.call('GET', '/notifications', { n: 30 }).catch(() => []));
            const f2 = this.seenV2 ? v2.filter(n => !this.seenV2.has(n.id)) : [];
            this.seenV2 = new Set(v2.map(n => n.id));
            for (const n of f2) if (/^group\./.test(str(n.type))) this.alert('🛡️ ' + (str(n.title) || 'Gruppe'), cut(str(n.message), 120), 'vrc');
        }
    }
    async autoInvite(n, a) {
        const st = this.app.game.state, acc = this.acc();
        if (!st.running || !st.worldId || !acc) return false;
        if (a.invite_joinme_only && str(acc.me && acc.me.status) !== 'join me') return false;
        const f = this.friendMap().get(n.senderUserId);
        if (!f || (a.invite === 'favorites' && !(f.favorite || f.fav_group))) return false;
        const body = { instanceId: st.worldId + ':' + st.instance };
        await this.call('POST', '/invite/' + encodeURIComponent(n.senderUserId), {}, body);
        await this.call('PUT', '/auth/user/notifications/' + encodeURIComponent(n.id) + '/hide').catch(() => {});
        this.alert('✅ ' + str(n.senderUsername) + ' automatisch eingeladen', st.worldName);
        return true;
    }

    // ======================= Sicherung =======================
    backupTick() {
        const mode = this.settings.get('sys.backup');
        if (mode === 'off' || !this.db.file) return;
        const last = int(this.db.setting('last_backup', '0'));
        if (now() - last < (mode === 'daily' ? 86400 : 7 * 86400)) return;
        this.backupNow();
    }
    backupNow() {
        if (!this.db.file) throw new UserError('Keine Datenbank-Datei (Testmodus).');
        this.db.save();
        const dir = path.join(this.userData, 'backups');
        fs.mkdirSync(dir, { recursive: true });
        const f = path.join(dir, 'nexus_' + new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19) + '.sqlite');
        fs.copyFileSync(this.db.file, f);
        this.db.setSetting('last_backup', String(now()));
        const keep = Math.max(1, int(this.settings.get('sys.backup_keep')) || 5);
        const files = fs.readdirSync(dir).filter(x => /^nexus_.*\.sqlite$/.test(x)).sort().reverse();
        files.slice(keep).forEach(x => fs.unlinkSync(path.join(dir, x)));
        return { msg: 'Datenbank gesichert: ' + path.basename(f) };
    }

    // ======================= Spielerliste =======================
    cached(id) {
        const r = this.db.prepare('SELECT data, fetched FROM user_cache WHERE vrc_id = ?').get(id);
        if (!r) return null;
        try { return Object.assign(JSON.parse(r.data), { _fetched: int(r.fetched) }); } catch (e) { return null; }
    }
    enqueue(ids) {
        if (!this.settings.get('social.players_fetch') || !this.acc()) return;
        const ttl = (int(this.settings.get('social.players_cache')) || 60) * 60;
        for (const id of ids) {
            if (!/^usr_[0-9a-f-]{36}$/i.test(id) || this.queue.includes(id)) continue;
            const c = this.cached(id);
            if (!c || c._fetched < now() - ttl) this.queue.push(id);
        }
        if (!this.fetching && this.queue.length) this.drain();
    }
    async drain() {
        this.fetching = true;
        try {
            while (this.queue.length) {
                const id = this.queue.shift();
                try {
                    const u = await this.call('GET', '/users/' + encodeURIComponent(id));
                    this.db.prepare('INSERT OR REPLACE INTO user_cache (vrc_id, data, fetched) VALUES (?, ?, ?)').run(id, JSON.stringify(u), now());
                } catch (e) {
                    if (e instanceof VrcAuthError) { this.queue = []; break; }
                    if (/429|zu viele/i.test(e.message)) { this.queue.unshift(id); await sleep(15000); }
                }
                await sleep(1100);
            }
        } finally { this.fetching = false; }
    }
    playerRow(p, friends, notes, ownerId, meId) {
        const u = p.id ? this.cached(p.id) : null;
        const tags = arr(u && u.tags);
        const f = friends.get(p.id);
        const n = notes.get(p.id) || {};
        const langs = tags.filter(t => t.startsWith('language_')).map(t => LANG[t.slice(9)] || t.slice(9));
        const icons = [];
        if (p.id === meId) icons.push(['me', '🫵', 'Du']);
        if (p.id && p.id === ownerId) icons.push(['owner', '👑', 'Instanz-Besitzer']);
        if (f) icons.push(['friend', '🤝', 'Freund']);
        if (f && (f.favorite || f.fav_group)) icons.push(['fav', '⭐', 'Favorit']);
        if (tags.includes('system_supporter')) icons.push(['vrcplus', '➕', 'VRChat+']);
        if (u && str(u.ageVerificationStatus) === '18+') icons.push(['age', '🔞', 'Altersverifiziert (18+)']);
        else if (u && u.ageVerified) icons.push(['agev', '🪪', 'Altersverifiziert']);
        if (tags.includes('system_early_adopter')) icons.push(['early', '🌱', 'Early Adopter']);
        if (tags.some(t => t.startsWith('admin_')) || (u && u.developerType && u.developerType !== 'none')) icons.push(['team', '🛠', 'VRChat-Team']);
        if (tags.includes('system_probable_troll') || tags.includes('system_troll')) icons.push(['troll', '⚠', 'Als Störenfried markiert']);
        if (u && u.isFriend === false && u.friendRequestStatus === 'outgoing') icons.push(['req', '📨', 'Freundschaftsanfrage gesendet']);
        return { id: p.id, name: p.name, since: int(p.since), image: u ? U.userImage(u) : f ? f.image : '', trust: u ? U.trust(tags) : f ? f.trust : ['?', '#7b8794'],
            status: u ? str(u.status) : f ? f.status : '', status_desc: u ? str(u.statusDescription) : f ? f.status_desc : '', icons,
            platform: U.VRC_PLATFORM[str(u && u.last_platform)] || (u ? str(u.last_platform) : ''), langs, links: arr(u && u.bioLinks).filter(l => /^https?:\/\//.test(str(l))).slice(0, 3),
            note: u ? cut(str(u.note), 300) : '', memo: str(n.note), pronouns: u ? str(u.pronouns) : '', loaded: !!u, fetched: u ? u._fetched : 0 };
    }
    playersNow() {
        const st = this.app.game.state, acc = this.acc();
        const friends = this.friendMap();
        const notes = new Map(this.db.prepare('SELECT vrc_id, note, favorite FROM notes WHERE user_id = ?').all(UID).map(r => [r.vrc_id, r]));
        const ownerId = ownerOf(st.instance), meId = st.userId || (acc ? acc.vrc_id : '');
        const players = [...st.players.values()];
        this.enqueue(players.map(p => p.id).filter(Boolean));
        const loc = st.worldId ? U.location(st.worldId + ':' + st.instance) : null;
        const owner = ownerId ? (players.find(p => p.id === ownerId) || friends.get(ownerId) || this.cached(ownerId)) : null;
        return { running: st.running, world_id: st.worldId, world_name: st.worldName, instance: st.instance, since: st.since, loc,
            owner: ownerId ? { id: ownerId, name: owner ? str(owner.name || owner.displayName) : '' } : (loc && loc.group ? { id: loc.group, name: 'Gruppe' } : null),
            players: players.map(p => this.playerRow(p, friends, notes, ownerId, meId)),
            left: this.settings.get('social.players_left') ? this.left.slice(0, 30).map(l => Object.assign(this.playerRow({ id: l.id, name: l.name, since: 0 }, friends, notes, ownerId, meId), { left: l.left })) : [],
            queue: this.queue.length, fetch: !!this.settings.get('social.players_fetch'), logs: !!this.app.game.watcher };
    }
    async memoSave(id, memo) {
        U.valid(id, 'usr');
        const n = this.app.vrc.note(id);
        this.app.vrc.noteSave(id, memo, !!n.favorite);
        if (this.settings.get('social.memo_remote')) { await this.call('POST', '/userNotes', {}, { targetUserId: id, note: cut(memo, 256) }); return { msg: 'Memo gespeichert – auch als VRChat-Notiz.' }; }
        return { msg: 'Memo gespeichert.' };
    }

    // ======================= Freunde-Netz & Instanz-Aktivität =======================
    network(start) {
        const friends = this.app.vrc.friendList();
        const limit = int(this.settings.get('social.mutual_limit')) || 150;
        const ttl = (int(this.settings.get('social.mutual_cache')) || 7) * 86400;
        const ids = new Set(friends.map(f => f.id));
        const rows = new Map(this.db.prepare('SELECT vrc_id, data, fetched FROM mutual_cache').all().map(r => [r.vrc_id, r]));
        const pick = friends.slice().sort((a, b) => (b.favorite - a.favorite) || (a.state === 'offline') - (b.state === 'offline')).slice(0, limit);
        const todo = pick.filter(f => !rows.has(f.id) || int(rows.get(f.id).fetched) < now() - ttl).map(f => f.id);
        if (start && todo.length && !this.netBusy) this.netFetch(todo);
        const edges = [];
        const seen = new Set();
        for (const [id, r] of rows) {
            if (!ids.has(id)) continue;
            let list = [];
            try { list = JSON.parse(r.data); } catch (e) { list = []; }
            for (const o of list) {
                if (!ids.has(o)) continue;
                const k = id < o ? id + o : o + id;
                if (!seen.has(k)) { seen.add(k); edges.push([id, o]); }
            }
        }
        const deg = {};
        edges.forEach(([a, b]) => { deg[a] = (deg[a] || 0) + 1; deg[b] = (deg[b] || 0) + 1; });
        return { nodes: friends.filter(f => deg[f.id] || rows.has(f.id)).map(f => ({ id: f.id, name: f.name, image: f.image, color: f.trust[1], deg: deg[f.id] || 0, fav: f.favorite })),
            edges, done: pick.length - todo.length, total: pick.length, busy: this.netBusy };
    }
    async netFetch(ids) {
        this.netBusy = true;
        try {
            for (const id of ids) {
                const out = [];
                try {
                    for (let off = 0; off < 1000; off += 100) {
                        const page = arr(await this.call('GET', '/users/' + encodeURIComponent(id) + '/mutuals/friends', { n: 100, offset: off }));
                        out.push(...page.map(x => str(x.id)).filter(Boolean));
                        if (page.length < 100) break;
                        await sleep(800);
                    }
                    this.db.prepare('INSERT OR REPLACE INTO mutual_cache (vrc_id, data, fetched) VALUES (?, ?, ?)').run(id, JSON.stringify(out), now());
                } catch (e) {
                    if (e instanceof VrcAuthError) break;
                    if (/429/.test(e.message)) { await sleep(20000); continue; }
                    this.db.prepare('INSERT OR REPLACE INTO mutual_cache (vrc_id, data, fetched) VALUES (?, ?, ?)').run(id, '[]', now());
                }
                await sleep(1500);
            }
        } finally { this.netBusy = false; }
    }
    activity(day) {
        const d = /^\d{4}-\d{2}-\d{2}$/.test(str(day)) ? new Date(day + 'T00:00:00') : new Date(new Date().toDateString());
        const from = Math.floor(d.getTime() / 1000), to = from + 86400;
        const rows = this.db.prepare(`SELECT ts, kind, world_id, instance, world_name, player_id, player_name FROM game_log WHERE user_id = ? AND ts >= ? AND ts < ?
                                      AND kind IN ('world_join','world_leave','vrc_quit','player_join','player_leave') ORDER BY ts, id`).all(UID, from - 43200, to);
        const segs = [];
        let cur = null;
        const close = t => { if (cur) { cur.end = t; if (cur.end > from) segs.push(cur); cur = null; } };
        for (const r of rows) {
            if (r.kind === 'world_join') { close(r.ts); cur = { start: r.ts, end: 0, world_id: r.world_id, world_name: r.world_name, instance: r.instance, players: 1, max: 1, people: new Set() }; }
            else if (r.kind === 'world_leave' || r.kind === 'vrc_quit') close(r.ts);
            else if (cur && r.kind === 'player_join') { cur.players++; cur.max = Math.max(cur.max, cur.players); if (r.player_name) cur.people.add(r.player_name); }
            else if (cur && r.kind === 'player_leave') cur.players = Math.max(1, cur.players - 1);
        }
        if (cur) { cur.end = Math.min(now(), to); if (cur.end > from) segs.push(cur); }
        const days = this.db.prepare("SELECT DISTINCT date(ts, 'unixepoch', 'localtime') AS d FROM game_log WHERE user_id = ? AND kind = 'world_join' ORDER BY d DESC LIMIT 60").column(UID);
        return { day: d.toISOString().slice(0, 10), from, to, days,
            segments: segs.map(s => ({ start: Math.max(s.start, from), end: Math.min(s.end, to), world_id: s.world_id, world_name: s.world_name, access: U.location(s.world_id + ':' + s.instance).access,
                max: s.max, people: [...s.people].slice(0, 40), location: s.world_id ? s.world_id + ':' + s.instance : '' })) };
    }

    // ======================= Screenshot-Manager =======================
    screenshots(q) {
        const base = this.photoDir();
        const limit = int(this.settings.get('media.manager_limit')) || 300;
        const files = [];
        const walk = (dir, depth) => {
            let ents = [];
            try { ents = fs.readdirSync(dir, { withFileTypes: true }); } catch (e) { return; }
            for (const e of ents) {
                const f = path.join(dir, e.name);
                if (e.isDirectory() && depth < 2) walk(f, depth + 1);
                else if (e.isFile() && /\.(png|jpe?g)$/i.test(e.name)) { try { files.push({ f, m: fs.statSync(f).mtimeMs, size: fs.statSync(f).size }); } catch (x) { /* weg */ } }
            }
        };
        walk(base, 0);
        files.sort((a, b) => b.m - a.m);
        const needle = str(q).trim().toLowerCase();
        const out = [];
        for (const x of files) {
            if (out.length >= limit) break;
            const rel = path.relative(base, x.f);
            const meta = needle || out.length < 60 ? this.shotMeta(x.f) : null;
            if (needle && !(rel.toLowerCase().includes(needle) || JSON.stringify(meta || {}).toLowerCase().includes(needle))) continue;
            out.push({ rel, name: path.basename(x.f), ts: Math.floor(x.m / 1000), size: x.size, world: meta ? meta.world : '', players: meta ? meta.players.length : 0, has_meta: !!(meta && meta.source) });
        }
        return { dir: base, exists: fs.existsSync(base), total: files.length, shots: out };
    }
    shotPath(rel) {
        const base = path.resolve(this.photoDir());
        const f = path.resolve(base, str(rel));
        if (!f.startsWith(base + path.sep) || !/\.(png|jpe?g)$/i.test(f) || !fs.existsSync(f)) throw new UserError('Bild nicht gefunden.');
        return f;
    }
    shotMeta(file) {
        if (!/\.png$/i.test(file)) return { world: '', world_id: '', players: [], author: '', source: '', raw: {} };
        let buf;
        try {
            const fd = fs.openSync(file, 'r');
            const size = fs.fstatSync(fd).size;
            buf = Buffer.alloc(size);
            fs.readSync(fd, buf, 0, size, 0);
            fs.closeSync(fd);
        } catch (e) { return null; }
        const t = pngText(buf);
        if (!t) return null;
        const out = { width: t.width, height: t.height, world: '', world_id: '', instance: '', players: [], author: '', source: '', created: '', raw: {} };
        const desc = t.text.Description;
        if (desc) {
            let j = null;
            try { j = JSON.parse(desc.replace(/^lfs\|\d+\|/, '')); } catch (e) { j = null; }
            if (j && typeof j === 'object') {
                out.source = str(j.application) || 'Description';
                out.world = str(j.world && j.world.name); out.world_id = str(j.world && j.world.id); out.instance = str(j.world && j.world.instanceId);
                out.author = str(j.author && j.author.displayName); out.created = str(j.created_at);
                out.players = arr(j.players).map(p => ({ id: str(p.id), name: str(p.displayName) })).slice(0, 80);
            } else out.raw.Description = cut(desc, 400);
        }
        const xmp = t.text['XML:com.adobe.xmp'];
        if (xmp) {
            const pick = re => (re.exec(xmp) || [])[1] || '';
            out.world = out.world || pick(/<vrc:WorldDisplayName>([^<]*)</);
            out.world_id = out.world_id || pick(/<vrc:WorldID>([^<]*)</);
            out.author = out.author || pick(/<xmp:Author>([^<]*)</) || pick(/<vrc:AuthorName>([^<]*)</);
            out.created = out.created || pick(/<xmp:CreateDate>([^<]*)</);
            if (!out.source) out.source = 'VRChat';
        }
        for (const [k, v] of Object.entries(t.text)) if (!['Description', 'XML:com.adobe.xmp'].includes(k)) out.raw[k] = cut(v, 200);
        return out;
    }
    async shotImage(rel, thumb) {
        const f = this.shotPath(rel);
        if (thumb && this.host.thumbnail) { const b = await this.host.thumbnail(f).catch(() => null); if (b) return { type: 'image/jpeg', body: b }; }
        return { type: /\.png$/i.test(f) ? 'image/png' : 'image/jpeg', body: fs.readFileSync(f) };
    }

    // ======================= VRChat config.json =======================
    configRead() {
        const f = path.join(this.vrchatDir(), 'config.json');
        let data = {}, exists = false;
        try { data = JSON.parse(fs.readFileSync(f, 'utf8')); exists = true; } catch (e) { data = {}; }
        return { file: f, exists, dir_exists: fs.existsSync(this.vrchatDir()), data, fields: CONFIG_FIELDS };
    }
    configSave(input) {
        const cur = this.configRead();
        if (!cur.dir_exists) throw new UserError('VRChat-Datenordner nicht gefunden: ' + this.vrchatDir());
        const d = Object.assign({}, cur.data);
        for (const [k, type] of CONFIG_FIELDS) {
            if (!(k in input)) continue;
            const v = str(input[k]).trim();
            if (type === 'bool') { if (['1', 'true', 'on'].includes(v)) d[k] = true; else delete d[k]; }
            else if (v === '') delete d[k];
            else if (type === 'number') { const n = Number(v.replace(',', '.')); if (!isFinite(n) || n < 0) throw new UserError(k + ': keine gültige Zahl.'); d[k] = n; }
            else d[k] = v;
        }
        if (d.cache_size !== undefined && d.cache_size < 30) throw new UserError('Die Cache-Größe muss mindestens 30 GB sein.');
        if (cur.exists) fs.copyFileSync(cur.file, cur.file + '.bak');
        fs.writeFileSync(cur.file, JSON.stringify(d, null, 2));
        return { msg: 'config.json gespeichert (Sicherung: config.json.bak). Wirkt beim nächsten VRChat-Start.', config: this.configRead() };
    }

    // ======================= Startoptionen =======================
    launchCfg() { try { return Object.assign({}, LAUNCH_DEF, JSON.parse(this.db.setting('launch_cfg', '{}'))); } catch (e) { return Object.assign({}, LAUNCH_DEF); } }
    launchSave(input) {
        const c = this.launchCfg();
        const b = k => ['1', 'true', 'on'].includes(str(input[k]));
        Object.assign(c, { mode: input.mode === 'exe' ? 'exe' : 'steam', exe: cut(str(input.exe).trim(), 400), no_vr: b('no_vr'), profile: Math.max(0, Math.min(99, int(input.profile))),
            fps: Math.max(0, Math.min(1000, int(input.fps))), width: Math.max(0, Math.min(16384, int(input.width))), height: Math.max(0, Math.min(16384, int(input.height))),
            fullscreen: ['', '0', '1'].includes(str(input.fullscreen)) ? str(input.fullscreen) : '', debug_gui: b('debug_gui'), sdk_logs: b('sdk_logs'), watch_worlds: b('watch_worlds'),
            watch_avatars: b('watch_avatars'), osc: /^(\d{1,5}:[\w.-]+:\d{1,5})?$/.test(str(input.osc).trim()) ? str(input.osc).trim() : '', extra: cut(str(input.extra).replace(/[\r\n"`$&|<>^;%]/g, ' ').trim(), 300) });
        this.db.setSetting('launch_cfg', JSON.stringify(c));
        return c;
    }
    launchArgs(c = this.launchCfg()) {
        const a = [];
        if (c.no_vr) a.push('--no-vr');
        if (c.profile) a.push('--profile=' + c.profile);
        if (c.fps) a.push('--fps=' + c.fps);
        if (c.width && c.height) a.push('-screen-width', String(c.width), '-screen-height', String(c.height));
        if (c.fullscreen !== '') a.push('-screen-fullscreen', c.fullscreen);
        if (c.debug_gui) a.push('--enable-debug-gui');
        if (c.sdk_logs) a.push('--enable-sdk-log-levels');
        if (c.watch_worlds) a.push('--watch-worlds');
        if (c.watch_avatars) a.push('--watch-avatars');
        if (c.osc) a.push('--osc=' + c.osc);
        if (c.extra) a.push(...c.extra.split(/\s+/).filter(Boolean));
        return a;
    }
    async launch(location) {
        const c = this.launchCfg();
        const args = this.launchArgs(c);
        if (location) { if (!/^wrld_[0-9a-f-]{36}:[\w~()\-.:]+$/i.test(location)) throw new UserError('Ungültige Instanz.'); args.push('vrchat://launch?ref=vrc-nexus&id=' + location); }
        if (c.mode === 'exe' && c.exe) {
            if (!/VRChat\.exe$/i.test(c.exe) || !fs.existsSync(c.exe)) throw new UserError('VRChat.exe nicht gefunden: ' + c.exe);
            if (this.host.spawn) this.host.spawn(c.exe, args);
            return { msg: 'VRChat wird gestartet …', args: args.join(' ') };
        }
        const url = 'steam://run/438100//' + encodeURIComponent(args.join(' ')) + '/';
        if (this.host.openExternal) await this.host.openExternal(url);
        return { msg: 'VRChat wird über Steam gestartet …', args: args.join(' '), url };
    }

    // ======================= Registry-Sicherung =======================
    regDir() { const d = path.join(this.userData, 'registry'); fs.mkdirSync(d, { recursive: true }); return d; }
    regList() {
        const d = this.regDir();
        return fs.readdirSync(d).filter(f => /\.reg$/i.test(f)).map(f => { const s = fs.statSync(path.join(d, f)); return { name: f, size: s.size, ts: Math.floor(s.mtimeMs / 1000) }; }).sort((a, b) => b.ts - a.ts);
    }
    reg(args) {
        if (process.platform !== 'win32' && !process.env.NEXUS_REG_CMD) return Promise.reject(new UserError('Die Registry gibt es nur unter Windows.'));
        return new Promise((resolve, reject) => execFile(process.env.NEXUS_REG_CMD || 'reg', args, { windowsHide: true, timeout: 30000 }, (e, out, err) => (e ? reject(new UserError('reg: ' + (str(err).trim() || e.message))) : resolve(out))));
    }
    async regBackup(label) {
        const name = 'vrchat_' + new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19) + (label ? '_' + str(label).replace(/[^\w-]+/g, '_').slice(0, 30) : '') + '.reg';
        await this.reg(['export', 'HKCU\\Software\\VRChat\\VRChat', path.join(this.regDir(), name), '/y']);
        return { msg: 'Sicherung erstellt: ' + name, list: this.regList() };
    }
    regFile(name) {
        if (!/^[\w.-]+\.reg$/i.test(str(name))) throw new UserError('Ungültiger Dateiname.');
        const f = path.join(this.regDir(), name);
        if (!fs.existsSync(f)) throw new UserError('Sicherung nicht gefunden.');
        return f;
    }
    async regRestore(name) {
        if (this.app.game.state.running) throw new UserError('Bitte erst VRChat beenden – sonst überschreibt VRChat die Werte wieder.');
        const f = this.regFile(name);
        await this.regBackup('vor-wiederherstellung');
        await this.reg(['import', f]);
        return { msg: 'Wiederhergestellt: ' + name + ' (vorher automatisch gesichert).', list: this.regList() };
    }
    regDelete(name) { fs.unlinkSync(this.regFile(name)); return { list: this.regList() }; }

    // ======================= Gruppen =======================
    groupSlim(g) { return { id: str(g.id || g.groupId), name: str(g.name), short: str(g.shortCode) + (g.discriminator ? '.' + g.discriminator : ''), icon: str(g.iconUrl), banner: str(g.bannerUrl), members: int(g.memberCount), privacy: str(g.privacy) }; }
    async groupInvites() { const acc = this.acc(); return { groups: arr(await this.call('GET', '/users/' + encodeURIComponent(acc.vrc_id) + '/groups/invited')).map(g => this.groupSlim(g)) }; }
    async groupBlocked() { const acc = this.acc(); return { groups: arr(await this.call('GET', '/users/' + encodeURIComponent(acc.vrc_id) + '/groups/userblocked')).map(g => this.groupSlim(g)) }; }
    async groupAct(kind, id) {
        U.valid(id, 'grp');
        const acc = this.acc(), g = encodeURIComponent(id), me = encodeURIComponent(acc.vrc_id);
        if (kind === 'accept') { await this.call('POST', '/groups/' + g + '/join', {}, {}); return { msg: 'Gruppe beigetreten.' }; }
        if (kind === 'decline') { await this.call('DELETE', '/groups/' + g + '/invites/' + me); return { msg: 'Einladung abgelehnt.' }; }
        if (kind === 'block') { await this.call('POST', '/groups/' + g + '/block'); return { msg: 'Gruppe blockiert – sie kann dich nicht mehr einladen.' }; }
        if (kind === 'unblock') { await this.call('DELETE', '/groups/' + g + '/members/' + me); return { msg: 'Blockierung aufgehoben.' }; }
        throw new UserError('Unbekannte Aktion.');
    }

    // ======================= Einladungsnachrichten & Meldungen =======================
    async messages(type) {
        if (!MSG_TYPES[type]) throw new UserError('Unbekannte Nachrichtenart.');
        const acc = this.acc();
        const list = arr(await this.call('GET', '/message/' + encodeURIComponent(acc.vrc_id) + '/' + type));
        return { type, types: MSG_TYPES, messages: list.map(m => ({ slot: int(m.slot), message: str(m.message), cooldown: int(m.remainingCooldownMinutes), can: m.canBeUpdated !== false, updated: str(m.updatedAt) })).sort((a, b) => a.slot - b.slot) };
    }
    async messageSave(type, slot, message) {
        if (!MSG_TYPES[type]) throw new UserError('Unbekannte Nachrichtenart.');
        slot = int(slot);
        if (slot < 0 || slot > 11) throw new UserError('Platz 0–11.');
        message = str(message).trim();
        if (!message) throw new UserError('Nachricht fehlt.');
        const acc = this.acc();
        await this.call('PUT', '/message/' + encodeURIComponent(acc.vrc_id) + '/' + type + '/' + slot, {}, { message: cut(message, 64) });
        return Object.assign(await this.messages(type), { msg: 'Gespeichert – danach 60 Minuten Pause für diesen Platz (Vorgabe von VRChat).' });
    }
    async reports() {
        const acc = this.acc();
        return { reports: arr(await this.call('GET', '/moderationReports', { reportingUserId: acc.vrc_id, n: 100 })).map(r => ({ id: str(r.id), type: str(r.type), category: str(r.category), reason: str(r.reason),
            target: str(r.contentName), target_id: str(r.contentId), image: str(r.contentThumbnailImageUrl), created: str(r.created), status: str(r.status || r.reportStatus) })) };
    }
    async reportDelete(id) {
        if (!/^[\w-]{3,64}$/.test(str(id))) throw new UserError('Ungültige Meldung.');
        await this.call('DELETE', '/moderationReports/' + encodeURIComponent(id));
        return Object.assign(await this.reports(), { msg: 'Meldung gelöscht.' });
    }

    // ======================= Exporte =======================
    discordNames() {
        const rows = [];
        const re = /(?:discord(?:\.gg|app\.com\/users|\.com\/users)?[\s:\-=@]*|dc[\s:]+)([a-z0-9_.]{2,32}(?:#\d{4})?)/i;
        for (const f of this.app.vrc.friendList()) {
            const u = this.cached(f.id) || {};
            const bio = str(u.bio || this.db.prepare('SELECT bio FROM friends WHERE user_id = ? AND vrc_id = ?').value(UID, f.id));
            const links = arr(u.bioLinks).map(str);
            let found = '';
            const link = links.find(l => /discord\.(com\/users|gg)\//i.test(l));
            if (link) found = link;
            else { const m = re.exec(bio); if (m && !/^(server|link|https?)$/i.test(m[1])) found = m[1]; }
            if (found) rows.push({ id: f.id, name: f.name, discord: found });
        }
        return rows;
    }
    csv(kind, rows) {
        if (kind === 'discord') return '\ufeffVRChat-Name;VRChat-ID;Discord\n' + rows.map(r => [r.name, r.id, r.discord].map(csvQ).join(';')).join('\n');
        if (kind === 'avatars') return '\ufeffName;ID;Status;Plattformen;Erstellt;Aktualisiert\n' + rows.map(a => [a.name, a.id, a.releaseStatus, [...new Set(arr(a.unityPackages).map(p => U.VRC_PLATFORM[p.platform] || p.platform))].join('+'), a.created_at, a.updated_at].map(csvQ).join(';')).join('\n');
        if (kind === 'memos') return '\ufeffVRChat-ID;Name;Memo;Favorit;Listen\n' + rows.map(r => [r.vrc_id, r.name, r.note, r.favorite ? 'ja' : '', r.tags].map(csvQ).join(';')).join('\n');
        return '';
    }
    async ownAvatars() {
        const out = [];
        for (let off = 0; off < 2000; off += 100) {
            const page = arr(await this.call('GET', '/avatars', { user: 'me', releaseStatus: 'all', n: 100, offset: off, sort: 'updated', order: 'descending' }));
            out.push(...page);
            if (page.length < 100) break;
            await sleep(500);
        }
        return out;
    }
    memos() {
        return this.db.prepare(`SELECT n.vrc_id, COALESCE(f.display_name, '') AS name, n.note, n.favorite, n.tags FROM notes n LEFT JOIN friends f ON f.vrc_id = n.vrc_id AND f.user_id = n.user_id
                                WHERE n.user_id = ? AND n.note <> '' ORDER BY name COLLATE NOCASE`).all(UID);
    }
    async memosToVrc(overwrite) {
        const list = this.memos().filter(m => /^usr_[0-9a-f-]{36}$/i.test(m.vrc_id));
        let done = 0, skipped = 0;
        for (const m of list.slice(0, 500)) {
            const u = this.cached(m.vrc_id);
            if (!overwrite && u && str(u.note)) { skipped++; continue; }
            await this.call('POST', '/userNotes', {}, { targetUserId: m.vrc_id, note: cut(m.note, 256) });
            done++;
            await sleep(1200);
        }
        return { msg: done + ' Memos als VRChat-Notiz gespeichert' + (skipped ? ', ' + skipped + ' übersprungen (hatten schon eine Notiz)' : '') + '.' };
    }

    // ======================= Profil-Reiter & frühere Instanzen =======================
    async userJson(id) { U.valid(id, 'usr'); return { user: await this.call('GET', '/users/' + encodeURIComponent(id)) }; }
    worldSlim(w) { return { id: str(w.id), name: str(w.name), thumb: str(w.thumbnailImageUrl || w.imageUrl), author: str(w.authorName), occupants: int(w.occupants), favorites: int(w.favorites), capacity: int(w.capacity), status: str(w.releaseStatus) }; }
    async userTab(id, tab) {
        U.valid(id, 'usr');
        const e = encodeURIComponent(id);
        const quiet = async fn => { try { return await fn(); } catch (x) { if (x instanceof VrcAuthError) throw x; return { error: x.message }; } };
        switch (tab) {
            case 'mutuals': return quiet(async () => {
                const out = [];
                for (let off = 0; off < 500; off += 100) { const p = arr(await this.call('GET', '/users/' + e + '/mutuals/friends', { n: 100, offset: off })); out.push(...p); if (p.length < 100) break; }
                const fr = this.friendMap();
                return { users: out.map(u => { const f = fr.get(u.id); return { id: str(u.id), name: str(u.displayName), image: f ? f.image : U.userImage(u), status: str(u.status), status_desc: str(u.statusDescription), state: f ? f.state : '' }; }) };
            });
            case 'worlds': return quiet(async () => ({ worlds: arr(await this.call('GET', '/worlds', { userId: id, releaseStatus: 'public', n: 100, sort: 'updated', order: 'descending' })).map(w => this.worldSlim(w)) }));
            case 'favworlds': return quiet(async () => ({ worlds: arr(await this.call('GET', '/worlds/favorites', { userId: id, n: 100 })).map(w => this.worldSlim(w)) }));
            case 'avatars': return quiet(async () => ({ avatars: arr(await this.call('GET', '/avatars', { userId: id, releaseStatus: 'public', n: 100, sort: 'updated', order: 'descending' }))
                .map(a => ({ id: str(a.id), name: str(a.name), thumb: str(a.thumbnailImageUrl || a.imageUrl), author: str(a.authorName), status: str(a.releaseStatus) })) }));
            case 'instances': return { instances: this.prevInstances("player_id = ?", [id]) };
            case 'activity': {
                const meets = this.db.prepare("SELECT COUNT(*) AS n, MIN(ts) AS first, MAX(ts) AS last, COUNT(DISTINCT world_id) AS worlds FROM game_log WHERE user_id = ? AND kind = 'player_join' AND player_id = ?").get(UID, id) || {};
                const byDay = this.db.prepare("SELECT date(ts, 'unixepoch', 'localtime') AS d, COUNT(*) AS n FROM feed WHERE user_id = ? AND vrc_id = ? AND ts > ? GROUP BY d ORDER BY d").all(UID, id, now() - 60 * 86400);
                const types = this.db.prepare('SELECT type, COUNT(*) AS n FROM feed WHERE user_id = ? AND vrc_id = ? GROUP BY type ORDER BY n DESC').all(UID, id);
                return { meets: { count: int(meets.n), first: int(meets.first), last: int(meets.last), worlds: int(meets.worlds) }, days: byDay, types, stats: this.app.vrc.friendStats(id) };
            }
        }
        throw new UserError('Unbekannter Reiter.');
    }
    /** Frühere Instanzen aus dem Spiel-Log (wo man war, optional gefiltert) */
    prevInstances(where, args) {
        const rows = this.db.prepare(`SELECT world_id, instance, MAX(world_name) AS world_name, MIN(ts) AS first, MAX(ts) AS last, COUNT(*) AS n FROM game_log
                                      WHERE user_id = ? AND world_id <> '' AND ${where} GROUP BY world_id, instance ORDER BY last DESC LIMIT 100`).all(UID, ...args);
        return rows.map(r => ({ world_id: r.world_id, world_name: r.world_name, location: r.world_id + ':' + r.instance, access: U.location(r.world_id + ':' + r.instance).access,
            region: U.location(r.world_id + ':' + r.instance).region, first: int(r.first), last: int(r.last), count: int(r.n) }));
    }
    groupInstances(id) { U.valid(id, 'grp'); return { instances: this.prevInstances("kind = 'world_join' AND instance LIKE ?", ['%group(' + id + ')%']) }; }

    // ======================= Stream Deck / lokale Schnittstelle =======================
    apiState() {
        const st = this.app.game.state, acc = this.acc();
        let fr = [];
        try { fr = this.app.vrc.friendList(); } catch (e) { fr = []; }
        const c = this.app.game.cfg();
        return { ok: true, version: VERSION, connected: !!acc, me: acc ? { name: acc.display_name, status: str(acc.me && acc.me.status), status_desc: str(acc.me && acc.me.statusDescription) } : null,
            friends: { in_game: fr.filter(f => f.state === 'online').length, online: fr.filter(f => f.state === 'active').length, total: fr.length },
            game: { running: st.running, world: st.worldName, world_id: st.worldId, instance: st.instance, players: st.players.size, since: st.since },
            osc: { active: this.app.game.osc.active, avatar: this.app.game.osc.avatarId, params: Object.fromEntries(Object.entries(this.app.game.osc.params).slice(0, 64)) },
            rotator: !!c.rotator_enabled, presets: this.app.game.presets().map(p => ({ id: p.id, label: p.label, kind: p.kind })),
            notifs: (() => { try { return this.app.x.notifList({ view: 'open' }).unseen; } catch (e) { return 0; } })(),
            cam: { mode: (this.app.game.osc.cam || {}).Mode ?? null, zoom: (this.app.game.osc.cam || {}).Zoom ?? null, burst: !!this.app.cam.burstRun },
            macros: this.app.macros.list().map(m => ({ id: m.id, name: m.name })), running_macros: [...this.app.macros.running.keys()],
            overlay: this.host.overlayState ? this.host.overlayState() : null, speech: this.app.speech ? this.app.speech.status() : null,
            friend_list: fr.slice(0, 400).map(f => ({ id: f.id, name: f.name, state: f.state, loc: f.loc.kind === 'world' ? f.loc.location : '', world: f.loc.world_name || '' })) };
    }
    async apiAction(action, d) {
        const g = this.app.game;
        switch (action) {
            case 'status': {
                const st = str(d.status);
                if (!['join me', 'active', 'ask me', 'busy'].includes(st)) throw new UserError('Ungültiger Status.');
                await this.setStatus(st, str(d.desc), 'manual');
                return { ok: true, msg: 'Status: ' + st };
            }
            case 'chatbox': {
                let text = str(d.text);
                if (d.translate) text = (await this.app.tr.chat(text, str(d.target))).text;
                await g.oscSend('chatbox', { text, sound: d.sound ? '1' : '', split: d.split ? '1' : '' });
                return { ok: true, text };
            }
            case 'typing': await g.oscSend('typing', { on: d.on ? '1' : '' }); return { ok: true };
            case 'param': await g.oscSend('param', { name: d.name, type: d.type || 'bool', value: d.value }); return { ok: true };
            case 'toggle': {
                const name = str(d.name);
                const cur = g.osc.params[name];
                const next = !(cur === true || cur === 1);
                await g.oscSend('param', { name, type: 'bool', value: next ? '1' : '0' });
                g.osc.params[name] = next;       // VRChat meldet den Wert normalerweise selbst zurück
                return { ok: true, value: next };
            }
            case 'input': await g.oscSend('input', d.value === undefined ? { name: d.name, value: 1, pulse: '1' } : { name: d.name, value: d.value ? 1 : 0, pulse: '' }); return { ok: true };
            case 'preset': {
                const p = this.db.prepare('SELECT * FROM osc_presets WHERE id = ? AND user_id = ?').get(int(d.id), UID);
                if (!p) throw new UserError('Vorlage nicht gefunden.');
                await g.oscSend(p.kind, JSON.parse(p.payload));
                return { ok: true, msg: p.label };
            }
            case 'rotator': {
                const c = g.cfg();
                const on = d.on === undefined ? !c.rotator_enabled : !!d.on;
                g.saveCfg({ rotator_enabled: on ? '1' : '0' });
                return { ok: true, value: on };
            }
            case 'launch': return Object.assign({ ok: true }, await this.launch(''));
            case 'rejoin': {
                const st = g.state;
                if (!st.worldId) throw new UserError('Du bist gerade in keiner Instanz.');
                if (this.host.openExternal) await this.host.openExternal('vrchat://launch?ref=vrc-nexus&id=' + st.worldId + ':' + st.instance);
                return { ok: true };
            }
            case 'camera': {
                if (str(d.cmd) === 'burst') return { ok: true, msg: this.app.cam.burst(d.count, d.seconds, d.delay) };
                if (str(d.cmd) === 'preset') return { ok: true, msg: await this.app.cam.loadPreset(d.preset) };
                if (str(d.cmd) === 'step') { const cur = Number((g.osc.cam || {})[d.name]); return { ok: true, msg: await this.app.cam.cmd({ cmd: str(d.name), value: (Number.isFinite(cur) ? cur : Number(d.base) || 0) + (Number(d.step) || 0) }) }; }
                return { ok: true, msg: await this.app.cam.cmd({ cmd: str(d.cmd), value: d.value }) };
            }
            case 'macro': return Object.assign({ ok: true }, await this.app.macros.run(str(d.id || d.script)));
            case 'macro_stop': this.app.macros.stop(); return { ok: true };
            case 'avatar': {
                const id = str(d.id).trim();
                if (!/^avtr_[0-9a-f-]{36}$/i.test(id)) throw new UserError('Ungültige Avatar-ID.');
                await this.call('PUT', '/avatars/' + id + '/select');
                return { ok: true, msg: 'Avatar gewechselt.' };
            }
            case 'param_step': {
                const name = str(d.name), cur = Number(g.osc.params[name]) || 0;
                const type = str(d.type || 'float');
                let v = cur + (Number(d.step) || 0);
                v = type === 'int' ? Math.max(0, Math.min(255, Math.round(v))) : Math.max(-1, Math.min(1, Math.round(v * 1000) / 1000));
                await g.oscSend('param', { name, type, value: String(v) });
                g.osc.params[name] = v;
                return { ok: true, value: v };
            }
            case 'friend': {
                const f = (this.app.vrc.friendList() || []).find(x => x.id === str(d.id));
                if (!f) throw new UserError('Nicht in deiner Freundesliste.');
                if (str(d.op) === 'invite') {
                    const st = g.state;
                    if (!st.worldId) throw new UserError('Du bist gerade in keiner Instanz.');
                    await this.call('POST', '/invite/' + f.id, {}, { instanceId: st.worldId + ':' + st.instance });
                    return { ok: true, msg: f.name + ' eingeladen.' };
                }
                if (f.loc.kind !== 'world') throw new UserError(f.name + ' ist in keiner offenen Instanz.');
                const loc = f.loc.location;
                if (str(d.op) === 'invite_me') { await this.call('POST', '/invite/myself/to/' + loc); return { ok: true, msg: 'Selbst-Einladung geschickt.' }; }
                if (this.host.openExternal) await this.host.openExternal('vrchat://launch?ref=vrc-nexus&id=' + loc);
                return { ok: true, msg: 'Trete ' + f.name + ' bei.' };
            }
            case 'overlay': if (!this.host.overlayToggle) throw new UserError('Das SteamVR-Overlay gibt es nur in der Windows-App.'); return Object.assign({ ok: true }, await this.host.overlayToggle(d.on));
            case 'speech': if (!this.app.speech) throw new UserError('Spracheingabe ist nicht verfügbar.'); return Object.assign({ ok: true }, await this.app.speech.ptt(str(d.op || 'toggle')));
            case 'open': if (this.host.showTab) this.host.showTab(/^[a-z]{2,12}$/.test(str(d.tab)) ? str(d.tab) : ''); return { ok: true };
            case 'invite_self': {
                const loc = str(d.location);
                if (!/^wrld_[0-9a-f-]{36}:[\w~()\-.:]+$/i.test(loc)) throw new UserError('Ungültige Instanz.');
                await this.call('POST', '/invite/myself/to/' + loc);
                return { ok: true };
            }
        }
        throw new UserError('Unbekannte Aktion: ' + action);
    }

    // ======================= Übersicht für die Werkzeug-Seite =======================
    async overview() {
        const c = this.app.game.cfg();
        return { paths: { photos: this.photoDir(), vrchat: this.vrchatDir(), crashes: this.crashDir(), nexus: this.userData, logs: this.app.game.logDir(), steam: await this.steamDir() },
            auto: this.autoCfg(), launch: this.launchCfg(), launch_args: this.launchArgs().join(' '), windows: process.platform === 'win32',
            api: this.api.status(), rpc: this.settings.get('int.rpc') ? this.rpc.status() : 'aus', rotator: !!c.rotator_enabled, log: this.log.slice(0, 15) };
    }
}

module.exports = { Tools, pngText, pngSetText, crc32, LANG, ownerOf };
