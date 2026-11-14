// Ersatz für das PowerShell-Skript der Windows-Mediensteuerung (Tests): gleiches Protokoll
// stdout: alle 300 ms eine JSON-Zeile mit den Sitzungen (mit BOM wie PowerShell manchmal)
// Befehle: Datei NEXUS_MEDIA_CMD, je Zeile „toggle|next|prev|play|pause [app-id]“
const fs = require('fs');
const songs = [['Daft Punk', 'Harder, Better, Faster, Stronger', 224], ['Lofi Girl', 'Snowfall', 151]];
let i = 0, playing = true, pos = 42, first = true;
const out = () => {
    process.stdout.write((first ? '﻿' : '') + JSON.stringify([
        { app: 'Spotify.exe', title: songs[i][1], artist: songs[i][0], album: 'Album', status: playing ? 4 : 5, pos, dur: songs[i][2], age: 0, cur: true },
        { app: '308046B0AF4A39CB', title: 'Ein YouTube-Video', artist: '', album: '', status: 5, pos: 10, dur: 600, age: 0, cur: false },
    ]) + '\n');
    first = false;
};
const cf = process.env.NEXUS_MEDIA_CMD, parent = Number(process.env.NEXUS_MEDIA_PARENT);
out();
setInterval(() => {
    if (parent) { try { process.kill(parent, 0); } catch (e) { process.exit(0); } }
    if (cf && fs.existsSync(cf)) {
        const work = cf + '.work';
        fs.renameSync(cf, work);
        for (const line of fs.readFileSync(work, 'utf8').split('\n')) {
            const cmd = line.trim().split(' ')[0];
            if (cmd === 'toggle') playing = !playing;
            if (cmd === 'pause') playing = false;
            if (cmd === 'play') playing = true;
            if (cmd === 'next') { i = (i + 1) % songs.length; pos = 0; }
            if (cmd === 'prev') { i = (i + songs.length - 1) % songs.length; pos = 0; }
        }
        fs.unlinkSync(work);
        out();
    }
}, 100);
setInterval(() => { if (playing) pos++; out(); }, 300);
