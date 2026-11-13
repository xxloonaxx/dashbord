'use strict';
/*
 * Kamera-Werkzeuge über die OSC-Schnittstelle der VRChat-Kamera („/usercamera/…“).
 * VRChat meldet den Zustand der Kamera auf dem Empfangsport (9001) zurück – daraus kommen Pose & Werte für Vorlagen.
 */
const { str, int, cut, sleep, UserError } = require('./util');

const MODES = { 0: 'Aus', 1: 'Foto', 2: 'Stream', 3: 'Emoji', 4: 'Mehrschicht', 5: 'Druck', 6: 'Drohne' };
/* Schieberegler: [Adresse, Bezeichnung, min, max, Schritt] */
const SLIDERS = [
    ['Zoom', 'Zoom', 20, 150, 1], ['Exposure', 'Belichtung', -10, 4, 0.1], ['FocalDistance', 'Fokus-Abstand', 0, 10, 0.05], ['Aperture', 'Blende', 1.4, 32, 0.1],
    ['Hue', 'Farbton', 0, 360, 1], ['Saturation', 'Sättigung', -100, 100, 1], ['Lightness', 'Helligkeit', -50, 50, 1],
    ['LookAtMeXOffset', 'Blick-Versatz X', -25, 25, 0.5], ['LookAtMeYOffset', 'Blick-Versatz Y', -25, 25, 0.5], ['FlySpeed', 'Fluggeschwindigkeit', 0.1, 15, 0.1], ['TurnSpeed', 'Drehgeschwindigkeit', 0.1, 5, 0.1],
    ['SmoothingStrength', 'Glättung', 0.1, 10, 0.1], ['PhotoRate', 'Fotorate', 0.1, 2, 0.05], ['Duration', 'Dauer', 0.1, 60, 0.1],
];
/* Schalter */
const TOGGLES = [
    ['ShowUIInCamera', 'UI im Bild'], ['Lock', 'Kamera sperren'], ['LocalPlayer', 'Mich zeigen'], ['RemotePlayer', 'Andere zeigen'], ['Environment', 'Umgebung'], ['GreenScreen', 'Greenscreen'],
    ['SmoothMovement', 'Weiche Bewegung'], ['LookAtMe', 'Schau mich an'], ['AutoLevelRoll', 'Auto-Horizont (Rollen)'], ['AutoLevelPitch', 'Auto-Horizont (Neigen)'], ['Flying', 'Fliegen'],
    ['TriggerTakesPhotos', 'Abzug löst aus'], ['DollyPathsStayVisible', 'Dolly-Pfade sichtbar'], ['AudioFromCamera', 'Ton von der Kamera'], ['ShowFocus', 'Fokus anzeigen'], ['Streaming', 'Streamen'],
    ['RollWhileFlying', 'Rollen beim Fliegen'], ['OrientationIsLandscape', 'Querformat'],
];
const ACTIONS = { Capture: 'Foto', CaptureDelayed: 'Foto mit Selbstauslöser', Close: 'Kamera schließen' };
const isName = n => /^[A-Za-z]{2,40}$/.test(n);

class Camera {
    constructor(app) { this.app = app; this.burstRun = null; }
    get osc() { return this.app.game.osc; }
    send(name, ...args) {
        if (!isName(name)) throw new UserError('Unbekannter Kamera-Befehl.');
        return this.osc.send('/usercamera/' + name, ...args);
    }
    state() {
        const c = this.osc.cam || {};
        return { modes: MODES, sliders: SLIDERS, toggles: TOGGLES, actions: ACTIONS, values: c, mode: c.Mode === undefined ? null : c.Mode, pose: c.Pose || null,
            osc_active: this.osc.active, presets: this.presets(), burst: this.burstRun ? { done: this.burstRun.done, count: this.burstRun.count } : null };
    }
    async cmd(input) {
        const name = str(input.cmd);
        if (name === 'Mode') { const m = int(input.value); if (!(m in MODES)) throw new UserError('Unbekannter Modus.'); await this.send('Mode', m); return 'Modus: ' + MODES[m]; }
        if (ACTIONS[name]) { await this.send(name, true); return ACTIONS[name]; }
        const sl = SLIDERS.find(s => s[0] === name);
        if (sl) { const v = Math.min(sl[3], Math.max(sl[2], Number(input.value) || 0)); await this.send(name, { float: v }); this.remember(name, v); return sl[1] + ': ' + v; }
        const tg = TOGGLES.find(t => t[0] === name);
        if (tg) { const v = input.value === true || input.value === '1' || input.value === 'true'; await this.send(name, v); this.remember(name, v); return tg[1] + (v ? ' an' : ' aus'); }
        if (name === 'Pose') { const p = this.pose(input.value); await this.send('Pose', ...p.map(f => ({ float: f }))); this.remember('Pose', p); return 'Pose gesetzt'; }
        throw new UserError('Unbekannter Kamera-Befehl.');
    }
    remember(k, v) { this.osc.cam = Object.assign(this.osc.cam || {}, { [k]: v }); }
    pose(v) {
        let p = v;
        if (typeof p === 'string') { try { p = JSON.parse(p); } catch (e) { p = p.split(/[,; ]+/); } }
        p = (Array.isArray(p) ? p : []).slice(0, 6).map(Number);
        if (p.length !== 6 || p.some(n => !Number.isFinite(n))) throw new UserError('Eine Pose braucht 6 Zahlen (Position X Y Z, Drehung X Y Z).');
        return p;
    }
    /** Serienbild: n Fotos im Abstand von s Sekunden (läuft im Hintergrund) */
    burst(count, seconds, delayFirst) {
        if (this.burstRun) throw new UserError('Es läuft schon eine Serie.');
        const n = Math.max(1, Math.min(100, int(count) || 3)), ms = Math.max(300, Math.min(600000, Math.round((Number(seconds) || 1) * 1000)));
        const run = this.burstRun = { count: n, done: 0, stop: false };
        (async () => {
            if (int(delayFirst) > 0) await sleep(Math.min(60, int(delayFirst)) * 1000);
            for (let i = 0; i < n && !run.stop; i++) { try { await this.send('Capture', true); } catch (e) { break; } run.done++; if (i < n - 1) await sleep(ms); }
        })().finally(() => { if (this.burstRun === run) this.burstRun = null; });
        return 'Serie gestartet: ' + n + ' Fotos alle ' + ms / 1000 + ' s';
    }
    stopBurst() { if (this.burstRun) this.burstRun.stop = true; return 'Serie gestoppt.'; }

    // ---------- Vorlagen (Pose + Werte) ----------
    presets() { try { return JSON.parse(this.app.db.setting('cam_presets', '[]')) || []; } catch (e) { return []; } }
    savePreset(name) {
        const c = this.osc.cam || {};
        const vals = {};
        for (const [k] of SLIDERS.concat(TOGGLES)) if (c[k] !== undefined) vals[k] = c[k];
        if (c.Pose) vals.Pose = c.Pose;
        if (c.Mode !== undefined) vals.Mode = c.Mode;
        if (!Object.keys(vals).length) throw new UserError('Noch keine Kamerawerte empfangen – Kamera in VRChat öffnen (OSC muss an sein) oder Werte hier einstellen.');
        const l = this.presets().filter(p => p.name !== cut(str(name).trim(), 40));
        l.push({ name: cut(str(name).trim(), 40) || 'Vorlage ' + (l.length + 1), vals, created: Math.floor(Date.now() / 1000) });
        this.app.db.setSetting('cam_presets', JSON.stringify(l.slice(-50)));
        return this.presets();
    }
    deletePreset(name) { this.app.db.setSetting('cam_presets', JSON.stringify(this.presets().filter(p => p.name !== str(name)))); return this.presets(); }
    async loadPreset(name) {
        const p = this.presets().find(x => x.name === str(name));
        if (!p) throw new UserError('Vorlage nicht gefunden.');
        if (p.vals.Mode !== undefined) await this.cmd({ cmd: 'Mode', value: p.vals.Mode });
        for (const [k, v] of Object.entries(p.vals)) {
            if (k === 'Mode') continue;
            await this.cmd({ cmd: k, value: k === 'Pose' ? v : typeof v === 'boolean' ? (v ? '1' : '') : v });
        }
        return 'Vorlage „' + p.name + '“ geladen.';
    }

    async action(act, input) {
        switch (act) {
            case 'cam_state': return this.state();
            case 'cam_cmd': return Object.assign({ msg: await this.cmd(input) }, this.state());
            case 'cam_burst': return Object.assign({ msg: this.burst(input.count, input.seconds, input.delay) }, this.state());
            case 'cam_burst_stop': return Object.assign({ msg: this.stopBurst() }, this.state());
            case 'cam_preset_save': this.savePreset(input.name); return Object.assign({ msg: 'Vorlage gespeichert.' }, this.state());
            case 'cam_preset_delete': this.deletePreset(input.name); return this.state();
            case 'cam_preset_load': return Object.assign({ msg: await this.loadPreset(input.name) }, this.state());
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { Camera, MODES, SLIDERS, TOGGLES };
