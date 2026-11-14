'use strict';
/*
 * Auto-Update über GitHub-Releases (Tag „vrc-nexus-v<Version>“).
 * Windows: Installer still ausführen bzw. portable EXE ersetzen (macht main.js über host.installUpdate).
 * Android: APK-Download öffnen – installieren muss man selbst bestätigen.
 */
const { str, now, cut, UserError } = require('./util');
const { VERSION } = require('./vrchat');

const REPO = 'xxloonaxx/dashbord';
const TAG_PREFIX = 'vrc-nexus-v';

/** „1.2.10“ > „1.2.9“ */
function newer(a, b) {
    const pa = str(a).split('.').map(n => parseInt(n, 10) || 0), pb = str(b).split('.').map(n => parseInt(n, 10) || 0);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) { if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) > (pb[i] || 0); }
    return false;
}

class Updater {
    constructor(app) { this.app = app; this.last = null; this.busy = false; this.timer = null; this.progress = ''; }
    s(k) { return this.app.settings.get(k); }
    api() { return (process.env.NEXUS_UPDATE_API || 'https://api.github.com').replace(/\/+$/, ''); }

    /** Neueste Version auf GitHub suchen → { available, current, version, notes, published, page, assets } */
    async check() {
        // GitHub sortiert die Release-Liste NICHT nach Datum oder Versionsnummer (1.9.2 kam vor 1.10.0 – Textsortierung),
        // daher alle Seiten holen (max. 5 × 100) und selbst die höchste Version suchen.
        const list = [];
        for (let page = 1; page <= 5; page++) {
            let res;
            try {
                res = await fetch(this.api() + '/repos/' + REPO + '/releases?per_page=100&page=' + page, { headers: { Accept: 'application/vnd.github+json', 'User-Agent': 'VRC-Nexus-Updater/' + VERSION }, signal: AbortSignal.timeout(15000) });
            } catch (e) { throw new UserError('GitHub nicht erreichbar (' + (e.cause && e.cause.code || e.message) + ').'); }
            if (res.status === 404) throw new UserError('Keine Releases gefunden (ist das Repository privat?).');
            if (res.status === 403 || res.status === 429) throw new UserError('GitHub hat die Anfrage gerade begrenzt – später noch einmal versuchen.');
            if (!res.ok) throw new UserError('GitHub antwortet mit HTTP ' + res.status + '.');
            const got = (await res.json()) || [];
            if (!Array.isArray(got)) break;
            list.push(...got);
            if (got.length < 100) break;
        }
        // Nur Releases, die die Datei für DIESES Gerät schon haben: Android- und Windows-Build laden ihre Dateien
        // nacheinander hoch – sonst meldet die App ein Update, bevor das Setup da ist („Im Release fehlt die passende Datei“).
        const need = this.app.mobile ? /\.apk$/i : process.env.PORTABLE_EXECUTABLE_FILE ? /-portable\.exe$/i : /-Setup\.exe$/i;
        let best = null;
        for (const r of list) {
            if (!r || r.draft || r.prerelease || !str(r.tag_name).startsWith(TAG_PREFIX)) continue;
            const v = str(r.tag_name).slice(TAG_PREFIX.length);
            if (!/^\d+\.\d+\.\d+$/.test(v)) continue;
            if (!(r.assets || []).some(x => need.test(str(x.name)) && (x.state === undefined || x.state === 'uploaded'))) continue;
            if (!best || newer(v, best.v)) best = { v, r };
        }
        const out = { available: false, current: VERSION, version: VERSION, notes: '', published: '', page: '', assets: {}, checked: now() };
        if (best) {
            const a = {};
            for (const x of best.r.assets || []) {
                const n = str(x.name), u = str(x.browser_download_url);
                if (!/^https:\/\//.test(u) && !(process.env.NEXUS_UPDATE_API && /^http:\/\/127\.0\.0\.1:/.test(u))) continue;   // http nur im lokalen Test
                if (/-Setup\.exe$/i.test(n)) a.setup = { name: n, url: u, size: x.size };
                else if (/-portable\.exe$/i.test(n)) a.portable = { name: n, url: u, size: x.size };
                else if (/\.apk$/i.test(n)) a.apk = { name: n, url: u, size: x.size };
            }
            Object.assign(out, { available: newer(best.v, VERSION), version: best.v, notes: cut(str(best.r.body), 4000), published: str(best.r.published_at), page: str(best.r.html_url), assets: a });
        }
        this.last = out;
        this.app.db.setSetting('update_last', JSON.stringify(out));
        return out;
    }
    status() {
        if (!this.last) { try { this.last = JSON.parse(this.app.db.setting('update_last', 'null')); } catch (e) { this.last = null; } }
        const l = this.last || {};
        return Object.assign({ current: VERSION, available: false }, l, { available: !!(l.version && newer(l.version, VERSION)), current: VERSION, busy: this.busy, progress: this.progress, prog: this.prog || null, platform: this.app.mobile ? 'android' : process.platform });
    }
    /** Update anwenden (Windows: herunterladen + installieren; Android: Download öffnen) */
    async install() {
        const st = this.status();
        if (!st.available) throw new UserError('Du hast schon die neueste Version (' + VERSION + ').');
        const host = this.app.tools.host;
        if (this.app.mobile) {
            if (!st.assets.apk) throw new UserError('Im Release fehlt die APK.');
            if (host.downloadApk) {
                if (this.busy) throw new UserError('Update läuft schon …');
                this.busy = true;
                this.setProg('download', 0, st.assets.apk.size || 0);
                // im Hintergrund laden – die Oberfläche fragt den Fortschritt ab
                host.downloadApk(st.assets.apk, (got, total) => this.setProg('download', got, total || st.assets.apk.size || 0))
                    .then(() => { this.setProg('install', 1, 1); this.progress = 'Installation geöffnet – „Installieren“ antippen'; setTimeout(() => { this.busy = false; }, 4000); })
                    .catch(async e => {
                        this.busy = false;
                        this.setProg('error', 0, 0, e.message);
                        if (host.openExternal) await host.openExternal(st.assets.apk.url);   // Ersatzweg: Browser-Download
                    });
                return { msg: 'Update auf ' + st.version + ' wird heruntergeladen …', busy: true };
            }
            if (host.openExternal) await host.openExternal(st.assets.apk.url);
            return { msg: 'Download von ' + st.assets.apk.name + ' geöffnet – danach antippen und „Installieren“ wählen.' };
        }
        if (!host.installUpdate) throw new UserError('Automatisches Installieren geht nur in der Windows-App – Download: ' + (st.page || 'GitHub-Releases'));
        if (this.busy) throw new UserError('Update läuft schon …');
        this.busy = true;
        try {
            this.progress = 'lade herunter …';
            const a = (process.env.PORTABLE_EXECUTABLE_FILE ? st.assets.portable : st.assets.setup) || {};
            this.setProg('download', 0, a.size || 0);
            await host.installUpdate(st.assets, (p, total) => {
                if (typeof p === 'number') this.setProg('download', p, total || a.size || 0);
                else this.progress = p;
            });
            this.setProg('install', 1, 1);
            this.progress = 'wird installiert – die App startet gleich neu';
            return { msg: 'Update auf ' + st.version + ' wird installiert – VRC Nexus startet gleich neu.' };
        } catch (e) { this.busy = false; this.progress = ''; this.setProg('error', 0, 0, e.message); throw e; }
    }

    /** Fortschritt: Phase, Bytes, Prozent, Geschwindigkeit (gleitend), Restzeit */
    setProg(phase, got, total, error) {
        const now = Date.now(), p = this.prog && this.prog.phase === phase ? this.prog : null;
        // Geschwindigkeit über Zeitfenster ≥ 0,5 s messen (einzelne Pakete kommen viel zu dicht)
        let speed = p ? p.speed : 0, sAt = p ? p.sAt : now, sGot = p ? p.sGot : got;
        if (p && now - p.sAt >= 500) {
            const inst = (got - p.sGot) / ((now - p.sAt) / 1000);
            speed = speed ? speed * 0.6 + inst * 0.4 : inst;
            sAt = now; sGot = got;
        }
        const pct = total > 0 ? Math.min(100, Math.round(got / total * 1000) / 10) : null;
        this.prog = { phase, got, total, pct, speed: Math.round(speed), eta: speed > 0 && total > got ? Math.round((total - got) / speed) : null, sAt, sGot, error: error || '' };
        if (phase === 'download') this.progress = pct !== null ? pct.toFixed(0) + ' %' : Math.round(got / 1048576) + ' MB';
    }

    start() {
        const run = () => {
            if (!this.s('upd.check')) return;
            this.check().then(st => {
                if (!st.available) return;
                if (this.s('upd.mode') === 'auto' && !this.app.mobile && this.app.tools.host.installUpdate && !this.app.game.state.running) this.install().catch(() => {});
                else if (this.notified !== st.version) { this.notified = st.version; this.app.tools.alert('⬆ VRC Nexus ' + st.version + ' ist da', 'Einstellungen → System → Update installieren'); }
            }).catch(() => {});
        };
        setTimeout(run, 20000);
        this.timer = setInterval(run, 6 * 3600 * 1000);
        if (this.timer.unref) this.timer.unref();
    }
    stop() { if (this.timer) clearInterval(this.timer); }
}

module.exports = { Updater, newer, REPO, TAG_PREFIX };
