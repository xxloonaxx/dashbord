'use strict';
/*
 * Übersetzen mit DeepL oder Google Cloud Translation.
 * Die API-Schlüssel werden verschlüsselt gespeichert (Windows: DPAPI) und nie an die Oberfläche zurückgegeben.
 */
const { UserError, str, cut } = require('./util');

/* Zielsprachen (DeepL-Code, Google-Code, Name) */
const LANGS = [
    ['DE', 'de', '🇩🇪 Deutsch'], ['EN-US', 'en', '🇺🇸 Englisch (US)'], ['EN-GB', 'en', '🇬🇧 Englisch (UK)'], ['JA', 'ja', '🇯🇵 Japanisch'], ['KO', 'ko', '🇰🇷 Koreanisch'],
    ['ZH-HANS', 'zh-CN', '🇨🇳 Chinesisch (vereinfacht)'], ['ZH-HANT', 'zh-TW', '🇹🇼 Chinesisch (traditionell)'], ['FR', 'fr', '🇫🇷 Französisch'], ['ES', 'es', '🇪🇸 Spanisch'],
    ['PT-BR', 'pt', '🇧🇷 Portugiesisch (BR)'], ['IT', 'it', '🇮🇹 Italienisch'], ['NL', 'nl', '🇳🇱 Niederländisch'], ['PL', 'pl', '🇵🇱 Polnisch'], ['RU', 'ru', '🇷🇺 Russisch'],
    ['UK', 'uk', '🇺🇦 Ukrainisch'], ['TR', 'tr', '🇹🇷 Türkisch'], ['SV', 'sv', '🇸🇪 Schwedisch'], ['DA', 'da', '🇩🇰 Dänisch'], ['NB', 'no', '🇳🇴 Norwegisch'], ['FI', 'fi', '🇫🇮 Finnisch'],
    ['CS', 'cs', '🇨🇿 Tschechisch'], ['HU', 'hu', '🇭🇺 Ungarisch'], ['RO', 'ro', '🇷🇴 Rumänisch'], ['EL', 'el', '🇬🇷 Griechisch'], ['ID', 'id', '🇮🇩 Indonesisch'],
    ['AR', 'ar', '🇸🇦 Arabisch'], ['TH', 'th', '🇹🇭 Thai'], ['VI', 'vi', '🇻🇳 Vietnamesisch'],
];
const BY_CODE = new Map(LANGS.map(l => [l[0], l]));

class Translator {
    constructor(db, crypt, settings) { this.db = db; this.crypt = crypt; this.settings = settings; this.cache = new Map(); this.usedChars = 0; }

    // ---------- Schlüssel ----------
    keys() {
        try { return JSON.parse(this.crypt.decrypt(this.db.setting('tr_keys', '')) || '{}') || {}; } catch (e) { return {}; }
    }
    saveKey(provider, key) {
        if (!['deepl', 'google'].includes(provider)) throw new UserError('Unbekannter Anbieter.');
        key = str(key).trim();
        if (key && !/^[\w:.\-]{20,120}$/.test(key)) throw new UserError('Der Schlüssel sieht nicht gültig aus.');
        const k = this.keys();
        if (key) k[provider] = key; else delete k[provider];
        this.db.setSetting('tr_keys', this.crypt.encrypt(JSON.stringify(k)));
        this.cache.clear();
        return this.status();
    }
    status() {
        const k = this.keys();
        const mask = s => (s ? s.slice(0, 4) + '…' + s.slice(-4) : '');
        return { deepl: mask(k.deepl), google: mask(k.google), provider: this.provider(), langs: LANGS.map(l => ({ code: l[0], name: l[2] })), used: this.usedChars };
    }
    provider() {
        const want = this.settings.get('tr.provider'), k = this.keys();
        if (want === 'google' && k.google) return 'google';
        if (want === 'deepl' && k.deepl) return 'deepl';
        return k.deepl ? 'deepl' : k.google ? 'google' : '';
    }

    // ---------- Übersetzen ----------
    /** → { text, detected, provider } */
    async translate(text, target, provider) {
        text = str(text).trim();
        if (!text) throw new UserError('Kein Text.');
        if (Array.from(text).length > 5000) throw new UserError('Text zu lang (max. 5000 Zeichen).');
        provider = provider || this.provider();
        if (!provider) throw new UserError('Kein Übersetzungs-Schlüssel eingetragen (Einstellungen → Übersetzen).');
        const lang = BY_CODE.get(str(target).toUpperCase()) || BY_CODE.get(this.settings.get('tr.ui_target')) || BY_CODE.get('DE');
        const ck = provider + '|' + lang[0] + '|' + text;
        if (this.cache.has(ck)) return this.cache.get(ck);
        const key = this.keys()[provider];
        if (!key) throw new UserError((provider === 'deepl' ? 'DeepL' : 'Google') + '-Schlüssel fehlt.');
        const r = provider === 'deepl' ? await this.deepl(key, text, lang[0]) : await this.google(key, text, lang[1]);
        r.provider = provider;
        r.target = lang[0];
        this.usedChars += text.length;
        if (this.cache.size > 300) this.cache.clear();
        this.cache.set(ck, r);
        return r;
    }
    async post(url, opts, who) {
        let res;
        try { res = await fetch(url, Object.assign({ method: 'POST', signal: AbortSignal.timeout(15000) }, opts)); }
        catch (e) { throw new UserError(who + ' ist nicht erreichbar (' + (e.cause && e.cause.code || e.message) + ').'); }
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        if (!res.ok) {
            const msg = data && (data.message || (data.error && data.error.message)) || '';
            const hint = { 400: 'Anfrage ungültig', 401: 'Schlüssel ungültig', 403: 'Schlüssel ungültig oder nicht freigeschaltet', 429: 'zu viele Anfragen – kurz warten', 456: 'Monatskontingent aufgebraucht' }[res.status];
            throw new UserError(who + ': ' + (hint || 'Fehler ' + res.status) + (msg ? ' (' + cut(msg, 120) + ')' : ''));
        }
        return data || {};
    }
    async deepl(key, text, target) {
        // Free-Schlüssel enden auf „:fx“ und haben einen eigenen Server
        const base = process.env.NEXUS_DEEPL_BASE || (key.endsWith(':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com');
        const d = await this.post(base + '/v2/translate', { headers: { Authorization: 'DeepL-Auth-Key ' + key, 'Content-Type': 'application/json' }, body: JSON.stringify({ text: [text], target_lang: target }) }, 'DeepL');
        const t = (d.translations || [])[0] || {};
        return { text: str(t.text), detected: str(t.detected_source_language) };
    }
    async google(key, text, target) {
        const base = process.env.NEXUS_GOOGLE_BASE || 'https://translation.googleapis.com';
        const d = await this.post(base + '/language/translate/v2?key=' + encodeURIComponent(key), { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ q: [text], target, format: 'text' }) }, 'Google');
        const t = ((d.data || {}).translations || [])[0] || {};
        return { text: str(t.translatedText), detected: str(t.detectedSourceLanguage).toUpperCase() };
    }

    /** Chatbox-Text: übersetzen und je nach Einstellung mit Original kombinieren (max. 144 Zeichen) */
    async chat(text, target) {
        const r = await this.translate(text, target || this.settings.get('tr.chat_target'));
        const mode = this.settings.get('tr.chat_mode');
        let out = r.text;
        if (mode === 'both') out = text + ' / ' + r.text;
        if (mode === 'both_nl') out = text + '\n' + r.text;
        if (Array.from(out).length > 144) out = r.text;           // passt nicht → nur die Übersetzung
        return { text: Array.from(out).slice(0, 144).join(''), translation: r };
    }
}

module.exports = { Translator, LANGS };
