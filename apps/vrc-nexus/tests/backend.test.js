'use strict';
/* Tests ohne Netzwerk und ohne Electron:  npm test */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const dgram = require('dgram');
const U = require('../backend/util');
const { openDatabase } = require('../backend/db');
const { LogParser, LogWatcher, oscEncode, oscDecode, GameLog } = require('../backend/gamelog');
const { formToObject } = require('../backend/app');

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('Ort zerlegen', () => {
    const l = U.location('wrld_1:123~hidden(usr_x)~region(eu)');
    assert.strictEqual(l.access, 'Friends+');
    assert.strictEqual(l.region, 'EU');
    assert.strictEqual(l.name, '123');
    assert.strictEqual(U.location('wrld_1:9~private(usr_x)~canRequestInvite').access, 'Invite+');
    assert.strictEqual(U.location('wrld_1:9~group(grp_a)~groupAccessType(plus)').access, 'Group+');
    assert.strictEqual(U.location('offline').kind, 'offline');
    assert.strictEqual(U.location('traveling:wrld_2:1').kind, 'traveling');
});

test('Vertrauensstufe & Text', () => {
    assert.strictEqual(U.trust(['system_trust_basic', 'system_trust_known'])[0], 'User');
    assert.strictEqual(U.trust(['admin_moderator'])[0], 'VRChat-Team');
    const h = U.formatText('<b>hi</b>\nhttps://vrchat.com/x.');
    assert.ok(h.includes('&lt;b&gt;') && h.includes('<br>') && h.includes('href="https://vrchat.com/x"'));
    assert.strictEqual(U.cut('🦊🦊🦊', 2), '🦊🦊');
    assert.strictEqual(U.imgUrl('https://api.vrchat.cloud/api/1/file/file_00000001-1111-2222-3333-444444444444/3/file'), 'nexus.php?img=file_00000001-1111-2222-3333-444444444444&v=3');
    assert.throws(() => U.valid('evil', 'usr'));
});

test('Formular mit Listen', () => {
    assert.deepStrictEqual(formToObject([['act', 'x'], ['ids[]', 'a'], ['ids[]', 'b']]), { act: 'x', ids: ['a', 'b'] });
});

test('Datenbank: Schema, Einstellungen, Speichern', async () => {
    const f = path.join(os.tmpdir(), 'nexus-test-' + process.pid + '.sqlite');
    let db = await openDatabase(f);
    db.setSetting('x', 'y');
    db.prepare('INSERT INTO worlds (world_id, name) VALUES (?, ?)').run('wrld_1', 'Testwelt 🦊');
    db.close();
    db = await openDatabase(f);
    assert.strictEqual(db.setting('x'), 'y');
    assert.strictEqual(db.prepare('SELECT name FROM worlds WHERE world_id = ?').value('wrld_1'), 'Testwelt 🦊');
    assert.strictEqual(db.prepare('PRAGMA user_version').value(), 8);
    db.close();
    fs.unlinkSync(f);
});

const SAMPLE = `2026.11.12 20:00:02 Log        -  User Authenticated: Luna Fox (usr_11111111-2222-3333-4444-555555555555)
2026.11.12 20:00:10 Log        -  [Behaviour] Joining wrld_aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee:12345~friends(usr_11111111-2222-3333-4444-555555555555)~region(eu)
2026.11.12 20:00:11 Log        -  [Behaviour] Joining or Creating Room: The Great Pug
2026.11.12 20:00:16 Log        -  [Behaviour] OnPlayerJoined Wolf Byte (usr_99999999-2222-3333-4444-555555555555)
2026.11.12 20:00:17 Log        -  [Behaviour] OnPlayerJoined OldName
2026.11.12 20:01:00 Log        -  [Behaviour] Switching Luna Fox to avatar Novabeast Deluxe
2026.11.12 20:02:00 Log        -  [Video Playback] Attempting to resolve URL 'https://www.youtube.com/watch?v=abc'
2026.11.12 20:04:00 Log        -  [API] Received Notification: <Notification from username:Wolf Byte, sender user id:usr_99999999-2222-3333-4444-555555555555 to of type: friendRequest, id: not_1> received
2026.11.12 20:05:01 Log        -  [Behaviour] OnPlayerLeft Wolf Byte (usr_99999999-2222-3333-4444-555555555555)
2026.11.12 20:06:00 Log        -  [Behaviour] OnLeftRoom
2026.11.12 20:07:00 Log        -  VRCApplication: OnApplicationQuit at 1234.5`;

test('Log-Parser', () => {
    const p = new LogParser();
    const ev = [];
    for (const l of SAMPLE.split('\n')) ev.push(...p.feed(l + '\r'));
    assert.deepStrictEqual(ev.map(e => e.kind), ['world_join', 'player_join', 'player_join', 'avatar', 'video', 'notification', 'player_leave', 'world_leave', 'vrc_quit']);
    assert.strictEqual(ev[0].world_name, 'The Great Pug');
    assert.ok(ev[0].instance.startsWith('12345~friends'));
    assert.strictEqual(ev[1].player_id, 'usr_99999999-2222-3333-4444-555555555555');
    assert.strictEqual(ev[3].detail, 'Novabeast Deluxe');
    assert.strictEqual(ev[5].detail, 'Freundschaftsanfrage');
    assert.strictEqual(p.state.running, false);
});

test('Log-Watcher (mitlesen, halbe Zeilen, neue Datei)', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nxlog-'));
    const f1 = path.join(dir, 'output_log_2026-11-12_19-59-00.txt');
    const lines = SAMPLE.split('\n').map(l => l + '\r\n');
    fs.writeFileSync(f1, lines.slice(0, 3).join('') + lines[3].slice(0, 25));
    const w = new LogWatcher(dir, new LogParser());
    assert.deepStrictEqual(w.poll().map(e => e.kind), ['vrc_start', 'world_join']);
    fs.appendFileSync(f1, lines[3].slice(25) + lines[4]);
    assert.deepStrictEqual(w.poll().map(e => e.kind), ['player_join', 'player_join']);
    assert.deepStrictEqual(w.poll(), []);
    const f2 = path.join(dir, 'output_log_2026-11-12_21-00-00.txt');
    fs.writeFileSync(f2, '2026.11.12 21:00:05 Log        -  [Behaviour] Joining or Creating Room: Zweite Welt\n');
    const t = Date.now() / 1000 + 5;
    fs.utimesSync(f2, t, t);
    assert.deepStrictEqual(w.poll().map(e => e.kind), ['vrc_start', 'world_join']);
    assert.strictEqual(w.parser.state.worldName, 'Zweite Welt');
    fs.rmSync(dir, { recursive: true });
});

test('OSC kodieren/dekodieren', () => {
    const pkt = oscEncode('/chatbox/input', 'Hallo 🦊', true, false);
    assert.strictEqual(pkt.length % 4, 0);
    assert.deepStrictEqual(oscDecode(pkt), [['/chatbox/input', ['Hallo 🦊', true, false]]]);
    assert.deepStrictEqual(oscEncode('/avatar/parameters/Ears', { float: 0.5 }), Buffer.concat([Buffer.from('/avatar/parameters/Ears\0,f\0\0'), Buffer.from([0x3f, 0, 0, 0])]));
    assert.deepStrictEqual(oscDecode(oscEncode('/input/Jump', 1)), [['/input/Jump', [1]]]);
    const m1 = oscEncode('/avatar/parameters/A', true), m2 = oscEncode('/avatar/parameters/B', 3);
    const len = n => { const b = Buffer.alloc(4); b.writeInt32BE(n); return b; };
    assert.deepStrictEqual(oscDecode(Buffer.concat([Buffer.from('#bundle\0'), Buffer.alloc(8), len(m1.length), m1, len(m2.length), m2])), [['/avatar/parameters/A', [true]], ['/avatar/parameters/B', [3]]]);
});

test('Spiel-Log: speichern, doppelt erkennen, OSC senden', async () => {
    const db = await openDatabase(null);
    const g = new GameLog(db);
    g.osc.stopListen();
    const p = new LogParser();
    const ev = [];
    for (const l of SAMPLE.split('\n')) ev.push(...p.feed(l));
    assert.strictEqual(g.store(ev), ev.length);
    assert.strictEqual(g.store(ev), 0);
    assert.strictEqual(g.players(3650).length, 2);
    assert.strictEqual(g.visits(3650).length, 1);
    const sock = dgram.createSocket('udp4');
    const got = [];
    sock.on('message', m => got.push(oscDecode(m)[0]));
    await new Promise(r => sock.bind(0, '127.0.0.1', r));
    g.osc.configure('127.0.0.1', sock.address().port);
    await g.oscSend('param', { name: 'Tail', type: 'float', value: '0,25' });
    await g.oscSend('chatbox', { text: 'Hi', sound: '1' });
    await U.sleep(200);
    sock.close();
    assert.deepStrictEqual(got[0], ['/avatar/parameters/Tail', [0.25]]);
    assert.deepStrictEqual(got[1], ['/chatbox/input', ['Hi', true, true]]);
    assert.strictEqual(g.oscRecent()[0].status, 'done');
    assert.throws(() => g.presetSave('', 'chatbox', {}));
    g.presetSave('Hallo', 'chatbox', { text: 'Hi' });
    assert.strictEqual(g.presets().length, 1);
    assert.ok(g.exportCsv().split('\n').length >= ev.length);
});


test('PNG-Text lesen/schreiben (Screenshot-Infos)', () => {
    const { pngText, pngSetText, crc32 } = require('../backend/tools');
    assert.strictEqual(crc32(Buffer.from('IEND')), 0xae426082);
    const zlib = require('zlib');
    const chunk = (t, d) => { const h = Buffer.alloc(8); h.writeUInt32BE(d.length); h.write(t, 4, 'latin1'); const c = Buffer.alloc(4); c.writeUInt32BE(crc32(Buffer.concat([Buffer.from(t, 'latin1'), d]))); return Buffer.concat([h, d, c]); };
    const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(4, 0); ihdr.writeUInt32BE(3, 4); ihdr[8] = 8; ihdr[9] = 2;
    const png = Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(Buffer.alloc(3 * 13))), chunk('IEND', Buffer.alloc(0))]);
    const meta = JSON.stringify({ application: 'VRC Nexus', world: { name: 'Welt 🦊' }, players: [{ displayName: 'A' }] });
    const out = pngSetText(png, 'Description', meta);
    const t = pngText(out);
    assert.strictEqual(t.width, 4);
    assert.strictEqual(JSON.parse(t.text.Description).world.name, 'Welt 🦊');
    const again = pngText(pngSetText(out, 'Description', '{"x":1}'));
    assert.strictEqual(again.text.Description, '{"x":1}');           // ersetzt, nicht doppelt
    assert.strictEqual(pngText(Buffer.from('kein png')), null);
});

test('Einstellungen: Standard, Prüfung, Token', async () => {
    const { Settings } = require('../backend/settings');
    const db = await openDatabase(null);
    const s = new Settings(db);
    assert.strictEqual(s.get('notify.desktop'), true);
    assert.ok(s.get('int.api_token').length >= 20);
    let seen = null;
    s.onChange(c => { seen = c; });
    s.save({ 'ui.zoom': '999', 'ui.accent': 'rot', 'notify.player_join': 'quatsch', 'sys.backup_keep': '3', 'unbekannt': 1, 'int.api_token': 'x', 'notify.quiet_from': '22:00' });
    assert.strictEqual(s.get('ui.zoom'), 150);
    assert.strictEqual(s.get('ui.accent'), '#38d5e6');
    assert.strictEqual(s.get('notify.player_join'), 'friends');
    assert.strictEqual(s.get('sys.backup_keep'), 3);
    assert.notStrictEqual(s.get('int.api_token'), 'x');
    assert.ok(seen.includes('ui.zoom') && seen.includes('notify.quiet_from'));
    assert.throws(() => s.save({ 'int.webhook': 'https://example.org/hook' }));
    assert.throws(() => s.save({ 'int.rpc_client': 'abc' }));
    s.save({ 'adv.user_agent': 'Test/1.0' });
    assert.strictEqual(db.setting('vrc_user_agent'), 'Test/1.0');
    const tok = s.get('int.api_token');
    assert.notStrictEqual(s.newToken(), tok);
});

test('Discord Rich Presence (über nachgebaute Discord-IPC)', async () => {
    if (process.platform === 'win32') return;
    const net = require('net');
    const { DiscordRpc, frame } = require('../backend/discordrpc');
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dipc-'));
    process.env.DISCORD_IPC_DIR = dir;
    const got = [];
    const srv = net.createServer(sock => {
        let buf = Buffer.alloc(0);
        sock.on('data', d => {
            buf = Buffer.concat([buf, d]);
            while (buf.length >= 8 && buf.length >= 8 + buf.readInt32LE(4)) {
                const op = buf.readInt32LE(0), len = buf.readInt32LE(4), msg = JSON.parse(buf.slice(8, 8 + len));
                buf = buf.slice(8 + len);
                got.push([op, msg]);
                if (op === 0) sock.write(frame(1, { cmd: 'DISPATCH', evt: 'READY', data: { v: 1 } }));
            }
        });
    });
    await new Promise(r => srv.listen(path.join(dir, 'discord-ipc-0'), r));
    const rpc = new DiscordRpc();
    await rpc.connect('123456789012345678');
    rpc.setActivity({ details: 'The Great Pug', state: 'Public · 5 Spieler' });
    await U.sleep(300);
    assert.strictEqual(got[0][1].client_id, '123456789012345678');
    assert.strictEqual(got[1][1].cmd, 'SET_ACTIVITY');
    assert.strictEqual(got[1][1].args.activity.details, 'The Great Pug');
    assert.strictEqual(rpc.status(), 'verbunden');
    rpc.close(); srv.close();
    delete process.env.DISCORD_IPC_DIR;
});

test('Werkzeuge: Benachrichtigung an XSOverlay, Startoptionen, Spielerliste', async () => {
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const opened = [];
    const app = new NexusApp({ db, crypt: { encrypt: s => s, decrypt: s => s }, host: { userData: os.tmpdir(), openExternal: u => opened.push(u), notify: () => {} } });
    app.game.osc.stopListen();
    const sock = dgram.createSocket('udp4');
    const got = [];
    sock.on('message', m => got.push(JSON.parse(m)));
    await new Promise(r => sock.bind(0, '127.0.0.1', r));
    app.settings.save({ 'vr.xsoverlay': true, 'vr.only_in_game': false, 'vr.xs_port': sock.address().port, 'notify.desktop': false });
    app.tools.alert('➡ Wolf', 'hat die Instanz betreten');
    await U.sleep(200);
    sock.close();
    assert.strictEqual(got[0].title, '➡ Wolf');
    assert.strictEqual(got[0].sourceApp, 'VRC Nexus');
    const c = app.tools.launchSave({ no_vr: '1', fps: '144', profile: '1', extra: 'x"; rm & y', osc: 'quatsch' });
    assert.strictEqual(c.osc, '');
    assert.ok(!/["&;]/.test(c.extra));
    const r = await app.tools.launch('');
    assert.ok(r.url.startsWith('steam://run/438100//') && opened.length === 1);
    await assert.rejects(app.tools.launch('evil'));
    const p = new LogParser(app.game.state);
    for (const l of SAMPLE.split('\n').slice(0, 5)) p.feed(l);
    const pl = app.tools.playersNow();
    assert.strictEqual(pl.world_name, 'The Great Pug');
    assert.strictEqual(pl.players.length, 2);
    assert.ok(pl.players.some(x => x.name === 'Wolf Byte' && x.id.startsWith('usr_')));
    assert.strictEqual(pl.loc.access, 'Friends');
    assert.deepStrictEqual(app.tools.activity('2000-01-01').segments, []);
    app.tools.stop();
});


// Nachbau von DeepL und Google Translate für die Tests
function trMock() {
    const http = require('http');
    const seen = [];
    const srv = http.createServer((req, res) => {
        let b = '';
        req.on('data', d => { b += d; });
        req.on('end', () => {
            const j = JSON.parse(b || '{}');
            seen.push({ url: req.url, auth: req.headers.authorization || '', body: j });
            res.setHeader('Content-Type', 'application/json');
            if (req.url.startsWith('/v2/translate')) {
                if (req.headers.authorization !== 'DeepL-Auth-Key deepl-test-key-1234567890:fx') { res.statusCode = 403; return res.end('{"message":"Wrong key"}'); }
                return res.end(JSON.stringify({ translations: [{ detected_source_language: 'DE', text: '[' + j.target_lang + '] ' + j.text[0] }] }));
            }
            if (req.url.startsWith('/language/translate/v2')) return res.end(JSON.stringify({ data: { translations: [{ translatedText: '<' + j.target + '> ' + j.q[0], detectedSourceLanguage: 'de' }] } }));
            res.statusCode = 404; res.end('{}');
        });
    });
    return new Promise(r => srv.listen(0, '127.0.0.1', () => r({ srv, seen, base: 'http://127.0.0.1:' + srv.address().port })));
}

test('Übersetzen: DeepL, Google, Chatbox-Modus, Schlüssel verschlüsselt', async () => {
    const m = await trMock();
    process.env.NEXUS_DEEPL_BASE = m.base; process.env.NEXUS_GOOGLE_BASE = m.base;
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const crypt = { encrypt: s => 'enc:' + Buffer.from(s).toString('base64'), decrypt: s => Buffer.from(s.slice(4), 'base64').toString() };
    const app = new NexusApp({ db, crypt, host: { userData: os.tmpdir() } });
    app.game.osc.stopListen();
    await assert.rejects(app.tr.translate('Hallo', 'EN-US'), /Kein Übersetzungs-Schlüssel/);
    app.tr.saveKey('deepl', 'deepl-test-key-1234567890:fx');
    app.tr.saveKey('google', 'google-test-key-ABCDEFGHIJKLMNOP');
    assert.ok(db.setting('tr_keys').startsWith('enc:'));
    assert.ok(!JSON.stringify(app.tr.status()).includes('deepl-test-key-1234567890'));
    const r = await app.tr.translate('Hallo Welt', 'JA');
    assert.deepStrictEqual([r.text, r.provider, r.detected], ['[JA] Hallo Welt', 'deepl', 'DE']);
    const g = await app.tr.translate('Hallo', 'JA', 'google');
    assert.strictEqual(g.text, '<ja> Hallo');
    app.settings.save({ 'tr.chat_mode': 'both', 'tr.chat_target': 'EN-US' });
    assert.strictEqual((await app.tr.chat('Hi du')).text, 'Hi du / [EN-US] Hi du');
    app.settings.save({ 'tr.chat_mode': 'both_nl' });
    assert.strictEqual((await app.tr.chat('Hi du')).text, 'Hi du\n[EN-US] Hi du');
    app.tr.saveKey('deepl', 'deepl-falscher-schluessel-0000000:fx');
    app.tr.cache.clear();
    await assert.rejects(app.tr.translate('Neu', 'EN-US', 'deepl'), /DeepL: Schlüssel ungültig/);
    const st = await app.trAction('tr_prefs', { provider: 'google', ui_target: 'FR' });
    assert.strictEqual(st.provider, 'google');
    assert.strictEqual(st.prefs.ui_target, 'FR');
    m.srv.close(); app.tools.stop();
    delete process.env.NEXUS_DEEPL_BASE; delete process.env.NEXUS_GOOGLE_BASE;
});

test('Chatbox: /n, Aufteilen, Platzhalter, Rotator mit mehreren Texten', async () => {
    const { chatNewlines, splitChat } = require('../backend/gamelog');
    assert.strictEqual(chatNewlines('Hallo/nWelt\\nzwei https://x.de/n ok'), 'Hallo\nWelt\nzwei https://x.de/n ok');
    const parts = splitChat('Wort '.repeat(80).trim(), 144);
    assert.ok(parts.length === 3 && parts.every(p => [...p].length <= 144) && parts[2].endsWith('(3/3)'));
    const db = await openDatabase(null);
    const g = new GameLog(db);
    g.osc.stopListen();
    g.vars = () => ({ freunde: '7', status: 'Online' });
    g.state.worldName = 'Pug'; g.state.players.set('a', { id: 'a', name: 'A', since: 0 });
    assert.strictEqual(g.rotatorText('🌐 {welt}/n👥 {spieler} · {freunde} Freunde · {status} · {gibtsnicht}'), '🌐 Pug\n👥 1 · 7 Freunde · Online · {gibtsnicht}');
    assert.ok(/^\d+ h \d\d min$|^\d+ T \d+ h \d\d min$/.test(g.rotatorText('{countdown:2099-01-01 00:00}')));
    assert.ok(['a', 'b'].includes(g.rotatorText('{zufall:a|b}')));
    const c = g.saveCfg({ rotator_texts: 'eins\n\nzwei\ndrei', rotator_mode: 'seq' });
    assert.deepStrictEqual([g.rotatorNext(c), g.rotatorNext(c), g.rotatorNext(c), g.rotatorNext(c)], ['eins', 'zwei', 'drei', 'eins']);
    // senden: aufteilen + Platzhalter + Zeilenumbruch
    const sock = dgram.createSocket('udp4');
    const got = [];
    sock.on('message', m => got.push(oscDecode(m)[0][1][0]));
    await new Promise(r => sock.bind(0, '127.0.0.1', r));
    g.osc.configure('127.0.0.1', sock.address().port);
    g.osc.lastChat = 0;
    await g.oscSend('chatbox', { text: 'In {welt}/nhallo', sound: '1' });
    await g.oscSend('chatbox', { clear: '1' });
    await U.sleep(3600);
    sock.close();
    assert.deepStrictEqual(got.slice(0, 2), ['In Pug\nhallo', '']);
});


test('Auto-Update: neueste Version finden, Windows installieren, Android öffnen', async () => {
    const { newer } = require('../backend/updater');
    assert.ok(newer('1.2.10', '1.2.9') && newer('2.0.0', '1.9.9') && !newer('1.2.1', '1.2.1') && !newer('1.2.0', '1.2.1'));
    const http = require('http');
    const asset = (n, sz) => ({ name: n, size: sz, browser_download_url: 'https://github.com/x/releases/download/t/' + n });
    const releases = [
        { tag_name: 'vrc-nexus-v99.0.1', draft: false, prerelease: true, assets: [] },                       // Vorabversion → ignorieren
        { tag_name: 'anderes-v100.0.0', draft: false, prerelease: false, assets: [] },                         // anderes Projekt → ignorieren
        { tag_name: 'vrc-nexus-v9.1.0', draft: false, prerelease: false, body: '## Neu\n**Mehr**', html_url: 'https://github.com/x/releases/tag/vrc-nexus-v9.1.0',
          published_at: '2026-11-13T10:00:00Z', assets: [asset('VRC-Nexus-9.1.0-Setup.exe', 10), asset('VRC-Nexus-9.1.0-portable.exe', 11), asset('VRC-Nexus-9.1.0-android.apk', 12)] },
        { tag_name: 'vrc-nexus-v9.0.9', draft: false, prerelease: false, assets: [] },
    ];
    const srv = http.createServer((req, res) => { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(req.url.startsWith('/repos/xxloonaxx/dashbord/releases') ? releases : [])); });
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    process.env.NEXUS_UPDATE_API = 'http://127.0.0.1:' + srv.address().port;
    const { NexusApp } = require('../backend/app');
    const calls = [];
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => s, decrypt: s => s }, host: { userData: os.tmpdir(), installUpdate: async (a, p) => { p('50 %'); calls.push(a.setup.name); } } });
    app.game.osc.stopListen();
    const st = await app.updater.check();
    assert.strictEqual(st.version, '9.1.0');
    assert.ok(st.available && st.assets.setup && st.assets.apk && st.assets.portable);
    assert.ok((await app.post({ act: 'update_check' }, {})).msg.includes('9.1.0'));
    const r = await app.post({ act: 'update_install' }, {});
    assert.ok(r.msg.includes('9.1.0') && calls[0] === 'VRC-Nexus-9.1.0-Setup.exe');
    // Android: Download öffnen statt installieren
    const opened = [];
    const db2 = await openDatabase(null);
    const mob = new NexusApp({ db: db2, crypt: { encrypt: s => s, decrypt: s => s }, mobile: true, host: { userData: os.tmpdir(), openExternal: u => opened.push(u) } });
    mob.game.osc.stopListen();
    await mob.updater.check();
    assert.strictEqual(mob.updater.status().platform, 'android');
    await mob.updater.install();
    assert.ok(opened[0].endsWith('VRC-Nexus-9.1.0-android.apk'));
    srv.close(); app.tools.stop(); mob.tools.stop();
    delete process.env.NEXUS_UPDATE_API;
});

(async () => {
    let ok = 0;
    for (const [name, fn] of tests) {
        try { await fn(); ok++; console.log('✔', name); }
        catch (e) { console.log('✖', name, '\n  ', e.message); process.exitCode = 1; }
    }
    console.log(ok + '/' + tests.length + ' Tests bestanden');
    process.exit(process.exitCode || 0);
})();
