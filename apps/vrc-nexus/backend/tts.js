'use strict';
/*
 * Sprachausgabe (TTS) – damit du in VRChat „sprechen“ kannst (z. B. die Übersetzung deiner Spracheingabe).
 * Anbieter: Windows-Stimmen (SAPI, offline), Piper (offline, neuronal, wird heruntergeladen),
 *           Google Cloud TTS, Microsoft Azure, ElevenLabs, OpenAI (jeweils eigener Schlüssel, verschlüsselt gespeichert).
 * Das Backend erzeugt die Audiodatei; abgespielt wird in der Oberfläche auf dem gewählten Ausgabegerät
 * (z. B. „CABLE Input“ von VB-Audio – in VRChat dann „CABLE Output“ als Mikrofon wählen).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFile, spawn } = require('child_process');
const { str, int, cut, UserError } = require('./util');
const { download, findFile } = require('./speech');

const PROVIDERS = {
    windows: ['🪟 Windows-Stimmen', 'Offline und kostenlos – nutzt die in Windows installierten Stimmen (SAPI).', false],
    piper: ['🐦 Piper', 'Offline, kostenlos, natürlich klingend – Programm + Stimme werden einmal heruntergeladen (~25–65 MB je Stimme).', false],
    google: ['🔵 Google Cloud TTS', 'Sehr viele Sprachen und Stimmen (WaveNet/Neural2). Monatliches Freikontingent, eigener API-Schlüssel.', true],
    azure: ['🟦 Microsoft Azure', 'Über 600 neuronale Stimmen in 150+ Sprachen. Freikontingent, Schlüssel + Region.', true],
    elevenlabs: ['🎙 ElevenLabs', 'Sehr natürliche, ausdrucksstarke Stimmen. Kleines Freikontingent, eigener Schlüssel.', true],
    openai: ['⚪ OpenAI', 'Gute mehrsprachige Stimmen (alloy, nova, shimmer …), günstig, kein Freikontingent.', true],
};
// Piper-Stimmen (huggingface.co/rhasspy/piper-voices) – Auswahl, weitere per eigenem .onnx-Pfad
const PIPER_VOICES = {
    'de_DE-thorsten-medium': ['Deutsch – Thorsten (männlich)', 63], 'de_DE-thorsten_emotional-medium': ['Deutsch – Thorsten emotional', 77], 'de_DE-kerstin-low': ['Deutsch – Kerstin (weiblich)', 63],
    'de_DE-eva_k-x_low': ['Deutsch – Eva (weiblich, klein)', 21], 'en_US-amy-medium': ['Englisch (US) – Amy', 63], 'en_US-lessac-medium': ['Englisch (US) – Lessac', 63],
    'en_GB-alba-medium': ['Englisch (UK) – Alba', 63], 'fr_FR-siwis-medium': ['Französisch – Siwis', 63], 'es_ES-davefx-medium': ['Spanisch – Davefx', 63], 'nl_NL-mls-medium': ['Niederländisch – MLS', 63],
};
const PIPER_BIN = 'https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_windows_amd64.zip';
const OPENAI_VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer'];
const DEF = { enabled: false, provider: 'windows', voice: '', rate: 1, volume: 100, device: '', device_label: '', monitor: false, on_speech: true, speak: 'sent', on_chat: false, azure_region: 'westeurope',
    el_model: 'eleven_multilingual_v2', oa_model: 'gpt-4o-mini-tts', piper_bin: '' };
const base = (env, def) => (process.env[env] || def).replace(/\/+$/, '');
const run = (cmd, args, opts = {}) => new Promise((res, rej) => {
    const p = execFile(cmd, args, Object.assign({ windowsHide: true, timeout: 60000, maxBuffer: 50 * 1024 * 1024, encoding: 'buffer' }, opts), (e, out, err) => (e ? rej(new UserError((String(err || '').trim().split('\n').pop() || e.message).slice(0, 300))) : res(out)));
    if (opts.input !== undefined) { p.stdin.end(opts.input); }
});

class Tts {
    constructor(app, crypt) {
        this.app = app;
        this.crypt = crypt;
        this.queue = [];         // fertige Audios für die Oberfläche (Abspielen auf dem gewählten Gerät)
        this.seq = 0;
        this.dl = null;
        this.lastError = '';
        this.voiceCache = {};
    }
    dir() { return path.join(this.app.tools.userData || os.tmpdir(), 'tts'); }
    cfg() { try { return Object.assign({}, DEF, JSON.parse(this.app.db.setting('tts_cfg', '{}'))); } catch (e) { return Object.assign({}, DEF); } }
    saveCfg(c) {
        const cur = this.cfg(), b = v => v === true || v === '1' || v === 'true' || v === 'on';
        const n = Object.assign(cur, {
            enabled: c.enabled !== undefined ? b(c.enabled) : cur.enabled,
            provider: PROVIDERS[c.provider] ? c.provider : cur.provider,
            voice: c.voice !== undefined ? cut(str(c.voice), 200) : cur.voice,
            rate: c.rate !== undefined ? Math.max(0.5, Math.min(2, Number(c.rate) || 1)) : cur.rate,
            volume: c.volume !== undefined ? Math.max(0, Math.min(200, int(c.volume))) : cur.volume,
            device: c.device !== undefined ? cut(str(c.device), 200) : cur.device,
            device_label: c.device_label !== undefined ? cut(str(c.device_label), 200) : cur.device_label,
            monitor: c.monitor !== undefined ? b(c.monitor) : cur.monitor,
            on_speech: c.on_speech !== undefined ? b(c.on_speech) : cur.on_speech,
            speak: ['sent', 'text', 'both'].includes(str(c.speak)) ? str(c.speak) : cur.speak,
            on_chat: c.on_chat !== undefined ? b(c.on_chat) : cur.on_chat,
            azure_region: c.azure_region !== undefined ? str(c.azure_region).replace(/[^a-z0-9]/gi, '').toLowerCase().slice(0, 30) || 'westeurope' : cur.azure_region,
            el_model: c.el_model !== undefined ? str(c.el_model).replace(/[^\w.-]/g, '').slice(0, 60) || DEF.el_model : cur.el_model,
            oa_model: c.oa_model !== undefined ? str(c.oa_model).replace(/[^\w.-]/g, '').slice(0, 60) || DEF.oa_model : cur.oa_model,
            piper_bin: c.piper_bin !== undefined ? cut(str(c.piper_bin).trim(), 400) : cur.piper_bin,
        });
        if (c.provider !== undefined && c.provider !== cur.provider && c.voice === undefined) n.voice = '';
        this.app.db.setSetting('tts_cfg', JSON.stringify(n));
        return n;
    }
    // ---------- Schlüssel (verschlüsselt) ----------
    keys() { try { return JSON.parse(this.crypt.decrypt(this.app.db.setting('tts_keys', '')) || '{}') || {}; } catch (e) { return {}; } }
    setKey(provider, key) {
        if (!PROVIDERS[provider] || !PROVIDERS[provider][2]) throw new UserError('Dieser Anbieter braucht keinen Schlüssel.');
        key = str(key).trim();
        if (key && !/^[\w.\-:]{16,300}$/.test(key)) throw new UserError('Das sieht nicht wie ein gültiger Schlüssel aus.');
        const k = this.keys();
        if (key) k[provider] = key; else delete k[provider];
        this.app.db.setSetting('tts_keys', this.crypt.encrypt(JSON.stringify(k)));
        this.voiceCache[provider] = null;
    }
    /** Schlüssel für einen Anbieter – Google/OpenAI dürfen vorhandene Schlüssel (Übersetzer / Spracheingabe) mitbenutzen */
    key(p) {
        const k = this.keys()[p];
        if (k) return k;
        try { if (p === 'google') return (this.app.tr.keys() || {}).google || ''; } catch (e) { /* egal */ }
        try { if (p === 'openai' && this.app.speech) return this.app.speech.key() || ''; } catch (e) { /* egal */ }
        return '';
    }
    piperBin() {
        const c = this.cfg();
        if (process.env.NEXUS_PIPER_BIN) return process.env.NEXUS_PIPER_BIN;
        if (c.piper_bin && fs.existsSync(c.piper_bin)) return c.piper_bin;
        return findFile(path.join(this.dir(), 'piper'), /^piper(\.exe)?$/i);
    }
    piperModel(v) { return path.join(this.dir(), (v || 'de_DE-thorsten-medium') + '.onnx'); }
    status() {
        const c = this.cfg(), k = this.keys();
        return { cfg: c, providers: PROVIDERS, piper_voices: PIPER_VOICES, piper_have: Object.fromEntries(Object.keys(PIPER_VOICES).map(v => [v, fs.existsSync(this.piperModel(v))])),
            piper_bin: this.piperBin(), has: Object.fromEntries(Object.keys(PROVIDERS).map(p => [p, PROVIDERS[p][2] ? !!this.key(p) : true])), own_key: Object.fromEntries(Object.keys(k).map(p => [p, true])),
            download: this.dl, error: this.lastError, windows: process.platform === 'win32', seq: this.seq };
    }

    // ---------- Stimmen je Anbieter ----------
    async voices(p) {
        p = PROVIDERS[p] ? p : this.cfg().provider;
        const cached = this.voiceCache[p];
        if (cached && Date.now() - cached.at < 3600000) return cached.list;
        let list = [];
        const k = this.key(p);
        if (p === 'windows') {
            if (process.platform !== 'win32' && !process.env.NEXUS_FAKE_SAPI) return [];
            const out = process.env.NEXUS_FAKE_SAPI ? Buffer.from('Microsoft Hedda Desktop|de-DE|Female\nMicrosoft Zira Desktop|en-US|Female') : await run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command',
                'Add-Type -AssemblyName System.Speech; $s=New-Object System.Speech.Synthesis.SpeechSynthesizer; $s.GetInstalledVoices() | ForEach-Object { $v=$_.VoiceInfo; $v.Name+"|"+$v.Culture.Name+"|"+$v.Gender }']);
            list = String(out).split(/\r?\n/).filter(Boolean).map(l => { const [name, lang, g] = l.split('|'); return { id: name, name: name.replace(/^Microsoft /, '') + (g ? ' (' + (g === 'Female' ? 'w' : g === 'Male' ? 'm' : g) + ')' : ''), lang }; });
        } else if (p === 'piper') {
            list = Object.entries(PIPER_VOICES).map(([id, v]) => ({ id, name: v[0] + (fs.existsSync(this.piperModel(id)) ? '' : ' – noch laden (' + v[1] + ' MB)'), lang: id.slice(0, 5).replace('_', '-') }));
        } else if (p === 'openai') {
            list = OPENAI_VOICES.map(v => ({ id: v, name: v, lang: 'multi' }));
        } else {
            if (!k) return [];
            if (p === 'google') {
                const j = await this.json(base('NEXUS_GTTS_BASE', 'https://texttospeech.googleapis.com') + '/v1/voices?key=' + encodeURIComponent(k));
                list = (j.voices || []).map(v => ({ id: v.name, name: v.name + ' (' + (v.ssmlGender === 'FEMALE' ? 'w' : v.ssmlGender === 'MALE' ? 'm' : '–') + ')', lang: (v.languageCodes || [])[0] || '' }));
            }
            if (p === 'azure') {
                const j = await this.json(base('NEXUS_AZURE_BASE', 'https://' + this.cfg().azure_region + '.tts.speech.microsoft.com') + '/cognitiveservices/voices/list', { headers: { 'Ocp-Apim-Subscription-Key': k } });
                list = (Array.isArray(j) ? j : []).map(v => ({ id: v.ShortName, name: v.LocalName + ' – ' + v.ShortName + ' (' + (v.Gender === 'Female' ? 'w' : 'm') + ')', lang: v.Locale }));
            }
            if (p === 'elevenlabs') {
                const j = await this.json(base('NEXUS_EL_BASE', 'https://api.elevenlabs.io') + '/v1/voices', { headers: { 'xi-api-key': k } });
                list = (j.voices || []).map(v => ({ id: v.voice_id, name: v.name + (v.labels && v.labels.gender ? ' (' + v.labels.gender + ')' : ''), lang: (v.labels && v.labels.language) || 'multi' }));
            }
        }
        list.sort((a, b) => a.lang.localeCompare(b.lang) || a.name.localeCompare(b.name));
        this.voiceCache[p] = { at: Date.now(), list };
        return list;
    }
    async json(url, opts = {}) {
        const r = await fetch(url, Object.assign({ signal: AbortSignal.timeout(15000) }, opts));
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new UserError('Anbieter antwortet mit HTTP ' + r.status + (j.error && j.error.message ? ': ' + j.error.message : j.detail && j.detail.message ? ': ' + j.detail.message : ''));
        return j;
    }

    // ---------- Sprechen ----------
    /** Text → Audio { mime, b64 } */
    async synth(text, opt = {}) {
        const c = Object.assign(this.cfg(), opt);
        text = str(text).replace(/\s+/g, ' ').trim().slice(0, 1000);
        if (!text) throw new UserError('Kein Text.');
        const p = c.provider, k = this.key(p);
        if (PROVIDERS[p][2] && !k) throw new UserError(PROVIDERS[p][0] + ': Schlüssel fehlt.');
        const rate = Number(c.rate) || 1;
        if (p === 'windows') {
            if (process.platform !== 'win32' && !process.env.NEXUS_FAKE_SAPI) throw new UserError('Windows-Stimmen gibt es nur unter Windows.');
            const f = path.join(os.tmpdir(), 'nexus-tts-' + Date.now() + '.wav');
            const tf = f + '.txt';
            fs.writeFileSync(tf, text, 'utf8');
            const ps = 'Add-Type -AssemblyName System.Speech; $s=New-Object System.Speech.Synthesis.SpeechSynthesizer;'
                + (c.voice ? ' try { $s.SelectVoice(' + JSON.stringify(c.voice).replace(/"/g, "'") + ') } catch {};' : '')
                + ' $s.Rate=' + Math.max(-10, Math.min(10, Math.round((rate - 1) * 10))) + '; $s.SetOutputToWaveFile(' + JSON.stringify(f).replace(/"/g, "'") + '); $s.Speak([IO.File]::ReadAllText(' + JSON.stringify(tf).replace(/"/g, "'") + ')); $s.Dispose()';
            try {
                if (process.env.NEXUS_FAKE_SAPI) fs.writeFileSync(f, Buffer.concat([Buffer.from('RIFF'), Buffer.alloc(200)]));
                else await run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', ps]);
                return { mime: 'audio/wav', b64: fs.readFileSync(f).toString('base64') };
            } finally { fs.rm(f, { force: true }, () => {}); fs.rm(tf, { force: true }, () => {}); }
        }
        if (p === 'piper') {
            const bin = this.piperBin();
            if (!bin) throw new UserError('Piper fehlt – unter Sprachausgabe „Piper laden“ drücken.');
            const voice = c.voice && PIPER_VOICES[c.voice] ? c.voice : c.voice && /\.onnx$/i.test(c.voice) ? c.voice : 'de_DE-thorsten-medium';
            const model = /\.onnx$/i.test(voice) ? voice : this.piperModel(voice);
            if (!fs.existsSync(model) && !process.env.NEXUS_PIPER_BIN) throw new UserError('Stimme „' + voice + '“ fehlt – erst laden.');
            const f = path.join(os.tmpdir(), 'nexus-piper-' + Date.now() + '.wav');
            try {
                const args = ['--model', model, '--output_file', f, '--length_scale', String(Math.round(100 / rate) / 100)];
                if (/\.js$/i.test(bin)) await run(process.execPath, [bin].concat(args), { input: text });          // Test-Ersatz
                else await run(bin, args, { input: text, cwd: path.dirname(bin) });
                return { mime: 'audio/wav', b64: fs.readFileSync(f).toString('base64') };
            } finally { fs.rm(f, { force: true }, () => {}); }
        }
        let r;
        if (p === 'google') {
            const voice = c.voice || 'de-DE-Neural2-B';
            r = await fetch(base('NEXUS_GTTS_BASE', 'https://texttospeech.googleapis.com') + '/v1/text:synthesize?key=' + encodeURIComponent(k), { method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: AbortSignal.timeout(20000),
                body: JSON.stringify({ input: { text }, voice: { languageCode: voice.split('-').slice(0, 2).join('-'), name: voice }, audioConfig: { audioEncoding: 'MP3', speakingRate: rate } }) });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) throw new UserError('Google: ' + ((j.error && j.error.message) || 'HTTP ' + r.status));
            return { mime: 'audio/mpeg', b64: j.audioContent };
        }
        if (p === 'azure') {
            const voice = c.voice || 'de-DE-KatjaNeural';
            const esc = s => s.replace(/[<>&'"]/g, x => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' }[x]));
            const ssml = '<speak version="1.0" xml:lang="' + voice.split('-').slice(0, 2).join('-') + '"><voice name="' + esc(voice) + '"><prosody rate="' + Math.round((rate - 1) * 100) + '%">' + esc(text) + '</prosody></voice></speak>';
            r = await fetch(base('NEXUS_AZURE_BASE', 'https://' + c.azure_region + '.tts.speech.microsoft.com') + '/cognitiveservices/v1', { method: 'POST', signal: AbortSignal.timeout(20000),
                headers: { 'Ocp-Apim-Subscription-Key': k, 'Content-Type': 'application/ssml+xml', 'X-Microsoft-OutputFormat': 'audio-24khz-48kbitrate-mono-mp3', 'User-Agent': 'VRC-Nexus' }, body: ssml });
        }
        if (p === 'elevenlabs') {
            if (!c.voice) throw new UserError('ElevenLabs: erst eine Stimme wählen.');
            r = await fetch(base('NEXUS_EL_BASE', 'https://api.elevenlabs.io') + '/v1/text-to-speech/' + encodeURIComponent(c.voice), { method: 'POST', signal: AbortSignal.timeout(30000),
                headers: { 'xi-api-key': k, 'Content-Type': 'application/json', Accept: 'audio/mpeg' }, body: JSON.stringify({ text, model_id: c.el_model, voice_settings: { stability: 0.5, similarity_boost: 0.75, speed: Math.max(0.7, Math.min(1.2, rate)) } }) });
        }
        if (p === 'openai') {
            r = await fetch(base('NEXUS_OPENAI_BASE', 'https://api.openai.com/v1') + '/audio/speech', { method: 'POST', signal: AbortSignal.timeout(30000),
                headers: { Authorization: 'Bearer ' + k, 'Content-Type': 'application/json' }, body: JSON.stringify({ model: c.oa_model, voice: OPENAI_VOICES.includes(c.voice) ? c.voice : 'nova', input: text, speed: Math.max(0.25, Math.min(4, rate)), response_format: 'mp3' }) });
        }
        if (!r.ok) {
            const t = await r.text().catch(() => '');
            let m = ''; try { const j = JSON.parse(t); m = (j.error && (j.error.message || j.error)) || (j.detail && (j.detail.message || j.detail)) || ''; } catch (e) { m = t.slice(0, 120); }
            throw new UserError(PROVIDERS[p][0].replace(/^\S+ /, '') + ': ' + (m || 'HTTP ' + r.status));
        }
        return { mime: r.headers.get('content-type') || 'audio/mpeg', b64: Buffer.from(await r.arrayBuffer()).toString('base64') };
    }
    /** Text sprechen: erzeugen und für die Oberfläche einreihen (die spielt auf dem gewählten Gerät ab) */
    async say(text, via = '') {
        const c = this.cfg();
        if (!c.enabled) throw new UserError('Sprachausgabe ist aus.');
        this.lastError = '';
        try {
            const a = await this.synth(text);
            const item = Object.assign({ id: ++this.seq, text: str(text).slice(0, 300), via, t: Date.now() }, a);
            this.queue.push(item);
            if (this.queue.length > 10) this.queue.splice(0, this.queue.length - 10);
            return item;
        } catch (e) { this.lastError = e.message; throw e; }
    }
    take(since) { return this.queue.filter(x => x.id > int(since)); }

    // ---------- Piper herunterladen ----------
    async install(what) {
        if (this.dl) throw new UserError('Es läuft schon ein Download.');
        fs.mkdirSync(this.dir(), { recursive: true });
        this.dl = { what, got: 0, total: 0 };
        const prog = (g, t) => { this.dl.got = g; this.dl.total = t; };
        (async () => {
            if (what === 'bin') {
                if (process.platform !== 'win32') throw new Error('Das fertige Piper-Programm gibt es hier nur für Windows – sonst selbst installieren und den Pfad eintragen.');
                const zip = path.join(this.dir(), 'piper.zip');
                await download(PIPER_BIN, zip, prog);
                fs.rmSync(path.join(this.dir(), 'piper'), { recursive: true, force: true });
                await new Promise((res, rej) => execFile('tar', ['-xf', zip, '-C', this.dir()], { windowsHide: true, timeout: 120000 }, e => (e ? rej(new Error('Entpacken fehlgeschlagen: ' + e.message)) : res())));
                fs.rmSync(zip, { force: true });
                if (!this.piperBin()) throw new Error('piper.exe nicht im Paket gefunden.');
            } else {
                if (!PIPER_VOICES[what]) throw new Error('Unbekannte Stimme.');
                const [lang, name, q] = [what.split('-')[0], what.split('-')[1], what.split('-')[2]];
                const u = 'https://huggingface.co/rhasspy/piper-voices/resolve/main/' + lang.split('_')[0] + '/' + lang + '/' + name + '/' + q + '/' + what;
                await download(u + '.onnx.json', this.piperModel(what) + '.json', () => {});
                await download(u + '.onnx', this.piperModel(what), prog);
            }
        })().then(() => { this.lastError = ''; this.voiceCache.piper = null; }).catch(e => { this.lastError = e.message; }).finally(() => { this.dl = null; });
        return 'Download gestartet.';
    }

    async action(act, input) {
        switch (act) {
            case 'tts_status': return this.status();
            case 'tts_cfg': return Object.assign(this.status(), { cfg: this.saveCfg(input), msg: 'Gespeichert.' });
            case 'tts_key': this.setKey(str(input.provider), input.key); return Object.assign(this.status(), { msg: input.key ? 'Schlüssel gespeichert (verschlüsselt).' : 'Schlüssel entfernt.' });
            case 'tts_voices': return { voices: await this.voices(str(input.provider)) };
            case 'tts_say': {
                const it = await this.say(str(input.text), 'manuell');
                if (input.chatbox === '1') await this.app.game.oscSend('chatbox', { text: str(input.text), sound: '', split: '1', vars: false }).catch(() => {});
                return { item: it };
            }
            case 'tts_poll': return { items: this.take(input.since), seq: this.seq };
            case 'tts_install': return Object.assign({ msg: await this.install(str(input.what)) }, this.status());
            case 'tts_voice_delete': {
                if (!PIPER_VOICES[str(input.voice)]) throw new UserError('Unbekannte Stimme.');
                fs.rmSync(this.piperModel(input.voice), { force: true }); fs.rmSync(this.piperModel(input.voice) + '.json', { force: true });
                this.voiceCache.piper = null;
                return this.status();
            }
        }
        throw new UserError('Unbekannte Aktion.');
    }
}

module.exports = { Tts, PROVIDERS, PIPER_VOICES };
