'use strict';
/*
 * App-Einstellungen: ein Schema (Reiter → Abschnitte → Felder) mit Standardwerten.
 * Gespeichert als JSON in der Tabelle settings (Schlüssel „app_settings“); die Oberfläche baut das Formular aus dem Schema.
 */
const crypto = require('crypto');
const { str, int, cut, UserError } = require('./util');

const STATUS = [['', '– nichts ändern –'], ['join me', '🔵 Join Me'], ['active', '🟢 Online'], ['ask me', '🟠 Ask Me'], ['busy', '🔴 Beschäftigt']];
const TABS = { system: '🖥 System', ui: '🎨 Oberfläche', social: '👥 Sozial', notify: '🔔 Benachrichtigungen', vr: '🥽 VR', media: '📷 Medien', int: '🔌 Integrationen', adv: '🧪 Erweitert' };
const START_TABS = [['friends', 'Freundesliste'], ['feed', 'Verlauf'], ['locations', 'Freunde-Orte'], ['players', 'Spielerliste'], ['favs', 'Favoriten'], ['worlds', 'Welten'],
    ['avatars', 'Meine Avatare'], ['notifs', 'Benachrichtigungen'], ['tools', 'Werkzeuge'], ['client', 'Spiel & OSC']];

/* [schlüssel, reiter, abschnitt, typ, standard, beschriftung, hinweis, optionen] */
const FIELDS = [
    // ---------- System ----------
    ['sys.autostart', 'system', 'Start', 'bool', false, 'Mit Windows starten', 'Startet VRC Nexus beim Anmelden an Windows.'],
    ['sys.start_minimized', 'system', 'Start', 'bool', true, 'Beim Autostart nur im Infobereich starten', ''],
    ['sys.close_to_tray', 'system', 'Start', 'bool', true, 'Fenster schließen = im Infobereich weiterlaufen', 'Aus: Schließen beendet die App.'],
    ['sys.tray_count', 'system', 'Start', 'bool', true, 'Freunde online im Infobereich-Tooltip zeigen', ''],
    ['sys.hw_accel', 'system', 'Leistung', 'bool', true, 'Hardwarebeschleunigung', 'Wirkt nach einem Neustart der App.'],
    ['sys.log_poll_ms', 'system', 'Leistung', 'select', '1000', 'VRChat-Log prüfen alle', '', [['500', '0,5 s'], ['1000', '1 s'], ['2000', '2 s'], ['5000', '5 s']]],
    ['sys.global_sample', 'system', 'Leistung', 'bool', true, 'Weltweite Spielerzahl alle 15 min messen', 'Für das Diagramm „VRChat weltweit“.'],
    ['sys.backup', 'system', 'Sicherung', 'select', 'weekly', 'Datenbank automatisch sichern', '', [['off', 'nie'], ['daily', 'täglich'], ['weekly', 'wöchentlich']]],
    ['sys.backup_keep', 'system', 'Sicherung', 'number', 5, 'Anzahl Sicherungen behalten', '', [1, 60]],
    // ---------- Oberfläche ----------
    ['ui.accent', 'ui', 'Aussehen', 'color', '#38d5e6', 'Akzentfarbe', ''],
    ['ui.zoom', 'ui', 'Aussehen', 'number', 100, 'Zoom (%)', '70–150 %', [70, 150]],
    ['ui.font', 'ui', 'Aussehen', 'select', 'default', 'Schrift', '', [['default', 'Standard'], ['system', 'Windows-Systemschrift'], ['mono', 'Monospace']]],
    ['ui.animations', 'ui', 'Aussehen', 'bool', true, 'Animationen', ''],
    ['ui.background', 'ui', 'Aussehen', 'bool', true, 'Hintergrund-Raster & Leuchten', ''],
    ['ui.show_images', 'ui', 'Aussehen', 'bool', true, 'Bilder (Avatare, Welten) anzeigen', 'Aus spart Datenvolumen.'],
    ['ui.compact', 'ui', 'Aussehen', 'bool', false, 'Kompakte Freundesliste', ''],
    ['ui.menu_style', 'ui', 'Menü', 'select', 'both', 'Menü-Darstellung', '', [['both', 'Symbol + Text'], ['icons', 'nur Symbole'], ['text', 'nur Text']]],
    ['ui.sticky_menu', 'ui', 'Menü', 'bool', true, 'Menü beim Scrollen oben festhalten', ''],
    ['ui.start_tab', 'ui', 'Menü', 'select', 'last', 'Beim Start öffnen', '', [['last', 'zuletzt geöffnete Seite']].concat(START_TABS)],
    ['ui.wide', 'ui', 'Menü', 'bool', false, 'Volle Fensterbreite nutzen', ''],
    // ---------- Sozial ----------
    ['social.show_offline', 'social', 'Freundesliste', 'bool', false, 'Offline-Freunde standardmäßig zeigen', ''],
    ['social.sort', 'social', 'Freundesliste', 'select', 'state', 'Standard-Sortierung', '', [['state', 'Nach Status'], ['name', 'Name A–Z'], ['since', 'Zuletzt geändert'], ['trust', 'Vertrauensstufe'], ['world', 'Nach Welt']]],
    ['social.loc_private', 'social', 'Freunde-Orte', 'bool', true, 'Private Instanzen zusammenfassen', 'Freunde in privaten Welten erscheinen unter „Privat“.'],
    ['social.loc_active', 'social', 'Freunde-Orte', 'bool', true, '„Online auf der Website/App“ mit anzeigen', ''],
    ['social.players_fetch', 'social', 'Spielerliste', 'bool', true, 'Profile der Mitspieler laden (VRC+, 18+, Rang, Sprache …)', 'Höchstens eine Abfrage pro Sekunde.'],
    ['social.players_cache', 'social', 'Spielerliste', 'number', 60, 'Profile zwischenspeichern (Minuten)', '', [5, 1440]],
    ['social.players_left', 'social', 'Spielerliste', 'bool', true, 'Wer die Instanz verlassen hat, unten anzeigen', ''],
    ['social.memo_remote', 'social', 'Spielerliste', 'bool', false, 'Memos zusätzlich als VRChat-Notiz speichern', ''],
    ['social.mutual_limit', 'social', 'Freundesnetz', 'number', 150, 'Höchstens so viele Freunde abfragen', '', [10, 2000]],
    ['social.mutual_cache', 'social', 'Freundesnetz', 'number', 7, 'Gemeinsame Freunde merken (Tage)', '', [1, 90]],
    // ---------- Benachrichtigungen ----------
    ['notify.desktop', 'notify', 'Allgemein', 'bool', true, 'Windows-Benachrichtigungen', ''],
    ['notify.silent', 'notify', 'Allgemein', 'bool', false, 'Ohne Ton', ''],
    ['notify.quiet_from', 'notify', 'Allgemein', 'time', '', 'Nicht stören von', 'leer = aus'],
    ['notify.quiet_to', 'notify', 'Allgemein', 'time', '', 'Nicht stören bis', ''],
    ['notify.tts', 'notify', 'Allgemein', 'bool', false, 'Benachrichtigungen vorlesen', 'Sprachausgabe von Windows.'],
    ['notify.watch', 'notify', 'Freunde', 'bool', true, 'Beobachtete Freunde kommen online', ''],
    ['notify.player_join', 'notify', 'Instanz', 'select', 'friends', 'Jemand betritt deine Instanz', '', [['off', 'aus'], ['favorites', 'nur ⭐ Favoriten'], ['friends', 'Freunde'], ['all', 'alle']]],
    ['notify.player_leave', 'notify', 'Instanz', 'select', 'off', 'Jemand verlässt deine Instanz', '', [['off', 'aus'], ['favorites', 'nur ⭐ Favoriten'], ['friends', 'Freunde'], ['all', 'alle']]],
    ['notify.vrc', 'notify', 'VRChat', 'bool', true, 'Neue VRChat-Benachrichtigungen (Einladungen, Anfragen, Freundschaften)', 'Wird alle 2 Minuten geprüft.'],
    ['notify.vrc_invite', 'notify', 'VRChat', 'bool', true, '… Einladungen', ''],
    ['notify.vrc_request', 'notify', 'VRChat', 'bool', true, '… Einladungs-Anfragen', ''],
    ['notify.vrc_friend', 'notify', 'VRChat', 'bool', true, '… Freundschaftsanfragen', ''],
    ['notify.vrc_group', 'notify', 'VRChat', 'bool', false, '… Gruppen (Ankündigungen, Einladungen)', ''],
    ['notify.screenshot', 'notify', 'Spiel', 'bool', false, 'Screenshot aufgenommen', ''],
    ['notify.vrc_start', 'notify', 'Spiel', 'bool', false, 'VRChat gestartet / beendet', ''],
    // ---------- VR ----------
    ['vr.xsoverlay', 'vr', 'XSOverlay', 'bool', false, 'Benachrichtigungen in XSOverlay anzeigen', 'XSOverlay muss laufen (Port 42069).'],
    ['vr.xs_port', 'vr', 'XSOverlay', 'number', 42069, 'XSOverlay-Port', '', [1, 65535]],
    ['vr.xs_timeout', 'vr', 'XSOverlay', 'number', 4, 'Anzeigedauer (Sekunden)', '', [1, 30]],
    ['vr.xs_opacity', 'vr', 'XSOverlay', 'number', 90, 'Deckkraft (%)', '', [10, 100]],
    ['vr.xs_volume', 'vr', 'XSOverlay', 'number', 50, 'Lautstärke (%)', '', [0, 100]],
    ['vr.chatbox', 'vr', 'Chatbox', 'bool', false, 'Benachrichtigungen auch in die VRChat-Chatbox schreiben', 'Andere Spieler sehen das!'],
    ['vr.chatbox_prefix', 'vr', 'Chatbox', 'text', '🔔', 'Vorangestelltes Zeichen', ''],
    ['vr.only_in_game', 'vr', 'Allgemein', 'bool', true, 'VR-Hinweise nur, wenn VRChat läuft', ''],
    // ---------- Medien ----------
    ['media.photo_dir', 'media', 'Ordner', 'text', '', 'VRChat-Fotoordner', 'leer = Bilder\\VRChat'],
    ['media.steam_dir', 'media', 'Ordner', 'text', '', 'Steam-Screenshot-Ordner', 'leer = automatisch suchen'],
    ['media.meta', 'media', 'Screenshots', 'bool', false, 'Welt & Mitspieler in neue Screenshots schreiben', 'Als PNG-Text „Description“ (lesbar im Screenshot-Manager, kompatibel mit anderen Tools).'],
    ['media.copy', 'media', 'Screenshots', 'bool', false, 'Neue Screenshots zusätzlich kopieren', ''],
    ['media.copy_dir', 'media', 'Screenshots', 'text', '', 'Zielordner', 'Unterordner pro Monat werden angelegt.'],
    ['media.copy_by_world', 'media', 'Screenshots', 'bool', false, 'Unterordner nach Welt statt nach Monat', ''],
    ['media.manager_limit', 'media', 'Screenshot-Manager', 'number', 300, 'Höchstens so viele Bilder anzeigen', '', [20, 5000]],
    // ---------- Integrationen ----------
    ['int.api', 'int', 'Stream Deck & lokale Schnittstelle', 'bool', false, 'Lokale Schnittstelle für das Stream-Deck-Plugin', 'Nur auf diesem PC erreichbar (127.0.0.1).'],
    ['int.api_port', 'int', 'Stream Deck & lokale Schnittstelle', 'number', 17865, 'Port', '', [1024, 65535]],
    ['int.api_token', 'int', 'Stream Deck & lokale Schnittstelle', 'secret', '', 'Zugangsschlüssel', 'Im Stream-Deck-Plugin eintragen.'],
    ['int.rpc', 'int', 'Discord Rich Presence', 'bool', false, 'Discord-Status „Spielt VRChat in …“', 'Braucht die Client-ID einer eigenen Discord-Anwendung.'],
    ['int.rpc_client', 'int', 'Discord Rich Presence', 'text', '', 'Discord Client-ID', 'discord.com/developers → Anwendung anlegen → Application ID'],
    ['int.rpc_world', 'int', 'Discord Rich Presence', 'bool', true, 'Weltnamen zeigen', ''],
    ['int.rpc_private', 'int', 'Discord Rich Presence', 'bool', true, 'In privaten Instanzen Welt verbergen', ''],
    ['int.rpc_players', 'int', 'Discord Rich Presence', 'bool', true, 'Spielerzahl zeigen', ''],
    ['int.rpc_join', 'int', 'Discord Rich Presence', 'bool', false, 'Knopf „Beitreten“ bei öffentlichen Instanzen', ''],
    ['int.webhook', 'int', 'Discord-Webhook', 'text', '', 'Webhook-URL', 'https://discord.com/api/webhooks/…'],
    ['int.webhook_join', 'int', 'Discord-Webhook', 'bool', false, 'Melden, wenn Freunde deine Instanz betreten', ''],
    ['int.webhook_vrc', 'int', 'Discord-Webhook', 'bool', false, 'VRChat-Benachrichtigungen weiterleiten', ''],
    ['int.webhook_world', 'int', 'Discord-Webhook', 'bool', false, 'Weltwechsel melden', ''],
    // ---------- Erweitert ----------
    ['adv.api_base', 'adv', 'Verbindung', 'text', '', 'VRChat-API-Adresse', 'leer = https://api.vrchat.cloud/api/1'],
    ['adv.user_agent', 'adv', 'Verbindung', 'text', '', 'User-Agent', 'Name/Version und Kontakt, z. B. MeinNexus/1.0 kontakt@example.org'],
    ['adv.debug', 'adv', 'Fehlersuche', 'bool', false, 'Fehlerprotokoll schreiben (nexus.log)', ''],
    ['adv.devtools', 'adv', 'Fehlersuche', 'bool', false, 'Entwicklerwerkzeuge mit F12', ''],
];

const KEYS = Object.fromEntries(FIELDS.map(f => [f[0], f]));

class Settings {
    constructor(db) { this.db = db; this.cache = null; this.listeners = []; }
    defaults() { return Object.fromEntries(FIELDS.map(f => [f[0], f[4]])); }
    all() {
        if (this.cache) return this.cache;
        let saved = {};
        try { saved = JSON.parse(this.db.setting('app_settings', '{}')) || {}; } catch (e) { saved = {}; }
        const s = Object.assign(this.defaults(), saved);
        // vorhandene Einzel-Einstellungen spiegeln
        s['adv.api_base'] = this.db.setting('vrc_api_base', '');
        s['adv.user_agent'] = this.db.setting('vrc_user_agent', '');
        if (!s['int.api_token']) { s['int.api_token'] = crypto.randomBytes(18).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); this.db.setSetting('app_settings', JSON.stringify(s)); }
        this.cache = s;
        return s;
    }
    get(k) { return this.all()[k]; }
    /** Felder prüfen und speichern; unbekannte Schlüssel werden ignoriert */
    save(input) {
        const cur = Object.assign({}, this.all());
        const changed = [];
        for (const [k, raw] of Object.entries(input || {})) {
            const f = KEYS[k];
            if (!f || f[3] === 'secret') continue;
            let v;
            switch (f[3]) {
                case 'bool': v = raw === true || raw === 1 || ['1', 'true', 'on', 'yes'].includes(str(raw)); break;
                case 'number': { v = int(raw); const [lo, hi] = f[7] || [-1e9, 1e9]; if (str(raw).trim() === '' || isNaN(Number(raw))) v = f[4]; v = Math.max(lo, Math.min(hi, v)); break; }
                case 'select': v = (f[7] || []).some(o => o[0] === str(raw)) ? str(raw) : f[4]; break;
                case 'color': v = /^#[0-9a-f]{6}$/i.test(str(raw)) ? str(raw).toLowerCase() : f[4]; break;
                case 'time': v = /^([01]\d|2[0-3]):[0-5]\d$/.test(str(raw)) ? str(raw) : ''; break;
                default: v = cut(str(raw).trim(), 400);
            }
            if (k === 'adv.api_base' && v && !/^https?:\/\/[\w.-]+(:\d+)?(\/[\w./-]*)?$/.test(v)) throw new UserError('API-Adresse ungültig.');
            if (k === 'int.webhook' && v && !/^https:\/\/(discord(app)?\.com|ptb\.discord\.com|canary\.discord\.com)\/api\/webhooks\/\d+\/[\w-]+$/.test(v)) throw new UserError('Das ist keine Discord-Webhook-URL.');
            if (k === 'int.rpc_client' && v && !/^\d{15,22}$/.test(v)) throw new UserError('Die Discord Client-ID besteht nur aus Ziffern.');
            if (cur[k] !== v) { cur[k] = v; changed.push(k); }
        }
        this.write(cur);
        if (changed.includes('adv.api_base')) this.db.setSetting('vrc_api_base', cur['adv.api_base']);
        if (changed.includes('adv.user_agent')) this.db.setSetting('vrc_user_agent', cur['adv.user_agent']);
        this.listeners.forEach(fn => { try { fn(changed, cur); } catch (e) { /* egal */ } });
        return { settings: cur, changed };
    }
    write(s) { this.cache = s; this.db.setSetting('app_settings', JSON.stringify(s)); }
    newToken() { const s = Object.assign({}, this.all(), { 'int.api_token': crypto.randomBytes(18).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') }); this.write(s); this.listeners.forEach(fn => fn(['int.api_token'], s)); return s['int.api_token']; }
    reset() { this.db.setSetting('app_settings', '{}'); this.cache = null; const s = this.all(); this.listeners.forEach(fn => fn(Object.keys(s), s)); return s; }
    onChange(fn) { this.listeners.push(fn); }
    /** Für die Oberfläche: Schema ohne Funktionen */
    schema() { return { tabs: TABS, fields: FIELDS.map(([key, tab, section, type, def, label, hint, opts]) => ({ key, tab, section, type, def, label, hint, opts: opts || null })) }; }
    /** Teil für das Rendern der Seite (Aussehen sofort anwenden) */
    ui() { const s = this.all(); return Object.fromEntries(Object.entries(s).filter(([k]) => k.startsWith('ui.') || k.startsWith('social.show') || k === 'social.sort')); }
}

module.exports = { Settings, FIELDS, STATUS, TABS };
