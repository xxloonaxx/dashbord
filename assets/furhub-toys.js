/* Furry Hub – Spielzeug: Fluff-Orakel, Horoskop, Glückskeks, Plopp-Folie, Pixel-Sticker, Fursuit-Pausen-Timer, Ship-Namen */
(function () {
    'use strict';
    const K = window.FHK; if (!K) return;
    const { FH, $, $$, esc, rnd, store, hash, toast, confetti, floatText, unlock, sfx, download } = K;
    const daySeed = s => hash(new Date().toDateString() + '|' + s);
    const pick = (list, seed) => list[seed % list.length];

    // ---------- Fluff-Orakel ----------
    const ORACLE = ['Die Pfoten sagen: Ja! 🐾', 'Absolut – awoo! 🐺', 'Ganz sicher. Boop drauf. 👃', 'Die Sterne über der Con sagen ja. ✨', 'Sieht flauschig gut aus.', 'Ja – aber erst nach einem Snack. 🍪',
        'Frag nach dem nächsten Headpat nochmal. 🤚', 'Das Orakel macht gerade ein Nickerchen. 😴', 'Die Zukunft ist noch verfilzt … bürste sie nochmal.', 'Konzentriere dich und wedel nochmal.',
        'Eher nicht. Nimm einen Keks zum Trost. 🍪', 'Meine Ohren sagen nein. 👂', 'Nope. Nicht mal für Leckerlis.', 'Sehr zweifelhaft … *schnüffel*', 'Auf keinen Fall – aber du bist trotzdem toll. 💖',
        'Die Antwort liegt in der Headless Lounge.', 'UwU … ja.', 'OwO? Vielleicht!', 'Frag einen Otter. 🦦', 'Das weiß nur der Waschbär. 🦝'];
    function toyOracle(b) {
        b.innerHTML = `<div class="toy-h">🔮 Fluff-Orakel</div><div class="orb" id="orb"><span>?</span></div>
            <form class="toy-row" id="orForm"><input id="orQ" maxlength="120" placeholder="Stell eine Ja/Nein-Frage …" required><button class="fh-btn sm gold">Fragen</button></form>`;
        $('#orForm', b).onsubmit = e => {
            e.preventDefault();
            const orb = $('#orb', b); orb.classList.remove('shake'); void orb.offsetWidth; orb.classList.add('shake');
            orb.innerHTML = '<span>…</span>'; sfx.mrrp && sfx.mrrp();
            setTimeout(() => { orb.innerHTML = '<span class="ans">' + esc(rnd(ORACLE)) + '</span>'; unlock('oracle'); }, 1100);
        };
    }

    // ---------- Horoskop ----------
    const HS = {
        fox: '🦊 Fuchs', wolf: '🐺 Wolf', cat: '🐱 Katze', dog: '🐶 Hund', dragon: '🐉 Drache', otter: '🦦 Otter', rabbit: '🐰 Hase', raccoon: '🦝 Waschbär',
        redpanda: '🐼 Roter Panda', novabeast: '🌌 Novabeast', bird: '🐦 Vogel', deer: '🦌 Hirsch', tiger: '🐯 Tiger', protogen: '🤖 Protogen', bat: '🦇 Fledermaus',
    };
    const H_MOOD = ['Heute fühlt sich dein Fell besonders weich an.', 'Eine unerwartete Nachricht bringt dein Schwänzchen zum Wedeln.', 'Du hast heute Energie für drei Furwalks.', 'Ein gemütlicher Tag – Decke und Tee sind deine Verbündeten.',
        'Deine Kreativität sprudelt: Zeit für eine neue Zeichnung!', 'Jemand denkt gerade an deine Nase. 👃', 'Die Sterne empfehlen: weniger Doomscrolling, mehr Headpats.', 'Heute klappt alles, was du mit Pfoten anpackst.',
        'Ein kleines Chaos wartet – aber du bist ja eh chaotisch.', 'Du strahlst heute wie ein frisch gebürsteter Samojede.'];
    const H_LOVE = ['Ein Boop zur richtigen Zeit öffnet heute Herzen.', 'Schreib der Person, an die du gerade denkst.', 'Gemeinsame Snacks stärken heute jede Bindung.', 'Jemand aus deinem Rudel braucht eine Umarmung.',
        'Dein Charme wirkt heute doppelt – nutze ihn weise.', 'Ein Kompliment an eine Sona macht heute jemanden glücklich.'];
    const H_TIP = ['Trink ein Glas Wasser mehr. 💧', 'Zeichne heute deine Sona mit einem Hut.', 'Plane schon mal das Outfit für die nächste Con.', 'Gönn dir ein Nickerchen – Profis nennen es „Powernap“.',
        'Räum dein Postfach auf und belohne dich mit einem Keks.', 'Sag „Awoo“ in einen Chat deiner Wahl.', 'Probier heute eine neue Welt in VRChat aus.', 'Ruf einen alten Freund an.'];
    const SNACKS = ['🍪 Keks', '🍕 Pizza', '🍣 Sushi', '🫐 Blaubeeren', '🍩 Donut', '🥨 Brezel', '🍫 Schoki (nur für Menschen!)', '🧁 Cupcake', '🍜 Ramen', '🥕 Karotte'];
    function toyHoro(b) {
        const mySp = (K.currentLook() || {}).species;
        let sp = store.get('horoSp', HS[mySp] ? mySp : 'fox');
        const render = () => {
            const s = daySeed(sp), st = n => '★'.repeat(n) + '☆'.repeat(5 - n);
            b.innerHTML = `<div class="toy-h">✨ Horoskop des Tages</div><select id="hsSel">${Object.entries(HS).map(([k, l]) => `<option value="${k}" ${k === sp ? 'selected' : ''}>${l}</option>`).join('')}</select>
                <div class="horo"><p>🌙 ${esc(pick(H_MOOD, s))}</p><p>💞 ${esc(pick(H_LOVE, s >>> 3))}</p><p>💡 ${esc(pick(H_TIP, s >>> 6))}</p>
                <div class="horo-st"><span>Flausch ${st(1 + (s % 5))}</span><span>Glück ${st(1 + ((s >>> 4) % 5))}</span><span>Energie ${st(1 + ((s >>> 8) % 5))}</span></div>
                <small>Glücks-Snack: ${pick(SNACKS, s >>> 10)} · Glückszahl: ${1 + (s % 99)}</small></div>`;
            $('#hsSel', b).onchange = e => { sp = e.target.value; store.set('horoSp', sp); render(); };
        };
        render();
    }

    // ---------- Glückskeks ----------
    const COOKIES = ['Ein Boop am Morgen vertreibt Kummer und Sorgen.', 'Dein nächster Con-Besuch wird legendär.', 'Wer viel kuschelt, hat viel Fell an der Kleidung – und viel Glück.', 'Heute findest du den perfekten Sticker.',
        'Ein Fremder wird deine Sona erkennen.', 'Auch kleine Pfoten hinterlassen große Spuren.', 'Die beste Zeit für ein Nickerchen ist jetzt.', 'Ein Artist wird bald deine Commission annehmen.',
        'Dein Lachen ist ansteckender als Con Crud.', 'Glück ist eine warme Decke und ein voller Snack-Vorrat.', 'Sag öfter, was du an deinen Freunden magst.', 'Ein Otter hält heute an dich gedacht die Pfote.',
        'Du bist flauschiger, als du denkst.', 'Neue Abenteuer warten hinter der nächsten VRChat-Welt.', 'Wer zuletzt awoot, awoot am besten.', 'Ein Kompliment kostet nichts und wärmt das Fell.',
        'Dein Rudel ist größer, als du glaubst.', 'Trink Wasser, du wunderbares Wesen. 💧', 'Heute ist ein guter Tag, um etwas Neues zu zeichnen.', 'Die Antwort ist: mehr Headpats.'];
    function toyCookie(b) {
        b.innerHTML = `<div class="toy-h">🥠 Glückskeks</div><button type="button" class="cookie" id="cookie" title="Knacken!">🥠</button><div class="fortune" id="fortune">Klick den Keks!</div>`;
        $('#cookie', b).onclick = () => {
            const c = $('#cookie', b); c.classList.remove('crack'); void c.offsetWidth; c.classList.add('crack'); sfx.squeak && sfx.squeak();
            const nums = Array.from({ length: 4 }, () => 1 + Math.floor(Math.random() * 49)).sort((x, y) => x - y);
            setTimeout(() => { $('#fortune', b).innerHTML = '„' + esc(rnd(COOKIES)) + '“<small>Glückszahlen: ' + nums.join(' · ') + '</small>'; unlock('cookie'); }, 380);
        };
    }

    // ---------- Plopp-Folie ----------
    function toyPop(b) {
        let total = store.get('pops', 0);
        const fill = () => {
            $('#pops', b).innerHTML = Array.from({ length: 48 }, (_, i) => `<button type="button" class="bub" data-b="${i}" aria-label="Blase"></button>`).join('');
        };
        b.innerHTML = `<div class="toy-h">🫧 Plopp-Folie <small id="popN"></small></div><div class="pops" id="pops"></div><button type="button" class="fh-btn sm" id="popNew">🔄 Neue Folie</button>`;
        const upd = () => { $('#popN', b).textContent = total.toLocaleString('de-DE') + ' geploppt'; };
        let down = false;
        const pop = el => {
            if (!el || el.classList.contains('pp')) return;
            el.classList.add('pp'); total++; store.set('pops', total); upd();
            try { sfx.boop(); } catch (e) { /* stumm */ }
            if (total >= 100) unlock('pop');
            if (!$$('.bub:not(.pp)', b).length) { confetti(16, ['🫧', '✨']); setTimeout(fill, 600); }
        };
        b.addEventListener('pointerdown', e => { down = true; pop(e.target.closest('.bub')); });
        b.addEventListener('pointerover', e => { if (down) pop(e.target.closest('.bub')); });
        window.addEventListener('pointerup', () => { down = false; });
        $('#popNew', b).onclick = fill;
        fill(); upd();
    }

    // ---------- Pixel-Sticker ----------
    const PAL = ['#000000', '#ffffff', '#7f7f7f', '#3a2a22', '#e8742a', '#ffd166', '#ff5555', '#ff8fab', '#c77dff', '#4cc9f0', '#3da5ff', '#56c271', '#a5f0ff', '#f3c262', '#8d5a3b', '#2b2540'];
    const PAW = '................|................|.....xx....xx...|....xxxx..xxxx..|....xxxx..xxxx..|.....xx....xx...|..xx..........xx|.xxxx........xxxx|.xxxx..xxxx..xxxx|..xx..xxxxxx..xx|.....xxxxxxxx...|.....xxxxxxxx...|.....xxxxxxxx...|......xxxxxx....|................|................';
    function toyPixel(b) {
        const N = 16;
        let px = store.get('pixel', null) || Array(N * N).fill(''), color = '#e8742a', tool = 'pen', mirror = store.get('pixMirror', false), down = false;
        b.innerHTML = `<div class="toy-h">🎨 Pixel-Sticker <small>16×16</small></div>
            <div class="pix-wrap"><div class="pix" id="pix"></div>
            <div class="pix-side"><div class="pal" id="pal">${PAL.map(c => `<button type="button" data-c="${c}" style="background:${c}" aria-label="${c}"></button>`).join('')}<input type="color" id="pixC" value="${color}"></div>
                <div class="pix-tools"><button type="button" data-tool="pen" class="on">✏️</button><button type="button" data-tool="erase">🧽</button><button type="button" data-tool="fill">🪣</button><button type="button" data-tool="pick">💧</button></div>
                <label class="chk sm"><input type="checkbox" id="pixMirror" ${mirror ? 'checked' : ''}> 🪞 Spiegeln</label>
                <div class="pix-tools"><button type="button" id="pixPaw" title="Pfoten-Vorlage">🐾</button><button type="button" id="pixClear" title="Leeren">🗑</button><button type="button" id="pixPng" title="Als PNG (512 px)">⬇</button></div></div></div>`;
        const grid = $('#pix', b);
        const draw = () => {
            // Pixel nur einfärben statt neu aufbauen (sonst geht das Loslassen der Maus verloren)
            if (grid.children.length !== N * N) grid.innerHTML = px.map((c, i) => `<span data-i="${i}"></span>`).join('');
            Array.from(grid.children).forEach((el, i) => { el.style.background = px[i] || ''; });
        };
        const save = () => store.set('pixel', px);
        const setPx = (i, c) => {
            px[i] = c;
            if (mirror) { const x = i % N, y = Math.floor(i / N); px[y * N + (N - 1 - x)] = c; }
        };
        const flood = (i, to) => {
            const from = px[i]; if (from === to) return;
            const st = [i];
            while (st.length) { const j = st.pop(); if (j < 0 || j >= N * N || px[j] !== from) continue; px[j] = to; const x = j % N; st.push(j - N, j + N); if (x > 0) st.push(j - 1); if (x < N - 1) st.push(j + 1); }
        };
        const apply = el => {
            if (!el || el.dataset.i === undefined) return;
            const i = +el.dataset.i;
            if (tool === 'pick') { if (px[i]) { color = px[i]; $('#pixC', b).value = color; tool = 'pen'; markTool(); } return; }
            if (tool === 'fill') flood(i, color);
            else setPx(i, tool === 'erase' ? '' : color);
            draw(); save(); unlock('pixel');
        };
        const markTool = () => $$('[data-tool]', b).forEach(x => x.classList.toggle('on', x.dataset.tool === tool));
        grid.addEventListener('pointerdown', e => { down = true; e.preventDefault(); apply(e.target); });
        grid.addEventListener('pointerover', e => { if (down && tool !== 'fill' && tool !== 'pick') apply(e.target); });
        window.addEventListener('pointerup', () => { down = false; });
        grid.addEventListener('pointerleave', () => { down = false; });
        b.addEventListener('click', e => {
            const c = e.target.closest('[data-c]'); if (c) { color = c.dataset.c; $('#pixC', b).value = color; if (tool === 'erase' || tool === 'pick') { tool = 'pen'; markTool(); } }
            const t = e.target.closest('[data-tool]'); if (t) { tool = t.dataset.tool; markTool(); }
        });
        $('#pixC', b).oninput = e => { color = e.target.value; };
        $('#pixMirror', b).onchange = e => { mirror = e.target.checked; store.set('pixMirror', mirror); };
        $('#pixClear', b).onclick = () => { if (px.some(Boolean) && !confirm('Alles löschen?')) return; px = Array(N * N).fill(''); draw(); save(); };
        $('#pixPaw', b).onclick = () => { PAW.split('|').forEach((row, y) => [...row].forEach((ch, x) => { if (ch === 'x') px[y * N + x] = color; })); draw(); save(); };
        $('#pixPng', b).onclick = () => {
            const c = document.createElement('canvas'); c.width = c.height = 512; const g = c.getContext('2d'), s = 512 / N;
            px.forEach((col, i) => { if (col) { g.fillStyle = col; g.fillRect((i % N) * s, Math.floor(i / N) * s, s, s); } });
            c.toBlob(bl => { download(bl, 'pixel_sticker.png'); unlock('pixel'); });
        };
        draw();
    }

    // ---------- Fursuit-Pausen-Timer ----------
    function toySuit(b) {
        let t = store.get('suit', { end: 0, mins: 45, water: 0, day: '' }), iv = null;
        if (t.day !== new Date().toDateString()) { t.water = 0; t.day = new Date().toDateString(); }
        b.innerHTML = `<div class="toy-h">⏱️ Fursuit-Pausen-Timer</div>
            <div class="suit"><svg viewBox="0 0 120 120" class="suit-ring"><circle cx="60" cy="60" r="52" class="bg"/><circle cx="60" cy="60" r="52" class="fg" id="suitFg"/></svg><div class="suit-t" id="suitT">–</div></div>
            <div class="toy-row"><select id="suitM">${[20, 30, 45, 60, 90].map(m => `<option value="${m}" ${m === t.mins ? 'selected' : ''}>${m} Min.</option>`).join('')}</select><button type="button" class="fh-btn sm gold" id="suitGo">▶ Start</button><button type="button" class="fh-btn sm" id="suitStop">■</button></div>
            <div class="toy-row water"><span>💧 Wasser heute: <b id="suitW">${t.water}</b> Gläser</span><button type="button" class="fh-btn sm" id="suitWp">＋💧</button></div>
            <p class="muted small">Für Suiter: erinnert an Pausen in der Headless Lounge – Kopf ab, trinken, abkühlen.</p>`;
        const C = 2 * Math.PI * 52, fg = $('#suitFg', b);
        fg.style.strokeDasharray = C;
        const save = () => store.set('suit', t);
        const tick = () => {
            if (!b.isConnected) { clearInterval(iv); return; }
            const left = t.end ? Math.max(0, t.end - Date.now()) : 0;
            $('#suitT', b).textContent = t.end ? `${String(Math.floor(left / 60000)).padStart(2, '0')}:${String(Math.floor(left / 1000) % 60).padStart(2, '0')}` : 'bereit';
            fg.style.strokeDashoffset = t.end ? C * (1 - left / (t.mins * 60000)) : C;
            if (t.end && left === 0) {
                t.end = 0; save();
                try { sfx.chirp(); setTimeout(() => sfx.chirp(), 300); setTimeout(() => sfx.chirp(), 600); } catch (e) { /* stumm */ }
                if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
                if ('Notification' in window && Notification.permission === 'granted') { try { new Notification('Pause! 🐾', { body: 'Kopf ab, Wasser trinken, abkühlen. 💧' }); } catch (e) { /* egal */ } }
                toast('⏱️ Pause! Kopf ab, Wasser trinken, abkühlen. 💧'); confetti(14, ['💧', '🐾']);
            }
        };
        $('#suitGo', b).onclick = () => {
            t.mins = +$('#suitM', b).value; t.end = Date.now() + t.mins * 60000; save(); tick(); unlock('suit');
            if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission().catch(() => {});
        };
        $('#suitStop', b).onclick = () => { t.end = 0; save(); tick(); };
        $('#suitWp', b).onclick = () => { t.water++; save(); $('#suitW', b).textContent = t.water; floatText(innerWidth / 2 + scrollX, scrollY + innerHeight / 2, '💧'); };
        iv = setInterval(tick, 1000); tick();
    }

    // ---------- Ship-Namen ----------
    function ships(a, b) {
        a = a.trim(); b = b.trim(); if (!a || !b) return [];
        const vow = /[aeiouäöüy]/i;
        const cutA = s => { const m = Math.ceil(s.length / 2); for (let i = m; i < s.length; i++) if (vow.test(s[i - 1])) return s.slice(0, i); return s.slice(0, m); };
        const cutB = s => { const m = Math.floor(s.length / 2); for (let i = m; i > 0; i--) if (vow.test(s[i])) return s.slice(i); return s.slice(m); };
        const cap = s => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
        return [...new Set([cap(cutA(a) + cutB(b)), cap(cutA(b) + cutB(a)), cap(a.slice(0, 2) + b.slice(-3)), cap(b.slice(0, 2) + a.slice(-3)), cap(a + b.slice(-2)), a + ' × ' + b])].filter(x => x.length > 1);
    }
    function toyShip(b) {
        b.innerHTML = `<div class="toy-h">💞 Ship-Namen</div><div class="toy-row"><input id="shA" maxlength="24" placeholder="Name 1"><span>💞</span><input id="shB" maxlength="24" placeholder="Name 2"></div><div class="ships" id="ships"></div>`;
        const run = () => {
            const a = $('#shA', b).value, c = $('#shB', b).value, list = ships(a, c);
            const pct = a && c ? 50 + (hash((a + c).toLowerCase().split('').sort().join('')) % 51) : 0;
            $('#ships', b).innerHTML = list.length ? `<div class="ship-pct">💘 ${pct} % Fluff-Kompatibilität</div>` + list.map(s => `<button type="button" class="chip" data-copy-ship="${esc(s)}" title="Kopieren">${esc(s)}</button>`).join('') : '<span class="muted small">Zwei Namen eingeben …</span>';
            if (list.length) unlock('ship');
        };
        b.addEventListener('input', run);
        b.addEventListener('click', e => { const s = e.target.closest('[data-copy-ship]'); if (s && navigator.clipboard) navigator.clipboard.writeText(s.dataset.copyShip).then(() => toast('⧉ „' + s.dataset.copyShip + '“ kopiert')); });
        const me = FH.mySona && FH.mySona.name; if (me) $('#shA', b).value = me;
        run();
    }

    const TOYS = [toyOracle, toyHoro, toyCookie, toyPop, toyPixel, toySuit, toyShip];
    K.INIT.toys = () => {
        const g = $('#toysGrid');
        g.innerHTML = TOYS.map((_, i) => `<div class="fh-card toy" id="toy${i}"></div>`).join('');
        TOYS.forEach((f, i) => f($('#toy' + i)));
    };
})();
