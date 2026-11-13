'use strict';
/*
 * SteamVR-Overlay (nur Electron/Windows):
 *  - zeichnet renderer/overlay.html unsichtbar (Offscreen-Rendering, 1024 × 640)
 *  - schickt jedes neue Bild an NexusOverlay.exe (OpenVR) → eigener Reiter im SteamVR-Dashboard + Handgelenk/frei im Raum
 *  - Laser-Klicks/Scrollen aus SteamVR kommen als Maus-Ereignisse zurück
 * Startet automatisch, sobald SteamVR (vrserver.exe) läuft und das Overlay eingeschaltet ist.
 */
const { BrowserWindow } = require('electron');
const { spawn, execFile } = require('child_process');
const fs = require('fs');
const path = require('path');

const W = 1024, H = 640;

class VrOverlay {
    constructor(opts) {
        this.exe = opts.exe;             // Pfad zu NexusOverlay.exe
        this.icon = opts.icon;           // Vorschaubild für den Dashboard-Reiter
        this.url = opts.url;             // app://nexus/overlay.html
        this.notify = opts.notify || (() => {});
        this.cfg = {};
        this.proc = null;
        this.win = null;
        this.visible = false;
        this.status = 'aus';
        this.lastFrame = 0;
        this.pendingFrame = null;
        this.watch = null;
        this.failures = 0;
    }
    state() {
        const li = this.lastInput;
        return { enabled: !!this.cfg['vr.overlay'], running: !!this.proc, visible: this.visible, mode: this.cfg['vr.overlay_mode'] || 'wrist',
            status: this.status + (li ? ' · letzte Laser-Eingabe ' + li.t + ' bei ' + li.x + ',' + li.y + ' (' + Math.round((Date.now() - li.at) / 1000) + ' s her)' : this.proc ? ' · noch keine Laser-Eingabe empfangen' : '') };
    }

    apply(s) {
        this.cfg = s;
        clearInterval(this.watch);
        if (!s['vr.overlay']) { this.stop(); this.status = 'aus'; return; }
        if (!fs.existsSync(this.exe)) { this.status = 'NexusOverlay.exe fehlt (nur im Windows-Build enthalten)'; return; }
        // alle 15 s schauen, ob SteamVR läuft
        const check = () => this.steamVrRunning().then(on => { if (on && !this.proc) this.start(); if (!on && this.proc) this.stop(); if (!on) this.status = 'wartet auf SteamVR'; });
        check();
        this.watch = setInterval(check, 15000);
        if (this.proc) this.sendLayout();
        if (this.win) this.win.webContents.setFrameRate(Math.max(1, Math.min(30, Number(s['vr.overlay_fps']) || 10)));
    }
    steamVrRunning() {
        if (process.platform !== 'win32') return Promise.resolve(false);
        return new Promise(r => execFile('tasklist', ['/fi', 'imagename eq vrserver.exe', '/fo', 'csv', '/nh'], { windowsHide: true, timeout: 8000 }, (e, out) => r(!e && /vrserver\.exe/i.test(String(out)))));
    }

    start() {
        if (this.proc || this.failures > 5) return;
        this.status = 'startet …';
        const p = spawn(this.exe, [this.icon], { stdio: ['pipe', 'pipe', 'ignore'], windowsHide: true, cwd: path.dirname(this.exe) });
        this.proc = p;
        let buf = '';
        p.stdout.setEncoding('utf8');
        p.stdout.on('data', d => {
            buf += d;
            let i;
            while ((i = buf.indexOf('\n')) >= 0) { const line = buf.slice(0, i).trim(); buf = buf.slice(i + 1); if (line) this.onEvent(line); }
        });
        p.stdin.on('error', () => {});
        p.on('exit', code => {
            if (this.proc === p) this.proc = null;
            this.visible = false;
            if (code && code !== 0) { this.failures++; this.status = this.status.startsWith('⚠') ? this.status : '⚠ beendet (Code ' + code + ')'; }
            else this.status = 'SteamVR beendet';
            this.closeWindow();
        });
        this.openWindow();
    }
    stop() {
        if (this.proc) { try { this.cmd({ cmd: 'quit' }); this.proc.stdin.end(); } catch (e) { /* egal */ } const p = this.proc; setTimeout(() => { try { p.kill(); } catch (e) { /* egal */ } }, 1500); this.proc = null; }
        this.visible = false;
        this.closeWindow();
    }
    openWindow() {
        if (this.win) return;
        this.win = new BrowserWindow({ width: W, height: H, show: false, frame: false, useContentSize: true,
            webPreferences: { offscreen: true, contextIsolation: true, sandbox: true, nodeIntegration: false, backgroundThrottling: false } });
        this.win.webContents.setFrameRate(Math.max(1, Math.min(30, Number(this.cfg['vr.overlay_fps']) || 10)));
        this.win.webContents.on('paint', (e, dirty, image) => this.frame(image));
        this.win.loadURL(this.url);
    }
    closeWindow() { if (this.win) { try { this.win.destroy(); } catch (e) { /* egal */ } this.win = null; } }

    /** Bild an den Helfer: [1][Länge][Breite][Höhe][BGRA …] – höchstens so oft wie eingestellt */
    frame(image) {
        if (!this.proc) return;
        const minGap = 1000 / Math.max(1, Math.min(30, Number(this.cfg['vr.overlay_fps']) || 10));
        const now = Date.now();
        if (now - this.lastFrame < minGap) { this.pendingFrame = image; if (!this.frameTimer) this.frameTimer = setTimeout(() => { this.frameTimer = null; const im = this.pendingFrame; this.pendingFrame = null; if (im) this.frame(im); }, minGap); return; }
        this.lastFrame = now;
        const { width, height } = image.getSize();
        const px = image.toBitmap();
        if (!width || px.length !== width * height * 4) return;
        const head = Buffer.alloc(13);
        head[0] = 1;
        head.writeUInt32LE(px.length + 8, 1);
        head.writeUInt32LE(width, 5);
        head.writeUInt32LE(height, 9);
        if (this.proc.stdin.writableLength > 8 * 1024 * 1024) return;   // Helfer kommt nicht hinterher → Bild auslassen
        this.proc.stdin.write(head);
        this.proc.stdin.write(px);
    }
    cmd(obj) {
        if (!this.proc) return;
        const b = Buffer.from(JSON.stringify(obj), 'utf8');
        const head = Buffer.alloc(5);
        head[0] = 2;
        head.writeUInt32LE(b.length, 1);
        this.proc.stdin.write(Buffer.concat([head, b]));
    }
    sendLayout() {
        const s = this.cfg;
        this.cmd({ cmd: 'mode', mode: s['vr.overlay_mode'] || 'wrist', hand: s['vr.overlay_hand'] || 'left', width: (Number(s['vr.overlay_width']) || 30) / 100,
            alpha: (Number(s['vr.overlay_alpha']) || 95) / 100, distance: (Number(s['vr.overlay_distance']) || 120) / 100 });
    }
    onEvent(line) {
        let m;
        try { m = JSON.parse(line); } catch (e) { return; }
        const wc = this.win && this.win.webContents;
        switch (m.ev) {
            case 'ready':
                this.status = 'läuft';
                this.failures = 0;
                this.sendLayout();
                if (this.cfg['vr.overlay_show'] !== false && (this.cfg['vr.overlay_mode'] || 'wrist') !== 'off') this.cmd({ cmd: 'show' });
                if (wc) wc.invalidate();
                break;
            case 'error': this.status = '⚠ ' + m.msg; break;
            case 'visible': this.visible = !!m.v; break;
            case 'quit': this.status = 'SteamVR beendet'; break;
            case 'mouse':
                if (m.t !== 'move' || !this.lastInput || Date.now() - this.lastInput.at > 1000) this.lastInput = { t: m.t === 'move' ? 'Bewegung' : m.t === 'down' ? 'Klick' : 'Loslassen', x: m.x, y: m.y, at: Date.now() };
                if (!wc) return;
                // Koordinaten auf die Seite begrenzen (falls SteamVR doch 0…1 liefert: hochrechnen)
                if (m.x <= 1 && m.y <= 1 && m.x >= 0 && m.y >= 0 && (m.x % 1 || m.y % 1)) { m.x = Math.round(m.x * W); m.y = Math.round(m.y * H); }
                m.x = Math.max(0, Math.min(W - 1, Math.round(m.x))); m.y = Math.max(0, Math.min(H - 1, Math.round(m.y)));
                if (m.t === 'move') wc.sendInputEvent({ type: 'mouseMove', x: m.x, y: m.y });
                else {
                    if (m.t === 'down') { try { wc.focus(); } catch (e) { /* egal */ } wc.sendInputEvent({ type: 'mouseMove', x: m.x, y: m.y }); }
                    wc.sendInputEvent({ type: m.t === 'down' ? 'mouseDown' : 'mouseUp', x: m.x, y: m.y, button: 'left', clickCount: 1 });
                }
                break;
            case 'scroll': if (wc) wc.sendInputEvent({ type: 'mouseWheel', x: W / 2, y: H / 2, deltaX: 0, deltaY: Math.round(m.dy * 120), canScroll: true }); break;
        }
    }
    /** an/aus (on undefined = umschalten) – für Stream Deck, Startseite, Overlay selbst */
    toggle(on) {
        if (!this.proc) throw new Error(this.cfg['vr.overlay'] ? 'Das Overlay läuft noch nicht (' + this.status + ').' : 'Das SteamVR-Overlay ist aus (Einstellungen → VR).');
        const show = on === undefined || on === null ? !this.visible : !!on && on !== '0';
        this.cmd({ cmd: show ? 'show' : 'hide' });
        this.visible = show;
        return { msg: show ? 'Overlay eingeblendet.' : 'Overlay ausgeblendet.', visible: show };
    }
}

module.exports = { VrOverlay };
