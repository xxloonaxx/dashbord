'use strict';
/*
 * VRC Nexus Desktop – eigenständige Windows-App (ohne Server).
 * Die Oberfläche läuft unter app://nexus/, alle Anfragen an „nexus.php“ beantwortet das eingebaute Backend (backend/app.js).
 * Läuft im Infobereich weiter (Verlauf, Statistik, Spiel-Log & OSC zeichnen dann weiter auf).
 */
const { app, BrowserWindow, Menu, Tray, Notification, protocol, shell, safeStorage, nativeImage, dialog } = require('electron');
const fs = require('fs');
const path = require('path');
const { openDatabase } = require('./backend/db');
const { NexusApp, handleWebRequest } = require('./backend/app');

const RENDERER = path.join(__dirname, 'renderer');
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.ico': 'image/x-icon', '.svg': 'image/svg+xml' };

protocol.registerSchemesAsPrivileged([{ scheme: 'app', privileges: { standard: true, secure: true, supportFetchAPI: true, stream: true } }]);
if (!app.requestSingleInstanceLock()) { app.quit(); process.exit(0); }
app.setAppUserModelId('de.ghostnode.vrcnexus');

let win = null, tray = null, nexus = null, db = null, quitting = false;

/** Sitzungs-Cookie verschlüsseln: Windows-Schlüsselspeicher (DPAPI) über Electron safeStorage */
const crypt = {
    encrypt: s => (safeStorage.isEncryptionAvailable() ? 'dpapi:' + safeStorage.encryptString(s).toString('base64') : 'plain:' + Buffer.from(s).toString('base64')),
    decrypt: s => {
        if (s.startsWith('dpapi:')) return safeStorage.decryptString(Buffer.from(s.slice(6), 'base64'));
        if (s.startsWith('plain:')) return Buffer.from(s.slice(6), 'base64').toString();
        return '{}';
    },
};

function notify(title, body) {
    if (Notification.isSupported()) new Notification({ title, body, icon: path.join(RENDERER, 'icon.png') }).show();
}

function createWindow() {
    win = new BrowserWindow({
        width: 1380, height: 900, minWidth: 900, minHeight: 600, backgroundColor: '#040609', title: 'VRC Nexus', icon: path.join(RENDERER, 'icon.png'),
        autoHideMenuBar: true, show: !process.argv.includes('--minimized'),
        webPreferences: { contextIsolation: true, sandbox: true, nodeIntegration: false, backgroundThrottling: false, spellcheck: false },
    });
    win.loadURL('app://nexus/');
    // Fremde Links im Browser bzw. VRChat öffnen, nie in der App
    win.webContents.setWindowOpenHandler(({ url }) => { if (/^(https?|vrchat):/i.test(url)) shell.openExternal(url); return { action: 'deny' }; });
    win.webContents.on('will-navigate', (e, url) => { if (!url.startsWith('app://nexus/')) { e.preventDefault(); if (/^(https?|vrchat):/i.test(url)) shell.openExternal(url); } });
    win.on('close', e => { if (!quitting) { e.preventDefault(); win.hide(); } });
    win.webContents.session.on('will-download', (e, item) => {
        item.setSaveDialogOptions({ title: 'Speichern unter', defaultPath: path.join(app.getPath('downloads'), item.getFilename()) });
    });
}

function createTray() {
    tray = new Tray(nativeImage.createFromPath(path.join(RENDERER, 'icon.png')).resize({ width: 16, height: 16 }));
    tray.setToolTip('VRC Nexus');
    const build = () => Menu.buildFromTemplate([
        { label: 'VRC Nexus öffnen', click: () => { win.show(); win.focus(); } },
        { type: 'separator' },
        { label: 'Mit Windows starten', type: 'checkbox', checked: app.getLoginItemSettings().openAtLogin,
          click: m => app.setLoginItemSettings({ openAtLogin: m.checked, args: ['--minimized'] }) },
        { label: 'Datenordner öffnen', click: () => shell.openPath(app.getPath('userData')) },
        { type: 'separator' },
        { label: 'Beenden', click: () => { quitting = true; app.quit(); } },
    ]);
    tray.setContextMenu(build());
    tray.on('click', () => { if (win.isVisible()) win.focus(); else win.show(); });
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
    nexus = new NexusApp({ db, crypt, notify });
    nexus.game.start(1000);
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
    const sample = () => nexus.x.visitsSample().catch(() => {});
    sample();
    setInterval(sample, 15 * 60 * 1000);
});

app.on('before-quit', () => { quitting = true; try { if (nexus) nexus.game.stop(); if (db) db.close(); } catch (e) { /* egal */ } });
app.on('window-all-closed', () => { /* läuft im Infobereich weiter */ });
