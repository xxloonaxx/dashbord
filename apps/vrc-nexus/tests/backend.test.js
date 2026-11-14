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
        { tag_name: 'vrc-nexus-v9.2.0', draft: false, prerelease: false, assets: [asset('VRC-Nexus-9.2.0-android.apk', 13)] },     // Windows-Setup noch nicht hochgeladen
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
    assert.strictEqual(st.version, '9.1.0');           // 9.2.0 hat noch kein Setup → Windows bekommt 9.1.0 (kein „Datei fehlt“)
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
    assert.strictEqual(mob.updater.status().version, '9.2.0');     // Android hat seine APK schon
    await mob.updater.install();
    assert.ok(opened[0].endsWith('VRC-Nexus-9.2.0-android.apk'));
    srv.close(); app.tools.stop(); mob.tools.stop();
    delete process.env.NEXUS_UPDATE_API;
});

test('1.3: Parameter-Erkennung, Makros, Kamera, Automatik, Sprache, Rückblick', async () => {
    const { NexusApp } = require('../backend/app');
    const { parse } = require('../backend/macros');
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nx13-'));
    const U1 = 'usr_11111111-2222-3333-4444-555555555555', A = 'avtr_aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    fs.mkdirSync(path.join(dir, 'OSC', U1, 'Avatars'), { recursive: true });
    fs.writeFileSync(path.join(dir, 'OSC', U1, 'Avatars', A + '.json'), '﻿' + JSON.stringify({ id: A, name: 'Fuchs', parameters: [
        { name: 'Ears', input: { type: 'Bool' }, output: { type: 'Bool' } }, { name: 'Outfit', input: { type: 'Int' }, output: { type: 'Int' } }, { name: 'VelocityX', output: { type: 'Float' } }] }));
    process.env.NEXUS_VRCHAT_DIR = dir;
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => s, decrypt: s => s }, host: { userData: dir } });
    app.game.osc.stopListen();
    const sent = [];
    app.game.osc.send = async (a, ...x) => { sent.push(a + ' ' + JSON.stringify(x)); };
    app.game.osc.chatbox = async t => { sent.push('/chatbox/input ' + t); };
    app.game.osc.avatarId = A;
    app.game.osc.params = { Ears: true };
    // Parameter
    const pl = await app.post({ act: 'client_params' }, {});
    assert.strictEqual(pl.avatar.name, 'Fuchs');
    assert.deepStrictEqual(pl.params.map(p => p.name + ':' + p.type + ':' + p.builtin), ['Ears:bool:false', 'Outfit:int:false', 'VelocityX:float:true']);
    await app.post({ act: 'client_param_fav', avatar: A, name: 'Outfit' }, {});
    assert.strictEqual((await app.post({ act: 'client_params' }, {})).params[0].name, 'Outfit');
    // Makros
    assert.throws(() => parse('blub 1'), /Unbekannter Schritt/);
    assert.strictEqual(parse('param Hue = 0,5\nwait 1,5')[1].s, 1.5);
    await app.macros.run('param Ears = 0\ncam Zoom 80\ncam Capture\nchatbox Hallo {name}');
    assert.ok(sent.includes('/avatar/parameters/Ears [false]') && sent.includes('/usercamera/Zoom [{"float":80}]') && sent.includes('/usercamera/Capture [true]'), sent.join(' | '));
    // Kamera-Vorlage
    app.game.osc.cam = { Zoom: 60, Pose: [1, 2, 3, 0, 90, 0] };
    await app.post({ act: 'cam_preset_save', name: 'Porträt' }, {});
    sent.length = 0;
    await app.post({ act: 'cam_preset_load', name: 'Porträt' }, {});
    assert.ok(sent.some(x => x.startsWith('/usercamera/Pose')), sent.join(' | '));
    // Automatik: Begrüßung bei neuem Spieler
    await app.post({ act: 'auto_save', name: 'Hallo', trigger: 'player_join', arg: 'all', script: 'chatbox Hi {spieler_name}!', cooldown: 0 }, {});
    app.game.state.running = true;
    sent.length = 0;
    app.auto.onGameEvent({ ts: Math.floor(Date.now() / 1000), kind: 'player_join', player_id: 'usr_x', player_name: 'Neko' });
    await U.sleep(50);
    assert.ok(sent.includes('/chatbox/input Hi Neko!'), sent.join(' | '));
    // AFK an/aus über OSC
    await app.post({ act: 'auto_save', name: 'AFK', trigger: 'afk_on', script: 'chatbox AFK', cooldown: 0 }, {});
    sent.length = 0;
    app.auto.onOsc('/avatar/parameters/AFK', [true]);
    await U.sleep(50);
    assert.ok(sent.includes('/chatbox/input AFK'));
    // Sprache: Ersatz-Programm statt whisper.cpp
    const fake = path.join(dir, 'fakewhisper.js');
    fs.writeFileSync(fake, '#!/usr/bin/env node\nconsole.log("[00:00:00.000 --> 00:00:01.000]  Hallo Welt");\n');
    fs.chmodSync(fake, 0o755);
    if (process.platform !== 'win32') {
        process.env.NEXUS_WHISPER_BIN = fake;
        app.speech.saveCfg({ enabled: '1', prefix: '' });
        sent.length = 0;
        const wav = Buffer.concat([Buffer.from('RIFF'), Buffer.alloc(3000)]);
        const r = await app.post({ act: 'speech_transcribe', audio: wav.toString('base64') }, {});
        assert.strictEqual(r.text, 'Hallo Welt');
        assert.ok(sent.includes('/chatbox/input Hallo Welt'), sent.join(' | '));
        delete process.env.NEXUS_WHISPER_BIN;
        // 1.7.5: Paket mit veraltetem „main“ + „whisper-cli“ → whisper-cli wird genommen; nur „main“ → verständliche Meldung
        const rel = path.join(app.speech.dir(), 'bin', 'Release');
        fs.mkdirSync(rel, { recursive: true });
        fs.writeFileSync(path.join(rel, 'main'), '#!/usr/bin/env node\nconsole.error("WARNING: The binary main is deprecated. Please use whisper-cli instead.");process.exit(1);\n', { mode: 0o755 });
        fs.writeFileSync(path.join(rel, 'whisper-cli'), '#!/usr/bin/env node\nconsole.log("Hallo aus whisper-cli");\n', { mode: 0o755 });
        fs.writeFileSync(app.speech.modelPath('tiny'), 'x');
        app.speech.saveCfg({ enabled: '1', prefix: '', model: 'tiny' });
        assert.strictEqual(path.basename(app.speech.binPath()), 'whisper-cli');
        const r2 = await app.post({ act: 'speech_transcribe', audio: wav.toString('base64') }, {});
        assert.strictEqual(r2.text, 'Hallo aus whisper-cli');
        fs.rmSync(path.join(rel, 'whisper-cli'));
        assert.strictEqual(path.basename(app.speech.binPath()), 'main');
        await assert.rejects(app.post({ act: 'speech_transcribe', audio: wav.toString('base64') }, {}), /veraltet/);
        fs.rmSync(path.join(app.speech.dir(), 'bin'), { recursive: true, force: true });
    }
    // Rückblick & Startseite
    const now = Math.floor(Date.now() / 1000);
    app.game.store([{ ts: now - 3600, kind: 'world_join', world_id: 'wrld_1', world_name: 'Testwelt' }, { ts: now - 3000, kind: 'player_join', player_id: 'usr_y', player_name: 'Luna' }, { ts: now - 600, kind: 'world_leave' }]);
    const rc = await app.post({ act: 'ins_recap', days: 7 }, {});
    assert.strictEqual(rc.top_worlds[0].name, 'Testwelt');
    assert.ok(rc.playtime >= 2900 && rc.top_people[0].name === 'Luna');
    const home = await app.post({ act: 'home_data' }, {});
    assert.ok(Array.isArray(home.macros) && home.friends && home.cam);
    // 1.6: Daten der neuen Widgets
    assert.ok(Array.isArray(home.events) && Array.isArray(home.anniv) && Array.isArray(home.rules) && Array.isArray(home.osclog) && home.coll && 'week' in home && 'stream' in home);
    // OSC-Log: Kategorien, nur Änderungen, Drosselung, Gesendetes
    const { category } = require('../backend/osclog');
    assert.deepStrictEqual(['/avatar/change', '/avatar/parameters/Ears', '/avatar/parameters/Outfit', '/avatar/parameters/Hue', '/avatar/parameters/VelocityX', '/avatar/parameters/GestureLeft', '/avatar/parameters/AFK', '/usercamera/Zoom', '/chatbox/input', '/input/Jump', '/foo']
        .map((a, i) => category(a, [[A, true, 3, 0.5, 0.1, 2, true, 50, 'x', 1, 1][i]])), ['avatar', 'toggle', 'int', 'float', 'motion', 'gesture', 'state', 'camera', 'chatbox', 'input', 'other']);
    const L = app.osclog;
    L.buf = [];
    L.add('in', '/avatar/parameters/Ears', [true]); L.add('in', '/avatar/parameters/Ears', [true]); L.add('in', '/avatar/parameters/Ears', [false]);
    for (let i = 0; i < 5; i++) L.add('in', '/avatar/parameters/VelocityX', [i / 10]);
    L.add('out', '/chatbox/input', ['Hi', true, false]);
    const ql = L.query({ range: 'live' });
    assert.strictEqual(ql.counts.toggle, 2);
    assert.strictEqual(ql.counts.motion, 1);
    assert.strictEqual(ql.items[0].d, 'out');
    assert.deepStrictEqual(ql.items.find(e => e.c === 'toggle').p, true);
    L.flush();
    assert.ok(L.query({ range: 'today' }).total >= 4);
    app.tools.stop();
    delete process.env.NEXUS_VRCHAT_DIR;
});

test('1.4: Stream-Bot (Twitch-Zeilen, Punkte, Befehle, Belohnungen), Cloud-Sicherung, OBS-Zugriff', async () => {
    const { NexusApp } = require('../backend/app');
    const { CloudBackup } = require('../backend/cloudbackup');
    const http = require('http');
    const db = await openDatabase(null);
    const crypt = { encrypt: s => 'x' + Buffer.from(s).toString('base64'), decrypt: s => Buffer.from(s.slice(1), 'base64').toString() };
    const app = new NexusApp({ db, crypt, host: { userData: os.tmpdir() } });
    app.game.osc.stopListen();
    const sent = [];
    app.game.osc.send = async (a, ...x) => { sent.push(a + ' ' + JSON.stringify(x)); };
    const sb = app.stream;
    const said = [];
    sb.twSend = t => { said.push(t); return true; };
    sb.saveCfg({ greet: '1', p_per_msg: 2, p_msg_cooldown: 0 });
    // echte Twitch-IRC-Zeile mit Tags
    sb.twLine('@badges=moderator/1;display-name=ModKatze;mod=1;user-id=42 :modkatze!m@m.tmi.twitch.tv PRIVMSG #luna :Hallo!');
    sb.twLine('@badges=;display-name=FoxFan;mod=0;user-id=7 :foxfan!f@f.tmi.twitch.tv PRIVMSG #luna :!punkte');
    await U.sleep(20);
    assert.ok(said.includes('Willkommen ModKatze! 👋'), said.join(' | '));
    assert.ok(said.some(t => /^FoxFan hat 2 Punkte\./.test(t)), said.join(' | '));
    sb.twLine('@badges=moderator/1;display-name=ModKatze;mod=1;user-id=42 :modkatze!m@m.tmi.twitch.tv PRIVMSG #luna :!addpunkte foxfan 100');
    await U.sleep(20);
    assert.strictEqual(sb.findUser('foxfan').a.p, 102);       // 2 (eine Nachricht) + 100
    // Belohnung → Makro → OSC
    const c = sb.cfg();
    c.rewards = [{ name: 'ohren', cost: 50, script: 'param Ears = 1', cooldown: 0, response: '{user} löst ein ({kosten})' }];
    sb.writeCfg(c);
    sb.twLine('@badges=;display-name=FoxFan;mod=0;user-id=7 :foxfan!f@f.tmi.twitch.tv PRIVMSG #luna :!ohren');
    await U.sleep(50);
    assert.ok(sent.includes('/avatar/parameters/Ears [1]'), sent.join(' | '));
    assert.strictEqual(sb.findUser('foxfan').a.p, 102 + 2 - 50);
    assert.ok(said.includes('FoxFan löst ein (50)'), said.join(' | '));
    // zu wenig Punkte → nichts passiert
    sent.length = 0;
    sb.twLine('@badges=;display-name=Arm;mod=0;user-id=9 :arm!a@a.tmi.twitch.tv PRIVMSG #luna :!ohren');
    await U.sleep(30);
    assert.ok(!sent.length && said.some(t => /kostet 50/.test(t)));
    // nicht-Mod darf keine Punkte vergeben
    sb.twLine('@badges=;display-name=Arm;mod=0;user-id=9 :arm!a@a.tmi.twitch.tv PRIVMSG #luna :!addpunkte arm 9999');
    await U.sleep(20);
    assert.ok(sb.findUser('arm').a.p < 100);
    // Befehls-Baukasten: nur Abonnenten, Wert aus dem Chat → Avatar-Parameter, Hilfe ohne Wert, keine eingeschleusten Schritte
    const c2 = sb.cfg();
    c2.commands.push({ name: 'farbe', aliases: 'color', perm: 'sub', cost: 0, cooldown: 0, user_cooldown: 0, script: 'param Color = {1}', response: 'Farbe {1}', usage: '<0–7>' });
    sb.writeCfg(c2);
    sent.length = 0; said.length = 0;
    sb.twLine('@badges=;display-name=Normalo;mod=0;user-id=11 :normalo!n@n.tmi.twitch.tv PRIVMSG #luna :!farbe 3');
    await U.sleep(30);
    assert.ok(!sent.length, 'ohne Abo darf nichts passieren');
    sb.twLine('@badges=subscriber/3;display-name=Abo;mod=0;user-id=12 :abo!a@a.tmi.twitch.tv PRIVMSG #luna :!color 4');
    await U.sleep(30);
    assert.ok(sent.includes('/avatar/parameters/Color [4]'), sent.join(' | '));
    sb.twLine('@badges=subscriber/3;display-name=Abo;mod=0;user-id=12 :abo!a@a.tmi.twitch.tv PRIVMSG #luna :!farbe');
    await U.sleep(30);
    assert.ok(said.some(t => /So geht's: !farbe <0–7>/.test(t)), said.join(' | '));
    assert.ok(!/[\r\n;]/.test(sb.fillScript('param Color = {1}', { 1: '5;\nwebhook http://x' })));
    const plain = Buffer.from('SQLite format 3\0' + 'x'.repeat(5000));
    const blob = CloudBackup.seal(plain, 'Passwort123');
    assert.ok(!blob.includes(Buffer.from('xxxxxxxx')));
    assert.ok(CloudBackup.open(blob, 'Passwort123').equals(plain));
    assert.throws(() => CloudBackup.open(blob, 'falsch!!'), /Falsches/);
    blob[blob.length - 3] ^= 1;
    assert.throws(() => CloudBackup.open(blob, 'Passwort123'), /Falsches/);
    // OBS-Seiten: ohne Stream-Deck-Schnittstelle erreichbar, fremde Webseiten nicht
    app.settings.save({ 'int.api': false, 'int.obs': true, 'int.api_port': 17991 });
    app.tools.api.apply();
    await U.sleep(100);
    const tok = app.settings.get('int.api_token');
    const get = (p, origin) => new Promise(res => http.get({ host: '127.0.0.1', port: 17991, path: p, headers: origin ? { Origin: origin } : {} }, r => { r.resume(); res(r.statusCode); }));
    assert.strictEqual(await get('/obs.json?token=' + tok), 200);
    assert.strictEqual(await get('/chat.json?token=' + tok, 'http://127.0.0.1:17991'), 200);
    assert.strictEqual(await get('/obs.json?token=' + tok, 'https://boese.example'), 403);
    assert.strictEqual(await get('/api/state?token=' + tok), 403);       // Stream-Deck-Teil bleibt aus
    assert.strictEqual(await get('/obs.json?token=falsch'), 401);
    app.tools.api.stop(); sb.stop(); app.tools.stop();
});

test('1.8: Chatbox-Module (Status, Zeit, Wetter, Rechner, Netzwerk, Twitch, Puls, VR/Desktop)', async () => {
    const http = require('http');
    // Nachbildungen: Open-Meteo (Wetter + Orte), decapi.me (Twitch), Pulsoid
    const srv = http.createServer((req, res) => {
        const u = new URL(req.url, 'http://x');
        const j = o => { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(o)); };
        if (u.pathname === '/v1/search') return j({ results: [{ name: 'Leipzig', admin1: 'Sachsen', country_code: 'DE', latitude: 51.34, longitude: 12.37 }] });
        if (u.pathname === '/v1/forecast') return j({ current: { temperature_2m: 7.4, apparent_temperature: 4.1, relative_humidity_2m: 81, wind_speed_10m: 13.2, weather_code: 61 } });
        if (u.pathname === '/twitch/uptime/luna') return res.end('2 hours, 5 minutes');
        if (u.pathname === '/twitch/viewercount/luna') return res.end('42');
        if (u.pathname === '/twitch/game/luna') return res.end('VRChat');
        if (u.pathname === '/api/v1/data/heart_rate/latest') return req.headers.authorization === 'Bearer pulsoid-token-1234567890abcdef' ? j({ measured_at: Date.now(), data: { heart_rate: 88 } }) : (res.statusCode = 403, j({}));
        res.statusCode = 404; res.end('');
    });
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    const B = 'http://127.0.0.1:' + srv.address().port;
    Object.assign(process.env, { NEXUS_OPENMETEO_BASE: B, NEXUS_GEO_BASE: B, NEXUS_DECAPI_BASE: B, NEXUS_PULSOID_BASE: B, NEXUS_FAKE_VR: '0' });
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nxcm-'));
    // nvidia-smi + Netzwerkzähler nachbilden
    const smi = path.join(dir, 'nvidia-smi.js');
    fs.writeFileSync(smi, 'console.log("57, 63, 6144, 12288, 180.5, NVIDIA GeForce RTX 4070");\n');
    process.env.NEXUS_NVIDIA_SMI = smi;
    const netdev = path.join(dir, 'netdev');
    const net = (rx, tx) => fs.writeFileSync(netdev, 'Inter-|   Receive\n face |bytes\n    lo: 999 0 0 0 0 0 0 0 999 0 0 0 0 0 0 0\n  eth0: ' + rx + ' 0 0 0 0 0 0 0 ' + tx + ' 0 0 0 0 0 0 0\n');
    net(1000000, 500000);
    process.env.NEXUS_NETDEV = netdev;
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => 'enc:' + s, decrypt: s => s.replace(/^enc:/, '') }, host: { userData: dir } });
    app.game.osc.stopListen();
    const sent = [];
    app.game.osc.send = async (a, ...x) => { sent.push(a + ' ' + JSON.stringify(x)); };
    app.game.osc.chatbox = async t => { sent.push('/chatbox/input ' + t); };
    app.game.saveCfg({ rotator_enabled: '1', rotator_texts: 'Hallo Welt 🦊\nBin am Basteln', rotator_seconds: 5 });
    const post = (act, d) => app.post(Object.assign({ act }, d || {}), {});
    let r = await post('cm_get');
    assert.ok(r.modules.weather && r.cfg.mods.status.on && !r.cfg.on);
    await post('cm_place', { city: 'Leipzig' });
    for (const k of ['weather', 'system', 'network', 'twitch', 'heart', 'vrchat']) await post('cm_save', { mod: k, mod_on: '1' });
    await post('cm_save', { twitch: 'https://twitch.tv/luna', sep: 'line', on: '1', seconds: 3, only_game: '0' });
    await assert.rejects(post('cm_hr_key', { token: 'kurz' }), /Pulsoid/);
    await post('cm_hr_key', { token: 'pulsoid-token-1234567890abcdef' });
    assert.ok(db.setting('chatmods_hr', '').startsWith('enc:'), 'Token nicht verschlüsselt');
    app.game.state.worldName = 'Testwelt';
    await post('cm_get', { refresh: '1' });
    net(3000000, 1500000);
    await U.sleep(2100);
    r = await post('cm_get', { refresh: '1' });
    const v = r.values;
    assert.strictEqual(v.temp, '7'); assert.strictEqual(v.wetter_symbol, '🌧'); assert.strictEqual(v.gpu, '57'); assert.strictEqual(v.gpu_temp, '63');
    assert.strictEqual(v.twitch_zuschauer, '42'); assert.strictEqual(v.twitch_spiel, 'VRChat'); assert.strictEqual(v.puls, '88');
    assert.ok(/MB\/s|KB\/s/.test(v.netz_down) && v.cpu !== undefined, JSON.stringify(v));
    assert.ok(sent.some(x => x.startsWith('/avatar/parameters/HeartRate [88]')), 'Puls nicht als Avatar-Parameter gesendet');
    let p = r.preview.text;
    // zu lang für 144 Zeichen → ganze Module am Ende fallen weg (hier der Puls), nichts wird mitten im Text abgeschnitten
    assert.ok(Array.from(p).length <= 144 && r.preview.dropped.includes('heart') && !p.includes('💓'), JSON.stringify(r.preview));
    await post('cm_save', { mod: 'system', mod_on: '0' }); await post('cm_save', { mod: 'vrchat', mod_on: '0' });
    r = await post('cm_get'); p = r.preview.text;
    for (const part of ['🌧 7°C', '🔴 LIVE · 👀 42 · VRChat', '💓 88 bpm', '📶 ↓']) assert.ok(p.includes(part), 'fehlt: ' + part + ' in ' + p);
    assert.ok(!r.preview.dropped.length && Array.from(p).length <= 144);
    // Reihenfolge ändern: Puls nach vorn
    await post('cm_save', { order: 'heart,status' });
    r = await post('cm_get');
    assert.ok(r.preview.text.startsWith('💓 88 bpm'), r.preview.text);
    // nur-Desktop-Module verschwinden in VR, Fenster zeigt „In VR“
    await post('cm_save', { mod: 'window', mod_on: '1', where: 'both' });
    process.env.NEXUS_FAKE_VR = '1'; app.chatmods.last.vr = 0;
    r = await post('cm_get');
    assert.ok(!r.preview.text.includes('📶') && r.preview.text.includes('🥽 In VR'), r.preview.text);
    // Senden + Rotator pausiert, solange Module an sind
    sent.length = 0;
    await post('cm_send');
    assert.ok(sent.some(x => x.startsWith('/chatbox/input ') && x.includes('💓 88 bpm')));
    assert.ok(app.game.suppressRotator);
    app.game.lastRot = 0; sent.length = 0; app.game.tick();
    assert.ok(!sent.some(x => x.includes('Hallo Welt')), 'Rotator lief trotz Modulen');
    // AFK ersetzt den Status-Text
    app.auto.afkSince = Date.now() - 5 * 60000;
    r = await post('cm_get');
    assert.ok(r.preview.text.includes('💤 AFK seit 5 min'), r.preview.text);
    // Platzhalter auch im Rotator/Makros nutzbar
    assert.ok(app.game.fill('{temp}°C {puls}').includes('7°C 88'));
    await post('cm_save', { on: '0' });
    assert.ok(!app.game.suppressRotator);
    app.chatmods.stop(); app.tools.stop(); srv.close();
    for (const k of ['NEXUS_OPENMETEO_BASE', 'NEXUS_GEO_BASE', 'NEXUS_DECAPI_BASE', 'NEXUS_PULSOID_BASE', 'NEXUS_FAKE_VR', 'NEXUS_NVIDIA_SMI', 'NEXUS_NETDEV']) delete process.env[k];
});

test('1.9: Sprachausgabe (Windows, Piper, Google, Azure, ElevenLabs, OpenAI) + nach Spracheingabe sprechen', async () => {
    const http = require('http');
    const seen = [];
    const srv = http.createServer(async (req, res) => {
        let body = ''; for await (const c of req) body += c;
        const u = new URL(req.url, 'http://x');
        seen.push(req.method + ' ' + u.pathname);
        const mp3 = () => { res.setHeader('Content-Type', 'audio/mpeg'); res.end(Buffer.from('ID3fakeaudio')); };
        res.setHeader('Content-Type', 'application/json');
        if (u.pathname === '/v1/voices' && u.searchParams.get('key') === 'gkey-1234567890abcdef') return res.end(JSON.stringify({ voices: [{ name: 'de-DE-Neural2-B', ssmlGender: 'MALE', languageCodes: ['de-DE'] }] }));
        if (u.pathname === '/v1/text:synthesize') { const j = JSON.parse(body); return res.end(JSON.stringify({ audioContent: Buffer.from('G:' + j.input.text).toString('base64') })); }
        if (u.pathname === '/cognitiveservices/voices/list') return req.headers['ocp-apim-subscription-key'] === 'azkey-1234567890abcdef' ? res.end(JSON.stringify([{ ShortName: 'de-DE-KatjaNeural', LocalName: 'Katja', Gender: 'Female', Locale: 'de-DE' }])) : (res.statusCode = 401, res.end('{}'));
        if (u.pathname === '/cognitiveservices/v1') { assert.ok(body.includes('<voice name="de-DE-KatjaNeural">') && body.includes('&lt;3')); return mp3(); }
        if (u.pathname === '/v1/voices') return res.end(JSON.stringify({ voices: [{ voice_id: 'v123', name: 'Rachel', labels: { gender: 'female' } }] }));
        if (u.pathname === '/v1/text-to-speech/v123') { assert.strictEqual(req.headers['xi-api-key'], 'elkey-1234567890abcdef'); return mp3(); }
        if (u.pathname === '/audio/speech') { const j = JSON.parse(body); assert.strictEqual(j.voice, 'nova'); return mp3(); }
        res.statusCode = 404; res.end('{}');
    });
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    const B = 'http://127.0.0.1:' + srv.address().port;
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nxtts-'));
    const piper = path.join(dir, 'piper.js');
    fs.writeFileSync(piper, "let t='';process.stdin.on('data',d=>t+=d);process.stdin.on('end',()=>{const a=process.argv;require('fs').writeFileSync(a[a.indexOf('--output_file')+1],Buffer.concat([Buffer.from('RIFF'),Buffer.from(t)]))});");
    Object.assign(process.env, { NEXUS_GTTS_BASE: B, NEXUS_AZURE_BASE: B, NEXUS_EL_BASE: B, NEXUS_OPENAI_BASE: B, NEXUS_FAKE_SAPI: '1', NEXUS_PIPER_BIN: piper });
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => 'enc:' + s, decrypt: s => s.replace(/^enc:/, '') }, host: { userData: dir } });
    app.game.osc.stopListen();
    const sent = [];
    app.game.osc.chatbox = async t => { sent.push(t); };
    const post = (act, d) => app.post(Object.assign({ act }, d || {}), {});
    await assert.rejects(post('tts_say', { text: 'Hallo' }), /aus/);
    await post('tts_cfg', { enabled: '1', provider: 'windows' });
    let v = await post('tts_voices', { provider: 'windows' });
    assert.ok(v.voices.some(x => x.id === 'Microsoft Hedda Desktop'));
    let r = await post('tts_say', { text: 'Hallo Welt', chatbox: '1' });
    assert.strictEqual(r.item.mime, 'audio/wav');
    assert.ok(sent.includes('Hallo Welt'), 'nicht in die Chatbox');
    // Piper: Text kommt per stdin an, WAV zurück
    await post('tts_cfg', { provider: 'piper', voice: 'de_DE-thorsten-medium' });
    r = await post('tts_say', { text: 'Guten Abend' });
    assert.strictEqual(Buffer.from(r.item.b64, 'base64').toString(), 'RIFFGuten Abend');
    // Anbieter mit Schlüssel (verschlüsselt gespeichert)
    await post('tts_cfg', { provider: 'google' });
    await assert.rejects(post('tts_say', { text: 'x' }), /Schlüssel fehlt/);
    await post('tts_key', { provider: 'google', key: 'gkey-1234567890abcdef' });
    assert.ok(db.setting('tts_keys', '').startsWith('enc:'));
    v = await post('tts_voices', { provider: 'google' });
    assert.strictEqual(v.voices[0].id, 'de-DE-Neural2-B');
    r = await post('tts_say', { text: 'Google spricht' });
    assert.strictEqual(Buffer.from(r.item.b64, 'base64').toString(), 'G:Google spricht');
    await post('tts_key', { provider: 'azure', key: 'azkey-1234567890abcdef' });
    await post('tts_cfg', { provider: 'azure', voice: 'de-DE-KatjaNeural' });
    assert.strictEqual((await post('tts_voices', { provider: 'azure' })).voices[0].id, 'de-DE-KatjaNeural');
    r = await post('tts_say', { text: 'Ich hab euch <3 lieb' });
    assert.strictEqual(r.item.mime, 'audio/mpeg');
    await post('tts_key', { provider: 'elevenlabs', key: 'elkey-1234567890abcdef' });
    await post('tts_cfg', { provider: 'elevenlabs' });
    assert.strictEqual((await post('tts_voices', { provider: 'elevenlabs' })).voices[0].id, 'v123');
    await post('tts_cfg', { voice: 'v123' });
    await post('tts_say', { text: 'Eleven' });
    await post('tts_key', { provider: 'openai', key: 'sk-test1234567890abcdef' });
    await post('tts_cfg', { provider: 'openai', voice: 'nova' });
    await post('tts_say', { text: 'OpenAI' });
    // Oberfläche holt fertige Audios ab
    const q = await post('tts_poll', { since: 0 });
    assert.ok(q.items.length >= 6 && q.items[q.items.length - 1].text === 'OpenAI');
    // nach der Spracheingabe: Übersetzung sprechen
    await post('tts_cfg', { provider: 'piper', on_speech: '1', speak: 'sent' });
    const fakeW = path.join(dir, 'w.js');
    fs.writeFileSync(fakeW, 'console.log("[00:00:00.000 --> 00:00:01.000]  Guten Morgen");');
    if (process.platform !== 'win32') {
        fs.writeFileSync(path.join(dir, 'w.sh'), '#!/bin/sh\nexec "' + process.execPath + '" "' + fakeW + '"\n', { mode: 0o755 });
        process.env.NEXUS_WHISPER_BIN = path.join(dir, 'w.sh');
        app.speech.saveCfg({ enabled: '1', prefix: '' });
        const before = (await post('tts_poll', { since: 0 })).seq;
        await app.speech.transcribe(Buffer.concat([Buffer.from('RIFF'), Buffer.alloc(3000)]));
        await U.sleep(300);
        const n = await post('tts_poll', { since: before });
        assert.ok(n.items.some(x => x.text === 'Guten Morgen' && x.via === 'sprache'), JSON.stringify(n.items.map(x => x.text)));
        delete process.env.NEXUS_WHISPER_BIN;
    }
    // Makro-Schritt „speak“ nutzt die Sprachausgabe
    const before2 = (await post('tts_poll', { since: 0 })).seq;
    await app.macros.run('speak Makro spricht');
    await U.sleep(300);
    assert.ok((await post('tts_poll', { since: before2 })).items.some(x => x.text === 'Makro spricht'));
    app.tools.stop(); srv.close();
    for (const k of ['NEXUS_GTTS_BASE', 'NEXUS_AZURE_BASE', 'NEXUS_EL_BASE', 'NEXUS_OPENAI_BASE', 'NEXUS_FAKE_SAPI', 'NEXUS_PIPER_BIN']) delete process.env[k];
});

test('1.9.1: Über-Seite – Changelog aktuell (aus der README gebaut) und letzte 2 Versionen', async () => {
    const { build } = require('../scripts/changelog-build');
    const fresh = build(), saved = require('../backend/changelog.json');
    assert.deepStrictEqual(saved, fresh, 'backend/changelog.json ist veraltet – node scripts/changelog-build.js ausführen');
    const { VERSION } = require('../backend/vrchat');
    assert.strictEqual(fresh[0].version, VERSION, 'README hat keinen Abschnitt „### Neu in ' + VERSION + '“');
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => s, decrypt: s => s }, host: { userData: os.tmpdir() } });
    app.game.osc.stopListen();
    const r = await app.post({ act: 'about' }, {});
    assert.strictEqual(r.changelog.length, 2); assert.strictEqual(r.version, VERSION); assert.ok(r.changelog[0].body.length > 20);
    app.tools.stop();
});

test('1.10: VRChat-Store (Store-ID aus /config, Regale, Restzeit, Artikelart), Meldungen mit Beschreibung, Artikel-Markdown', async () => {
    const U = require('../backend/util');
    const h = U.formatText('# Titel\n**fett** [Hilfe](https://help.vrchat.com/a) <b>x</b>\n- Punkt', true);
    assert.ok(h.includes('<b class="md-h">Titel</b>') && h.includes('<b>fett</b>') && h.includes('href="https://help.vrchat.com/a"') && h.includes('&lt;b&gt;') && h.includes('• Punkt'), h);
    assert.ok(!U.formatText('**a**').includes('<b>'), 'ohne md bleibt Text unverändert');
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => s, decrypt: s => s }, host: { userData: os.tmpdir() } });
    app.game.osc.stopListen();
    const SID = 'esto_11111111-2222-3333-4444-555555555555', end = new Date(Date.now() + 35.5 * 86400000).toISOString(), calls = [];
    app.x.vrc.call = async (acc, m, p, q) => {
        calls.push(p);
        if (p === '/config') return { a: { vrchatStoreId: SID }, junk: 'esto_kaputt' };
        if (p === '/economy/store') { assert.strictEqual(q.storeId, SID); return { displayName: 'VRChat Store', listings: [] }; }
        if (p === '/economy/store/shelves') return [{ shelfTitle: 'Spookality', shelfBannerImageUrl: 'https://assets.vrchat.com/b.png', listings: [
            { id: 'prod_1', displayName: 'Paket', priceTokens: 1000, vrcPlusDiscountPrice: 900, endDate: end, hydratedProducts: [{ inventoryItem: { itemType: 'portal' } }, { inventoryItem: { itemType: 'prop' } }] },
            { id: 'prod_2', displayName: 'Hut', priceTokens: 300, hydratedProducts: [{ itemType: 'Accessory' }] }] }];
        throw new Error('unerwartet ' + p);
    };
    const r = await app.x.shopStore({ vrc_id: 'usr_x' });
    assert.ok(r.found && r.id === SID && r.title === 'VRChat Store');
    const [a, b] = r.shelves[0].listings;
    assert.strictEqual(r.shelves[0].banner, 'https://assets.vrchat.com/b.png');
    assert.deepStrictEqual(a.items, ['bundle', 'portal', 'prop']); assert.strictEqual(a.ends, end); assert.strictEqual(a.vrcplus_price, 900);
    assert.deepStrictEqual(b.items, ['accessory']); assert.strictEqual(b.ends, '');
    await app.x.shopStore({ vrc_id: 'usr_x' });
    assert.strictEqual(calls.filter(c => c === '/config').length, 1, '/config wird zwischengespeichert');
    app.settings.save({ 'social.store_id': 'https://vrchat.com/home/store/esto_99999999-2222-3333-4444-555555555555' });
    app.x.vrc.call = async (acc, m, p, q) => { if (p === '/config') throw new Error('nicht fragen'); return p === '/economy/store' ? { displayName: q.storeId } : []; };
    assert.strictEqual((await app.x.shopStore({})).id, 'esto_99999999-2222-3333-4444-555555555555', 'Store-ID aus Einstellung');
    // Merken (Artikel & Shops)
    const P = 'prod_00000001-1111-2222-3333-444444444444', S = 'usr_00000001-1111-2222-3333-444444444444';
    let sv = app.x.saveToggle('item', { id: P, name: 'Hut', price: 300, vrcplus_price: 270, image: 'https://assets.vrchat.com/h.png', evil: '<script>' });
    assert.ok(sv.saved && sv.items.length === 1 && sv.items[0].name === 'Hut' && sv.items[0].evil === undefined, 'Artikel gemerkt (nur bekannte Felder)');
    sv = app.x.saveToggle('shop', { target: S, title: 'Shop von Fuchs' });
    assert.strictEqual(sv.shops[0].target, S);
    assert.deepStrictEqual(app.x.saved().items.map(x => x.id), [P], 'bleibt gespeichert');
    sv = app.x.saveToggle('item', { id: P });
    assert.ok(!sv.saved && !sv.items.length, 'zweites Mal = entfernen');
    assert.throws(() => app.x.saveToggle('item', { id: '../x' }));
    assert.throws(() => app.x.saveToggle('shop', { target: 'javascript:1' }));
    // Meldungen
    app.tools.call = async (m, p) => ({ hasNext: false, totalCount: 1, results: [{ id: 'modr_1', type: 'avatar', category: 'inappropriate', reason: 'sexual', contentId: 'avtr_1', contentName: 'A', created: '2026-11-01', status: 'open', description: 'Text  der Meldung' }] });
    app.tools.acc = () => ({ vrc_id: 'usr_x' });
    const rep = (await app.tools.reports()).reports[0];
    assert.strictEqual(rep.description, 'Text  der Meldung'); assert.strictEqual(rep.type, 'avatar'); assert.strictEqual(rep.image, '');
    app.tools.stop();
});

test('1.10.2: Spielt gerade über die Windows-Mediensteuerung (Sitzungen, Platzhalter, ⏯ ⏭, Fallback)', async () => {
    const path = require('path');
    const { appName, PS } = require('../backend/media');
    assert.strictEqual(appName('308046B0AF4A39CB'), 'Firefox'); assert.strictEqual(appName('Microsoft.ZuneMusic_8wekyb3d8bbwe!Microsoft.ZuneMusic'), 'Medienwiedergabe');
    assert.ok(PS.includes("IAsyncOperation`1") && PS.includes('TryTogglePlayPauseAsync'), 'PowerShell-Skript vollständig');
    process.env.NEXUS_MEDIA_PS = path.join(__dirname, 'fixtures', 'fake-media.js');
    delete process.env.NEXUS_MUSIC;
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => s, decrypt: s => s }, host: { userData: os.tmpdir() } });
    app.game.osc.stopListen();
    try {
        app.media.start();
        const wait = async f => { for (let i = 0; i < 60 && !f(); i++) await new Promise(r => setTimeout(r, 100)); assert.ok(f(), 'Zeitüberschreitung'); };
        await wait(() => app.media.now());
        const n = app.media.now();
        assert.strictEqual(n.app, 'Spotify'); assert.strictEqual(n.status, 'playing'); assert.strictEqual(n.title, 'Harder, Better, Faster, Stronger');
        assert.strictEqual(app.media.sessions.length, 2, 'auch pausierter Browser wird gelistet');
        app.auto.refreshMusic();
        assert.strictEqual(app.auto.music, 'Daft Punk – Harder, Better, Faster, Stronger');
        const v = app.auto.vars();
        assert.strictEqual(v.musik_kuenstler, 'Daft Punk'); assert.strictEqual(v.musik_app, 'Spotify'); assert.ok(/^\d+:\d\d\/3:44$/.test(v.musik_zeit), v.musik_zeit);
        await app.post({ act: 'media_ctl', cmd: 'next', id: 'Spotify.exe' }, {});
        await wait(() => app.media.now() && app.media.now().title === 'Snowfall');
        await app.post({ act: 'media_ctl', cmd: 'toggle' }, {});
        await wait(() => app.media.sessions[0] && app.media.sessions[0].status === 'paused');
        assert.strictEqual(app.media.text(), '', 'pausiert = nichts „spielt gerade“');
        assert.strictEqual(app.auto.vars().musik_titel, '–');
        const st = await app.post({ act: 'media_status' }, {});
        assert.ok(st.available && st.running && st.now.status === 'paused');
        await assert.rejects(app.post({ act: 'media_ctl', cmd: 'rm -rf' }, {}));
        const h = await app.post({ act: 'home_data' }, {}).catch(() => null);
        if (h && h.media !== undefined) assert.ok(h.media_on);
    } finally {
        app.media.stop(); app.tools.stop();
        delete process.env.NEXUS_MEDIA_PS;
    }
});

test('1.10.3: echtes PowerShell-Skript der Mediensteuerung antwortet (nur Windows)', async () => {
    if (process.platform !== 'win32') return;
    const { Media } = require('../backend/media');
    const m = new Media({ mobile: false });
    try {
        m.start();
        for (let i = 0; i < 400 && !m.at && !m.error; i++) await new Promise(r => setTimeout(r, 100));
        console.log('   Mediensteuerung:', m.at ? 'antwortet, ' + m.sessions.length + ' Sitzung(en)' : 'Fehler: ' + m.error, m.stderr ? '| stderr: ' + m.stderr.slice(-200) : '');
        assert.ok(m.at || m.error, 'Das PowerShell-Skript hat in 40 s nichts gemeldet (hängt es?)');
        if (m.at) {   // Befehlsdatei wird abgeholt (blockiert nicht)
            m.control('pause');
            for (let i = 0; i < 50 && require('fs').existsSync(m.cmdFile); i++) await new Promise(r => setTimeout(r, 100));
            assert.ok(!require('fs').existsSync(m.cmdFile), 'Befehlsdatei wurde nicht abgeholt');
            const at = m.at;
            for (let i = 0; i < 60 && m.at === at; i++) await new Promise(r => setTimeout(r, 100));
            assert.ok(m.at > at, 'nach einem Befehl kommen weiter Meldungen');
        }
    } finally { m.stop(); }
});

test('1.10.7: Datenbank aufräumen – Vorgabe löscht nichts, Zeiträume greifen, offene Benachrichtigungen bleiben', async () => {
    const { NexusApp } = require('../backend/app');
    const db = await openDatabase(null);
    const app = new NexusApp({ db, crypt: { encrypt: s => s, decrypt: s => s }, host: { userData: os.tmpdir() } });
    app.game.osc.stopListen();
    const old = Math.floor(Date.now() / 1000) - 400 * 86400, fresh = Math.floor(Date.now() / 1000) - 3600;
    for (const ts of [old, fresh]) {
        db.prepare("INSERT INTO feed (user_id, ts, type, vrc_id) VALUES (1, ?, 'online', 'usr_x')").run(ts);
        db.prepare('INSERT INTO stats (user_id, ts, online, active, total) VALUES (1, ?, 1, 1, 1)').run(ts);
        db.prepare("INSERT INTO user_cache (vrc_id, data, fetched) VALUES (?, '{}', ?)").run('usr_' + ts, ts);
    }
    db.prepare("INSERT INTO notif_log (user_id, id, type, created, active) VALUES (1, 'not_a', 'invite', ?, 1)").run(old);
    db.prepare("INSERT INTO notif_log (user_id, id, type, created, active) VALUES (1, 'not_b', 'invite', ?, 0)").run(old);
    const rows = k => app.tools.dbInfo().tables.find(t => t.key === k).rows;
    let r = app.tools.cleanup(false);
    assert.strictEqual(rows('feed'), 2, 'Vorgabe „für immer“ löscht keinen Verlauf');
    assert.strictEqual(rows('cache'), 1, 'nur Zwischenspeicher älter als 30 Tage weg');
    app.settings.save({ 'sys.keep_feed': '365', 'sys.keep_stats': '365', 'sys.keep_notifs': '365' });
    r = app.tools.cleanup(true);
    assert.strictEqual(rows('feed'), 1); assert.strictEqual(rows('stats'), 1);
    assert.strictEqual(rows('notifs'), 1, 'offene Benachrichtigung bleibt');
    assert.ok(r.total >= 3 && /gelöscht/.test(r.msg));
    app.tools.stop();
});

test('1.7: Wörterbuch (EN/FR/JA) vollständig und Platzhalter stimmen', () => {
    const fs = require('fs'), path = require('path'), vm = require('vm');
    const file = path.join(__dirname, '..', 'renderer', 'js', 'i18n-dict.js');
    const box = { window: {} };
    vm.runInNewContext(fs.readFileSync(file, 'utf8'), box);
    const d = box.window.NexusDict;
    assert.ok(d && d.en && d.fr && d.ja, 'Wörterbuch fehlt');
    const keys = Object.keys(d.en);
    assert.ok(keys.length > 1000, 'zu wenige Einträge: ' + keys.length);
    for (const l of ['fr', 'ja']) assert.deepStrictEqual(Object.keys(d[l]).sort(), keys.slice().sort(), l + ': Einträge fehlen');
    const n = s => (s.match(/#/g) || []).length;
    for (const k of keys) for (const l of ['en', 'fr', 'ja']) assert.strictEqual(n(d[l][k]), n(k), l + ': Platzhalter in „' + k + '“');
    // Datei ist aktuell gebaut (tsv neuer als js → vergessen zu bauen)
    const tsv = fs.readdirSync(path.join(__dirname, '..', 'renderer', 'i18n')).map(f => fs.statSync(path.join(__dirname, '..', 'renderer', 'i18n', f)).mtimeMs);
    if (!process.env.CI) assert.ok(Math.max(...tsv) <= fs.statSync(file).mtimeMs + 1000, 'i18n-dict.js ist veraltet – node scripts/i18n-build.js ausführen');
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
