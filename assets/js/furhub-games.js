/* Furry Hub – Minispiele: Leckerli fangen, Nasen-Boopen, Pfoten-Memory, Tierlaute raten, Glücksrad. Münzen gehen ans Tamagotchi. */
(function () {
    'use strict';
    const K = window.FHK; if (!K) return;
    const { $, $$, esc, rnd, store, toast, confetti, floatText, unlock, react, boopCount, sfx, SOUNDS, face } = K;

    let stopCurrent = null;
    const box = () => $('#gameBox');
    const best = (k, v) => { const b = store.get('best', {}); if (v !== undefined && (b[k] === undefined || v > b[k])) { b[k] = v; store.set('best', b); return true; } return false; };
    const bestOf = k => store.get('best', {})[k];
    function reward(n, why) {
        if (n > 0 && K.addCoins) { K.addCoins(n, why); toast(`🪙 +${n} Münzen fürs Tamagotchi!`); }
        if (K.petGame) K.petGame();
    }
    /** Gesicht des Tamagotchis als Bild (für Canvas-Spiele) */
    function petImage(emo = 'happy') {
        return new Promise(res => {
            const look = K.petLook ? K.petLook() : K.randomLook('fox');
            const html = face(look, emo), img = new Image();
            img.onload = () => res(img); img.onerror = () => res(null);
            const m = html.match(/^<img[^>]+src="([^"]+)"/);
            img.src = m ? m[1].replace(/&amp;/g, '&') : 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(html);
        });
    }

    // ---------- Leckerli fangen ----------
    async function gameCatch() {
        const b = box();
        b.innerHTML = `<div class="g-head"><b>🍖 Leckerli fangen</b><span id="gcInfo"></span></div><canvas id="gcCan" width="420" height="480"></canvas>
            <p class="muted small">Maus, Finger oder ← → bewegen. Fang 🍖🐟🍪🫐🥕 – aber nicht 🧅🍫🍇 (giftig für viele Tiere!). ⭐ = Bonus. Rekord: ${bestOf('catch') ?? '–'}</p>`;
        const can = $('#gcCan'), g = can.getContext('2d'), W = can.width, Hh = can.height;
        const pet = await petImage('happy');
        const GOOD = ['🍖', '🐟', '🍪', '🫐', '🥕'], BAD = ['🧅', '🍫', '🍇'];
        let x = W / 2, score = 0, lives = 3, items = [], t0 = performance.now(), last = t0, spawn = 0, run = true, flashBad = 0, keys = {};
        const move = cx => { const r = can.getBoundingClientRect(); x = Math.max(30, Math.min(W - 30, (cx - r.left) / r.width * W)); };
        can.onpointermove = e => move(e.clientX);
        can.ontouchmove = e => { e.preventDefault(); move(e.touches[0].clientX); };
        const kd = e => { keys[e.key] = true; if (['ArrowLeft', 'ArrowRight'].includes(e.key)) e.preventDefault(); }, ku = e => { keys[e.key] = false; };
        document.addEventListener('keydown', kd); document.addEventListener('keyup', ku);
        stopCurrent = () => { run = false; document.removeEventListener('keydown', kd); document.removeEventListener('keyup', ku); };
        const frame = now => {
            if (!run) return;
            const dt = Math.min(50, now - last) / 1000; last = now;
            const el = (now - t0) / 1000, speed = 150 + el * 6;
            if (keys.ArrowLeft) x = Math.max(30, x - 380 * dt);
            if (keys.ArrowRight) x = Math.min(W - 30, x + 380 * dt);
            spawn -= dt;
            if (spawn <= 0) {
                spawn = Math.max(0.28, 0.85 - el * 0.012);
                const r = Math.random();
                items.push({ x: 20 + Math.random() * (W - 40), y: -20, e: r < 0.06 ? '⭐' : r < 0.3 + Math.min(0.2, el * 0.004) ? rnd(BAD) : rnd(GOOD), v: speed * (0.8 + Math.random() * 0.5) });
            }
            g.clearRect(0, 0, W, Hh);
            const grd = g.createLinearGradient(0, 0, 0, Hh); grd.addColorStop(0, '#1d3557'); grd.addColorStop(1, '#3a2e5c'); g.fillStyle = grd; g.fillRect(0, 0, W, Hh);
            if (flashBad > 0) { g.fillStyle = `rgba(255,60,60,${flashBad})`; g.fillRect(0, 0, W, Hh); flashBad -= dt * 2; }
            g.font = '28px serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
            items = items.filter(it => {
                it.y += it.v * dt;
                g.fillText(it.e, it.x, it.y);
                if (it.y > Hh - 80 && it.y < Hh - 30 && Math.abs(it.x - x) < 38) {
                    if (it.e === '⭐') { score += 5; floatText(can.getBoundingClientRect().left + scrollX + it.x * can.getBoundingClientRect().width / W, can.getBoundingClientRect().top + scrollY + it.y * can.getBoundingClientRect().height / Hh, '+5'); }
                    else if (BAD.includes(it.e)) { lives--; flashBad = 0.5; sfx.hiss(); }
                    else { score++; sfx.boop(); }
                    return false;
                }
                return it.y < Hh + 30;
            });
            if (pet) g.drawImage(pet, x - 40, Hh - 90, 80, 80); else { g.font = '60px serif'; g.fillText('🦊', x, Hh - 50); }
            g.font = 'bold 18px sans-serif'; g.fillStyle = '#fff'; g.textAlign = 'left'; g.fillText('🍖 ' + score, 12, 22); g.textAlign = 'right'; g.fillText('❤️'.repeat(Math.max(0, lives)), W - 12, 22);
            $('#gcInfo').textContent = Math.round(el) + ' s';
            if (lives <= 0) return end();
            requestAnimationFrame(frame);
        };
        const end = () => {
            stopCurrent();
            const rec = best('catch', score), coins = Math.floor(score / 4);
            if (score >= 50) unlock('catch');
            g.fillStyle = 'rgba(0,0,0,.65)'; g.fillRect(0, 0, W, Hh); g.fillStyle = '#fff'; g.textAlign = 'center'; g.font = 'bold 30px sans-serif';
            g.fillText('Vorbei!', W / 2, Hh / 2 - 30); g.font = '20px sans-serif'; g.fillText(score + ' Punkte' + (rec ? ' – Rekord! 🏆' : ''), W / 2, Hh / 2 + 6); g.fillText('🪙 ' + coins + ' Münzen', W / 2, Hh / 2 + 36);
            if (rec) confetti(30); reward(coins, 'Leckerli fangen');
            b.insertAdjacentHTML('beforeend', '<button type="button" class="fh-btn gold" data-game="catch">↺ Nochmal</button>');
        };
        requestAnimationFrame(frame);
    }

    // ---------- Nasen-Boopen ----------
    async function gameWhack() {
        const b = box();
        const looks = [];
        try { (await K.loadSonas()).slice(0, 8).forEach(s => looks.push(s.look)); } catch (e) { /* egal */ }
        while (looks.length < 6) looks.push(K.randomLook());
        b.innerHTML = `<div class="g-head"><b>👃 Nasen-Boopen</b><span id="gwInfo">30 s</span></div><div class="whack" id="whack">${Array.from({ length: 9 }, (_, i) => `<div class="hole" data-h="${i}"><div class="critter"></div></div>`).join('')}</div>
            <p class="muted small">Boope jede Nase, bevor sie verschwindet! 😠-Gesichter nicht boopen (−2). Jeder Treffer zählt auch für den gemeinsamen Zähler. Rekord: ${bestOf('whack') ?? '–'}</p>`;
        let score = 0, time = 30, run = true;
        const holes = $$('.hole', b);
        const pop = () => {
            if (!run) return;
            const free = holes.filter(h => !h.classList.contains('up'));
            if (free.length) {
                const h = rnd(free), angry = Math.random() < 0.18;
                h.dataset.angry = angry ? '1' : '';
                h.querySelector('.critter').innerHTML = face(rnd(looks), angry ? 'angry' : 'neutral');
                h.classList.add('up');
                clearTimeout(h._t); h._t = setTimeout(() => h.classList.remove('up'), Math.max(550, 1200 - (30 - time) * 22));
            }
            setTimeout(pop, Math.max(300, 700 - (30 - time) * 13));
        };
        $('#whack').onpointerdown = e => {
            const h = e.target.closest('.hole.up'); if (!h || !run) return;
            h.classList.remove('up'); clearTimeout(h._t);
            if (h.dataset.angry) { score = Math.max(0, score - 2); sfx.hiss(); floatText(e.pageX, e.pageY - 20, '−2'); }
            else { score++; sfx.boop(); react('boop'); boopCount(); floatText(e.pageX, e.pageY - 20, rnd(['boop!', '*boop*', '>w<'])); h.querySelector('.critter').innerHTML = face(rnd(looks), 'surprised'); }
            $('#gwInfo').textContent = time + ' s · ' + score + ' 👃';
        };
        const tick = setInterval(() => {
            time--; $('#gwInfo').textContent = time + ' s · ' + score + ' 👃';
            if (time <= 0) {
                run = false; clearInterval(tick); holes.forEach(h => h.classList.remove('up'));
                const rec = best('whack', score), coins = Math.floor(score / 3);
                if (score >= 30) unlock('whack');
                if (rec) confetti(30);
                b.insertAdjacentHTML('beforeend', `<div class="g-result">⏱ Zeit um! <b>${score}</b> Nasen gebooped${rec ? ' – Rekord! 🏆' : ''} · 🪙 ${coins}<button type="button" class="fh-btn gold" data-game="whack">↺ Nochmal</button></div>`);
                reward(coins, 'Nasen-Boopen');
            }
        }, 1000);
        stopCurrent = () => { run = false; clearInterval(tick); };
        pop();
    }

    // ---------- Pfoten-Memory ----------
    function gameMemory() {
        const b = box();
        const set = ['🦊', '🐺', '🐱', '🦝', '🦦', '🐰', '🐉', '🐼', '🦁', '🐯', '🦌', '🦇'].sort(() => Math.random() - 0.5).slice(0, 8);
        const deck = set.concat(set).sort(() => Math.random() - 0.5);
        b.innerHTML = `<div class="g-head"><b>🃏 Pfoten-Memory</b><span id="gmInfo">0 Züge</span></div><div class="memory" id="mem">${deck.map((e, i) => `<button type="button" class="mc" data-i="${i}"><span class="back">🐾</span><span class="front">${e}</span></button>`).join('')}</div>
            <p class="muted small">Finde alle 8 Paare. Bestleistung: ${bestOf('memory') !== undefined ? (100 - bestOf('memory')) + ' Züge' : '–'}</p>`;
        let open = [], moves = 0, found = 0, lock = false;
        const t0 = Date.now();
        $('#mem').onclick = e => {
            const c = e.target.closest('.mc'); if (!c || lock || c.classList.contains('open') || c.classList.contains('done')) return;
            c.classList.add('open'); open.push(c); sfx.boop();
            if (open.length === 2) {
                moves++; $('#gmInfo').textContent = moves + ' Züge';
                const [a, d] = open;
                if (deck[a.dataset.i] === deck[d.dataset.i]) { a.classList.add('done'); d.classList.add('done'); open = []; found++; sfx.chirp(); if (found === 8) done(); }
                else { lock = true; setTimeout(() => { a.classList.remove('open'); d.classList.remove('open'); open = []; lock = false; }, 750); }
            }
        };
        const done = () => {
            const secs = Math.round((Date.now() - t0) / 1000), rec = best('memory', 100 - moves), coins = Math.max(3, 16 - Math.max(0, moves - 8));
            unlock('memory'); confetti(rec ? 40 : 20);
            b.insertAdjacentHTML('beforeend', `<div class="g-result">🎉 Geschafft in <b>${moves}</b> Zügen und ${secs} s${rec ? ' – Bestleistung! 🏆' : ''} · 🪙 ${coins}<button type="button" class="fh-btn gold" data-game="memory">↺ Nochmal</button></div>`);
            reward(coins, 'Memory');
        };
        stopCurrent = null;
    }

    // ---------- Tierlaute raten ----------
    function gameSound() {
        const b = box();
        const pool = SOUNDS.filter(([k]) => k !== 'boop');
        let round = 0, score = 0, cur = null;
        const next = () => {
            if (round >= 10) return finish();
            round++;
            cur = rnd(pool);
            const opts = [cur].concat(pool.filter(x => x !== cur).sort(() => Math.random() - 0.5).slice(0, 3)).sort(() => Math.random() - 0.5);
            b.innerHTML = `<div class="g-head"><b>🔊 Tierlaute raten</b><span>Runde ${round}/10 · ${score} ✓</span></div>
                <div class="snd-q"><button type="button" class="fh-btn gold big" id="gsPlay">▶ Anhören</button></div>
                <div class="snd-opts">${opts.map(([k, ic, l]) => `<button type="button" class="fh-btn" data-ans="${k}">${ic} ${esc(l)}</button>`).join('')}</div>`;
            $('#gsPlay').onclick = () => sfx[cur[0]]();
            setTimeout(() => sfx[cur[0]](), 250);
            $$('[data-ans]', b).forEach(btn => btn.onclick = () => {
                const ok = btn.dataset.ans === cur[0];
                if (ok) score++;
                btn.classList.add(ok ? 'right' : 'wrong');
                if (!ok) $(`[data-ans="${cur[0]}"]`, b).classList.add('right');
                $$('[data-ans]', b).forEach(x => { x.disabled = true; });
                setTimeout(next, 900);
            });
        };
        const finish = () => {
            const rec = best('sound', score), coins = score;
            if (score >= 8) unlock('ear');
            if (rec) confetti(25);
            b.innerHTML = `<div class="g-result big">👂 <b>${score} von 10</b> richtig${rec ? ' – Rekord! 🏆' : ''}<br>${score >= 9 ? 'Du sprichst fließend Tier!' : score >= 6 ? 'Sehr gutes Gehör!' : 'Übung macht den Fluff.'} · 🪙 ${coins}<button type="button" class="fh-btn gold" data-game="sound">↺ Nochmal</button></div>`;
            reward(coins, 'Tierlaute raten');
        };
        stopCurrent = null;
        next();
    }

    // ---------- Glücksrad ----------
    const WHEEL_DEFAULT = ['Fursuit-Walk 🐾', 'Panel besuchen 🎤', 'Dealers’ Den 🛍️', 'Zeichnen ✏️', 'Snack holen 🍕', 'Nickerchen 😴', 'Fotos machen 📸', 'Tanzen 💃', 'Neue Leute treffen 👋', 'Sticker tauschen 🏷️'];
    function gameWheel() {
        const b = box();
        let entries = store.get('wheel', WHEEL_DEFAULT), rot = 0, spinning = false;
        const COLORS = ['#ff9f43', '#ff6b9d', '#4cc9f0', '#7bd88f', '#c77dff', '#f3c262', '#ff7a3d', '#56c271', '#9b7bff', '#3da5ff'];
        const svg = () => {
            const n = entries.length, R = 150, parts = [];
            entries.forEach((e, i) => {
                const a0 = i / n * 2 * Math.PI - Math.PI / 2, a1 = (i + 1) / n * 2 * Math.PI - Math.PI / 2, am = (a0 + a1) / 2;
                const p = (a, r) => (160 + Math.cos(a) * r).toFixed(1) + ' ' + (160 + Math.sin(a) * r).toFixed(1);
                parts.push(`<path d="M 160 160 L ${p(a0, R)} A ${R} ${R} 0 ${a1 - a0 > Math.PI ? 1 : 0} 1 ${p(a1, R)} Z" fill="${COLORS[i % COLORS.length]}" stroke="#0b0f14" stroke-width="2"/>`);
                parts.push(`<text x="${p(am, R * 0.62).split(' ')[0]}" y="${p(am, R * 0.62).split(' ')[1]}" transform="rotate(${am * 180 / Math.PI} ${p(am, R * 0.62)})" text-anchor="middle" dominant-baseline="middle" font-size="${n > 10 ? 9 : 11}" font-weight="700" fill="#14101c">${esc(e.length > 18 ? e.slice(0, 17) + '…' : e)}</text>`);
            });
            return `<svg viewBox="0 0 320 320" class="wheel-svg"><g id="wheelG" style="transform-origin:160px 160px;transform:rotate(${rot}deg)">${parts.join('')}</g><circle cx="160" cy="160" r="18" fill="#14101c" stroke="#fff" stroke-width="3"/><text x="160" y="166" text-anchor="middle" font-size="16">🐾</text></svg>`;
        };
        b.innerHTML = `<div class="g-head"><b>🎡 Glücksrad</b><span id="gwRes"></span></div><div class="wheel-wrap"><div class="wheel-box"><div class="wheel-pin">▼</div><div id="wheelSvg">${svg()}</div><button type="button" class="fh-btn gold big" id="spin">Drehen!</button></div>
            <label class="wheel-edit">Einträge (einer pro Zeile)<textarea id="wheelTxt" rows="10">${esc(entries.join('\n'))}</textarea><button type="button" class="fh-btn sm" id="wheelReset">Standard</button></label></div>`;
        $('#wheelTxt').oninput = e => { entries = e.target.value.split('\n').map(x => x.trim()).filter(Boolean).slice(0, 24); if (entries.length < 2) entries = WHEEL_DEFAULT.slice(0, 2); store.set('wheel', entries); $('#wheelSvg').innerHTML = svg(); };
        $('#wheelReset').onclick = () => { entries = WHEEL_DEFAULT.slice(); store.set('wheel', entries); $('#wheelTxt').value = entries.join('\n'); $('#wheelSvg').innerHTML = svg(); };
        $('#spin').onclick = () => {
            if (spinning) return;
            spinning = true; $('#gwRes').textContent = '';
            const n = entries.length, pick = Math.floor(Math.random() * n);
            const target = 360 - (pick + 0.5) / n * 360 + (Math.random() - 0.5) * (300 / n);
            rot = rot - (rot % 360) + 360 * 6 + target;
            const g = $('#wheelG'); g.style.transition = 'transform 4.2s cubic-bezier(.17,.67,.12,1)'; g.style.transform = `rotate(${rot}deg)`;
            let ticks = 0; const tk = setInterval(() => { if (++ticks > 30) clearInterval(tk); else sfx.boop(); }, 120);
            setTimeout(() => { spinning = false; $('#gwRes').textContent = '➜ ' + entries[pick]; toast('🎡 ' + entries[pick]); confetti(16); unlock('wheel'); }, 4300);
        };
        stopCurrent = null;
    }

    const GAMES = { catch: gameCatch, whack: gameWhack, memory: gameMemory, sound: gameSound, wheel: gameWheel };
    function start(k) {
        if (stopCurrent) { stopCurrent(); stopCurrent = null; }
        $$('#gamesPick [data-game]').forEach(b => b.classList.toggle('on', b.dataset.game === k));
        GAMES[k]();
    }
    K.INIT.games = () => {
        $('[data-panel="games"]').addEventListener('click', e => { const b = e.target.closest('[data-game]'); if (b) start(b.dataset.game); });
    };
    K.SHOW.games = () => {};
    // Spiel beim Verlassen des Reiters anhalten
    document.addEventListener('click', e => { if (e.target.closest('#fhTabs button') && stopCurrent && e.target.closest('[data-tab]').dataset.tab !== 'games') { stopCurrent(); stopCurrent = null; } });
})();
