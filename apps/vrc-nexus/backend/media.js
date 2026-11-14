'use strict';
/*
 * „Spielt gerade“ über die Windows-Mediensteuerung (System Media Transport Controls):
 * alles, was Windows im Lautstärke-/Medien-Popup zeigt – Spotify, Browser (YouTube, SoundCloud …), Apple Music, Tidal, VLC, Medienwiedergabe …
 * Ein dauerhafter PowerShell-Prozess (Skript per -EncodedCommand) meldet alle 2 s die Sitzungen als JSON-Zeile auf stdout.
 * Befehle (play/pause/next/prev) kommen über eine kleine Befehlsdatei im Temp-Ordner – stdin wird bewusst nicht benutzt,
 * denn [Console]::In.ReadLineAsync() blockiert in Windows PowerShell 5.1 (dann käme nie eine Ausgabe).
 * Test/Entwicklung: NEXUS_MEDIA_PS=<datei.js> startet stattdessen ein Node-Skript mit demselben Zeilenprotokoll.
 */
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const { str, int } = require('./util');

const PS = String.raw`
$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
Add-Type -AssemblyName System.Runtime.WindowsRuntime
$asTask = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation` + '`' + String.raw`1' })[0]
function Await($op, $type) { $t = $asTask.MakeGenericMethod($type).Invoke($null, @($op)); [void]$t.Wait(4000); $t.Result }
$null = [Windows.Media.Control.GlobalSystemMediaTransportControlsSessionManager, Windows.Media.Control, ContentType = WindowsRuntime]
$mgr = Await ([Windows.Media.Control.GlobalSystemMediaTransportControlsSessionManager]::RequestAsync()) ([Windows.Media.Control.GlobalSystemMediaTransportControlsSessionManager])
if ($null -eq $mgr) { [Console]::Out.WriteLine('{"error":"Windows-Mediensteuerung nicht verfügbar"}'); exit 1 }
$cf = $env:NEXUS_MEDIA_CMD; $n = 0
while ($true) {
  if ($cf -and (Test-Path -LiteralPath $cf)) {
    $work = $cf + '.work'
    Move-Item -LiteralPath $cf -Destination $work -Force
    foreach ($cmd in @(Get-Content -LiteralPath $work -Encoding UTF8)) {
      $parts = ([string]$cmd).Trim().Split(' ', 2); $s = $null
      foreach ($x in $mgr.GetSessions()) { if ($parts.Count -gt 1 -and $x.SourceAppUserModelId -eq $parts[1]) { $s = $x } }
      if ($null -eq $s) { $s = $mgr.GetCurrentSession() }
      if ($null -ne $s) {
        switch ($parts[0]) { 'toggle' { $null = $s.TryTogglePlayPauseAsync() } 'next' { $null = $s.TrySkipNextAsync() } 'prev' { $null = $s.TrySkipPreviousAsync() } 'play' { $null = $s.TryPlayAsync() } 'pause' { $null = $s.TryPauseAsync() } }
      }
    }
    Remove-Item -LiteralPath $work -Force; $n = 3
  }
  if ($n -le 0) {
    if ($env:NEXUS_MEDIA_PARENT -and -not (Get-Process -Id ([int]$env:NEXUS_MEDIA_PARENT) -ErrorAction SilentlyContinue)) { exit 0 }   # App beendet → mit beenden
    $out = New-Object System.Collections.ArrayList
    $cur = $mgr.GetCurrentSession(); $curId = if ($cur) { $cur.SourceAppUserModelId } else { '' }
    foreach ($s in $mgr.GetSessions()) {
      try {
        $p = Await ($s.TryGetMediaPropertiesAsync()) ([Windows.Media.Control.GlobalSystemMediaTransportControlsSessionMediaProperties])
        $i = $s.GetPlaybackInfo(); $tl = $s.GetTimelineProperties()
        $age = ([DateTimeOffset]::Now - $tl.LastUpdatedTime).TotalSeconds
        [void]$out.Add(@{ app = $s.SourceAppUserModelId; title = $p.Title; artist = $p.Artist; album = $p.AlbumTitle; status = [int]$i.PlaybackStatus; pos = [math]::Round($tl.Position.TotalSeconds); dur = [math]::Round($tl.EndTime.TotalSeconds); age = [math]::Round($age); cur = ($s.SourceAppUserModelId -eq $curId) })
      } catch {}
    }
    [Console]::Out.WriteLine((ConvertTo-Json -Compress -Depth 3 -InputObject @($out.ToArray()))); [Console]::Out.Flush()
    $n = 8
  }
  Start-Sleep -Milliseconds 250; $n--
}
`;

// „Spotify.exe“, „Microsoft.ZuneMusic_8wekyb3d8bbwe!Microsoft.ZuneMusic“, „308046B0AF4A39CB“ (Firefox), „MSEdge“, „Chrome“ → lesbarer Name
const APPS = [[/spotify/i, 'Spotify'], [/zunemusic|mediaplayer/i, 'Medienwiedergabe'], [/applemusic|itunes/i, 'Apple Music'], [/308046B0AF4A39CB|firefox/i, 'Firefox'], [/msedge|edge/i, 'Edge'],
    [/chrome/i, 'Chrome'], [/opera/i, 'Opera'], [/brave/i, 'Brave'], [/vivaldi/i, 'Vivaldi'], [/tidal/i, 'TIDAL'], [/deezer/i, 'Deezer'], [/amazon/i, 'Amazon Music'], [/vlc/i, 'VLC'],
    [/foobar/i, 'foobar2000'], [/aimp/i, 'AIMP'], [/musicbee/i, 'MusicBee'], [/discord/i, 'Discord'], [/youtube/i, 'YouTube Music'], [/soundcloud/i, 'SoundCloud'], [/winamp/i, 'Winamp']];
function appName(id) {
    id = str(id);
    for (const [re, n] of APPS) if (re.test(id)) return n;
    return id.replace(/!.*$/, '').replace(/_[a-z0-9]{13}$/i, '').replace(/\.exe$/i, '').split(/[\\/.]/).filter(Boolean).pop() || id;
}
const STATUS = { 4: 'playing', 5: 'paused', 3: 'stopped', 2: 'stopped', 1: 'opening', 0: 'closed' };

class Media {
    constructor(app) {
        this.app = app;
        this.proc = null;
        this.sessions = [];
        this.at = 0;
        this.error = '';
        this.restartAt = 0;
        this.fails = 0;
    }
    available() { return !this.app.mobile && (process.platform === 'win32' || !!process.env.NEXUS_MEDIA_PS); }
    start() {
        if (!this.available() || this.proc || Date.now() < this.restartAt) return;
        let p;
        this.cmdFile = path.join(os.tmpdir(), 'vrc-nexus-media-' + process.pid + '.cmd');
        try { fs.unlinkSync(this.cmdFile); } catch (e) { /* gab es nicht */ }
        const env = Object.assign({}, process.env, { NEXUS_MEDIA_CMD: this.cmdFile, NEXUS_MEDIA_PARENT: String(process.pid) });
        try {
            const fake = process.env.NEXUS_MEDIA_PS;
            p = fake ? spawn(process.execPath, [fake], { env: Object.assign(env, { ELECTRON_RUN_AS_NODE: '1' }), windowsHide: true })
                : spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', Buffer.from(PS, 'utf16le').toString('base64')], { env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
        } catch (e) { this.error = e.message; return; }
        this.proc = p;
        let buf = '';
        p.stdout.setEncoding('utf8');
        p.stdout.on('data', d => {
            buf += d;
            let i;
            while ((i = buf.indexOf('\n')) >= 0) { const line = buf.slice(0, i).trim(); buf = buf.slice(i + 1); if (line) this.onLine(line); }
            if (buf.length > 1e6) buf = '';
        });
        this.stderr = '';
        p.stderr.setEncoding('utf8');
        p.stderr.on('data', d => { this.stderr = (this.stderr + d).slice(-2000); });
        p.on('error', e => { this.error = e.message; });
        p.on('exit', () => {
            if (this.proc !== p) return;
            this.proc = null; this.sessions = [];
            if (!this.at) this.error = 'Windows-Mediensteuerung beendet' + (this.stderr ? ': ' + this.stderr.trim().split('\n').pop().slice(0, 200) : '');
            this.fails++;
            this.restartAt = Date.now() + Math.min(300000, 5000 * this.fails);   // nicht in einer Schleife neu starten
        });
    }
    stop() {
        if (this.proc) { const p = this.proc; this.proc = null; try { p.kill(); } catch (e) { /* egal */ } }
        if (this.cmdFile) try { fs.unlinkSync(this.cmdFile); } catch (e) { /* egal */ }
    }
    onLine(line) {
        let d;
        try { d = JSON.parse(line.replace(/^\uFEFF/, '')); } catch (e) { this.error = 'Unerwartete Ausgabe: ' + line.slice(0, 120); return; }
        if (d && !Array.isArray(d) && d.error) { this.error = str(d.error); return; }
        if (!Array.isArray(d)) d = d ? [d] : [];
        this.fails = 0; this.error = '';
        this.at = Date.now();
        this.sessions = d.filter(s => s && (s.title || s.artist)).map(s => {
            const pos = Math.max(0, int(s.pos)), dur = Math.max(0, int(s.dur)), st = STATUS[int(s.status)] || 'stopped';
            return { id: str(s.app), app: appName(s.app), title: str(s.title).trim(), artist: str(s.artist).trim(), album: str(s.album).trim(), status: st,
                pos: st === 'playing' && dur ? Math.min(dur, pos + Math.max(0, int(s.age))) : pos, dur, cur: !!s.cur };
        });
    }
    /** Was gerade läuft: spielende Sitzung (die von Windows gewählte zuerst), sonst die aktuelle pausierte */
    now() {
        if (!this.sessions.length || Date.now() - this.at > 15000) return null;
        const l = this.sessions;
        return l.find(s => s.status === 'playing' && s.cur) || l.find(s => s.status === 'playing') || l.find(s => s.cur) || null;
    }
    text(s) { s = s || this.now(); return s && s.status === 'playing' ? (s.artist ? s.artist + ' – ' : '') + s.title : ''; }
    control(cmd, id) {
        if (!['toggle', 'next', 'prev', 'play', 'pause'].includes(cmd)) throw new Error('Unbekannter Befehl.');
        if (!this.proc) { this.start(); throw new Error('Windows-Mediensteuerung startet gerade – gleich nochmal versuchen.'); }
        fs.appendFileSync(this.cmdFile, cmd + (id ? ' ' + str(id).replace(/[\r\n]/g, '') : '') + '\n');
        return { ok: true };
    }
    status() { return { available: this.available(), running: !!this.proc, error: this.error, waiting: !!this.proc && !this.at, now: this.now(), sessions: this.sessions }; }
}
const fmt = s => Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
module.exports = { Media, appName, fmt, PS };
