'use strict';
/*
 * Stream-Deck-Plugin gegen eine nachgebaute Stream-Deck-App testen.
 *   node tests/streamdeck.test.js <port der lokalen Schnittstelle> <token>
 * (VRC Nexus bzw. tests/server.js muss mit eingeschalteter lokaler Schnittstelle laufen.)
 * Mit NEXUS_MINIWS=1 wird der eingebaute WebSocket-Client statt des globalen benutzt.
 */
const { WebSocketServer } = require('ws');
const { spawn } = require('child_process');
const path = require('path');
const assert = require('assert');

const [apiPort, token] = process.argv.slice(2);
const PLUGIN = path.join(__dirname, '..', 'streamdeck', 'de.ghostnode.vrcnexus.sdPlugin', 'bin', 'plugin.js');
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
    const wss = new WebSocketServer({ port: 0, host: '127.0.0.1' });
    await new Promise(r => wss.on('listening', r));
    const got = [];
    let sock = null;
    wss.on('connection', s => { sock = s; s.on('message', m => got.push(JSON.parse(m))); });
    const child = spawn(process.execPath, [PLUGIN, '-port', String(wss.address().port), '-pluginUUID', 'PLUGINUUID', '-registerEvent', 'registerPlugin', '-info', '{}'], { stdio: 'inherit', env: process.env });
    const tx = o => sock.send(JSON.stringify(o));
    const wait = async (fn, ms = 6000) => { const t = Date.now(); while (Date.now() - t < ms) { const x = got.find(fn); if (x) return x; await sleep(50); } throw new Error('Zeitüberschreitung'); };
    try {
        await wait(m => m.event === 'registerPlugin');
        assert.strictEqual(got[0].uuid, 'PLUGINUUID');
        await wait(m => m.event === 'getGlobalSettings');
        tx({ event: 'didReceiveGlobalSettings', payload: { settings: { port: Number(apiPort), token } } });
        const keys = { friends: 'ctxF', players: 'ctxP', status: 'ctxS', rotator: 'ctxR', chatbox: 'ctxC', param: 'ctxPa', input: 'ctxI', open: 'ctxO' };
        const settings = { friends: { show: 'both' }, status: { status: 'busy' }, chatbox: { text: 'Hallo vom Stream Deck 🦊' }, param: { name: 'Tail', mode: 'toggle' }, input: { input: 'Jump' }, open: { tab: 'players' } };
        for (const [k, ctx] of Object.entries(keys)) tx({ event: 'willAppear', action: 'de.ghostnode.vrcnexus.' + k, context: ctx, payload: { settings: settings[k] || {} } });
        const fr = await wait(m => m.event === 'setTitle' && m.context === 'ctxF' && /\//.test(m.payload.title));
        const pl = await wait(m => m.event === 'setTitle' && m.context === 'ctxP' && /Spieler|aus/.test(m.payload.title));
        console.log('Taste Freunde:', JSON.stringify(fr.payload.title), '| Taste Spieler:', JSON.stringify(pl.payload.title));
        for (const k of ['chatbox', 'param', 'input', 'rotator', 'status', 'open']) {
            const n = got.length;
            tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.' + k, context: keys[k], payload: { settings: settings[k] || {} } });
            tx({ event: 'keyUp', action: 'de.ghostnode.vrcnexus.' + k, context: keys[k], payload: { settings: settings[k] || {} } });
            const r = await wait(m => got.indexOf(m) >= n && m.context === keys[k] && ['showOk', 'showAlert', 'setState'].includes(m.event));
            console.log('Taste ' + k.padEnd(8), r.event + (r.payload ? ' ' + JSON.stringify(r.payload) : ''));
            assert.notStrictEqual(r.event, 'showAlert');
        }
        // Rotator wieder aus
        tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.rotator', context: 'ctxR', payload: { settings: {} } });
        // Test-Knopf der Einstellungsseite
        tx({ event: 'sendToPlugin', action: 'de.ghostnode.vrcnexus.chatbox', context: 'PIUUID', payload: { cmd: 'test' } });
        const t = await wait(m => m.event === 'sendToPropertyInspector');
        assert.ok(t.payload.ok);
        // falscher Schlüssel → Fehlermeldung
        tx({ event: 'didReceiveGlobalSettings', payload: { settings: { port: Number(apiPort), token: 'falsch' } } });
        await sleep(300);
        const n = got.length;
        tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.chatbox', context: 'ctxC', payload: { settings: settings.chatbox } });
        const bad = await wait(m => got.indexOf(m) >= n && m.event === 'showAlert');
        const log = await wait(m => got.indexOf(m) >= n && m.event === 'logMessage');
        console.log('falscher Schlüssel:', bad.event, '–', log.payload.message);
        console.log('✔ Stream-Deck-Plugin funktioniert' + (process.env.NEXUS_MINIWS ? ' (eingebauter WebSocket-Client)' : ''));
    } catch (e) {
        console.log('✖', e.message); process.exitCode = 1;
    } finally {
        child.kill(); wss.close();
    }
})();
