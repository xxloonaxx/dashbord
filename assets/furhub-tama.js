/* Furry Hub – Tamagotchi: Spielstände, Hardcore-Modus, Einstellungen, Cheats, Ei, Wachstum, Pflege, Münzen, Shop & eigene Bilder */
(function () {
    'use strict';
    const K = window.FHK; if (!K) return;
    const { FH, $, $$, esc, rnd, clamp, store, toast, confetti, floatText, unlock, react, boopCount, sfx, face, emoSlots, randomLook, genName, api, download } = K;

    // ---------- Inhalte ----------
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
    const DIFFS = { easy: ['🌸 Entspannt', 0.5], normal: ['🙂 Normal', 1], hard: ['🔥 Schwer', 1.5], hardcore: ['💀 Hardcore', 2] };
    const CHEATS = {
        MOREFLUFF: ['+100 Münzen', s => { s.coins += 100; }],
        BELLYFULL: ['Alle Werte auf 100', s => { ['food', 'fun', 'energy', 'clean', 'health', 'love'].forEach(k => { s[k] = 100; }); }],
        HATCHNOW: ['Ei schlüpft sofort', s => { s.hatchAt = Math.min(s.hatchAt, Date.now() - 1000); }],
        TIMEWARP: ['Einen Tag älter', s => { s.hatchAt -= DAY; s.born -= DAY; }],
        LEVELUP: ['+1 Stufe', s => { s.level++; }],
        CLEANUP: ['Häufchen & Krankheit weg', s => { s.poops = 0; s.sick = false; s.health = Math.max(s.health, 80); }],
        FASHION: ['Alle Accessoires', s => { s.owned = Object.keys(HATS); }],
        SPARKLE: ['✨ Glitzer-Aura (an/aus)', s => { s.fx.sparkle = !s.fx.sparkle; }],
        BIGFLUFF: ['Riesig (an/aus)', s => { s.fx.big = !s.fx.big; s.fx.tiny = false; }],
        TINYPAWS: ['Winzig (an/aus)', s => { s.fx.tiny = !s.fx.tiny; s.fx.big = false; }],
        RAINBOW: ['🌈 Regenbogen-Gehäuse (an/aus)', s => { s.fx.rainbow = !s.fx.rainbow; }],
        DISCO: ['🪩 Disco-Bildschirm (an/aus)', s => { s.fx.disco = !s.fx.disco; }],
        IMMORTAL: ['Werte fallen nie unter 25 (an/aus)', s => { s.fx.immortal = !s.fx.immortal; }],
        NOCHEATS: ['Alle Cheat-Effekte aus', s => { s.fx = {}; }],
    };
    const H = 3600000, DAY = 24 * H, MAX_SLOTS = 6;
    const today = () => new Date().toDateString();

    // ---------- Zustand ----------
    let W = null;          // { v: 3, active, slots: { id: Haustier }, savedAt }
    let s = null;          // aktiver Spielstand
    let booted = false, wired = false, dirty = false, saveT = null, flashEmo = null, flashT = null, lastBoop = 0, cool = {}, tab = 'shop', combo = 0, comboT = null, lastBubble = Date.now(), localSaved = 0;

    function defCfg() { return { diff: 'normal', hardcore: false, time: 'real', growth: 'normal', poop: true, sick: true, autosleep: false, sound: true, bubbles: true, notify: false, vacation: false }; }
    function fresh(opts = {}) {
        const now = Date.now();
        const cfg = Object.assign(defCfg(), opts.cfg || {});
        if (cfg.hardcore) Object.assign(cfg, { diff: 'hardcore', time: 'real', poop: true, sick: true, vacation: false });
        return { v: 3, name: opts.name || genName().split(' ')[0], born: now, hatchAt: now + 90000, last: now, food: 80, fun: 80, energy: 85, clean: 90, health: 100, love: 30,
                 xp: 0, level: 1, coins: cfg.hardcore ? 10 : 20, sleeping: false, sick: false, poops: 0, nextPoop: now + (2.5 + Math.random() * 1.5) * H,
                 pers: rnd(Object.keys(PERS)), fav: rnd(['meat', 'fish', 'berries', 'carrot', 'cake', 'pizza']), voice: rnd(Object.keys(VOICES)),
                 lookSrc: K.currentLook() ? 'sona' : 'random', randomLook: randomLook(), images: {}, hat: null, owned: [], perm: [], inv: { treat: 3, medicine: 1 },
                 bg: 'meadow', shell: rnd(SHELLS), log: [[now, cfg.hardcore ? '💀 Ein Hardcore-Ei! Viel Glück …' : '🥚 Ein neues Ei!']], stat: { boops: 0, pats: 0, hugs: 0, feeds: 0, games: 0, cleaned: 0 },
                 daily: today(), stageSeen: 'egg', cfg, fx: {}, cheated: false, cheats: [], dead: false, diedAt: 0, created: now, lastNotify: 0 };
    }
    /** Objekte reparieren (PHP liefert leere Objekte als []) und fehlende Felder ergänzen */
    function norm(x) {
        const d = fresh({ cfg: x && x.cfg });
        x = Object.assign(d, x || {});
        ['images', 'inv', 'fx'].forEach(k => { if (!x[k] || Array.isArray(x[k])) x[k] = {}; });
        x.cfg = Object.assign(defCfg(), Array.isArray(x.cfg) ? {} : x.cfg);
        if (x.cfg.hardcore) Object.assign(x.cfg, { diff: 'hardcore', time: 'real', poop: true, sick: true, vacation: false });
        x.stat = Object.assign({ boops: 0, pats: 0, hugs: 0, feeds: 0, games: 0, cleaned: 0 }, Array.isArray(x.stat) ? {} : x.stat);
        ['owned', 'perm', 'log', 'cheats'].forEach(k => { if (!Array.isArray(x[k])) x[k] = []; });
        return x;
    }
    const newId = () => 's' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5);
    /** Speicherstand v3 aus allem, was es gibt (v3, v2 „tama“, v1 „pet“) */
    function wrap(src) {
        if (src && src.slots && !Array.isArray(src.slots)) {
            const w = { v: 3, active: src.active, slots: {}, savedAt: src.savedAt || 0 };
            Object.entries(src.slots).forEach(([id, p]) => { if (p) w.slots[id] = norm(p); });
            if (!w.slots[w.active]) w.active = Object.keys(w.slots)[0];
            if (!w.active) { const id = newId(); w.slots[id] = fresh(); w.active = id; }
            return w;
        }
        const id = newId();
        return { v: 3, active: id, slots: { [id]: norm(src || fresh()) }, savedAt: (src && src.savedAt) || 0 };
    }
    function migrateLocal() {
        const v3 = store.get('tama3', null); if (v3) return wrap(v3);
        const v2 = store.get('tama', null); if (v2) return wrap(v2);
        const v1 = store.get('pet', null);
        if (v1) { const p = fresh(); Object.assign(p, { name: v1.name || p.name, xp: v1.xp || 0, level: v1.level || 1, hatchAt: Date.now() - 1000, stageSeen: 'baby' }); if (v1.look) p.randomLook = v1.look; p.log = [[Date.now(), '🐾 ' + p.name + ' ist in ein Tamagotchi umgezogen!']]; return wrap(p); }
        return wrap(null);
    }
    function use(id) { W.active = id; s = W.slots[id]; flashEmo = null; }

    // ---------- Hilfen ----------
    const cfg = () => s.cfg;
    const hc = () => !!s.cfg.hardcore;
    const factor = () => DIFFS[hc() ? 'hardcore' : s.cfg.diff][1];
    const growthDiv = () => s.cfg.growth === 'fast' ? 6 : 1;
    function stageOf(p) { const now = Date.now(); if (now < p.hatchAt) return 'egg'; const age = (now - p.hatchAt) * (p.cfg.growth === 'fast' ? 6 : 1); return age < DAY ? 'baby' : age < 3 * DAY ? 'child' : age < 7 * DAY ? 'teen' : 'adult'; }
    const stage = () => stageOf(s);
    const has = p => s.perm.includes(p);
    const mult = k => (PERS[s.pers] || [0, 0, {}])[2][k] || 1;
    const play = n => { if (s.cfg.sound !== false && sfx[n]) sfx[n](); };
    function say(t, log) {
        const b = $('#tamaSay'); if (b && s.cfg.bubbles !== false) { b.textContent = t; b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop'); }
        if (log) { s.log.unshift([Date.now(), t]); s.log = s.log.slice(0, 40); }
        lastBubble = Date.now();
    }
    function flash(emo, ms = 900) { flashEmo = emo; clearTimeout(flashT); flashT = setTimeout(() => { flashEmo = null; renderPet(); }, ms); renderPet(); }
    function notify(text) {
        if (!s.cfg.notify || !('Notification' in window) || Notification.permission !== 'granted') return;
        if (Date.now() - (s.lastNotify || 0) < 30 * 60000) return;
        s.lastNotify = Date.now();
        try { new Notification((s.name || 'Tamagotchi') + ' braucht dich', { body: text, tag: 'fh-tama' }); } catch (e) { /* egal */ }
    }

    // ---------- Zeit vergeht ----------
    function decayOf(p, quiet) {
        const prev = s; s = p;
        try { decay(quiet); } finally { s = prev; }
    }
    function decay(quiet) {
        const now = Date.now();
        let h = Math.min(72, Math.max(0, (now - s.last) / H));
        s.last = now;
        if (s.dead) return;
        if (stage() === 'egg') return;
        if (s.cfg.vacation && !hc()) return;
        if (s.cfg.time === 'pause' && !hc() && h > 2 / 60) h = 0;   // Pause-Modus: Zeit, in der niemand da war, zählt nicht
        // Auto-Schlaf nachts
        const hr = new Date().getHours();
        if (s.cfg.autosleep && !s.sleeping && (hr >= 23 || hr < 7) && s.energy < 95) { s.sleeping = true; if (!quiet) say('🌙 Schlafenszeit – ' + s.name + ' geht ins Bett.', true); }
        if (s.cfg.autosleep && s.sleeping && hr >= 7 && hr < 23 && s.energy > 60) { s.sleeping = false; if (!quiet) say('☀️ Guten Morgen!', true); }
        const f = factor();
        s.food -= 6 * h * mult('food') * f;
        s.fun -= 5 * h * mult('fun') * f;
        s.clean -= (3 + 2 * s.poops) * h * mult('clean') * f;
        s.love -= 1.5 * h * mult('love') * (has('plush') ? 0.5 : 1) * f;
        s.energy += s.sleeping ? 22 * h * (has('bed') ? 1.4 : 1) : -4 * h * mult('energy') * f;
        if (s.cfg.poop) {
            let guard = 0;
            while (now > s.nextPoop && guard++ < 10) { s.poops = Math.min(4, s.poops + 1); s.nextPoop += (2.5 + Math.random() * 1.5) * H / Math.max(1, f * 0.8); }
            if (s.nextPoop < now) s.nextPoop = now + 2 * H;
        } else { s.poops = 0; s.nextPoop = now + 3 * H; }
        const bad = s.food < 20 || s.clean < 25 || s.poops >= 3;
        s.health += (bad ? -5 * f : 1.5) * h - (s.sick ? 2 * h * f : 0);
        ['food', 'fun', 'clean', 'love', 'energy', 'health'].forEach(k => { s[k] = clamp(s[k], 0, 100); });
        if (s.fx.immortal) ['food', 'fun', 'clean', 'love', 'energy', 'health'].forEach(k => { s[k] = Math.max(25, s[k]); });
        if (!hc()) s.health = Math.max(5, s.health);   // ohne Hardcore stirbt niemand
        if (s.cfg.sick && !s.sick && s.health < 30) { s.sick = true; if (!quiet) say('🤒 ' + s.name + ' ist krank geworden! Medizin hilft.', true); notify(s.name + ' ist krank geworden 🤒'); }
        if (!s.cfg.sick) s.sick = false;
        if (s.sleeping && s.energy >= 100 && !s.cfg.autosleep) { s.sleeping = false; if (!quiet) say('☀️ ' + s.name + ' ist ausgeschlafen!', true); }
        // Hardcore: Tod
        if (hc() && s.health <= 0) {
            s.dead = true; s.diedAt = now; s.sleeping = false; s.sick = false; s.poops = 0;
            if (!quiet) say('🌈 ' + s.name + ' ist über die Regenbogenbrücke gegangen. Danke für alles. 💜');
            s.log.unshift([now, '🌈 ' + s.name + ' ist über die Regenbogenbrücke gegangen.']);
            if (!quiet) { toast('🌈 ' + s.name + ' ist über die Regenbogenbrücke gegangen …', true); unlock('rainbow'); }
            notify(s.name + ' ist gestorben 🌈');
            return;
        }
        if (s.food < 15) notify(s.name + ' hat großen Hunger 🍖');
        else if (s.poops >= 3) notify('Da liegen ' + s.poops + ' Häufchen 💩');
        const st = stage();
        if (st !== s.stageSeen) {
            if (!quiet) {
                if (s.stageSeen === 'egg') { say('🐣 ' + s.name + ' ist geschlüpft! Hallo Welt!', true); confetti(40, ['🐣', '✨', '💖']); unlock('hatch'); }
                else say('🎉 ' + s.name + ' ist jetzt ' + STAGES[st][0].split(' ')[1] + '!', true);
                if (st === 'adult') { unlock('adult'); if (hc() && !s.cheated) unlock('hcadult'); }
            }
            s.stageSeen = st;
        }
    }
    function decayAll() { Object.values(W.slots).forEach(p => { if (p !== s) decayOf(p, true); }); decay(); touch(); }

    function emotion() {
        if (s.dead) return 'sleepy';
        if (s.sleeping) return 'sleepy';
        if (flashEmo) return flashEmo;
        if (s.sick || s.health < 35) return 'sad';
        if (Math.min(s.food, s.fun, s.clean) < 15 || s.love < 8) return 'angry';
        if (s.energy < 20) return 'sleepy';
        const m = (s.food + s.fun + s.clean + s.energy + s.health) / 5;
        return m > 70 ? 'happy' : m < 35 ? 'sad' : 'neutral';
    }
    function lookOf(p = s) {
        if (p.lookSrc === 'custom') return Object.assign({}, p.randomLook, { mode: 'custom', images: p.images });
        if (p.lookSrc === 'sona') return K.currentLook() || p.randomLook;
        return p.randomLook;
    }
    function needs(p = s) {
        if (p.dead) return ['🌈'];
        const n = [];
        if (stageOf(p) === 'egg') return n;
        if (p.cfg.vacation && !p.cfg.hardcore) return ['🏖️'];
        if (p.food < 25) n.push('🍖'); if (p.fun < 25) n.push('🎾'); if (p.clean < 25) n.push('🛁');
        if (p.energy < 20 && !p.sleeping) n.push('😴'); if (p.poops) n.push('💩'); if (p.sick) n.push('🤒'); if (p.love < 15) n.push('💔');
        return n;
    }

    // ---------- Speichern ----------
    function touch() { dirty = true; W.savedAt = Date.now(); try { store.set('tama3', W); } catch (e) { /* voll */ } clearTimeout(saveT); saveT = setTimeout(push, 2500); }
    const serverCopy = () => {
        const c = JSON.parse(JSON.stringify(W));
        Object.values(c.slots).forEach(p => Object.keys(p.images || {}).forEach(k => { if (String(p.images[k]).startsWith('data:')) delete p.images[k]; }));
        return c;
    };
    async function push() {
        if (!FH.me || !dirty) return;
        dirty = false;
        try { await api('pet_save', { state: JSON.stringify(serverCopy()) }); } catch (e) { dirty = true; }
    }
    window.addEventListener('beforeunload', () => { if (FH.me && dirty && navigator.sendBeacon && W) { const f = new FormData(); f.append('state', JSON.stringify(serverCopy())); navigator.sendBeacon('furhub.php?api=pet_save', f); } });

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
        boot();
        if (s.dead) return;
        n = Math.max(0, Math.round(n * (hc() ? 1.5 : 1))); if (!n) return;   // Hardcore: mehr Münzen
        s.coins += n; if (why) s.log.unshift([Date.now(), `🪙 +${n} (${why})`]);
        touch(); if (wired) renderSide();
    }

    // ---------- Aktionen ----------
    const COOLDOWN = { pat: 1200, hug: 3000, awoo: 2000 };
    function cd(k) { const now = Date.now(); if (now - (cool[k] || 0) < (COOLDOWN[k] || 0)) return false; cool[k] = now; return true; }
    function act(a, arg) {
        decay();
        const st = stage(), nm = s.name;
        if (s.dead) { say('🌈 ' + nm + ' ruht in Frieden. Unter „💾 Spielstände“ kannst du ein neues Ei legen.'); return; }
        if (s.cfg.vacation && !hc() && a !== 'boop') { say('🏖️ ' + nm + ' ist im Urlaub. (Einstellungen → Urlaub beenden)'); return; }
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
                flash('surprised', 550); play('boop'); react('boop'); boopCount();
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
                flash('happy', 1100); react('pat'); play('purr'); say(rnd(['*lehnt sich in die Hand*', '💖', 'Mehr! Mehr!', '*wedelt*', '*schnurrt*']));
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
                play('awoo'); react('awoo'); unlock('awoo'); s.fun = clamp(s.fun + 2, 0, 100);
                say('🐺 AWOOO! … ' + nm + ' heult mit!'); setTimeout(() => play(s.voice), 1500); flash('happy', 2000);
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
                flash('happy', 1000); play('chirp'); closeMenu();
                break;
            }
            case 'play':
                if (s.energy < 15) { say(nm + ' ist zu müde zum Spielen. 😴'); flash('sleepy', 900); return; }
                s.fun = clamp(s.fun + 20 * (s.pers === 'playful' ? 1.3 : 1) * (has('ball') ? 1.25 : 1), 0, 100);
                s.energy = clamp(s.energy - 12, 0, 100); s.food = clamp(s.food - 6, 0, 100); s.clean = clamp(s.clean - 6, 0, 100); xp(8);
                say(rnd([(has('ball') ? '⚽' : '🎾') + ' *jagt den Ball*', 'Nochmal! Nochmal!', '*zoomies* 💨', 'Hab ihn!']));
                $('#tamaPet').classList.add('bounce'); setTimeout(() => $('#tamaPet').classList.remove('bounce'), 700);
                flash('happy', 1200); play(s.voice);
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
        if (s.dead) {
            el.innerHTML = `<div class="tama-grave"><div class="grave-pet">${face(lookOf(), 'sleepy')}</div><div class="grave-stone">🪦</div><b>${esc(s.name)}</b><small>${ageText()} · Stufe ${s.level}</small></div>`;
            return;
        }
        if (st === 'egg') {
            const left = Math.max(0, s.hatchAt - Date.now());
            el.innerHTML = `<div class="tama-egg ${left < 20000 ? 'crack' : ''}">🥚</div><div class="egg-time">schlüpft in ${Math.ceil(left / 1000)} s · Boops wärmen!</div>`;
            return;
        }
        const hat = s.hat && HATS[s.hat] ? `<span class="tp-hat hat-${s.hat}">${HATS[s.hat][0]}</span>` : '';
        const sc = STAGES[st][1] * (s.fx.big ? 1.25 : s.fx.tiny ? 0.55 : 1);
        el.innerHTML = `<div class="tp-wrap ${s.fx.sparkle ? 'sparkle' : ''}" style="--sc:${sc}">${face(lookOf(), emotion())}${hat}</div>`;
    }
    function renderScreen() {
        const scr = $('#tamaScreen'); if (!scr) return;
        scr.style.background = (BGS[s.bg] || BGS.meadow)[1];
        scr.classList.toggle('dark', s.sleeping && !s.dead);
        scr.classList.toggle('dead', !!s.dead);
        scr.classList.toggle('disco', !!s.fx.disco);
        scr.classList.toggle('vacation', !!(s.cfg.vacation && !hc()));
        const dev = $('#tamaDevice');
        dev.style.setProperty('--shell', s.shell);
        dev.classList.toggle('rainbow', !!s.fx.rainbow);
        dev.classList.toggle('hardcore', hc());
        $('#tamaBrand').textContent = (hc() ? '💀 ' : '') + (s.name || 'FLUFF').toUpperCase() + '-GOTCHI' + (s.cheated ? ' 🃏' : '');
        const st = stage();
        const hearts = Math.round(s.love / 20);
        if (s.dead) { $('#tamaIcons').innerHTML = `<span>🌈 In Erinnerung</span><span>${new Date(s.diedAt).toLocaleDateString('de-DE')}</span>`; }
        else $('#tamaIcons').innerHTML = `<span>${STAGES[st][0]}</span><span>${'❤️'.repeat(hearts)}${'🤍'.repeat(5 - hearts)}</span>${s.sick ? '<span>🤒</span>' : ''}${s.sleeping ? '<span>💤</span>' : ''}${s.cfg.vacation && !hc() ? '<span>🏖️</span>' : ''}`;
        $('#tamaPoops').innerHTML = s.dead ? '' : Array.from({ length: s.poops }, (_, i) => `<button type="button" class="poop" data-poop="${i}" title="Wegräumen">💩</button>`).join('');
        $('#tamaSleep').textContent = s.sleeping ? '☀️ Aufwecken' : '💡 Licht aus';
        $$('#tamaQuick .fh-btn').forEach(b => { b.disabled = !!s.dead; });
        renderPet();
    }
    function bar(ic, l, v) { return `<div class="pbar"><span>${ic} ${l}</span><div><i style="width:${v}%;background:${v < 25 ? 'var(--accent-red)' : v < 55 ? 'var(--accent-gold)' : 'var(--accent-green)'}"></i></div><b>${Math.round(v)}</b></div>`; }
    function ageText(p = s) {
        if (stageOf(p) === 'egg') return 'noch im Ei';
        const d = ((p.dead ? p.diedAt : Date.now()) - p.hatchAt) / DAY;
        return d < 1 ? Math.max(1, Math.round(d * 24)) + ' Std. alt' : Math.floor(d) + (Math.floor(d) === 1 ? ' Tag alt' : ' Tage alt');
    }
    function renderSide() {
        if (!$('#tamaBars')) return;
        $('#tamaTitle').textContent = (s.dead ? '🌈 ' : '💗 ') + s.name;
        $('#tamaCoins').textContent = '🪙 ' + s.coins;
        $('#tamaBars').innerHTML = bar('🍖', 'Satt', s.food) + bar('🎾', 'Spaß', s.fun) + bar('⚡', 'Energie', s.energy) + bar('🛁', 'Sauber', s.clean) + bar('❤️‍🩹', 'Gesundheit', s.health) + bar('💞', 'Zuneigung', s.love);
        $('#tamaMeta').innerHTML = `<span class="mode-${hc() ? 'hc' : s.cfg.diff}">${DIFFS[hc() ? 'hardcore' : s.cfg.diff][0]}</span>${s.cheated ? '<span>🃏 Cheats benutzt</span>' : ''}<span>${STAGES[stage()][0]}</span><span>⭐ Stufe ${s.level} · ${s.xp}/${s.level * 50} XP</span><span>${ageText()}</span><span>${PERS[s.pers][0]} ${PERS[s.pers][1]}</span><span>❤ isst gern ${FOODS[s.fav][0]}</span><span>👃 ${s.stat.boops} Boops</span>`;
        renderTab();
    }
    function renderAll() { renderOutside(); if (!wired) return; renderScreen(); renderSide(); }

    // ---------- Futter-Menü ----------
    function openMenu() {
        const m = $('#tamaMenu');
        m.innerHTML = '<div class="tm-head">🍽️ Was gibt’s? <button type="button" data-close-menu>×</button></div><div class="tm-items">'
            + Object.entries(FOODS).map(([k, [ic, l]]) => { const n = k === 'kibble' ? '∞' : (s.inv[k] || 0); return `<button type="button" data-food="${k}" ${n === 0 ? 'disabled' : ''}><span>${ic}</span>${l}<small>${n}×${k === s.fav ? ' ❤' : ''}</small></button>`; }).join('') + '</div>';
        m.hidden = false;
    }
    function closeMenu() { const m = $('#tamaMenu'); if (m) m.hidden = true; }

    // ---------- Seitenreiter ----------
    const lock = on => on ? ' disabled title="Im Hardcore-Modus fest"' : '';
    function renderTab() {
        const box = $('#tamaTab'); if (!box) return;
        $$('[data-tt]').forEach(b => b.classList.toggle('on', b.dataset.tt === tab));
        if (tab === 'shop') {
            if (s.dead) { box.innerHTML = '<p class="muted">Der Shop ist geschlossen. 🌈</p>'; return; }
            const item = (kind, k, ic, l, price, desc, owned) => `<button type="button" class="shop-it ${owned ? 'owned' : ''}" data-buy="${kind}:${k}" ${owned || s.coins < price ? 'disabled' : ''} title="${esc(desc || '')}"><span>${ic}</span><b>${l}</b><small>${owned ? '✓ gekauft' : price + ' 🪙'}</small></button>`;
            box.innerHTML = `<p class="muted small">Münzen gibt’s für Stufen, jeden 25. Boop, den täglichen Besuch und die <a href="#games" data-goto="games">Minispiele</a>${hc() ? ' (im Hardcore-Modus 1,5×)' : ''}.</p>`
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
                ${s.lookSrc === 'sona' ? '<p class="muted small">Benutzt deine Fursona – mit deinen eigenen Bildern, falls du dort welche hochgeladen hast.</p>' : ''}
                <div class="emo-slots" id="tcSlots" ${s.lookSrc === 'custom' ? '' : 'hidden'}></div>
                <div class="sub">Hintergrund</div><div class="chips">${Object.entries(BGS).map(([k, [l]]) => `<button type="button" data-bg="${k}" class="${s.bg === k ? 'on' : ''}">${l}</button>`).join('')}</div>
                <div class="sub">Gehäusefarbe</div><div class="chips">${SHELLS.map(c => `<button type="button" data-shell="${c}" class="sw ${s.shell === c ? 'on' : ''}" style="background:${c}" aria-label="${c}"></button>`).join('')}<input type="color" id="tcShell" value="${s.shell}"></div>`;
            if (s.lookSrc === 'custom') emoSlots($('#tcSlots'), () => s.images, 'pet', () => { touch(); renderPet(); });
            $('#tcName').onchange = e => { s.name = e.target.value.trim().slice(0, 20) || s.name; touch(); renderAll(); };
            $('#tcPers').onchange = e => { s.pers = e.target.value; touch(); renderSide(); };
            $('#tcFav').onchange = e => { s.fav = e.target.value; touch(); renderSide(); };
            $('#tcVoice').onchange = e => { s.voice = e.target.value; touch(); };
            $('#tcVoiceTest').onclick = () => sfx[s.voice] && sfx[s.voice]();
            $('#tcShell').oninput = e => { s.shell = e.target.value; touch(); renderScreen(); };
            if ($('#tcReroll')) $('#tcReroll').onclick = () => { s.randomLook = randomLook(); touch(); renderPet(); };
        } else if (tab === 'settings') {
            const c = s.cfg, H_ = hc();
            const chk = (k, l, hint, locked) => `<label class="set-row"><input type="checkbox" data-cfg="${k}" ${c[k] ? 'checked' : ''}${lock(locked)}><span><b>${l}</b><small>${hint}</small></span></label>`;
            const cheatsOn = Object.entries(s.fx).filter(([, v]) => v).map(([k]) => k);
            box.innerHTML = (H_ ? '<div class="hc-banner">💀 <b>Hardcore-Modus</b> – doppelt so schnell, Zeit läuft immer weiter, keine Cheats, kein Urlaub. Wird es zu lange vernachlässigt, geht es über die Regenbogenbrücke. Diese Einstellungen sind fest.</div>' : '')
                + `<div class="f2"><label>Schwierigkeit <select data-cfg-sel="diff"${lock(H_)}>${Object.entries(DIFFS).filter(([k]) => k !== 'hardcore' || H_).map(([k, [l]]) => `<option value="${k}" ${(H_ ? 'hardcore' : c.diff) === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                  <label>Wachstum <select data-cfg-sel="growth"><option value="normal" ${c.growth === 'normal' ? 'selected' : ''}>🐢 Normal (7 Tage bis erwachsen)</option><option value="fast" ${c.growth === 'fast' ? 'selected' : ''}>🐇 Schnell (~1 Tag)</option></select></label></div>
                  <label>Zeit <select data-cfg-sel="time"${lock(H_)}><option value="real" ${c.time === 'real' ? 'selected' : ''}>⏰ Echtzeit – Werte sinken auch, wenn ich weg bin</option><option value="pause" ${c.time === 'pause' ? 'selected' : ''}>⏸️ Pausiert – nur, solange die Seite offen ist</option></select></label>`
                + chk('vacation', '🏖️ Urlaubsmodus', 'Alles steht still, bis du zurück bist.', H_)
                + chk('poop', '💩 Häufchen', 'Ab und zu muss aufgeräumt werden.', H_)
                + chk('sick', '🤒 Krankheiten', 'Bei schlechter Pflege kann es krank werden.', H_)
                + chk('autosleep', '🌙 Auto-Schlaf', 'Schläft nachts (23–7 Uhr) von selbst.')
                + chk('sound', '🔊 Töne', 'Geräusche bei Aktionen.')
                + chk('bubbles', '💬 Sprechblasen', 'Meldet sich von selbst, wenn es etwas braucht.')
                + chk('notify', '🔔 Browser-Benachrichtigungen', 'Meldung bei Hunger, Häufchen oder Krankheit (solange die Seite offen ist).')
                + `<div class="sub">🃏 Cheats ${H_ ? '<small class="muted">(im Hardcore-Modus gesperrt)</small>' : ''}</div>`
                + (H_ ? '<p class="muted small">Ehrlich spielen! 💀</p>' : `<form class="cheat-form" id="cheatForm"><input id="cheatIn" maxlength="20" placeholder="Code eingeben …" autocomplete="off" spellcheck="false"><button class="fh-btn sm">Aktivieren</button></form>
                    ${cheatsOn.length ? `<p class="small">Aktiv: ${cheatsOn.map(k => `<span class="cheat-on">${esc(k)}</span>`).join(' ')}</p>` : ''}
                    <details class="cheat-list"><summary>📜 Cheat-Liste (Spoiler!)</summary>${Object.entries(CHEATS).map(([k, [d]]) => `<div><code>${k}</code> <span class="muted small">${d}</span>${s.cheats.includes(k) ? ' ✓' : ''}</div>`).join('')}</details>
                    <p class="muted small">Benutzte Cheats markieren den Spielstand mit 🃏.</p>`);
            const cf = $('#cheatForm');
            if (cf) cf.onsubmit = e => { e.preventDefault(); cheat($('#cheatIn').value); };
        } else if (tab === 'slots') {
            renderSlots(box);
        } else {
            box.innerHTML = '<ul class="pet-log">' + (s.log.map(([t, txt]) => `<li><small>${new Date(t).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</small> ${esc(txt)}</li>`).join('') || '<li class="muted">Noch nichts erlebt.</li>') + '</ul>'
                + `<p class="muted small">Gestreichelt ${s.stat.pats}× · umarmt ${s.stat.hugs}× · gefüttert ${s.stat.feeds}× · ${s.stat.cleaned} Häufchen weggeräumt · ${s.stat.games} Spiele · Spielstand seit ${new Date(s.created || s.born).toLocaleDateString('de-DE')}</p>`;
        }
    }

    // ---------- Spielstände ----------
    function renderSlots(box) {
        const ids = Object.keys(W.slots);
        box.innerHTML = `<div class="slots">${ids.map(id => {
            const p = W.slots[id], st = stageOf(p), act = id === W.active;
            const mode = p.cfg.hardcore ? '💀 Hardcore' : DIFFS[p.cfg.diff][0];
            return `<div class="slot ${act ? 'on' : ''} ${p.dead ? 'dead' : ''}" data-slot="${id}">
                <div class="slot-face">${st === 'egg' ? '<span class="slot-egg">🥚</span>' : face(lookOf(p), p.dead ? 'sleepy' : 'neutral')}</div>
                <div class="slot-info"><b>${esc(p.name)}</b>${p.dead ? ' 🌈' : ''}${p.cheated ? ' 🃏' : ''}<small>${mode} · ${STAGES[st][0]} · Stufe ${p.level} · ${ageText(p)}</small><small>🪙 ${p.coins}${needs(p).length ? ' · ' + needs(p).join('') : ''}</small></div>
                <div class="slot-acts">${act ? '<span class="slot-active">▶ aktiv</span>' : `<button type="button" class="fh-btn sm" data-slot-use="${id}">Spielen</button>`}
                    ${p.cfg.hardcore ? '' : `<button type="button" class="fh-btn sm" data-slot-dup="${id}" title="Duplizieren" ${ids.length >= MAX_SLOTS ? 'disabled' : ''}>⧉</button>`}
                    <button type="button" class="fh-btn sm" data-slot-exp="${id}" title="Als Datei exportieren">⬇</button>
                    <button type="button" class="fh-btn sm danger" data-slot-del="${id}" title="Löschen" ${ids.length < 2 ? 'disabled' : ''}>×</button></div></div>`;
        }).join('')}</div>
        ${ids.length < MAX_SLOTS ? `<form class="new-slot" id="newSlot"><div class="sub">🥚 Neues Ei legen (${ids.length}/${MAX_SLOTS})</div>
            <div class="f2"><label>Name <input name="name" maxlength="20" placeholder="${esc(genName().split(' ')[0])}"></label>
            <label>Modus <select name="mode">${Object.entries(DIFFS).map(([k, [l]]) => `<option value="${k}" ${k === 'normal' ? 'selected' : ''}>${l}</option>`).join('')}</select></label></div>
            <p class="muted small" id="modeHint">Normal: Werte sinken in normalem Tempo, niemand stirbt.</p>
            <button class="fh-btn gold">🥚 Ei legen</button></form>` : `<p class="muted small">Alle ${MAX_SLOTS} Plätze belegt – lösche oder exportiere einen Spielstand.</p>`}
        <div class="slot-tools"><button type="button" class="fh-btn sm" id="slotImport">⬆ Spielstand importieren</button><input type="file" id="slotFile" accept="application/json,.json" hidden>
            <button type="button" class="fh-btn sm" id="slotExportAll">⬇ Alle exportieren</button></div>`;
        const ns = $('#newSlot');
        if (ns) {
            const hints = { easy: '🌸 Entspannt: Werte sinken nur halb so schnell – ideal zum Nebenbei-Spielen.', normal: 'Normal: Werte sinken in normalem Tempo, niemand stirbt.', hard: '🔥 Schwer: 1,5× schneller. Häufchen kommen öfter.', hardcore: '💀 Hardcore: 2× schneller, Echtzeit, keine Cheats, kein Urlaub – und wer zu lange vernachlässigt wird, stirbt. 1,5× Münzen.' };
            ns.elements.mode.onchange = () => { $('#modeHint').textContent = hints[ns.elements.mode.value]; };
            ns.onsubmit = e => {
                e.preventDefault();
                const m = ns.elements.mode.value;
                if (m === 'hardcore' && !confirm('Hardcore wirklich? Dein Haustier kann sterben und es gibt keine Cheats.')) return;
                const id = newId();
                W.slots[id] = fresh({ name: ns.elements.name.value.trim().slice(0, 20) || ns.elements.name.placeholder, cfg: m === 'hardcore' ? { hardcore: true } : { diff: m } });
                use(id); if (Object.keys(W.slots).length >= 3) unlock('slots');
                touch(); renderAll(); say(hc() ? '💀 Ein Hardcore-Ei … pass gut darauf auf.' : 'Ein neues Ei! Boop es, damit es schneller schlüpft. 🥚');
            };
        }
        $('#slotImport').onclick = () => $('#slotFile').click();
        $('#slotFile').onchange = async e => {
            const f = e.target.files[0]; e.target.value = ''; if (!f) return;
            try {
                const data = JSON.parse(await f.text());
                const list = data && data.type === 'fluffogotchi' ? (data.slots || [data.slot]) : null;
                if (!list) throw new Error('Das ist keine Fluffogotchi-Datei.');
                let n = 0;
                for (const p of list) {
                    if (Object.keys(W.slots).length >= MAX_SLOTS) break;
                    if (!p || typeof p !== 'object' || typeof p.name !== 'string') continue;
                    const id = newId(); W.slots[id] = norm(p); W.slots[id].last = Date.now(); n++;
                }
                if (!n) throw new Error('Kein Platz oder keine gültigen Spielstände.');
                touch(); renderAll(); toast(`⬆ ${n} Spielstand${n > 1 ? 'e' : ''} importiert.`);
            } catch (err) { toast(err.message || 'Import fehlgeschlagen.', true); }
        };
        $('#slotExportAll').onclick = () => download(new Blob([JSON.stringify({ type: 'fluffogotchi', v: 3, exported: new Date().toISOString(), slots: Object.values(W.slots) }, null, 1)], { type: 'application/json' }), 'fluffogotchi_alle.json');
    }
    function slotAction(e) {
        const t = e.target;
        const u = t.closest('[data-slot-use]'); if (u) { decayAll(); use(u.dataset.slotUse); decay(); touch(); renderAll(); say('Hallo ' + s.name + '! 👋'); return true; }
        const d = t.closest('[data-slot-dup]'); if (d) { const p = JSON.parse(JSON.stringify(W.slots[d.dataset.slotDup])); p.name = (p.name + ' 2').slice(0, 20); p.log = [[Date.now(), '⧉ Kopie von ' + W.slots[d.dataset.slotDup].name]]; const id = newId(); W.slots[id] = norm(p); touch(); renderTab(); return true; }
        const x = t.closest('[data-slot-exp]'); if (x) { const p = W.slots[x.dataset.slotExp]; download(new Blob([JSON.stringify({ type: 'fluffogotchi', v: 3, exported: new Date().toISOString(), slot: p }, null, 1)], { type: 'application/json' }), 'fluffogotchi_' + (p.name || 'haustier').replace(/[äöüÄÖÜß]/g, c => ({ ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'Ae', Ö: 'Oe', Ü: 'Ue', ß: 'ss' }[c])).replace(/[^\w-]+/g, '_') + '.json'); return true; }
        const del = t.closest('[data-slot-del]');
        if (del) {
            const p = W.slots[del.dataset.slotDel];
            if (!confirm(`Spielstand „${p.name}“ wirklich löschen? Das lässt sich nicht rückgängig machen (exportiere ihn vorher, wenn du ihn behalten willst).`)) return true;
            delete W.slots[del.dataset.slotDel];
            if (W.active === del.dataset.slotDel) use(Object.keys(W.slots)[0]);
            touch(); renderAll(); return true;
        }
        const sl = t.closest('.slot[data-slot]'); if (sl && !t.closest('button')) { if (sl.dataset.slot !== W.active) { decayAll(); use(sl.dataset.slot); decay(); touch(); renderAll(); } return true; }
        return false;
    }

    // ---------- Cheats ----------
    function cheat(code) {
        code = String(code || '').toUpperCase().replace(/[^A-Z]/g, '');
        if (hc()) { toast('💀 Im Hardcore-Modus gibt es keine Cheats.', true); return; }
        if (s.dead) return;
        const c = CHEATS[code];
        if (!c) { toast('❌ Unbekannter Code.', true); play('hiss'); return; }
        c[1](s);
        s.cheated = true; if (!s.cheats.includes(code)) s.cheats.push(code);
        s.log.unshift([Date.now(), '🃏 Cheat: ' + code]);
        unlock('cheater'); toast('🃏 ' + code + ' – ' + c[0]); confetti(16, ['🃏', '✨']); play('chirp');
        decay(); touch(); renderAll();
    }

    function buy(kind, k) {
        const price = kind === 'food' ? FOODS[k][2] : kind === 'item' ? ITEMS[k][2] : HATS[k][2];
        if (s.coins < price) { toast('Zu wenig Münzen. 🪙', true); return; }
        s.coins -= price;
        if (kind === 'hat') { s.owned.push(k); s.hat = k; unlock('hat'); say(HATS[k][0] + ' Steht ' + s.name + ' super!'); }
        else if (kind === 'item' && ITEMS[k][4]) { s.perm.push(k); say(ITEMS[k][0] + ' ' + ITEMS[k][1] + ' – ' + ITEMS[k][3] + '!'); }
        else { s.inv[k] = (s.inv[k] || 0) + 1; say((kind === 'food' ? FOODS[k][0] : ITEMS[k][0]) + ' gekauft!'); }
        unlock('shop'); play('chirp'); touch(); renderAll();
    }

    // ---------- Status außerhalb des Reiters: Reiter-Punkt & Startseiten-Karte ----------
    function renderOutside() {
        const n = needs();
        const tb = $('[data-tab="tama"]');
        if (tb) { tb.classList.toggle('alert', n.length > 0 && !n.includes('🏖️') && !n.includes('🌈')); tb.title = n.length ? 'Braucht dich: ' + n.join(' ') : ''; }
        const card = $('#tamaStartCard');
        if (card) {
            const st = stage();
            card.innerHTML = `<div class="fh-ct">🥚 Dein Tamagotchi <small>${hc() ? '💀 Hardcore' : ''}</small></div><div class="tsc"><div class="tsc-face">${s.dead ? '🪦' : st === 'egg' ? '<span>🥚</span>' : face(lookOf(), emotion())}</div>
                <div><b>${esc(s.name)}</b><small>${s.dead ? '🌈 ruht in Frieden' : STAGES[st][0] + ' · Stufe ' + s.level + ' · 🪙 ' + s.coins}</small><small>${n.length ? 'Braucht: ' + n.join(' ') : (st === 'egg' ? 'schlüpft bald …' : 'Rundum glücklich 💖')}</small><button type="button" class="fh-btn sm" data-goto="tama">Hin!</button></div></div>`;
        }
    }
    function autoBubble() {
        if (!s.cfg.bubbles || s.dead || s.sleeping || stage() === 'egg' || Date.now() - lastBubble < 25000) return;
        const n = needs(); if (!n.length) { if (Math.random() < 0.3) say(rnd(['*summt*', '🎶', 'Hab dich lieb! 💖', '*gähnt*', 'Boop mich! 👃', '*schaut dich an*'])); else lastBubble = Date.now(); return; }
        const msg = { '🍖': 'Ich hab Hunger … 🍖', '🎾': 'Mir ist langweilig! Spielen? 🎾', '🛁': 'Ich bin ganz schmutzig … 🛁', '😴': 'So müde … 😴', '💩': 'Ähm … da liegt was. 💩', '🤒': 'Mir geht’s nicht gut … 🤒', '💔': 'Hast du mich noch lieb? 💔' };
        say(msg[rnd(n)] || '…');
    }

    // ---------- Start ----------
    function boot() {
        if (booted) return;
        booted = true;
        W = migrateLocal();
        use(W.active);
        localSaved = W.savedAt || 0;
        Object.values(W.slots).forEach(p => {
            if (p.daily !== today()) { p.daily = today(); if (!p.dead && stageOf(p) !== 'egg') { p.coins += 10; if (p === s) setTimeout(() => toast('🪙 Täglicher Besuchs-Bonus: +10 Münzen!'), 600); } }
        });
        decayAll();
        // Startseiten-Karte
        const grid = $('[data-panel="start"] .fh-grid');
        if (grid && !$('#tamaStartCard')) grid.insertAdjacentHTML('afterbegin', '<div class="fh-card tama-start" id="tamaStartCard"></div>');
        renderOutside();
        if (FH.me) sync();
        setInterval(() => {
            if (document.hidden && !s.cfg.notify) return;
            Object.values(W.slots).forEach(p => { if (p !== s) decayOf(p, true); });
            decay();
            renderOutside();
            if (wired && !$('[data-panel="tama"]').hidden) { renderScreen(); if (!['custom', 'settings', 'slots'].includes(tab)) renderSide(); autoBubble(); }
        }, 5000);
    }
    async function sync() {
        try {
            const r = await api('pet');
            if (r.pet && r.pet.state && r.pet.updated * 1000 > localSaved + 1500) {
                const active = W.active;
                W = wrap(r.pet.state);
                if (W.slots[active]) W.active = active;
                use(W.active); decayAll(); renderAll(); renderOutside();
            } else touch();
        } catch (e) { /* offline */ }
    }
    function init() {
        boot();
        wired = true;
        const panel = $('[data-panel="tama"]');
        panel.addEventListener('click', e => {
            const t = e.target;
            if (slotAction(e)) return;
            const q = t.closest('[data-t]'); if (q && q.tagName === 'BUTTON') { if (q.dataset.t === 'feed' && !s.dead && !(s.cfg.vacation && !hc()) && stage() !== 'egg') { $('#tamaMenu').hidden ? openMenu() : closeMenu(); } else act(q.dataset.t); return; }
            if (t.closest('#tamaPet')) { act('boop'); return; }
            const hw = t.closest('[data-hw]'); if (hw) { act({ a: 'boop', b: 'feed', c: 'play' }[hw.dataset.hw]); return; }
            const p = t.closest('[data-poop]'); if (p) { s.poops = Math.max(0, s.poops - 1); s.stat.cleaned++; xp(2); if (s.stat.cleaned >= 10) unlock('tidy'); say('🧹 *wisch*'); touch(); renderAll(); return; }
            const fd = t.closest('[data-food]'); if (fd) { act('feed', fd.dataset.food); return; }
            if (t.closest('[data-close-menu]')) { closeMenu(); return; }
            const tt = t.closest('[data-tt]'); if (tt) { tab = tt.dataset.tt; renderTab(); return; }
            const b = t.closest('[data-buy]'); if (b) { const [kind, k] = b.dataset.buy.split(':'); buy(kind, k); return; }
            const h = t.closest('[data-hat]'); if (h) { s.hat = s.hat === h.dataset.hat ? null : h.dataset.hat; if (s.hat) unlock('hat'); touch(); renderAll(); return; }
            const src = t.closest('[data-src]'); if (src) { s.lookSrc = src.dataset.src; if (s.lookSrc === 'sona' && !K.currentLook()) toast('Du hast noch keine Fursona – solange wird das Zufallstier gezeigt.'); touch(); renderAll(); return; }
            const bg = t.closest('[data-bg]'); if (bg) { s.bg = bg.dataset.bg; touch(); renderAll(); return; }
            const sh = t.closest('[data-shell]'); if (sh) { s.shell = sh.dataset.shell; touch(); renderAll(); }
        });
        panel.addEventListener('change', async e => {
            const t = e.target;
            if (t.dataset.cfg) {
                const k = t.dataset.cfg;
                if (hc() && ['vacation', 'poop', 'sick'].includes(k)) { t.checked = s.cfg[k]; return; }
                if (k === 'notify' && t.checked && 'Notification' in window && Notification.permission !== 'granted') {
                    const p = await Notification.requestPermission().catch(() => 'denied');
                    if (p !== 'granted') { t.checked = false; toast('Benachrichtigungen wurden im Browser nicht erlaubt.', true); return; }
                }
                decay(); s.cfg[k] = t.checked;
                if (k === 'vacation') say(t.checked ? '🏖️ Ab in den Urlaub! Bis bald!' : '🏠 Wieder zu Hause!', true);
                touch(); renderAll();
            }
            if (t.dataset.cfgSel) { if (hc() && t.dataset.cfgSel !== 'growth') return; decay(); s.cfg[t.dataset.cfgSel] = t.value; touch(); renderAll(); }
        });
        // Tastenkürzel
        document.addEventListener('keydown', e => {
            if (panel.hidden || e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input, textarea, select')) return;
            const map = { f: 'feed', p: 'play', w: 'wash', c: 'clean', m: 'medicine', l: 'sleep', h: 'hug', k: 'pat' };
            const a = map[e.key.toLowerCase()];
            if (!a) return;
            if (a === 'feed') { $('#tamaMenu').hidden ? openMenu() : closeMenu(); } else act(a);
        });
        renderAll(); K.renderMine(); K.renderTop();
        if (!$('#tamaSay').textContent) say(s.dead ? '🌈 …' : stage() === 'egg' ? 'Ein Ei! Boop es, damit es schneller schlüpft. 🥚' : s.sick ? s.name + ' fühlt sich nicht gut … 🤒' : s.poops ? 'Da liegt was … 💩' : s.food < 25 ? s.name + ' hat Hunger!' : 'Hallo! ' + rnd(['🐾', '💖', '✨']));
    }

    K.INIT.tama = init;
    K.SHOW.tama = () => { if (s) { decay(); renderAll(); } };
    K.tamaBoop = () => { boot(); if (wired) act('boop'); };
    K.addCoins = addCoins;
    K.petLook = () => { boot(); return lookOf(); };
    K.petGame = () => { boot(); s.stat.games++; touch(); };
    document.addEventListener('DOMContentLoaded', boot);
})();
