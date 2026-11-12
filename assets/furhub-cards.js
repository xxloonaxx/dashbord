/* Furry Hub – Karten-Editor: Sammelkarte, Con-Badge & Steckbrief als SVG, PNG-Export, Speichern & Teilen */
(function () {
    'use strict';
    const K = window.FHK; if (!K) return;
    const { FH, $, $$, esc, rnd, store, api, toast, confetti, unlock, sonaSVG, imgFor, safeImg, uploadImage, download, genName } = K;

    const THEMES = {
        fire: ['🔥 Feuer', '#ff7a3d', '#2a0f06', '#ffd2b8'], water: ['💧 Wasser', '#3da5ff', '#081b33', '#c4e4ff'], forest: ['🌿 Wald', '#56c271', '#0b2213', '#c9f0d3'],
        night: ['🌙 Nacht', '#9b7bff', '#120d2b', '#ddd2ff'], candy: ['🍬 Bonbon', '#ff7ac3', '#2e0b22', '#ffd0ea'], gold: ['👑 Gold', '#f3c262', '#271d08', '#fff0c4'],
        ice: ['❄️ Eis', '#8de8ff', '#0a2229', '#dcf8ff'], mono: ['🖤 Schwarzweiß', '#e0e0e0', '#141414', '#f2f2f2'], toxic: ['☢️ Neon', '#b6ff3d', '#111a05', '#e9ffc4'],
    };
    const TPLS = { trading: ['🃏', 'Sammelkarte'], badge: ['🪪', 'Con-Badge'], profile: ['📋', 'Steckbrief'] };
    const FONT = "font-family=\"'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif\"";
    const ABIL = [['Boop-Attacke', '👃', 'Stupst die Nase des Gegners. Er ist verwirrt und muss lächeln.', '20'], ['Flausch-Wall', '🛡️', 'Wird so flauschig, dass niemand angreifen möchte.', '–'],
                  ['Awoo-Chor', '🐺', 'Heult so laut, dass alle mitmachen.', '40'], ['Snack-Diebstahl', '🍪', 'Stiehlt einen Snack und erholt 20 Flausch-Punkte.', '10'],
                  ['Headpat-Heilung', '🤚', 'Ein Kopfstreicheln heilt alle Freund*innen.', '–'], ['Zoomies', '💨', 'Rennt dreimal im Kreis. Trifft vielleicht etwas.', '30'],
                  ['Con-Crud', '🤧', 'Der Gegner bekommt Schnupfen. Bleibt zwei Runden im Bett.', '50'], ['Glitzerbombe', '✨', 'Alles glitzert. Für immer.', '60']];
    const STATS = [['Flausch', 8], ['Mut', 5], ['Snack-Hunger', 9], ['Charme', 7], ['Chaos', 6], ['Kuschel', 10]];
    const FIELDS = [['Art', 'Fuchs'], ['Pronomen', 'sie/ihr'], ['Mag', 'Kaffee, Sticker'], ['Mag nicht', 'Montage'], ['Kontakt', '@deinname']];

    function blank() {
        const sona = FH.mySona;
        return { tpl: 'trading', theme: 'fire', name: sona ? sona.name : genName().split(' ')[0], subtitle: sona ? (sona.species || '') : 'Fluffiges Wesen', type: '🔥', hp: 120,
                 img: { src: 'sona', url: '', emo: 'happy', zoom: 100, x: 0, y: 0 }, abilities: [ABIL[0], ABIL[2]].map(([name, cost, text, dmg]) => ({ name, cost, text, dmg })),
                 stats: STATS.slice(0, 3).map(([label, value]) => ({ label, value })), fields: FIELDS.map(([k, v]) => ({ k, v })),
                 flavor: 'Man sagt, wer ihn boopt, hat den ganzen Tag Glück.', footer: 'Furry Hub · ' + new Date().getFullYear(), rarity: 3, holo: false };
    }

    // ---------- Zeichnen ----------
    function wrap(text, max, lines) {
        const words = String(text || '').split(/\s+/).filter(Boolean), out = [];
        let cur = '';
        for (const w of words) {
            if ((cur + ' ' + w).trim().length > max) { if (cur) out.push(cur); cur = w; if (out.length >= lines) break; } else cur = (cur + ' ' + w).trim();
        }
        if (cur && out.length < lines) out.push(cur);
        if (out.length === lines && words.join(' ').length > out.join(' ').length) out[lines - 1] = out[lines - 1].replace(/.{0,2}$/, '…');
        return out;
    }
    const T = (x, y, txt, size, fill, extra = '') => `<text x="${x}" y="${y}" font-size="${size}" fill="${fill}" ${FONT} ${extra}>${esc(txt)}</text>`;

    /** Bild in ein Rechteck setzen (Fursona, Upload oder nichts). look: Fursona des Besitzers. inline: Bilder schon als Daten-URL */
    function imageInto(d, x, y, w, h, clipId, look, inline) {
        const im = d.img || {};
        const z = (im.zoom || 100) / 100, dx = (im.x || 0) / 100 * w, dy = (im.y || 0) / 100 * h;
        if (im.src === 'none') return '';
        let url = '';
        if (im.src === 'upload') url = (inline && inline[im.url]) || safeImg(im.url);
        else if (look) { const c = imgFor(look, im.emo); if (c) url = (inline && inline[c]) || c; }
        const size = Math.min(w, h) * z, cx = x + w / 2 + dx, cy = y + h / 2 + dy;
        let inner;
        if (url) inner = `<image href="${esc(url)}" x="${cx - (w * z) / 2}" y="${cy - (h * z) / 2}" width="${w * z}" height="${h * z}" preserveAspectRatio="xMidYMid ${im.src === 'upload' ? 'slice' : 'meet'}"/>`;
        else if (look || im.src === 'sona') inner = sonaSVG(look || K.defaultLook('fox'), im.emo).replace('<svg ', `<svg x="${cx - size / 2}" y="${cy - size / 2}" width="${size}" height="${size}" `);
        else return '';
        return `<clipPath id="${clipId}"><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="6"/></clipPath><g clip-path="url(#${clipId})">${inner}</g>`;
    }

    let cid = 0;
    function cardSVG(d, look, inline) {
        d = Object.assign(blank(), d || {});
        const [, A, B, C] = THEMES[d.theme] || THEMES.fire, id = 'cd' + (++cid);
        const o = [`<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 300 420" width="300" height="420" class="fh-card-svg">`];
        o.push(`<defs><linearGradient id="${id}f" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${A}"/><stop offset=".5" stop-color="${K.shade(A, -18)}"/><stop offset="1" stop-color="${A}"/></linearGradient>
            <linearGradient id="${id}b" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${K.shade(B, 8)}"/><stop offset="1" stop-color="${B}"/></linearGradient>
            <linearGradient id="${id}h" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff7ac3" stop-opacity=".22"/><stop offset=".25" stop-color="#ffe66d" stop-opacity=".1"/><stop offset=".5" stop-color="#5df2ff" stop-opacity=".22"/><stop offset=".75" stop-color="#a77bff" stop-opacity=".1"/><stop offset="1" stop-color="#7bffb0" stop-opacity=".22"/></linearGradient></defs>`);
        o.push(`<rect width="300" height="420" rx="16" fill="url(#${id}f)"/><rect x="9" y="9" width="282" height="402" rx="11" fill="url(#${id}b)"/>`);
        const stars = '★'.repeat(d.rarity) + '☆'.repeat(5 - d.rarity);
        const statRows = (y0, x0 = 22, w = 256) => d.stats.map((st, i) => {
            const y = y0 + i * 15;
            return T(x0, y + 8, st.label, 9, C, 'font-weight="600"') + `<rect x="${x0 + 80}" y="${y}" width="${w - 80}" height="8" rx="4" fill="${K.shade(B, 14)}"/><rect x="${x0 + 80}" y="${y}" width="${(w - 80) * st.value / 10}" height="8" rx="4" fill="${A}"/>`;
        }).join('');

        if (d.tpl === 'trading') {
            o.push(T(22, 38, d.name, 20, '#fff', 'font-weight="800"'));
            o.push(T(250, 38, '♥ ' + d.hp, 14, A, 'font-weight="800" text-anchor="end"'));
            o.push(`<circle cx="268" cy="32" r="13" fill="${A}"/>` + T(268, 37, d.type, 13, '#fff', 'text-anchor="middle"'));
            o.push(`<rect x="18" y="50" width="264" height="172" rx="8" fill="${A}"/><rect x="21" y="53" width="258" height="166" rx="6" fill="${K.shade(B, 20)}"/>`);
            o.push(imageInto(d, 21, 53, 258, 166, id + 'c', look, inline));
            o.push(`<rect x="30" y="226" width="240" height="16" rx="8" fill="${K.shade(A, -25)}"/>` + T(150, 237.5, d.subtitle, 9, '#fff', 'text-anchor="middle" font-style="italic"'));
            d.abilities.forEach((a, i) => {
                const y = 258 + i * 46;
                o.push(T(22, y, a.cost, 14, '#fff') + T(44, y, a.name, 13, '#fff', 'font-weight="800"') + T(278, y, a.dmg, 15, A, 'font-weight="800" text-anchor="end"'));
                wrap(a.text, 52, 2).forEach((l, j) => o.push(T(22, y + 14 + j * 11, l, 9, C)));
                if (i === 0) o.push(`<line x1="22" y1="${y + 32}" x2="278" y2="${y + 32}" stroke="${K.shade(B, 22)}"/>`);
            });
            o.push(statRows(346));
            wrap(d.flavor, 58, 1).forEach(l => o.push(T(150, 396, l, 8.5, C, 'text-anchor="middle" font-style="italic" opacity=".85"')));
            o.push(T(22, 406, stars, 9, A) + T(278, 406, d.footer, 7.5, C, 'text-anchor="end" opacity=".75"'));
        } else if (d.tpl === 'badge') {
            o.push(`<rect x="118" y="20" width="64" height="12" rx="6" fill="${K.shade(B, -10)}" stroke="${A}"/>`);
            o.push(`<circle cx="150" cy="150" r="100" fill="${A}"/><circle cx="150" cy="150" r="94" fill="${K.shade(B, 22)}"/>`);
            o.push(`<clipPath id="${id}r"><circle cx="150" cy="150" r="94"/></clipPath><g clip-path="url(#${id}r)">${imageInto(d, 56, 56, 188, 188, id + 'c', look, inline)}</g>`);
            o.push(T(150, 292, d.name, d.name.length > 12 ? 26 : 34, '#fff', 'font-weight="900" text-anchor="middle"'));
            o.push(T(150, 316, d.subtitle, 13, C, 'text-anchor="middle"'));
            o.push(T(42, 60, d.type, 22, '#fff', 'text-anchor="middle"') + T(258, 60, d.type, 22, '#fff', 'text-anchor="middle"'));
            wrap(d.flavor, 44, 2).forEach((l, j) => o.push(T(150, 342 + j * 13, l, 10, C, 'text-anchor="middle" font-style="italic"')));
            o.push(`<rect x="9" y="370" width="282" height="41" fill="${A}"/>` + T(150, 396, d.footer, 13, B, 'font-weight="800" text-anchor="middle"'));
            o.push(`<rect x="9" y="370" width="282" height="41" rx="0" fill="none"/>`);
        } else {
            o.push(`<rect x="9" y="9" width="282" height="46" rx="11" fill="${A}"/><rect x="9" y="40" width="282" height="15" fill="${A}"/>`);
            o.push(T(22, 40, d.name, 20, B, 'font-weight="900"') + T(278, 40, d.type, 18, B, 'text-anchor="end"'));
            o.push(`<rect x="20" y="66" width="122" height="122" rx="8" fill="${A}"/><rect x="23" y="69" width="116" height="116" rx="6" fill="${K.shade(B, 20)}"/>`);
            o.push(imageInto(d, 23, 69, 116, 116, id + 'c', look, inline));
            o.push(T(152, 78, d.subtitle, 10, A, 'font-weight="700"'));
            d.fields.forEach((f, i) => { const y = 96 + i * 19; o.push(T(152, y, f.k, 8, C, 'opacity=".7"') + T(152, y + 10, f.v, 10, '#fff', 'font-weight="600"')); });
            o.push(T(22, 210, 'Über mich', 10, A, 'font-weight="800"'));
            wrap(d.flavor, 50, 5).forEach((l, j) => o.push(T(22, 226 + j * 13, l, 10, C)));
            o.push(T(22, 302, 'Werte', 10, A, 'font-weight="800"') + statRows(310));
            o.push(T(22, 400, stars, 10, A) + T(278, 400, d.footer, 8, C, 'text-anchor="end" opacity=".75"'));
        }
        if (d.holo) o.push(`<rect x="9" y="9" width="282" height="402" rx="11" fill="url(#${id}h)" style="mix-blend-mode:screen"/>`);
        o.push('</svg>');
        return o.join('');
    }

    /** Alle Bilder als Daten-URL einbetten (für den PNG-Export) */
    async function inlineImages(d, look) {
        const urls = [];
        if (d.img && d.img.src === 'upload' && safeImg(d.img.url)) urls.push(d.img.url);
        if (d.img && d.img.src === 'sona' && look) { const c = imgFor(look, d.img.emo); if (c) urls.push(c); }
        const map = {};
        for (const u of urls) {
            if (u.startsWith('data:')) { map[u] = u; continue; }
            try { const b = await (await fetch(u)).blob(); map[u] = await new Promise(r => { const f = new FileReader(); f.onload = () => r(f.result); f.readAsDataURL(b); }); } catch (e) { /* Bild fehlt */ }
        }
        return map;
    }
    async function cardPNG(d, look) {
        const svg = cardSVG(d, look, await inlineImages(d, look));
        const img = new Image();
        img.onload = () => {
            const c = document.createElement('canvas'); c.width = 900; c.height = 1260;
            c.getContext('2d').drawImage(img, 0, 0, 900, 1260);
            c.toBlob(b => { download(b, ((d.name || 'karte').replace(/[äöüÄÖÜß]/g, ch => ({ ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'Ae', Ö: 'Oe', Ü: 'Ue', ß: 'ss' }[ch])).replace(/[^\w-]+/g, '_')) + '_karte.png'); unlock('card'); });
        };
        img.onerror = () => toast('Export fehlgeschlagen.', true);
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    }
    K.cardSVG = cardSVG;
    K.cardPNG = cardPNG;

    // ---------- Editor ----------
    let card = null, editId = null, cards = [];
    const myLook = () => K.currentLook() || (FH.mySona && FH.mySona.look) || null;
    function draw() {
        $('#cardPrev').innerHTML = cardSVG(card, myLook());
        $('#cardPrev').classList.toggle('holo', !!card.holo);
        store.set('cardDraft', { card, editId });
    }
    function fill() {
        const f = $('#cardForm');
        ['name', 'subtitle', 'type', 'hp', 'flavor', 'footer', 'rarity'].forEach(k => { f.elements[k].value = card[k]; });
        f.elements.holo.checked = !!card.holo;
        f.elements.imgsrc.value = card.img.src; f.elements.imgemo.value = card.img.emo;
        f.elements.zoom.value = card.img.zoom; f.elements.ix.value = card.img.x; f.elements.iy.value = card.img.y;
        $('#cardUpload').hidden = card.img.src !== 'upload'; f.elements.imgemo.hidden = card.img.src !== 'sona';
        $('#cardTpl').innerHTML = Object.entries(TPLS).map(([k, [ic, l]]) => `<button type="button" data-tpl="${k}" class="${card.tpl === k ? 'on' : ''}">${ic} ${l}</button>`).join('');
        $('#cardTheme').innerHTML = Object.entries(THEMES).map(([k, [l, a, b]]) => `<button type="button" data-theme="${k}" class="${card.theme === k ? 'on' : ''}" style="--a:${a};--b:${b}">${l}</button>`).join('');
        $('#cardAbilities').hidden = card.tpl !== 'trading';
        $('#cardAbilities').innerHTML = '<div class="sub">Fähigkeiten</div>' + card.abilities.map((a, i) => `<div class="abil"><input data-ab="${i}:cost" value="${esc(a.cost)}" maxlength="8" class="ab-cost"><input data-ab="${i}:name" value="${esc(a.name)}" maxlength="30" placeholder="Name"><input data-ab="${i}:dmg" value="${esc(a.dmg)}" maxlength="6" class="ab-dmg" placeholder="Schaden"><textarea data-ab="${i}:text" rows="2" maxlength="120">${esc(a.text)}</textarea></div>`).join('')
            + '<button type="button" class="fh-btn sm" id="abRand">🎲 Fähigkeiten würfeln</button>';
        $('#cardStats').hidden = card.tpl === 'badge';
        $('#cardStats').innerHTML = '<div class="sub">Werte (0–10)</div>' + card.stats.map((st, i) => `<div class="stat-row"><input data-st="${i}:label" value="${esc(st.label)}" maxlength="20"><input type="range" min="0" max="10" data-st="${i}:value" value="${st.value}"><b>${st.value}</b></div>`).join('');
        $('#cardFields').hidden = card.tpl !== 'profile';
        $('#cardFields').innerHTML = '<div class="sub">Steckbrief-Felder</div>' + card.fields.map((fl, i) => `<div class="stat-row"><input data-fl="${i}:k" value="${esc(fl.k)}" maxlength="20"><input data-fl="${i}:v" value="${esc(fl.v)}" maxlength="50" class="wide"></div>`).join('');
        draw();
    }
    function renderList() {
        $('#cardCount').textContent = cards.length ? cards.length + (FH.me ? ' / 40' : '') : '';
        $('#cardList').innerHTML = cards.map(c => `<div class="card-item ${c.id === editId ? 'on' : ''}" data-cid="${c.id}"><div class="card-thumb">${cardSVG(c.data, myLook())}</div><small>${esc(c.data.name || 'Karte')}${c.shared ? ' · 🌍' : ''}</small><button type="button" data-cdel="${c.id}" title="Löschen">×</button></div>`).join('')
            || '<p class="muted small">Noch keine gespeicherten Karten.</p>';
    }
    async function loadCards() {
        if (FH.me) { try { cards = (await api('cards')).cards; } catch (e) { cards = []; } }
        else cards = store.get('cards', []);
        renderList();
    }
    async function save() {
        try {
            if (card.img.src === 'upload' && card.img.url.startsWith('data:') && FH.me) {
                const m = { u: card.img.url }; await K.ensureServerImages(m, 'card'); card.img.url = m.u;
            }
            if (FH.me) {
                const r = await api('card_save', { id: editId || '', data: JSON.stringify(card), shared: $('#cardShared').checked ? 1 : '' });
                editId = r.id; cards = r.cards;
            } else {
                if (!editId) editId = Date.now();
                const i = cards.findIndex(c => c.id === editId), entry = { id: editId, data: JSON.parse(JSON.stringify(card)), shared: false, updated: Date.now() };
                if (i >= 0) cards[i] = entry; else cards.unshift(entry);
                if (cards.length > 20) cards.length = 20;
                try { store.set('cards', cards); } catch (e) { toast('Browser-Speicher voll.', true); }
            }
            renderList(); draw(); unlock('card'); confetti(14, ['🃏', '✨']); toast('💾 Karte gespeichert!');
        } catch (err) { toast(err.message, true); }
    }

    function init() {
        const d = store.get('cardDraft', null);
        card = d && d.card ? Object.assign(blank(), d.card) : blank();
        editId = d ? d.editId : null;
        const f = $('#cardForm');
        f.addEventListener('input', e => {
            const t = e.target;
            if (t.dataset.ab) { const [i, k] = t.dataset.ab.split(':'); card.abilities[i][k] = t.value; }
            else if (t.dataset.st) { const [i, k] = t.dataset.st.split(':'); card.stats[i][k] = k === 'value' ? +t.value : t.value; if (k === 'value') t.nextElementSibling.textContent = t.value; }
            else if (t.dataset.fl) { const [i, k] = t.dataset.fl.split(':'); card.fields[i][k] = t.value; }
            else if (t.name === 'holo') card.holo = t.checked;
            else if (t.name === 'imgsrc') { card.img.src = t.value; fill(); return; }
            else if (t.name === 'imgemo') card.img.emo = t.value;
            else if (t.name === 'zoom') card.img.zoom = +t.value;
            else if (t.name === 'ix') card.img.x = +t.value;
            else if (t.name === 'iy') card.img.y = +t.value;
            else if (['hp', 'rarity'].includes(t.name)) card[t.name] = +t.value;
            else if (t.name) card[t.name] = t.value;
            draw();
        });
        f.addEventListener('click', e => {
            const tp = e.target.closest('[data-tpl]'); if (tp) { card.tpl = tp.dataset.tpl; fill(); return; }
            const th = e.target.closest('[data-theme]'); if (th) { card.theme = th.dataset.theme; fill(); return; }
            if (e.target.id === 'abRand') { const pick = ABIL.slice().sort(() => Math.random() - 0.5).slice(0, 2); card.abilities = pick.map(([name, cost, text, dmg]) => ({ name, cost, text, dmg })); fill(); }
        });
        $('#cardUpload').addEventListener('click', () => $('#cardFile').click());
        $('#cardFile').addEventListener('change', async e => {
            const file = e.target.files[0]; if (!file) return;
            try { card.img.url = await uploadImage(file, 'card'); card.img.src = 'upload'; card.img.zoom = 100; card.img.x = card.img.y = 0; fill(); unlock('custom'); } catch (err) { toast(err.message, true); }
            e.target.value = '';
        });
        // Bild mit der Maus verschieben
        let drag = null;
        $('#cardPrev').addEventListener('pointerdown', e => { if (card.img.src === 'none') return; drag = { x: e.clientX, y: e.clientY, ix: card.img.x, iy: card.img.y }; e.target.setPointerCapture && e.target.setPointerCapture(e.pointerId); });
        window.addEventListener('pointermove', e => { if (!drag) return; const r = $('#cardPrev').getBoundingClientRect(); card.img.x = Math.max(-100, Math.min(100, Math.round(drag.ix + (e.clientX - drag.x) / r.width * 200))); card.img.y = Math.max(-100, Math.min(100, Math.round(drag.iy + (e.clientY - drag.y) / r.height * 160))); f.elements.ix.value = card.img.x; f.elements.iy.value = card.img.y; draw(); });
        window.addEventListener('pointerup', () => { drag = null; });
        $('#cardPrev').addEventListener('wheel', e => { if (card.img.src === 'none') return; e.preventDefault(); card.img.zoom = Math.max(50, Math.min(250, card.img.zoom - Math.sign(e.deltaY) * 8)); f.elements.zoom.value = card.img.zoom; draw(); }, { passive: false });
        // Holo-Glanz folgt der Maus
        $('#cardPrev').addEventListener('pointermove', e => { const r = e.currentTarget.getBoundingClientRect(); e.currentTarget.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100) + '%'); e.currentTarget.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100) + '%'); e.currentTarget.style.setProperty('--rx', ((e.clientY - r.top) / r.height - 0.5) * -10 + 'deg'); e.currentTarget.style.setProperty('--ry', ((e.clientX - r.left) / r.width - 0.5) * 10 + 'deg'); });
        $('#cardPrev').addEventListener('pointerleave', e => { e.currentTarget.style.setProperty('--rx', '0deg'); e.currentTarget.style.setProperty('--ry', '0deg'); });
        $('#cardNew').addEventListener('click', () => { card = blank(); editId = null; if ($('#cardShared')) $('#cardShared').checked = false; fill(); renderList(); });
        $('#cardRandom').addEventListener('click', () => {
            card.theme = rnd(Object.keys(THEMES)); card.type = rnd(['🔥', '💧', '🌿', '⚡', '🌙', '✨', '🍬', '❄️', '💜']); card.hp = 10 * (5 + Math.floor(Math.random() * 20));
            card.rarity = 1 + Math.floor(Math.random() * 5); card.holo = card.rarity >= 4;
            card.abilities = ABIL.slice().sort(() => Math.random() - 0.5).slice(0, 2).map(([name, cost, text, dmg]) => ({ name, cost, text, dmg }));
            card.stats = STATS.slice().sort(() => Math.random() - 0.5).slice(0, 3).map(([label]) => ({ label, value: 1 + Math.floor(Math.random() * 10) }));
            fill();
        });
        $('#cardPng').addEventListener('click', () => cardPNG(card, myLook()));
        $('#cardSave').addEventListener('click', save);
        $('#cardList').addEventListener('click', async e => {
            const del = e.target.closest('[data-cdel]');
            if (del) {
                if (!confirm('Karte löschen?')) return;
                const idn = +del.dataset.cdel;
                if (FH.me) { try { cards = (await api('card_delete', { id: idn })).cards; } catch (err) { toast(err.message, true); } }
                else { cards = cards.filter(c => c.id !== idn); store.set('cards', cards); }
                if (editId === idn) editId = null;
                renderList(); return;
            }
            const it = e.target.closest('[data-cid]');
            if (it) { const c = cards.find(x => x.id === +it.dataset.cid); card = Object.assign(blank(), JSON.parse(JSON.stringify(c.data))); editId = c.id; if ($('#cardShared')) $('#cardShared').checked = !!c.shared; fill(); renderList(); window.scrollTo({ top: $('#cardForm').offsetTop - 20, behavior: 'smooth' }); }
        });
        fill(); loadCards();
    }
    K.INIT.cards = init;
    K.SHOW.cards = () => { if (card) draw(); };
})();
