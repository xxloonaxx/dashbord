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
        // neue Tasten (1.3): Kamera, Makro, Benachrichtigungen, Freund, Drehregler
        const st = await (await fetch('http://127.0.0.1:' + apiPort + '/api/state', { headers: { 'X-Nexus-Token': token } })).json();
        const fid = (st.friend_list.find(f => f.state === 'online') || st.friend_list[0] || {}).id;
        const nk = { camera: ['ctxCam', { cmd: 'Capture' }], macro: ['ctxM', { script: 'param Tail = 1\nwait 0.2\nparam Tail = 0' }], notifs: ['ctxN', {}], friend: ['ctxFr', { id: fid, op: 'join' }] };
        for (const [k, [ctx, se]] of Object.entries(nk)) tx({ event: 'willAppear', action: 'de.ghostnode.vrcnexus.' + k, context: ctx, payload: { settings: se } });
        const nt = await wait(m => m.event === 'setTitle' && m.context === 'ctxN');
        const ft = await wait(m => m.event === 'setTitle' && m.context === 'ctxFr');
        console.log('Taste Benachrichtigungen:', JSON.stringify(nt.payload.title), '| Taste Freund:', JSON.stringify(ft.payload.title));
        for (const [k, [ctx, se]] of Object.entries(nk)) {
            const n = got.length;
            tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.' + k, context: ctx, payload: { settings: se } });
            tx({ event: 'keyUp', action: 'de.ghostnode.vrcnexus.' + k, context: ctx, payload: { settings: se } });
            const r = await wait(m => got.indexOf(m) >= n && m.context === ctx && ['showOk', 'showAlert', 'setState'].includes(m.event));
            console.log('Taste ' + k.padEnd(8), r.event + (r.payload ? ' ' + JSON.stringify(r.payload) : ''));
            assert.notStrictEqual(r.event, 'showAlert');
        }
        // 1.7.9: Freund-Taste – Profilbild als Symbol, kurz = beitreten/anfragen, halten = zu mir einladen
        const img = await wait(m => m.event === 'setImage' && m.context === 'ctxFr');
        const svg = Buffer.from(img.payload.image.split(',')[1], 'base64').toString();
        assert.ok(/^data:image\/svg\+xml;base64,/.test(img.payload.image) && svg.includes('<image') && /data:image\/(png|jpe?g|webp|gif);base64,/.test(svg), 'Profilbild fehlt im Symbol');
        console.log('Taste Freund: Profilbild-Symbol', Math.round(img.payload.image.length / 1024) + ' KB');
        let n2 = got.length;
        const fs2 = { id: fid, op: 'smart', hold_op: 'invite' };
        tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.friend', context: 'ctxFr', payload: { settings: fs2 } });
        await sleep(750);
        tx({ event: 'keyUp', action: 'de.ghostnode.vrcnexus.friend', context: 'ctxFr', payload: { settings: fs2 } });
        let r = await wait(m => got.indexOf(m) >= n2 && m.context === 'ctxFr' && ['showOk', 'showAlert'].includes(m.event));
        console.log('Freund halten (zu mir einladen):', r.event);
        assert.strictEqual(r.event, 'showOk');
        n2 = got.length;
        tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.friend', context: 'ctxFr', payload: { settings: fs2 } });
        tx({ event: 'keyUp', action: 'de.ghostnode.vrcnexus.friend', context: 'ctxFr', payload: { settings: fs2 } });
        r = await wait(m => got.indexOf(m) >= n2 && m.context === 'ctxFr' && ['showOk', 'showAlert'].includes(m.event));
        console.log('Freund kurz (beitreten/anfragen):', r.event);
        assert.strictEqual(r.event, 'showOk');
        tx({ event: 'willAppear', action: 'de.ghostnode.vrcnexus.dial', context: 'ctxD', payload: { settings: { target: 'param', name: 'Hue', type: 'float' } } });
        tx({ event: 'dialRotate', action: 'de.ghostnode.vrcnexus.dial', context: 'ctxD', payload: { settings: { target: 'param', name: 'Hue', type: 'float', step: 0.1 }, ticks: 3 } });
        const fb = await wait(m => m.event === 'setFeedback' && m.context === 'ctxD' && m.payload.value === '0.3');
        console.log('Drehregler Hue nach 3 Rasten:', JSON.stringify(fb.payload));
        // 2.3: Medien, Schnelltext, Chatbox-Module, Info-Anzeige
        const st2 = await (await fetch('http://127.0.0.1:' + apiPort + '/api/state', { headers: { 'X-Nexus-Token': token } })).json();
        const qid = st2.quick && st2.quick.list[0] ? st2.quick.list[0].id : '';
        const nk2 = { media: ['ctxMe', { cmd: 'toggle' }], quicktext: ['ctxQ', { id: qid }], chatmods: ['ctxCm', { mod: 'countdown' }], info: ['ctxIn', { text: '🕒 {zeit}/n👥 {freunde_im_spiel}' }] };
        for (const [k, [ctx, se]] of Object.entries(nk2)) tx({ event: 'willAppear', action: 'de.ghostnode.vrcnexus.' + k, context: ctx, payload: { settings: se } });
        if (st2.media) {
            const mt = await wait(m => m.event === 'setTitle' && m.context === 'ctxMe' && m.payload.title !== '–');
            console.log('Taste Medien:', JSON.stringify(mt.payload.title));
        }
        const it = await wait(m => m.event === 'setTitle' && m.context === 'ctxIn' && /\d/.test(m.payload.title));
        console.log('Taste Info-Anzeige:', JSON.stringify(it.payload.title));
        assert.ok(!/\{/.test(it.payload.title), 'Platzhalter ausgefüllt');
        const ct = await wait(m => m.event === 'setTitle' && m.context === 'ctxCm');
        console.log('Taste Chatbox-Module:', JSON.stringify(ct.payload.title));
        for (const [k, [ctx, se]] of Object.entries(nk2)) {
            if (k === 'info' || (k === 'quicktext' && !qid) || (k === 'media' && !st2.media)) continue;
            const n = got.length;
            tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.' + k, context: ctx, payload: { settings: se } });
            const r = await wait(m => got.indexOf(m) >= n && m.context === ctx && ['showOk', 'showAlert', 'setState'].includes(m.event));
            console.log('Taste ' + k.padEnd(9), r.event + (r.payload ? ' ' + JSON.stringify(r.payload) : ''));
            assert.notStrictEqual(r.event, 'showAlert');
        }
        // Medien: nach ⏯ meldet die Taste „pausiert“ (State 0), danach wieder abspielen
        if (st2.media) {
            await sleep(1500);
            const st3 = await (await fetch('http://127.0.0.1:' + apiPort + '/api/state', { headers: { 'X-Nexus-Token': token } })).json();
            console.log('Medien nach ⏯:', st3.media && st3.media.status);
            assert.strictEqual(st3.media && st3.media.status, 'paused');
            tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.media', context: 'ctxMe', payload: { settings: { cmd: 'toggle' } } });
        }
        // Chatbox-Modul wieder aus
        tx({ event: 'keyDown', action: 'de.ghostnode.vrcnexus.chatmods', context: 'ctxCm', payload: { settings: { mod: 'countdown' } } });
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
