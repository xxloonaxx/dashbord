/* Furry Hub – Tamagotchi: eigenes Haustier mit Ei, Wachstum, Pflege, Münzen, Shop & eigenen Bildern */
(function () {
    'use strict';
    const K = window.FHK; if (!K) return;
    const { FH, $, $$, esc, rnd, clamp, store, toast, confetti, floatText, unlock, react, boopCount, sfx, face, EMO, emoSlots, randomLook, genName, api } = K;

    const FOODS = {
        kibble: ['🥣', 'Trockenfutter', 0, { food: 15 }], treat: ['🍪', 'Leckerli', 2, { food: 8, fun: 8, love: 2 }],
        meat: ['🍖', 'Fleisch', 5, { food: 30 }], fish: ['🐟', 'Fisch', 5, { food: 30 }], berries: ['🫐', 'Beeren', 3, { food: 18, health: 3 }],
        carrot: ['🥕', 'Karotte', 3, { food: 18, health: 4 }], cake: ['🍰', 'Kuchen', 8, { food: 20, fun: 15, health: -3 }], pizza: ['🍕', 'Pizza', 7, { food: 35, fun: 8, health: -2 }],
    };
    const ITEMS = {
        medicine: ['💊', 'Medizin', 10, 'Heilt Krankheiten'], soap: ['🧼', 'Schaumbad', 6, 'Waschen wirkt viel stärker'],
        ball: ['⚽', 'Ball', 15, 'Spielen macht dauerhaft mehr Spaß', true], plush: ['🧸', 'Kuscheltier', 25, 'Zuneigung sinkt nur halb so schnell', true],
        bed: ['🛏️', 'Kuschelbett', 30, 'Schlaf erholt schneller', true],
    };
    const HATS = { bow: ['🎀', 'Schleife', 20], flower: ['🌸', 'Blüte', 15], shades: ['🕶️', 'Sonnenbrille', 25], tophat: ['🎩', 'Zylinder', 30], phones: ['🎧', 'Kopfhörer', 30], party: ['🎉', 'Party', 20], crown: ['👑', 'Krone', 60], star: ['💫', 'Sternchen', 45] };
    const PERS = { playful: ['🎾', 'verspielt', { fun: 1.4 }], hungry: ['🍖', 'verfressen', { food: 1.4 }], sleepy: ['😴', 'verschlafen', { energy: 1.4 }], cuddly: ['🫂', 'kuschelig', { love: 1.6 }], cheeky: ['😜', 'frech', { clean: 1.3 }] };
    const BGS = { meadow: ['🌼 Wiese', 'linear-gradient(#bfe9ff, #d8f5c8 62%, #9fd98a)'], night: ['🌙 Nacht', 'linear-gradient(#0b1030, #2a2060)'], beach: ['🏖️ Strand', 'linear-gradient(#9fe3ff, #ffe6a8 70%, #f5d08a)'],
                  cozy: ['🛋️ Kuschelecke', 'linear-gradient(#ffd6e0, #f7b9a8)'], space: ['🚀 Weltall', 'radial-gradient(circle at 30% 30%, #3a2a6a, #0a0a1a)'], forest: ['🌲 Wald', 'linear-gradient(#cde8c3, #6fa26a)'] };
    const SHELLS = ['#ff8fab', '#7bd88f', '#4cc9f0', '#f3c262', '#c77dff', '#ff9f43', '#e9eef5', '#3a3d55'];
    const VOICES = { yip: '🦊 Yip', bark: '🐶 Wuff', meow: '🐱 Miau', mrrp: '🐈 Mrrp', chirp: '🐦 Zwitschern', squeak: '🐭 Quietschen', hoot: '🦉 Huhu', purr: '😺 Schnurren', roar: '🐉 Brüllen' };
    const STAGES = { egg: ['🥚 Ei', 0.5], baby: ['🍼 Baby', 0.64], child: ['🧸 Kind', 0.78], teen: ['🎒 Teenie', 0.9], adult: ['⭐ Erwachsen', 1] };
    const H = 3600000, DAY = 24 * H;

    let localSaved = 0;
    let s = null, dirty = false, saveT = null, flashEmo = null, flashT = null, lastBoop = 0, cool = {}, tab = 'shop', combo = 0, comboT = null;

    function fresh() {
        const now = Date.now();
        return { v: 2, name: genName().split(' ')[0], born: now, hatchAt: now + 90000, last: now, food: 80, fun: 80, energy: 85, clean: 90, health: 100, love: 30,
                 xp: 0, level: 1, coins: 20, sleeping: false, sick: false, poops: 0, nextPoop: now + (2.5 + Math.random() * 1.5) * H,
                 pers: rnd(Object.keys(PERS)), fav: rnd(['meat', 'fish', 'berries', 'carrot', 'cake', 'pizza']), voice: rnd(Object.keys(VOICES)),
                 lookSrc: K.currentLook() ? 'sona' : 'random', randomLook: randomLook(), images: {}, hat: null, owned: [], perm: [], inv: { treat: 3, medicine: 1 },
                 bg: 'meadow', shell: rnd(SHELLS), log: [], stat: { boops: 0, pats: 0, hugs: 0, feeds: 0, games: 0, cleaned: 0 }, daily: '', stageSeen: 'egg', savedAt: now };
    }
    /** Alter Begleiter (Version 1) wird übernommen */
    function migrate() {
        const old = store.get('pet', null);
        const n = fresh();
        if (!old) return n;
        Object.assign(n, { name: old.name || n.name, xp: old.xp || 0, level: old.level || 1, hatchAt: Date.now() - 1000, stageSeen: 'baby', born: old.born || n.born });
        if (old.look) n.randomLook = old.look;
        n.log = [[Date.now(), '🐾 ' + n.name + ' ist in ein Tamagotchi umgezogen!']];
        return n;
    }

    /** Objekte reparieren (PHP liefert leere Objekte als []) */
    function norm(x) {
        ['images', 'inv'].forEach(k => { if (!x[k] || Array.isArray(x[k])) x[k] = {}; });
        x.stat = Object.assign({ boops: 0, pats: 0, hugs: 0, feeds: 0, games: 0, cleaned: 0 }, Array.isArray(x.stat) ? {} : x.stat);
        ['owned', 'perm', 'log'].forEach(k => { if (!Array.isArray(x[k])) x[k] = []; });
        return x;
    }
    const stage = () => { const now = Date.now(); if (now < s.hatchAt) return 'egg'; const age = now - s.hatchAt; return age < DAY ? 'baby' : age < 3 * DAY ? 'child' : age < 7 * DAY ? 'teen' : 'adult'; };
    const has = p => s.perm.includes(p);
    const mult = k => (PERS[s.pers] || [0, 0, {}])[2][k] || 1;
    function say(t, log) {
        const b = $('#tamaSay'); if (b) { b.textContent = t; b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop'); }
        if (log) { s.log.unshift([Date.now(), t]); s.log = s.log.slice(0, 30); }
    }
    function flash(emo, ms = 900) { flashEmo = emo; clearTimeout(flashT); flashT = setTimeout(() => { flashEmo = null; renderPet(); }, ms); renderPet(); }

    // ---------- Zeit vergeht ----------
    function decay() {
        const now = Date.now(), h = Math.min(72, Math.max(0, (now - s.last) / H));
        s.last = now;
        if (stage() === 'egg') return;
        s.food -= 6 * h * mult('food');
        s.fun -= 5 * h * mult('fun');
        s.clean -= (3 + 2 * s.poops) * h * mult('clean');
        s.love -= 1.5 * h * mult('love') * (has('plush') ? 0.5 : 1);
        s.energy += s.sleeping ? 22 * h * (has('bed') ? 1.4 : 1) : -4 * h * mult('energy');
        let guard = 0;
        while (now > s.nextPoop && guard++ < 10) { s.poops = Math.min(4, s.poops + 1); s.nextPoop += (2.5 + Math.random() * 1.5) * H; }
        if (s.nextPoop < now) s.nextPoop = now + 2 * H;
        const bad = s.food < 20 || s.clean < 25 || s.poops >= 3;
        s.health += (bad ? -5 : 1.5) * h - (s.sick ? 2 * h : 0);
        ['food', 'fun', 'clean', 'love', 'energy', 'health'].forEach(k => { s[k] = clamp(s[k], 0, 100); });
        if (!s.sick && s.health < 30) { s.sick = true; say('🤒 ' + s.name + ' ist krank geworden! Medizin hilft.', true); }
        if (s.sleeping && s.energy >= 100) { s.sleeping = false; say('☀️ ' + s.name + ' ist ausgeschlafen!', true); }
        const st = stage();
        if (st !== s.stageSeen) {
            if (s.stageSeen === 'egg') { say('🐣 ' + s.name + ' ist geschlüpft! Hallo Welt!', true); confetti(40, ['🐣', '✨', '💖']); unlock('hatch'); }
            else say('🎉 ' + s.name + ' ist jetzt ' + STAGES[st][0].split(' ')[1] + '!', true);
            if (st === 'adult') unlock('adult');
            s.stageSeen = st;
        }
        touch();
    }

    function emotion() {
        if (s.sleeping) return 'sleepy';
        if (flashEmo) return flashEmo;
        if (s.sick || s.health < 35) return 'sad';
        if (Math.min(s.food, s.fun, s.clean) < 15 || s.love < 8) return 'angry';
        if (s.energy < 20) return 'sleepy';
        const m = (s.food + s.fun + s.clean + s.energy + s.health) / 5;
        return m > 70 ? 'happy' : m < 35 ? 'sad' : 'neutral';
    }
    function lookOf() {
        if (s.lookSrc === 'custom') return Object.assign({}, s.randomLook, { mode: 'custom', images: s.images });
        if (s.lookSrc === 'sona') return K.currentLook() || s.randomLook;
        return s.randomLook;
    }

    // ---------- Speichern ----------
    function touch() { dirty = true; s.savedAt = Date.now(); store.set('tama', s); clearTimeout(saveT); saveT = setTimeout(push, 2500); }
    async function push() {
        if (!FH.me || !dirty) return;
        dirty = false;
        try { await api('pet_save', { state: JSON.stringify(serverCopy()) }); } catch (e) { dirty = true; }
    }
    const serverCopy = () => { const c = JSON.parse(JSON.stringify(s)); Object.keys(c.images || {}).forEach(k => { if (String(c.images[k]).startsWith('data:')) delete c.images[k]; }); return c; };
    window.addEventListener('beforeunload', () => { if (FH.me && dirty && navigator.sendBeacon) { const f = new FormData(); f.append('state', JSON.stringify(serverCopy())); navigator.sendBeacon('furhub.php?api=pet_save', f); } });

    // ---------- Belohnungen ----------
    function xp(n) {
        s.xp += n;
        while (s.xp >= s.level * 50) {
            s.xp -= s.level * 50; s.level++; s.coins += 10;
            say(`🎉 Stufe ${s.level}! +10 🪙`, true); confetti(30);
            if (s.level >= 5) unlock('pet5');
        }
    }
    function addCoins(n, why) {
        if (!s) load();
        n = Math.max(0, Math.round(n)); if (!n) return;
        s.coins += n; if (why) s.log.unshift([Date.now(), `🪙 +${n} (${why})`]);
        touch(); renderSide();
    }

    // ---------- Aktionen ----------
    const COOLDOWN = { pat: 1200, hug: 3000, awoo: 2000 };
    function cd(k) { const now = Date.now(); if (now - (cool[k] || 0) < (COOLDOWN[k] || 0)) return false; cool[k] = now; return true; }
    function act(a, arg) {
        decay();
        const st = stage(), nm = s.name;
        if (st === 'egg') {
            if (a === 'boop' || a === 'pat' || a === 'hug') { s.hatchAt -= 6000; const el = $('.tama-egg'); if (el) { el.classList.remove('wob'); void el.offsetWidth; el.classList.add('wob'); } say(rnd(['*knack*', 'Das Ei wackelt …', 'Es wird warm! 🔥', '*tok tok*'])); touch(); renderAll(); }
            else say('Erst muss das Ei schlüpfen! Wärm es mit Boops. 🥚');
            return;
        }
        if (s.sleeping && !['sleep', 'clean'].includes(a)) {
            if (a === 'boop') { say('*schnarch* … 💤'); flash('angry', 500); }
            else say('Pssst … ' + nm + ' schläft. 💤');
            return;
        }
        switch (a) {
            case 'boop': {
                const now = Date.now();
                if (now - lastBoop > 250) { s.love = clamp(s.love + 1, 0, 100); s.fun = clamp(s.fun + 1, 0, 100); }
                lastBoop = now; s.stat.boops++;
                combo++; clearTimeout(comboT); comboT = setTimeout(() => { combo = 0; }, 1300);
                const pet = $('#tamaPet'); pet.classList.remove('squish'); void pet.offsetWidth; pet.classList.add('squish');
                flash('surprised', 550); sfx.boop(); react('boop'); boopCount();
                const r = pet.getBoundingClientRect();
                floatText(r.left + scrollX + r.width / 2 - 20 + (Math.random() * 40 - 20), r.top + scrollY + r.height * 0.45, combo > 4 ? combo + '×' : rnd(['boop!', '*boop*', 'eep!', '>w<', 'mlem']));
                if (s.stat.boops % 25 === 0) { s.coins++; floatText(r.left + scrollX + r.width - 30, r.top + scrollY + 10, '+1 🪙'); }
                if (combo >= 25) unlock('combo');
                if (combo === 40) say(nm + ': „Okay, okay, meine Nase ist jetzt platt!“ 😳');
                break;
            }
            case 'pat':
                if (!cd('pat')) return;
                s.love = clamp(s.love + 3 * (s.pers === 'cuddly' ? 1.5 : 1), 0, 100); s.fun = clamp(s.fun + 4, 0, 100); s.stat.pats++; xp(1);
                flash('happy', 1100); react('pat'); sfx.purr(); say(rnd(['*lehnt sich in die Hand*', '💖', 'Mehr! Mehr!', '*wedelt*', '*schnurrt*']));
                break;
            case 'hug': {
                if (!cd('hug')) return;
                s.love = clamp(s.love + 5, 0, 100); s.fun = clamp(s.fun + 3, 0, 100); s.stat.hugs++; xp(2);
                flash('happy', 1500); react('hug'); say(rnd(['*kuschel*', 'Beste Umarmung! 🫂', '*drückt zurück*']));
                const r = $('#tamaPet').getBoundingClientRect();
                for (let i = 0; i < 5; i++) setTimeout(() => floatText(r.left + scrollX + Math.random() * r.width, r.top + scrollY + r.height * 0.4, rnd(['💖', '💕', '💗']), 'heart'), i * 120);
                break;
            }
            case 'awoo':
                if (!cd('awoo')) return;
                sfx.awoo(); react('awoo'); unlock('awoo'); s.fun = clamp(s.fun + 2, 0, 100);
                say('🐺 AWOOO! … ' + nm + ' heult mit!'); setTimeout(() => sfx[s.voice] && sfx[s.voice](), 1500); flash('happy', 2000);
                break;
            case 'feed': {
                const k = arg || 'kibble', f = FOODS[k]; if (!f) return;
                if (k !== 'kibble' && !(s.inv[k] > 0)) { say('Davon hast du nichts mehr – im Shop gibt’s Nachschub. 🛒'); return; }
                if (s.food > 95) { say(nm + ' ist pappsatt. *bäuerchen*'); flash('angry', 600); return; }
                if (k !== 'kibble') s.inv[k]--;
                Object.entries(f[3]).forEach(([st2, v]) => { s[st2] = clamp(s[st2] + v, 0, 100); });
                s.stat.feeds++; xp(5); unlock('pet');
                if (k === s.fav) { s.fun = clamp(s.fun + 10, 0, 100); s.love = clamp(s.love + 3, 0, 100); say(f[0] + ' Lieblingsessen!! 😍', true); }
                else say(rnd([f[0] + ' Mampf!', 'Nom nom nom!', '*leckt sich die Schnauze*', 'Lecker! ' + f[0]]));
                flash('happy', 1000); sfx.chirp(); closeMenu();
                break;
            }
            case 'play':
                if (s.energy < 15) { say(nm + ' ist zu müde zum Spielen. 😴'); flash('sleepy', 900); return; }
                s.fun = clamp(s.fun + 20 * (s.pers === 'playful' ? 1.3 : 1) * (has('ball') ? 1.25 : 1), 0, 100);
                s.energy = clamp(s.energy - 12, 0, 100); s.food = clamp(s.food - 6, 0, 100); s.clean = clamp(s.clean - 6, 0, 100); xp(8);
                say(rnd([(has('ball') ? '⚽' : '🎾') + ' *jagt den Ball*', 'Nochmal! Nochmal!', '*zoomies* 💨', 'Hab ihn!']));
                $('#tamaPet').classList.add('bounce'); setTimeout(() => $('#tamaPet').classList.remove('bounce'), 700);
                flash('happy', 1200); sfx[s.voice] ? sfx[s.voice]() : sfx.yip();
                break;
            case 'wash': {
                const soap = s.inv.soap > 0;
                if (soap) s.inv.soap--;
                s.clean = clamp(s.clean + (soap ? 55 : 35), 0, 100);
                if (s.pers === 'cheeky' && !soap) { s.fun = clamp(s.fun - 5, 0, 100); flash('angry', 900); say('Baden?! Pfff. 😤 (aber sauber)'); }
                else { flash('happy', 900); say(soap ? '🧼 Schaumparty! So flauschig!' : '🛁 Plitsch, platsch – sauber!'); }
                xp(3);
                const r = $('#tamaPet').getBoundingClientRect();
                for (let i = 0; i < 6; i++) setTimeout(() => floatText(r.left + scrollX + Math.random() * r.width, r.top + scrollY + r.height * 0.6, '🫧', 'heart'), i * 100);
                break;
            }
            case 'clean':
                if (!s.poops) { say('Alles blitzblank! ✨'); return; }
                s.stat.cleaned += s.poops; s.clean = clamp(s.clean + 5 * s.poops, 0, 100); xp(2 * s.poops); s.poops = 0;
                say('🧹 Weggeräumt! Danke!'); if (s.stat.cleaned >= 10) unlock('tidy');
                break;
            case 'medicine':
                if (!s.sick && s.health > 80) { say(nm + ' ist kerngesund. 💪'); return; }
                if (!(s.inv.medicine > 0)) { say('Keine Medizin mehr – im Shop kaufen! 💊'); return; }
                s.inv.medicine--; s.sick = false; s.health = clamp(s.health + 45, 0, 100);
                flash('sad', 700); setTimeout(() => flash('happy', 1200), 750); say('💊 Bäh! … Aber schon viel besser.', true);
                break;
            case 'sleep':
                s.sleeping = !s.sleeping;
                say(s.sleeping ? nm + ' kuschelt sich ein. Gute Nacht! 🌙' : nm + ' wurde geweckt. *gähn*', true);
                break;
        }
        touch(); renderAll();
    }

    // ---------- Anzeige ----------
    function renderPet() {
        const el = $('#tamaPet'); if (!el || !s) return;
        const st = stage();
        if (st === 'egg') {
            const left = Math.max(0, s.hatchAt - Date.now());
            el.innerHTML = `<div class="tama-egg ${left < 20000 ? 'crack' : ''}">🥚</div><div class="egg-time">schlüpft in ${Math.ceil(left / 1000)} s · Boops wärmen!</div>`;
            return;
        }
        const hat = s.hat && HATS[s.hat] ? `<span class="tp-hat hat-${s.hat}">${HATS[s.hat][0]}</span>` : '';
        el.innerHTML = `<div class="tp-wrap" style="--sc:${STAGES[st][1]}">${face(lookOf(), emotion())}${hat}</div>`;
    }
    function renderScreen() {
        const scr = $('#tamaScreen'); if (!scr) return;
        scr.style.background = (BGS[s.bg] || BGS.meadow)[1];
        scr.classList.toggle('dark', s.sleeping);
        $('#tamaDevice').style.setProperty('--shell', s.shell);
        $('#tamaBrand').textContent = (s.name || 'FLUFF').toUpperCase() + '-GOTCHI';
        const st = stage();
        const hearts = Math.round(s.love / 20);
        $('#tamaIcons').innerHTML = `<span>${STAGES[st][0]}</span><span>${'❤️'.repeat(hearts)}${'🤍'.repeat(5 - hearts)}</span>${s.sick ? '<span>🤒</span>' : ''}${s.sleeping ? '<span>💤</span>' : ''}`;
        $('#tamaPoops').innerHTML = Array.from({ length: s.poops }, (_, i) => `<button type="button" class="poop" data-poop="${i}" title="Wegräumen">💩</button>`).join('');
        $('#tamaSleep').textContent = s.sleeping ? '☀️ Aufwecken' : '💡 Licht aus';
        renderPet();
    }
    function bar(ic, l, v) { return `<div class="pbar"><span>${ic} ${l}</span><div><i style="width:${v}%;background:${v < 25 ? 'var(--accent-red)' : v < 55 ? 'var(--accent-gold)' : 'var(--accent-green)'}"></i></div><b>${Math.round(v)}</b></div>`; }
    function ageText() {
        const st = stage(); if (st === 'egg') return 'noch im Ei';
        const d = (Date.now() - s.hatchAt) / DAY;
        return d < 1 ? Math.max(1, Math.round(d * 24)) + ' Std. alt' : Math.floor(d) + (Math.floor(d) === 1 ? ' Tag alt' : ' Tage alt');
    }
    function renderSide() {
        if (!$('#tamaBars')) return;
        $('#tamaTitle').textContent = '💗 ' + s.name;
        $('#tamaCoins').textContent = '🪙 ' + s.coins;
        $('#tamaBars').innerHTML = bar('🍖', 'Satt', s.food) + bar('🎾', 'Spaß', s.fun) + bar('⚡', 'Energie', s.energy) + bar('🛁', 'Sauber', s.clean) + bar('❤️‍🩹', 'Gesundheit', s.health) + bar('💞', 'Zuneigung', s.love);
        $('#tamaMeta').innerHTML = `<span>${STAGES[stage()][0]}</span><span>⭐ Stufe ${s.level} · ${s.xp}/${s.level * 50} XP</span><span>${ageText()}</span><span>${PERS[s.pers][0]} ${PERS[s.pers][1]}</span><span>❤ isst gern ${FOODS[s.fav][0]}</span><span>👃 ${s.stat.boops} Boops</span>`;
        renderTab();
    }
    function renderAll() { renderScreen(); renderSide(); }

    // ---------- Futter-Menü ----------
    function openMenu() {
        const m = $('#tamaMenu');
        m.innerHTML = '<div class="tm-head">🍽️ Was gibt’s? <button type="button" data-close-menu>×</button></div><div class="tm-items">'
            + Object.entries(FOODS).map(([k, [ic, l]]) => { const n = k === 'kibble' ? '∞' : (s.inv[k] || 0); return `<button type="button" data-food="${k}" ${n === 0 ? 'disabled' : ''}><span>${ic}</span>${l}<small>${n}×${k === s.fav ? ' ❤' : ''}</small></button>`; }).join('') + '</div>';
        m.hidden = false;
    }
    function closeMenu() { const m = $('#tamaMenu'); if (m) m.hidden = true; }

    // ---------- Seitenreiter: Shop, Inventar, Anpassen, Tagebuch ----------
    function renderTab() {
        const box = $('#tamaTab'); if (!box) return;
        $$('[data-tt]').forEach(b => b.classList.toggle('on', b.dataset.tt === tab));
        if (tab === 'shop') {
            const item = (kind, k, ic, l, price, desc, owned) => `<button type="button" class="shop-it ${owned ? 'owned' : ''}" data-buy="${kind}:${k}" ${owned || s.coins < price ? 'disabled' : ''} title="${esc(desc || '')}"><span>${ic}</span><b>${l}</b><small>${owned ? '✓ gekauft' : price + ' 🪙'}</small></button>`;
            box.innerHTML = `<p class="muted small">Münzen gibt’s für Stufen, jeden 25. Boop, den täglichen Besuch und die <a href="#games" data-goto="games">Minispiele</a>.</p>`
                + '<div class="sub">Essen</div><div class="shop">' + Object.entries(FOODS).filter(([k]) => k !== 'kibble').map(([k, [ic, l, p]]) => item('food', k, ic, l, p, Object.entries(FOODS[k][3]).map(([a, v]) => a + ' ' + (v > 0 ? '+' : '') + v).join(', '))).join('') + '</div>'
                + '<div class="sub">Pflege & Spielzeug</div><div class="shop">' + Object.entries(ITEMS).map(([k, [ic, l, p, d, perm]]) => item('item', k, ic, l, p, d, perm && has(k))).join('') + '</div>'
                + '<div class="sub">Accessoires</div><div class="shop">' + Object.entries(HATS).map(([k, [ic, l, p]]) => item('hat', k, ic, l, p, 'Zum Aufsetzen', s.owned.includes(k))).join('') + '</div>';
        } else if (tab === 'bag') {
            const foods = Object.entries(FOODS).filter(([k]) => k === 'kibble' || s.inv[k] > 0);
            box.innerHTML = '<div class="sub">Essen – antippen zum Füttern</div><div class="shop">' + foods.map(([k, [ic, l]]) => `<button type="button" class="shop-it" data-food="${k}"><span>${ic}</span><b>${l}</b><small>${k === 'kibble' ? '∞' : s.inv[k] + '×'}</small></button>`).join('') + '</div>'
                + '<div class="sub">Pflege</div><div class="shop">' + ['medicine', 'soap'].map(k => `<div class="shop-it static"><span>${ITEMS[k][0]}</span><b>${ITEMS[k][1]}</b><small>${s.inv[k] || 0}×</small></div>`).join('')
                + s.perm.map(k => `<div class="shop-it static owned"><span>${ITEMS[k][0]}</span><b>${ITEMS[k][1]}</b><small>dauerhaft</small></div>`).join('') + '</div>'
                + '<div class="sub">Accessoires – antippen zum Aufsetzen</div><div class="shop">' + (s.owned.map(k => `<button type="button" class="shop-it ${s.hat === k ? 'worn' : ''}" data-hat="${k}"><span>${HATS[k][0]}</span><b>${HATS[k][1]}</b><small>${s.hat === k ? 'trägt es' : 'aufsetzen'}</small></button>`).join('') || '<p class="muted small">Noch keine – schau in den Shop!</p>') + '</div>';
        } else if (tab === 'custom') {
            box.innerHTML = `<div class="f2"><label>Name <input id="tcName" maxlength="20" value="${esc(s.name)}"></label>
                <label>Persönlichkeit <select id="tcPers">${Object.entries(PERS).map(([k, [ic, l]]) => `<option value="${k}" ${s.pers === k ? 'selected' : ''}>${ic} ${l}</option>`).join('')}</select></label></div>
                <div class="f2"><label>Lieblingsessen <select id="tcFav">${Object.entries(FOODS).filter(([k]) => k !== 'kibble').map(([k, [ic, l]]) => `<option value="${k}" ${s.fav === k ? 'selected' : ''}>${ic} ${l}</option>`).join('')}</select></label>
                <label>Stimme <span class="inl"><select id="tcVoice">${Object.entries(VOICES).map(([k, l]) => `<option value="${k}" ${s.voice === k ? 'selected' : ''}>${l}</option>`).join('')}</select><button type="button" class="fh-btn sm" id="tcVoiceTest">▶</button></span></label></div>
                <div class="sub">Aussehen</div>
                <div class="mode-switch three"><button type="button" data-src="sona" class="${s.lookSrc === 'sona' ? 'on' : ''}">🎨 Meine Fursona</button><button type="button" data-src="random" class="${s.lookSrc === 'random' ? 'on' : ''}">🎲 Zufallstier</button><button type="button" data-src="custom" class="${s.lookSrc === 'custom' ? 'on' : ''}">🖼️ Eigene Bilder</button></div>
                ${s.lookSrc === 'random' ? '<button type="button" class="fh-btn sm" id="tcReroll">🎲 Neues Zufallstier</button>' : ''}
                ${s.lookSrc === 'sona' ? '<p class="muted small">Benutzt deine Fursona aus dem Baukasten – mit eigenen Bildern, falls du dort welche hochgeladen hast.</p>' : ''}
                <div class="emo-slots" id="tcSlots" ${s.lookSrc === 'custom' ? '' : 'hidden'}></div>
                <div class="sub">Hintergrund</div><div class="chips">${Object.entries(BGS).map(([k, [l]]) => `<button type="button" data-bg="${k}" class="${s.bg === k ? 'on' : ''}">${l}</button>`).join('')}</div>
                <div class="sub">Gehäusefarbe</div><div class="chips">${SHELLS.map(c => `<button type="button" data-shell="${c}" class="sw ${s.shell === c ? 'on' : ''}" style="background:${c}" aria-label="${c}"></button>`).join('')}<input type="color" id="tcShell" value="${s.shell}"></div>
                <div class="sub">Neustart</div><button type="button" class="fh-btn sm danger" id="tcReset">🥚 Neues Ei legen</button>`;
            if (s.lookSrc === 'custom') emoSlots($('#tcSlots'), () => s.images, 'pet', () => { touch(); renderPet(); });
            $('#tcName').onchange = e => { s.name = e.target.value.trim().slice(0, 20) || s.name; touch(); renderAll(); };
            $('#tcPers').onchange = e => { s.pers = e.target.value; touch(); renderSide(); };
            $('#tcFav').onchange = e => { s.fav = e.target.value; touch(); renderSide(); };
            $('#tcVoice').onchange = e => { s.voice = e.target.value; touch(); };
            $('#tcVoiceTest').onclick = () => sfx[s.voice] && sfx[s.voice]();
            const shell = c => { s.shell = c; touch(); renderAll(); };
            $('#tcShell').oninput = e => shell(e.target.value);
            if ($('#tcReroll')) $('#tcReroll').onclick = () => { s.randomLook = randomLook(); touch(); renderPet(); };
            $('#tcReset').onclick = () => { if (!confirm('Wirklich ein neues Ei? ' + s.name + ' zieht dann in die weite Welt hinaus. (Münzen, Inventar und Accessoires bleiben.)')) return; const keep = { coins: s.coins, inv: s.inv, owned: s.owned, perm: s.perm, images: s.images, shell: s.shell, bg: s.bg }; s = Object.assign(fresh(), keep); s.log = [[Date.now(), '🥚 Ein neues Ei!']]; touch(); renderAll(); };
            box.onclickExtra = null;
        } else {
            box.innerHTML = '<ul class="pet-log">' + (s.log.map(([t, txt]) => `<li><small>${new Date(t).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</small> ${esc(txt)}</li>`).join('') || '<li class="muted">Noch nichts erlebt.</li>') + '</ul>'
                + `<p class="muted small">Gestreichelt ${s.stat.pats}× · umarmt ${s.stat.hugs}× · gefüttert ${s.stat.feeds}× · ${s.stat.cleaned} Häufchen weggeräumt · ${s.stat.games} Spiele</p>`;
        }
    }

    function buy(kind, k) {
        const price = kind === 'food' ? FOODS[k][2] : kind === 'item' ? ITEMS[k][2] : HATS[k][2];
        if (s.coins < price) { toast('Zu wenig Münzen. 🪙', true); return; }
        s.coins -= price;
        if (kind === 'hat') { s.owned.push(k); s.hat = k; unlock('hat'); say(HATS[k][0] + ' Steht ' + s.name + ' super!'); }
        else if (kind === 'item' && ITEMS[k][4]) { s.perm.push(k); say(ITEMS[k][0] + ' ' + ITEMS[k][1] + ' – ' + ITEMS[k][3] + '!'); }
        else { s.inv[k] = (s.inv[k] || 0) + 1; say((kind === 'food' ? FOODS[k][0] : ITEMS[k][0]) + ' gekauft!'); }
        unlock('shop'); sfx.chirp(); touch(); renderAll();
    }

    // ---------- Start ----------
    function load() {
        if (s) return;
        s = store.get('tama', null) || migrate();
        s = norm(Object.assign(fresh(), s));
        localSaved = s.savedAt || 0;
        const today = new Date().toDateString();
        if (s.daily !== today) { s.daily = today; if (s.stageSeen !== 'egg' || stage() !== 'egg') { s.coins += 10; setTimeout(() => toast('🪙 Täglicher Besuchs-Bonus: +10 Münzen!'), 600); } }
        decay();
    }
    async function init() {
        load();
        if (FH.me) {
            try {
                const r = await api('pet');
                if (r.pet && r.pet.state && (r.pet.updated * 1000 > localSaved + 1500)) { s = norm(Object.assign(fresh(), r.pet.state)); s.last = Math.min(s.last, Date.now()); decay(); }
                else touch();
            } catch (e) { /* offline */ }
        }
        const panel = $('[data-panel="tama"]');
        panel.addEventListener('click', e => {
            const t = e.target;
            const q = t.closest('[data-t]'); if (q) { if (q.dataset.t === 'feed') { $('#tamaMenu').hidden ? openMenu() : closeMenu(); } else act(q.dataset.t); return; }
            if (t.closest('#tamaPet')) { act('boop'); return; }
            const hw = t.closest('[data-hw]'); if (hw) { act({ a: 'boop', b: 'feed', c: 'play' }[hw.dataset.hw]); return; }
            const p = t.closest('[data-poop]'); if (p) { s.poops = Math.max(0, s.poops - 1); s.stat.cleaned++; xp(2); if (s.stat.cleaned >= 10) unlock('tidy'); say('🧹 *wisch*'); touch(); renderAll(); return; }
            const fd = t.closest('[data-food]'); if (fd) { act('feed', fd.dataset.food); return; }
            if (t.closest('[data-close-menu]')) { closeMenu(); return; }
            const tt = t.closest('[data-tt]'); if (tt) { tab = tt.dataset.tt; renderTab(); return; }
            const b = t.closest('[data-buy]'); if (b) { const [kind, k] = b.dataset.buy.split(':'); buy(kind, k); return; }
            const h = t.closest('[data-hat]'); if (h) { s.hat = s.hat === h.dataset.hat ? null : h.dataset.hat; if (s.hat) unlock('hat'); touch(); renderAll(); return; }
            const src = t.closest('[data-src]'); if (src) { s.lookSrc = src.dataset.src; if (s.lookSrc === 'sona' && !K.currentLook()) toast('Du hast noch keine Fursona gebaut – es wird solange das Zufallstier gezeigt.'); touch(); renderAll(); return; }
            const bg = t.closest('[data-bg]'); if (bg) { s.bg = bg.dataset.bg; touch(); renderAll(); return; }
            const sh = t.closest('[data-shell]'); if (sh) { s.shell = sh.dataset.shell; touch(); renderAll(); }
        });
        renderAll(); K.renderMine(); K.renderTop();
        if (!$('#tamaSay').textContent) say(stage() === 'egg' ? 'Ein Ei! Boop es, damit es schneller schlüpft. 🥚' : s.sick ? s.name + ' fühlt sich nicht gut … 🤒' : s.poops ? 'Da liegt was … 💩' : s.food < 25 ? s.name + ' hat Hunger!' : 'Hallo! ' + rnd(['🐾', '💖', '✨']));
        setInterval(() => { if (document.hidden) return; decay(); if (!$('[data-panel="tama"]').hidden) { renderScreen(); if (tab !== 'custom') renderSide(); } }, 5000);
    }

    K.INIT.tama = init;
    K.SHOW.tama = () => { if (s) { decay(); renderAll(); } };
    K.tamaBoop = () => act('boop');
    K.addCoins = addCoins;
    K.petLook = () => { load(); return lookOf(); };
    K.petGame = () => { load(); s.stat.games++; touch(); };
})();
