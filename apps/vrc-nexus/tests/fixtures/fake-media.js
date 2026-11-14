// Ersatz für das PowerShell-Skript der Windows-Mediensteuerung (Tests): gleiches Zeilenprotokoll
// stdout: alle 300 ms eine JSON-Zeile mit den Sitzungen · stdin: „toggle|next|prev|play|pause [app-id]“
const songs = [['Daft Punk', 'Harder, Better, Faster, Stronger', 224], ['Lofi Girl', 'Snowfall', 151]];
let i = 0, playing = true, pos = 42;
const out = () => process.stdout.write(JSON.stringify([
    { app: 'Spotify.exe', title: songs[i][1], artist: songs[i][0], album: 'Album', status: playing ? 4 : 5, pos, dur: songs[i][2], age: 0, cur: true },
    { app: '308046B0AF4A39CB', title: 'Ein YouTube-Video', artist: '', album: '', status: 5, pos: 10, dur: 600, age: 0, cur: false },
]) + '\n');
let buf = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', d => {
    buf += d;
    let n;
    while ((n = buf.indexOf('\n')) >= 0) {
        const [cmd] = buf.slice(0, n).trim().split(' ');
        buf = buf.slice(n + 1);
        if (cmd === 'toggle') playing = !playing;
        if (cmd === 'pause') playing = false;
        if (cmd === 'play') playing = true;
        if (cmd === 'next') { i = (i + 1) % songs.length; pos = 0; }
        if (cmd === 'prev') { i = (i + songs.length - 1) % songs.length; pos = 0; }
        out();
    }
});
process.stdin.on('end', () => process.exit(0));
out();
setInterval(() => { if (playing) pos++; out(); }, 300);
