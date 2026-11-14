'use strict';
/*
 * Sprache → Chatbox mit Whisper.
 *  - lokal: whisper.cpp (whisper-cli.exe) + ggml-Modell (tiny/base/small/medium) – wird auf Wunsch heruntergeladen, läuft offline
 *  - OpenAI: Whisper-API mit eigenem Schlüssel (verschlüsselt gespeichert)
 * Aufnahme passiert in der Oberfläche (Mikrofon → 16 kHz WAV); hier wird erkannt, optional übersetzt und in die Chatbox geschickt.
 */
const fs = require('fs');
const path = require('path');
const os = require('os');
const https = require('https');
const { execFile } = require('child_process');
const { str, int, cut, UserError } = require('./util');

const MODELS = { tiny: ['Tiny', 75], base: ['Base', 142], small: ['Small', 466], medium: ['Medium', 1500], 'large-v3-turbo': ['Large v3 Turbo', 1600] };
const LANGS = { de: 'Deutsch', en: 'Englisch', auto: 'automatisch', fr: 'Französisch', es: 'Spanisch', ja: 'Japanisch', nl: 'Niederländisch', pl: 'Polnisch', it: 'Italienisch', ru: 'Russisch', tr: 'Türkisch' };
const FALLBACK_BIN = 'https://github.com/ggml-org/whisper.cpp/releases/download/v1.7.6/whisper-bin-x64.zip';
const DEF = { enabled: false, engine: 'local', model: 'base', lang: 'de', translate: false, target: 'EN-US', whisper_en: false, prefix: '🎙 ', typing: true, sound: false,
    hotkey: 'Alt+V', vad: true, silence: 1.2, max: 30, threads: 4, bin: '', mic: '', mic_label: '', gain: 100, threshold: 2,
    mode: 'ptt', voice_min: 0.5, voice_mute_only: false, voice_game_only: true, log: true };

/** Datei herunterladen (mit Weiterleitungen), Fortschritt über onProgress(bytes, total) */
function download(url, file, onProgress, depth = 0) {
    return new Promise((resolve, reject) => {
        if (depth > 6) return reject(new Error('Zu viele Weiterleitungen'));
        https.get(url, { headers: { 'User-Agent': 'VRC-Nexus' } }, res => {
            if ([301, 302, 303, 307, 308].includes(res.statusCode) && res.headers.location) { res.resume(); return resolve(download(new URL(res.headers.location, url).toString(), file, onProgress, depth + 1)); }
            if (res.statusCode !== 200) { res.resume(); return reject(new Error('HTTP ' + res.statusCode + ' für ' + url)); }
            const total = int(res.headers['content-length']);
            let got = 0;
            const tmp = file + '.part';
            const out = fs.createWriteStream(tmp);
            res.on('data', c => { got += c.length; if (onProgress) onProgress(got, total); });
            res.pipe(out);
            out.on('finish', () => out.close(() => { fs.renameSync(tmp, file); resolve(file); }));
            out.on('error', reject);
            res.on('error', reject);
        }).on('error', reject);
    });
}
function findFile(dir, re, depth = 0) {
    let list = [];
    try { list = fs.readdirSync(dir, { withFileTypes: true }); } catch (e) { return ''; }
    for (const e of list) if (e.isFile() && re.test(e.name)) return path.join(dir, e.name);
    if (depth < 4) for (const e of list) if (e.isDirectory()) { const f = findFile(path.join(dir, e.name), re, depth + 1); if (f) return f; }
    return '';
}

/** verständliche Fehlermeldung aus der Ausgabe von whisper.cpp */
function whisperError(e, text, bin) {
    const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    if (lines.some(l => /deprecated/i.test(l))) return 'whisper.cpp: „' + path.basename(bin) + '“ ist veraltet – bitte whisper-cli.exe verwenden (unter Spiel & OSC → Sprache „Programm laden“ drücken).';
    if (e.code === 'ENOENT') return 'whisper.cpp: Programm nicht gefunden (' + bin + ').';
    if (e.killed || e.signal === 'SIGTERM') return 'whisper.cpp: hat zu lange gebraucht (über 2 Minuten) – kleineres Modell wählen.';
    if (e.code === 3221225781 || e.code === -1073741515) return 'whisper.cpp: eine DLL fehlt – Programm neu laden (Spiel & OSC → Sprache → „Programm laden“) oder das Visual-C++-Laufzeitpaket installieren.';
    const err = lines.filter(l => /error|failed|cannot|unable|invalid|not found/i.test(l)).pop() || lines.pop();
    return 'whisper.cpp: ' + (err ? err.slice(0, 300) : 'Fehlercode ' + (e.code ?? '?'));
}

class Speech {
    constructor(app, crypt) {
        this.app = app;
        this.crypt = crypt;
        this.recording = false;
        this.busy = false;
        this.last = '';
        this.lastError = '';
        this.pending = '';          // Befehl für die Oberfläche (start/stop/toggle), falls kein direkter Weg existiert
        this.dl = null;             // laufender Download { what, got, total }
    }
    dir() { return path.join(this.app.tools.userData || os.tmpdir(), 'whisper'); }
    cfg() { try { return Object.assign({}, DEF, JSON.parse(this.app.db.setting('speech_cfg', '{}'))); } catch (e) { return Object.assign({}, DEF); } }
    saveCfg(c) {
        const cur = this.cfg();
        const b = v => v === true || v === '1' || v === 'true' || v === 'on';
        const n = Object.assign(cur, {
            enabled: c.enabled !== undefined ? b(c.enabled) : cur.enabled,
            engine: ['local', 'openai'].includes(c.engine) ? c.engine : cur.engine,
            model: MODELS[c.model] ? c.model : cur.model,
            lang: LANGS[c.lang] ? c.lang : cur.lang,
            translate: c.translate !== undefined ? b(c.translate) : cur.translate,
            target: /^[A-Z]{2}(-[A-Z]{2,4})?$/i.test(str(c.target)) ? str(c.target).toUpperCase() : cur.target,
            whisper_en: c.whisper_en !== undefined ? b(c.whisper_en) : cur.whisper_en,
            prefix: c.prefix !== undefined ? cut(str(c.prefix), 20) : cur.prefix,
            typing: c.typing !== undefined ? b(c.typing) : cur.typing,
            sound: c.sound !== undefined ? b(c.sound) : cur.sound,
            hotkey: c.hotkey !== undefined ? cut(str(c.hotkey).trim(), 40) : cur.hotkey,
            vad: c.vad !== undefined ? b(c.vad) : cur.vad,
            silence: c.silence !== undefined ? Math.max(0.4, Math.min(5, Number(c.silence) || 1.2)) : cur.silence,
            max: c.max !== undefined ? Math.max(3, Math.min(120, int(c.max) || 30)) : cur.max,
            threads: c.threads !== undefined ? Math.max(1, Math.min(32, int(c.threads) || 4)) : cur.threads,
            bin: c.bin !== undefined ? cut(str(c.bin).trim(), 400) : cur.bin,
            mic: c.mic !== undefined ? cut(str(c.mic), 200) : cur.mic,
            mic_label: c.mic_label !== undefined ? cut(str(c.mic_label), 200) : cur.mic_label,
            gain: c.gain !== undefined ? Math.max(25, Math.min(400, int(c.gain) || 100)) : cur.gain,
            threshold: c.threshold !== undefined ? Math.max(1, Math.min(20, int(c.threshold) || 2)) : cur.threshold,
            mode: ['ptt', 'voice'].includes(str(c.mode)) ? str(c.mode) : cur.mode,
            voice_min: c.voice_min !== undefined ? Math.max(0.2, Math.min(3, Number(c.voice_min) || 0.5)) : cur.voice_min,
            voice_mute_only: c.voice_mute_only !== undefined ? b(c.voice_mute_only) : cur.voice_mute_only,
            voice_game_only: c.voice_game_only !== undefined ? b(c.voice_game_only) : cur.voice_game_only,
            log: c.log !== undefined ? b(c.log) : cur.log,
        });
        this.app.db.setSetting('speech_cfg', JSON.stringify(n));
        if (this.app.tools.host.applySpeech) this.app.tools.host.applySpeech(n);
        return n;
    }
    // ---------- OpenAI-Schlüssel (verschlüsselt) ----------
    key() { try { return JSON.parse(this.crypt.decrypt(this.app.db.setting('speech_key', '')) || '{}').openai || ''; } catch (e) { return ''; } }
    setKey(k) {
        k = str(k).trim();
        if (k && !/^sk-[\w-]{16,}$/.test(k)) throw new UserError('Das sieht nicht wie ein OpenAI-Schlüssel aus (beginnt mit „sk-“).');
        this.app.db.setSetting('speech_key', k ? this.crypt.encrypt(JSON.stringify({ openai: k })) : '');
    }
    // ---------- lokale Dateien ----------
    binPath() {
        const c = this.cfg();
        if (process.env.NEXUS_WHISPER_BIN) return process.env.NEXUS_WHISPER_BIN;
        // Neuere whisper.cpp-Pakete enthalten noch ein „main.exe“, das nur „veraltet“ meldet und mit Fehler endet → immer whisper-cli bevorzugen
        const cli = d => { const f = path.join(d, process.platform === 'win32' ? 'whisper-cli.exe' : 'whisper-cli'); return fs.existsSync(f) ? f : ''; };
        if (c.bin && fs.existsSync(c.bin)) return /^main(\.exe)?$/i.test(path.basename(c.bin)) ? cli(path.dirname(c.bin)) || c.bin : c.bin;
        const root = path.join(this.dir(), 'bin');
        return findFile(root, /^whisper-cli(\.exe)?$/i) || findFile(root, /^main(\.exe)?$/i);
    }
    modelPath(m) { return path.join(this.dir(), 'ggml-' + (m || this.cfg().model) + '.bin'); }
    status() {
        const c = this.cfg();
        const bin = this.binPath(), model = this.modelPath();
        const models = Object.fromEntries(Object.keys(MODELS).map(k => [k, fs.existsSync(this.modelPath(k))]));
        const ready = c.engine === 'openai' ? !!this.key() : !!bin && (models[c.model] || !!process.env.NEXUS_WHISPER_BIN);
        return { cfg: c, models: MODELS, langs: LANGS, have: models, bin, model: fs.existsSync(model) ? model : '', has_key: !!this.key(), ready, recording: this.recording, busy: this.busy,
            last: this.last, error: this.lastError, pending: this.pending, download: this.dl, windows: process.platform === 'win32',
            muted: (this.app.game.osc.params || {}).MuteSelf === true, game: !!this.app.game.state.running, log_count: this.history().length,
            engine_label: c.engine === 'openai' ? 'OpenAI Whisper' : 'whisper.cpp ' + (MODELS[c.model] || [''])[0] };
    }
    /** whisper.cpp (Windows-Programm) + Modell herunterladen */
    async install(what) {
        if (this.dl) throw new UserError('Es läuft schon ein Download.');
        fs.mkdirSync(this.dir(), { recursive: true });
        const c = this.cfg();
        this.dl = { what, got: 0, total: 0 };
        const prog = (g, t) => { this.dl.got = g; this.dl.total = t; };
        (async () => {
            if (what === 'bin') {
                if (process.platform !== 'win32') throw new Error('Das fertige whisper.cpp-Programm gibt es hier nur für Windows – unter Linux/macOS selbst bauen und den Pfad eintragen.');
                let url = FALLBACK_BIN;
                try {
                    const r = await fetch('https://api.github.com/repos/ggml-org/whisper.cpp/releases/latest', { headers: { 'User-Agent': 'VRC-Nexus', Accept: 'application/vnd.github+json' }, signal: AbortSignal.timeout(15000) });
                    const j = await r.json();
                    const a = (j.assets || []).find(x => /^whisper-bin-x64\.zip$/i.test(x.name));
                    if (a) url = a.browser_download_url;
                } catch (e) { /* Ersatzadresse */ }
                const zip = path.join(this.dir(), 'whisper-bin.zip');
                await download(url, zip, prog);
                const dest = path.join(this.dir(), 'bin');
                fs.rmSync(dest, { recursive: true, force: true });
                fs.mkdirSync(dest, { recursive: true });
                await new Promise((res, rej) => execFile('tar', ['-xf', zip, '-C', dest], { windowsHide: true, timeout: 120000 }, e => (e ? rej(new Error('Entpacken fehlgeschlagen: ' + e.message)) : res())));
                fs.rmSync(zip, { force: true });
                if (!this.binPath()) throw new Error('whisper-cli.exe nicht im Paket gefunden.');
            } else {
                const m = MODELS[what] ? what : c.model;
                await download('https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-' + m + '.bin', this.modelPath(m), prog);
            }
        })().then(() => { this.lastError = ''; }).catch(e => { this.lastError = e.message; }).finally(() => { this.dl = null; });
        return 'Download gestartet.';
    }
    removeModel(m) { if (!MODELS[m]) throw new UserError('Unbekanntes Modell.'); fs.rmSync(this.modelPath(m), { force: true }); }

    // ---------- Steuerung ----------
    ptt(op) {
        const c = this.cfg();
        if (!c.enabled) throw new UserError('Spracheingabe ist aus (Spiel & OSC → Sprache).');
        if (!['start', 'stop', 'toggle'].includes(op)) throw new UserError('Unbekannt.');
        const want = op === 'toggle' ? (this.recording ? 'stop' : 'start') : op;
        this.pending = want;
        if (this.app.tools.host.speechCmd) this.app.tools.host.speechCmd(want);
        return { msg: want === 'start' ? '🎙 Aufnahme …' : '⏹ Stopp', recording: want === 'start' };
    }
    /** von der Oberfläche: Aufnahme läuft/fertig; Befehl abgeholt */
    async state(rec, cancel) {
        const was = this.recording;
        this.recording = !!rec;
        this.pending = '';
        const c = this.cfg();
        // „tippt …“ an beim Start; aus erst nach der Erkennung (oder wenn nichts gesagt wurde)
        if (c.typing && ((this.recording && !was) || (!this.recording && cancel))) await this.app.game.oscSend('typing', { on: this.recording ? '1' : '' }).catch(() => {});
    }

    // ---------- Verlauf (gesprochen → erkannt → übersetzt) ----------
    history() { try { return JSON.parse(this.app.db.setting('speech_log', '[]')) || []; } catch (e) { return []; } }
    addLog(e) { const l = this.history(); l.unshift(e); this.app.db.setSetting('speech_log', JSON.stringify(l.slice(0, 500))); }
    /** WAV (16 kHz mono) → Text → (Übersetzung) → Chatbox */
    async transcribe(wav, opts = {}) {
        const c = this.cfg();
        if (!wav || wav.length < 1000) throw new UserError('Aufnahme zu kurz.');
        if (wav.length > 25 * 1024 * 1024) throw new UserError('Aufnahme zu lang.');
        this.busy = true;
        this.lastError = '';
        const t0 = Date.now();
        try {
            let text = c.engine === 'openai' ? await this.openai(wav, c) : await this.local(wav, c);
            text = text.replace(/\[(BLANK_AUDIO|Musik|Music|MUSIC|Applaus|Stille)\]|\((Musik|music|Stille)\)/gi, '').replace(/\s+/g, ' ').trim();
            if (!text) { this.last = ''; return { text: '', sent: '' }; }
            this.last = text;
            let sent = text;
            if (c.translate && !c.whisper_en) sent = (await this.app.tr.chat(text, c.target)).text;
            if (!opts.noSend) await this.app.game.oscSend('chatbox', { text: (c.prefix || '') + sent, sound: c.sound ? '1' : '', split: '1', vars: false });
            if (c.log) this.addLog({ t: Date.now(), text, sent: sent !== text ? sent : '', target: sent !== text ? c.target : '', lang: c.lang, engine: c.engine === 'openai' ? 'OpenAI' : 'whisper.cpp ' + ((MODELS[c.model] || [''])[0]), ms: Date.now() - t0, via: str(opts.via) || 'ptt', test: !!opts.noSend });
            return { text, sent };
        } catch (e) { this.lastError = e.message; throw e; }
        finally {
            this.busy = false;
            if (c.typing) this.app.game.oscSend('typing', { on: '' }).catch(() => {});
        }
    }
    local(wav, c) {
        const bin = this.binPath();
        if (!bin) throw new UserError('whisper.cpp fehlt – unter Spiel & OSC → Sprache „Programm laden“ drücken.');
        const model = this.modelPath();
        if (!fs.existsSync(model) && !process.env.NEXUS_WHISPER_BIN) throw new UserError('Modell „' + c.model + '“ fehlt – erst herunterladen.');
        const file = path.join(os.tmpdir(), 'nexus-speech-' + Date.now() + '.wav');
        fs.writeFileSync(file, wav);
        const args = ['-m', model, '-f', file, '-l', c.lang, '-nt', '-np', '-t', String(c.threads)];
        if (c.whisper_en) args.push('-tr');
        return new Promise((resolve, reject) => execFile(bin, args, { cwd: path.dirname(bin), windowsHide: true, timeout: 120000, maxBuffer: 4 * 1024 * 1024 }, (e, out, err) => {
            fs.rm(file, { force: true }, () => {});
            if (e) return reject(new UserError(whisperError(e, str(err) + '\n' + str(out), bin)));
            resolve(str(out).split(/\r?\n/).map(l => l.replace(/^\[[\d:.\s>-]+\]\s*/, '').trim()).filter(Boolean).join(' '));
        }));
    }
    async openai(wav, c) {
        const k = this.key();
        if (!k) throw new UserError('Kein OpenAI-Schlüssel hinterlegt.');
        const base = process.env.NEXUS_OPENAI_BASE || 'https://api.openai.com/v1';
        const fd = new FormData();
        fd.append('file', new Blob([wav], { type: 'audio/wav' }), 'sprache.wav');
        fd.append('model', 'whisper-1');
        if (c.lang !== 'auto' && !c.whisper_en) fd.append('language', c.lang);
        const r = await fetch(base + (c.whisper_en ? '/audio/translations' : '/audio/transcriptions'), { method: 'POST', headers: { Authorization: 'Bearer ' + k }, body: fd, signal: AbortSignal.timeout(60000) });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new UserError('OpenAI: ' + ((j.error && j.error.message) || 'HTTP ' + r.status));
        return str(j.text);
    }

    async action(act, input) {
        switch (act) {
            case 'speech_status': return this.status();
            case 'speech_log': {
                const q = str(input.q).toLowerCase().trim();
                const l = this.history().map((e, i) => Object.assign({ i }, e)).filter(e => !q || (e.text + ' ' + e.sent).toLowerCase().includes(q));
                return { items: l.slice(0, Math.max(10, Math.min(500, int(input.limit) || 100))), total: this.history().length };
            }
            case 'speech_log_clear': this.app.db.setSetting('speech_log', '[]'); return { items: [], total: 0, msg: 'Verlauf geleert.' };
            case 'speech_log_delete': { const l = this.history(); l.splice(int(input.i), 1); this.app.db.setSetting('speech_log', JSON.stringify(l)); return { msg: 'Gelöscht.' }; }
            case 'speech_log_send': {
                const e = this.history()[int(input.i)];
                if (!e) throw new UserError('Eintrag nicht gefunden.');
                const c = this.cfg();
                await this.app.game.oscSend('chatbox', { text: (c.prefix || '') + (input.which === 'text' ? e.text : e.sent || e.text), sound: c.sound ? '1' : '', split: '1', vars: false });
                return { msg: 'Erneut an die Chatbox gesendet.' };
            }
            case 'speech_cfg': return Object.assign(this.status(), { cfg: this.saveCfg(input), msg: 'Gespeichert.' });
            case 'speech_key': this.setKey(input.key); return Object.assign(this.status(), { msg: input.key ? 'Schlüssel gespeichert (verschlüsselt).' : 'Schlüssel entfernt.' });
            case 'speech_install': return Object.assign({ msg: await this.install(str(input.what)) }, this.status());
            case 'speech_model_delete': this.removeModel(str(input.model)); return this.status();
            case 'speech_ptt': return this.ptt(str(input.op || 'toggle'));
            case 'speech_state': await this.state(input.recording === '1' || input.recording === true, input.cancel === '1'); return { ok: true };
            case 'speech_transcribe': {
                const b64 = str(input.audio).replace(/^data:[^,]*,/, '');
                const r = await this.transcribe(Buffer.from(b64, 'base64'), { noSend: input.test === '1', via: str(input.via) });
                return Object.assign(r, { msg: r.text ? '🎙 „' + r.text + '“' : 'Nichts verstanden.' });
            }
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { Speech, MODELS, LANGS };
