'use strict';
/*
 * VRC Nexus für Android: dasselbe Backend wie die Windows-App, aber im WebView.
 * - Anfragen an „nexus.php“ beantwortet das eingebaute Backend (fetch wird umgeleitet).
 * - Anfragen an VRChat gehen über das native HTTP von Android (CapacitorHttp) – mit Cookies, ohne CORS.
 * - Die Datenbank liegt in IndexedDB (privater Speicher der App).
 * Ohne PC-Funktionen: Spiel-Log, OSC, Spielerliste, Stream Deck, Ordner, Registry, config.json …
 */
import { Capacitor, registerPlugin } from '@capacitor/core';
import { LocalNotifications } from '@capacitor/local-notifications';
import { Filesystem, Directory } from '@capacitor/filesystem';
import { Share } from '@capacitor/share';
import TEMPLATE from '../../vrc-nexus/renderer/page.html';

const fs = require('fs');
const initSqlJs = require('sql.js');
const { Database } = require('../../vrc-nexus/backend/db');
const { NexusApp, handleWebRequest } = require('../../vrc-nexus/backend/app');

const native = Capacitor.isNativePlatform();
const status = t => { const el = document.getElementById('bootMsg'); if (el) el.textContent = t; };

// ---------- Benachrichtigungen (Android) ----------
let notifId = 1;
async function notify(title, body) {
    if (!native) { console.log('[Benachrichtigung]', title, body); return; }
    try { await LocalNotifications.schedule({ notifications: [{ id: notifId++, title: String(title).slice(0, 120), body: String(body || '').slice(0, 300) }] }); } catch (e) { /* keine Berechtigung */ }
}
if (native) {
    // Die Oberfläche benutzt die Browser-Notification-API – auf Android gibt es sie im WebView nicht
    class AndroidNotification {
        constructor(title, opts) { notify(title, opts && opts.body); }
        static get permission() { return AndroidNotification._p || 'default'; }
        static requestPermission() { return LocalNotifications.requestPermissions().then(r => (AndroidNotification._p = r.display === 'granted' ? 'granted' : 'denied')); }
    }
    window.Notification = AndroidNotification;
    LocalNotifications.checkPermissions().then(r => { AndroidNotification._p = r.display === 'granted' ? 'granted' : r.display === 'denied' ? 'denied' : 'default'; }).catch(() => {});
}

// ---------- Start ----------
(async () => {
    status('Lade Datenbank …');
    await fs.__load();
    fs.__files.set('/renderer/page.html', TEMPLATE);
    const SQL = await initSqlJs({ locateFile: f => f });
    const db = new Database(SQL, '/data/nexus.sqlite');
    // Android-Sandbox: nur diese App kann ihren Speicher lesen
    const crypt = { encrypt: s => 'plain:' + Buffer.from(s).toString('base64'), decrypt: s => (s.startsWith('plain:') ? Buffer.from(s.slice(6), 'base64').toString() : '{}') };
    const host = {
        userData: '/data', notify,
        openExternal: url => { window.location.href = url; },     // externe Adresse → Capacitor öffnet den System-Browser / Download
        /** Update: APK mit Fortschritt in den App-Cache laden, danach Android-Installationsdialog öffnen */
        downloadApk: async (asset, progress) => {
            if (!native) throw new Error('Nur in der Android-App.');
            const W = registerPlugin('NexusWidget');
            const sub = await Filesystem.addListener('progress', p => { if (p && p.url === asset.url) progress(p.bytes || 0, p.contentLength || asset.size || 0); });
            try {
                await Filesystem.deleteFile({ path: asset.name, directory: Directory.Cache }).catch(() => {});
                const r = await Filesystem.downloadFile({ url: asset.url, path: asset.name, directory: Directory.Cache, progress: true });
                progress(asset.size || 1, asset.size || 1);
                const uri = r.path || (await Filesystem.getUri({ path: asset.name, directory: Directory.Cache })).uri;
                await W.installApk({ path: uri });
            } finally { sub.remove(); }
        },
        speak: t => { if (window.speechSynthesis) speechSynthesis.speak(Object.assign(new SpeechSynthesisUtterance(t), { lang: 'de-DE' })); },
    };
    const app = new NexusApp({ db, crypt, host, notify, mobile: true });
    app.game.stop();
    app.game.osc.stopListen();
    app.game.watcher = null;
    app.tools.backupTick = () => {};                       // Sicherungen sind am PC sinnvoll, nicht im Telefon-Speicher
    app.tools.start();
    app.updater.start();
    window.__nexus = app;

    // fetch umleiten: nexus.php → eingebautes Backend, alles andere → (native) Netz
    const upstream = window.__nexusUpstream || window.fetch.bind(window);   // __nexusUpstream nur im Test (ersetzt das native HTTP)
    window.fetch = async (input, init) => {
        const url = new URL(typeof input === 'string' ? input : input.url, location.href);
        if (url.origin === location.origin && /(^|\/)nexus\.php$/.test(url.pathname)) {
            const req = typeof input === 'string' || input instanceof URL ? new Request(url, init) : input;
            const r = await handleWebRequest(app, req);
            // nach Änderungen sofort sichern – die Oberfläche lädt z. B. nach der Anmeldung die Seite neu
            if (req.method === 'POST') { try { if (db.timer) db.save(); await fs.__flush(); } catch (e) { /* egal */ } }
            return new Response(r.body, { status: r.status, headers: r.headers });
        }
        return upstream(input, init);
    };

    // Datenbank sichern, wenn die App in den Hintergrund geht
    document.addEventListener('visibilitychange', () => { if (document.hidden) { try { db.save(); } catch (e) { /* egal */ } fs.__flush().catch(() => {}); } });
    window.addEventListener('pagehide', () => { try { db.save(); } catch (e) { /* egal */ } });

    // Homescreen-Widget mit einer Zusammenfassung versorgen (beim Start, jede Minute, beim Verlassen der App)
    const NexusWidget = native ? registerPlugin('NexusWidget') : null;
    // Homescreen-Widget: Text in der gewählten Sprache (Übersetzer aus nexus-i18n.js, sobald geladen)
    const widgetData = () => {
        const d = app.home.summary();
        const t = typeof window.t === 'function' ? window.t : s => s;
        d.line = t(d.in_game + ' im Spiel') + ' · ' + t(d.online + ' online');
        d.stand = t('Stand');
        return d;
    };
    const pushWidget = () => { if (!NexusWidget) return; try { NexusWidget.update({ data: JSON.stringify(widgetData()) }).catch(() => {}); } catch (e) { /* egal */ } };
    setTimeout(pushWidget, 8000);
    setInterval(pushWidget, 60000);
    document.addEventListener('visibilitychange', () => { if (document.hidden) pushWidget(); });

    window.NexusMobile = { download, imgFix, pushWidget };
    status('Starte …');
    const r = await app.handle({ method: 'GET', query: new URLSearchParams() });
    document.open();
    document.write(r.body);
    document.close();
    document.body.classList.add('mobile');
    const cs = document.getElementById('clientStandalone');
    if (cs) cs.parentNode.removeChild(cs);
    setTimeout(imgFix, 0);
})().catch(e => { status('⚠ Start fehlgeschlagen: ' + e.message); console.error(e); });

// ---------- Bilder: „nexus.php?img=…“ gibt es nicht als Datei → über fetch holen ----------
const imgCache = new Map();
function blobUrl(src) {
    if (!imgCache.has(src)) imgCache.set(src, window.fetch(src).then(r => (r.ok ? r.blob() : Promise.reject(new Error(r.status)))).then(b => URL.createObjectURL(b)).catch(() => ''));
    return imgCache.get(src);
}
function fixEl(el) {
    if (el.tagName === 'IMG') {
        const src = el.getAttribute('src') || '';
        if (/^nexus\.php\?/.test(src)) { el.removeAttribute('src'); el.dataset.nxsrc = src; blobUrl(src).then(u => { if (u) el.src = u; }); }
    }
    const st = el.getAttribute && el.getAttribute('style');
    if (st && st.includes('nexus.php?')) {
        const m = /url\(['"]?(nexus\.php\?[^'")]+)['"]?\)/.exec(st);
        if (m) { const orig = m[1]; el.setAttribute('style', st.replace(m[0], 'none')); blobUrl(orig.replace(/&amp;/g, '&')).then(u => { if (u) el.style.backgroundImage = 'url("' + u + '")'; }); }
    }
}
function imgFix() {
    document.querySelectorAll('img[src^="nexus.php?"], [style*="nexus.php?"]').forEach(fixEl);
    if (imgFix.obs) return;
    imgFix.obs = new MutationObserver(list => {
        for (const m of list) {
            if (m.type === 'attributes') { fixEl(m.target); continue; }
            m.addedNodes.forEach(n => { if (n.nodeType === 1) { fixEl(n); n.querySelectorAll && n.querySelectorAll('img[src^="nexus.php?"], [style*="nexus.php?"]').forEach(fixEl); } });
        }
    });
    imgFix.obs.observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['src', 'style'] });
    // Downloads (CSV, ICS, JSON) abfangen
    document.addEventListener('click', e => {
        const a = e.target.closest && e.target.closest('a[href^="nexus.php?"]');
        if (!a) return;
        e.preventDefault();
        download(a.getAttribute('href'));
    }, true);
}

// ---------- Downloads: auf Android speichern + teilen ----------
async function download(href) {
    const r = await window.fetch(href);
    if (!r.ok) { const d = await r.json().catch(() => ({})); alert(d.error || 'Download fehlgeschlagen.'); return; }
    const name = (/filename="([^"]+)"/.exec(r.headers.get('content-disposition') || '') || [])[1] || 'vrc-nexus.txt';
    const blob = await r.blob();
    if (!native) { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; a.click(); return; }
    const b64 = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(String(fr.result).split(',')[1]); fr.readAsDataURL(blob); });
    const w = await Filesystem.writeFile({ path: name, data: b64, directory: Directory.Cache });
    await Share.share({ title: name, files: [w.uri] }).catch(() => {});
}
