'use strict';
/*
 * VRC Nexus Desktop – eigenständige Windows-App (ohne Server).
 * Die Oberfläche läuft unter app://nexus/, alle Anfragen an „nexus.php“ beantwortet das eingebaute Backend (backend/app.js).
 * Läuft im Infobereich weiter (Verlauf, Statistik, Spiel-Log & OSC zeichnen dann weiter auf).
 */
const { app, BrowserWindow, Menu, Tray, Notification, protocol, shell, safeStorage, nativeImage, dialog, globalShortcut } = require('electron');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { openDatabase } = require('./backend/db');
const { NexusApp, handleWebRequest } = require('./backend/app');

const RENDERER = path.join(__dirname, 'renderer');
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.ico': 'image/x-icon', '.svg': 'image/svg+xml' };

protocol.registerSchemesAsPrivileged([{ scheme: 'app', privileges: { standard: true, secure: true, supportFetchAPI: true, stream: true } }]);
if (!app.requestSingleInstanceLock()) { app.quit(); process.exit(0); }
app.setAppUserModelId('de.ghostnode.vrcnexus');

// Einstellungen, die vor dem Start gelten müssen (Spiegel der Datenbank-Einstellungen)
const PREFS_FILE = path.join(app.getPath('userData'), 'prefs.json');
let prefs = {};
try { prefs = JSON.parse(fs.readFileSync(PREFS_FILE, 'utf8')); } catch (e) { prefs = {}; }
if (prefs['sys.hw_accel'] === false) app.disableHardwareAcceleration();

let win = null, tray = null, nexus = null, db = null, quitting = false, vr = null;
const { VrOverlay } = require('./vroverlay');

/** Sitzungs-Cookie verschlüsseln: Windows-Schlüsselspeicher (DPAPI) über Electron safeStorage */
const crypt = {
    encrypt: s => (safeStorage.isEncryptionAvailable() ? 'dpapi:' + safeStorage.encryptString(s).toString('base64') : 'plain:' + Buffer.from(s).toString('base64')),
    decrypt: s => {
        if (s.startsWith('dpapi:')) return safeStorage.decryptString(Buffer.from(s.slice(6), 'base64'));
        if (s.startsWith('plain:')) return Buffer.from(s.slice(6), 'base64').toString();
        return '{}';
    },
};

function notify(title, body, silent) {
    if (Notification.isSupported()) new Notification({ title, body, silent: !!silent, icon: path.join(RENDERER, 'icon.png') }).show();
}

/** Hilfsfunktionen für backend/tools.js */
const host = {
    userData: app.getPath('userData'),
    pluginFile: app.isPackaged ? path.join(process.resourcesPath, 'VRC-Nexus.streamDeckPlugin') : path.join(__dirname, 'streamdeck', 'VRC-Nexus.streamDeckPlugin'),
    openPath: p => shell.openPath(p),
    openExternal: url => shell.openExternal(url),
    setLoginItem: on => { if (app.isPackaged) app.setLoginItemSettings({ openAtLogin: on, args: ['--minimized'] }); },
    spawn: (exe, args) => spawn(exe, args, { detached: true, stdio: 'ignore', cwd: path.dirname(exe) }).unref(),
    speak: text => { if (win) win.webContents.executeJavaScript('window.speechSynthesis && speechSynthesis.speak(Object.assign(new SpeechSynthesisUtterance(' + JSON.stringify(String(text).slice(0, 300)) + '), { lang: "de-DE" }))').catch(() => {}); },
    showTab: tab => { if (!win) return; win.show(); win.focus(); if (tab) win.webContents.executeJavaScript('window.NexusUI && NexusUI.tab(' + JSON.stringify(tab) + ')').catch(() => {}); },
    setTray: text => { if (tray) tray.setToolTip(text); },
    thumbnail: async file => (await nativeImage.createThumbnailFromPath(file, { width: 360, height: 220 })).toJPEG(80),
    /** Update installieren: Installer still ausführen bzw. portable EXE ersetzen, dann neu starten */
    installUpdate: async (assets, progress) => {
        const portable = process.env.PORTABLE_EXECUTABLE_FILE;
        const a = portable ? assets.portable : assets.setup;
        if (!a) throw new Error('Im Release fehlt die passende Datei (' + (portable ? 'portable' : 'Setup') + ').');
        const res = await fetch(a.url, { redirect: 'follow' });
        if (!res.ok || !res.body) throw new Error('Download fehlgeschlagen (HTTP ' + res.status + ').');
        const file = path.join(app.getPath('temp'), a.name);
        const out = fs.createWriteStream(file);
        let got = 0;
        for await (const chunk of res.body) {
            got += chunk.length;
            if (!out.write(chunk)) await new Promise(r => out.once('drain', r));
            if (a.size) progress(Math.round(got / a.size * 100) + ' %');
        }
        await new Promise(r => out.end(r));
        if (a.size && got !== a.size) throw new Error('Download unvollständig – bitte erneut versuchen.');
        if (portable) {
            // die laufende EXE kann sich nicht selbst ersetzen → kleines Skript wartet, kopiert und startet neu
            const cmd = path.join(app.getPath('temp'), 'vrc-nexus-update.cmd');
            fs.writeFileSync(cmd, ['@echo off', 'set n=0', ':retry', 'ping 127.0.0.1 -n 2 > nul', 'copy /y "' + file + '" "' + portable + '" > nul',
                'if errorlevel 1 (set /a n+=1 & if %n% lss 30 goto retry)', 'start "" "' + portable + '"', 'del "' + file + '"'].join('\r\n'));
            spawn('cmd.exe', ['/c', cmd], { detached: true, stdio: 'ignore', windowsHide: true }).unref();
        } else {
            spawn(file, ['/S', '--force-run'], { detached: true, stdio: 'ignore' }).unref();
        }
        setTimeout(() => { quitting = true; app.quit(); }, 1200);
    },
    /** Spracheingabe: Aufnahme in der Oberfläche starten/stoppen */
    speechCmd: op => { if (win) win.webContents.executeJavaScript('window.NexusSpeech && NexusSpeech.cmd(' + JSON.stringify(op) + ')').catch(() => {}); },
    /** globale Taste für die Spracheingabe (auch wenn VRChat im Vordergrund ist) */
    applySpeech: cfg => {
        if (host.speechKey) { try { globalShortcut.unregister(host.speechKey); } catch (e) { /* egal */ } host.speechKey = ''; }
        if (!cfg || !cfg.enabled || !cfg.hotkey) return;
        try { if (globalShortcut.register(cfg.hotkey, () => { try { nexus.speech.ptt('toggle'); } catch (e) { notify('VRC Nexus', e.message); } })) host.speechKey = cfg.hotkey; }
        catch (e) { notify('VRC Nexus', 'Tastenkürzel „' + cfg.hotkey + '“ ungültig: ' + e.message); }
    },
    overlayState: () => (vr ? vr.state() : null),
    overlayToggle: async on => { if (!vr) throw new Error('Overlay nicht verfügbar.'); return vr.toggle(on); },
    applySettings: (changed, s) => {
        if (vr && (changed.includes('*') || changed.some(k => k.startsWith('vr.overlay')))) vr.apply(s);
        prefs = { 'sys.hw_accel': s['sys.hw_accel'], 'sys.close_to_tray': s['sys.close_to_tray'], 'sys.start_minimized': s['sys.start_minimized'], 'adv.devtools': s['adv.devtools'] };
        try { fs.writeFileSync(PREFS_FILE, JSON.stringify(prefs)); } catch (e) { /* egal */ }
        if (tray && changed.includes('sys.autostart')) tray.setContextMenu(trayMenu());
    },
};

function createWindow() {
    win = new BrowserWindow({
        width: 1380, height: 900, minWidth: 900, minHeight: 600, backgroundColor: '#040609', title: 'VRC Nexus', icon: path.join(RENDERER, 'icon.png'),
        autoHideMenuBar: true, show: !(process.argv.includes('--minimized') && prefs['sys.start_minimized'] !== false),
        webPreferences: { contextIsolation: true, sandbox: true, nodeIntegration: false, backgroundThrottling: false, spellcheck: false },
    });
    win.loadURL('app://nexus/');
    // Fremde Links im Browser bzw. VRChat öffnen, nie in der App
    win.webContents.setWindowOpenHandler(({ url }) => { if (/^(https?|vrchat):/i.test(url)) shell.openExternal(url); return { action: 'deny' }; });
    win.webContents.on('will-navigate', (e, url) => { if (!url.startsWith('app://nexus/')) { e.preventDefault(); if (/^(https?|vrchat):/i.test(url)) shell.openExternal(url); } });
    win.on('close', e => { if (!quitting && prefs['sys.close_to_tray'] !== false) { e.preventDefault(); win.hide(); } else { quitting = true; app.quit(); } });
    win.webContents.on('before-input-event', (e, input) => { if (input.type === 'keyDown' && input.key === 'F12' && prefs['adv.devtools']) win.webContents.toggleDevTools(); });
    win.webContents.session.on('will-download', (e, item) => {
        item.setSaveDialogOptions({ title: 'Speichern unter', defaultPath: path.join(app.getPath('downloads'), item.getFilename()) });
    });
}

function createTray() {
    tray = new Tray(nativeImage.createFromPath(path.join(RENDERER, 'icon.png')).resize({ width: 16, height: 16 }));
    tray.setToolTip('VRC Nexus');
    tray.setContextMenu(trayMenu());
    tray.on('click', () => { if (win.isVisible()) win.focus(); else win.show(); });
}

function trayMenu() {
    return Menu.buildFromTemplate([
        { label: 'VRC Nexus öffnen', click: () => { win.show(); win.focus(); } },
        { type: 'separator' },
        { label: '👥 Spielerliste', click: () => host.showTab('players') },
        { label: '🧰 Werkzeuge', click: () => host.showTab('tools') },
        { label: '▶ VRChat starten', click: () => nexus && nexus.tools.launch('').catch(e => notify('VRC Nexus', e.message)) },
        { label: '⬆ Nach Updates suchen', click: () => nexus && nexus.updater.check().then(st => notify('VRC Nexus', st.available ? 'Version ' + st.version + ' ist verfügbar – Einstellungen → System → Jetzt aktualisieren.' : 'Du hast die neueste Version (' + st.current + ').')).catch(e => notify('VRC Nexus', e.message)) },
        { type: 'separator' },
        { label: 'Mit Windows starten', type: 'checkbox', checked: app.getLoginItemSettings().openAtLogin,
          click: m => { nexus.settings.save({ 'sys.autostart': m.checked }); } },
        { label: 'Datenordner öffnen', click: () => shell.openPath(app.getPath('userData')) },
        { type: 'separator' },
        { label: 'Beenden', click: () => { quitting = true; app.quit(); } },
    ]);
}

app.on('second-instance', () => { if (win) { win.show(); win.focus(); } });

app.whenReady().then(async () => {
    Menu.setApplicationMenu(null);
    try {
        db = await openDatabase(path.join(app.getPath('userData'), 'nexus.sqlite'));
    } catch (e) {
        dialog.showErrorBox('VRC Nexus', 'Die Datenbank konnte nicht geöffnet werden:\n' + e.message);
        app.exit(1);
        return;
    }
    nexus = new NexusApp({ db, crypt, notify, host });
    nexus.game.start(Number(nexus.settings.get('sys.log_poll_ms')) || 1000);
    nexus.tools.start();
    nexus.link.start();
    nexus.updater.start();
    host.applySpeech(nexus.speech.cfg());
    // SteamVR-Overlay: Vorschaubild muss als echte Datei vorliegen (nicht im asar-Archiv)
    const ovIcon = path.join(app.getPath('userData'), 'overlay-icon.png');
    try { fs.writeFileSync(ovIcon, fs.readFileSync(path.join(RENDERER, 'icon.png'))); } catch (e) { /* egal */ }
    vr = new VrOverlay({ exe: app.isPackaged ? path.join(process.resourcesPath, 'overlay', 'NexusOverlay.exe') : path.join(__dirname, 'overlay', 'bin', 'Release', 'net48', 'NexusOverlay.exe'),
        icon: ovIcon, url: 'app://nexus/overlay.html', notify });
    vr.apply(nexus.settings.all());
    protocol.handle('app', async request => {
        const url = new URL(request.url);
        if (url.pathname === '/' || url.pathname === '/nexus.php' || url.pathname === '/index.html') {
            const r = await handleWebRequest(nexus, request);
            return new Response(r.body, { status: r.status, headers: r.headers });
        }
        const file = path.normalize(path.join(RENDERER, decodeURIComponent(url.pathname)));
        if (!file.startsWith(RENDERER + path.sep) || !fs.existsSync(file) || path.basename(file) === 'page.html') return new Response('Nicht gefunden', { status: 404 });
        return new Response(fs.readFileSync(file), { headers: { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' } });
    });
    createWindow();
    createTray();
    // Weltweite Spielerzahl alle 15 min messen (öffentlich, ohne Anmeldung)
    const sample = () => { if (nexus.settings.get('sys.global_sample')) nexus.x.visitsSample().catch(() => {}); };
    sample();
    setInterval(sample, 15 * 60 * 1000);
});

app.on('before-quit', () => { quitting = true; try { if (vr) vr.stop(); if (nexus) { nexus.game.stop(); nexus.tools.stop(); } if (db) db.close(); } catch (e) { /* egal */ } });
app.on('window-all-closed', () => { /* läuft im Infobereich weiter */ });
