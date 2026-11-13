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
    assert.strictEqual(db.prepare('PRAGMA user_version').value(), 7);
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

(async () => {
    let ok = 0;
    for (const [name, fn] of tests) {
        try { await fn(); ok++; console.log('✔', name); }
        catch (e) { console.log('✖', name, '\n  ', e.message); process.exitCode = 1; }
    }
    console.log(ok + '/' + tests.length + ' Tests bestanden');
    process.exit(process.exitCode || 0);
})();
