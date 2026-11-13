'use strict';
/*
 * OSC-Makros: kleine Abläufe, eine Zeile pro Schritt, z. B.
 *   chatbox Hallo {name}!/nSchön, dass du da bist
 *   param Ears_Wiggle = 1
 *   wait 1.5
 *   param Ears_Wiggle = 0
 *   input Jump
 *   cam Capture
 *   status busy Bin im Film
 *   avatar avtr_…
 *   rotator an
 *   typing an
 * Benutzt von: Stream Deck, Chat-Widgets/Automatik, Overlay, Fernbedienung.
 */
const { str, int, cut, sleep, UserError } = require('./util');

const KINDS = ['webhook', 'chatbox', 'param', 'toggle', 'wait', 'input', 'cam', 'status', 'avatar', 'rotator', 'typing', 'speak', 'notify'];
const yes = v => /^(1|an|on|ja|true|ein)$/i.test(str(v).trim());

function parse(script) {
    const steps = [];
    for (const raw of str(script).replace(/\r/g, '').split('\n')) {
        const line = raw.trim();
        if (!line || line.startsWith('#') || line.startsWith('//')) continue;
        const m = /^(\w+)\s*(.*)$/.exec(line);
        const t = m[1].toLowerCase(), rest = m[2].trim();
        if (!KINDS.includes(t)) throw new UserError('Unbekannter Schritt „' + t + '“ – erlaubt: ' + KINDS.join(', '));
        if (t === 'param') {
            const p = /^([^=\s]+)\s*=?\s*(.*)$/.exec(rest);
            if (!p) throw new UserError('param braucht „Name = Wert“.');
            const v = p[2].trim().replace(',', '.');
            const type = /^(true|false|an|aus|on|off)$/i.test(v) ? 'bool' : /^-?\d+$/.test(v) ? (v === '0' || v === '1' ? 'auto' : 'int') : 'float';
            steps.push({ t, name: p[1], value: v, type });
        } else if (t === 'wait') steps.push({ t, s: Math.max(0, Math.min(600, Number(rest.replace(',', '.')) || 1)) });
        else if (t === 'cam') { const [cmd, ...v] = rest.split(/\s+/); steps.push({ t, cmd, value: v.join(' ') }); }
        else if (t === 'status') { const [s, ...d] = rest.split(/\s+/); steps.push({ t, status: s.replace(/_/g, ' ').toLowerCase(), desc: d.join(' ') }); }
        else steps.push({ t, arg: rest });
        if (steps.length > 200) throw new UserError('Höchstens 200 Schritte.');
    }
    return steps;
}

class Macros {
    constructor(app) { this.app = app; this.running = new Map(); }
    list() { try { return JSON.parse(this.app.db.setting('macros', '[]')) || []; } catch (e) { return []; } }
    save(name, script, id) {
        name = cut(str(name).trim(), 40);
        if (!name) throw new UserError('Name fehlt.');
        parse(script);                         // prüft die Schreibweise
        const l = this.list();
        const ex = l.find(m => m.id === str(id));
        if (ex) Object.assign(ex, { name, script: cut(str(script), 6000) });
        else l.push({ id: 'm' + Date.now().toString(36), name, script: cut(str(script), 6000) });
        this.app.db.setSetting('macros', JSON.stringify(l.slice(-100)));
        return this.list();
    }
    remove(id) { this.app.db.setSetting('macros', JSON.stringify(this.list().filter(m => m.id !== str(id)))); return this.list(); }
    find(idOrName) { const k = str(idOrName); return this.list().find(m => m.id === k || m.name.toLowerCase() === k.toLowerCase()) || null; }

    /** führt ein Makro aus (Name/ID oder Skript); vars ergänzen die Platzhalter */
    async run(idOrScript, vars = {}) {
        const m = this.find(idOrScript);
        const steps = parse(m ? m.script : idOrScript);
        const key = m ? m.id : 'adhoc';
        if (this.running.has(key)) this.running.get(key).stop = true;   // dasselbe Makro neu starten
        const run = { stop: false };
        this.running.set(key, run);
        const g = this.app.game;
        let n = 0;
        try {
            for (const s of steps) {
                if (run.stop) break;
                switch (s.t) {
                    case 'chatbox': await g.oscSend('chatbox', { text: s.arg, sound: '', vars }); break;
                    case 'typing': await g.oscSend('typing', { on: yes(s.arg) ? '1' : '' }); break;
                    case 'param': {
                        const v = s.value;
                        const type = s.type === 'auto' ? (typeof g.osc.params[s.name] === 'boolean' ? 'bool' : 'int') : s.type;
                        await g.oscSend('param', { name: s.name, type, value: type === 'bool' ? (yes(v) ? '1' : '') : v });
                        break;
                    }
                    case 'toggle': { const cur = g.osc.params[s.arg]; await g.oscSend('param', { name: s.arg, type: 'bool', value: cur === true || cur === 1 ? '' : '1' }); g.osc.params[s.arg] = !(cur === true || cur === 1); break; }
                    case 'wait': await sleep(s.s * 1000); break;
                    case 'input': await g.oscSend('input', { name: s.arg.split(/\s+/)[0] || 'Jump' }); break;
                    case 'cam': await this.app.cam.cmd({ cmd: s.cmd, value: s.value || (['Capture', 'CaptureDelayed', 'Close'].includes(s.cmd) ? '' : s.value) }); break;
                    case 'status': await this.app.tools.setStatus(s.status, s.desc, 'manual'); break;
                    case 'avatar': await this.app.tools.call('PUT', '/avatars/' + encodeURIComponent(s.arg.trim()) + '/select'); break;
                    case 'rotator': g.saveCfg({ rotator_enabled: yes(s.arg) ? '1' : '0' }); break;
                    case 'speak': if (this.app.tools.host.speak) this.app.tools.host.speak(g.fill(s.arg, vars)); break;
                    case 'webhook': {
                        // webhook https://… [Text] → POST JSON (z. B. Home Assistant, IFTTT, Node-RED)
                        const [url, ...rest] = s.arg.split(/\s+/);
                        if (!/^https?:\/\/[^\s]+$/i.test(url)) throw new UserError('webhook braucht eine http(s)-Adresse.');
                        const st = g.state;
                        await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: AbortSignal.timeout(10000),
                            body: JSON.stringify({ source: 'vrc-nexus', text: g.fill(rest.join(' '), vars), world: st.worldName || '', players: st.players.size, running: !!st.running, vars }) }).catch(e => { throw new UserError('Webhook: ' + e.message); });
                        break;
                    }
                    case 'notify': this.app.tools.alert('VRC Nexus', g.fill(s.arg, vars)); break;
                }
                n++;
            }
        } finally { if (this.running.get(key) === run) this.running.delete(key); }
        return { steps: n, name: m ? m.name : '' };
    }
    stop(id) { for (const [k, r] of this.running) if (!id || k === id) r.stop = true; }
}

module.exports = { Macros, parse, KINDS };
