/* Furry Hub – alle Spielereien im Browser */
(function () {
    'use strict';
    const FH = window.FH || {};
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const rnd = a => a[Math.floor(Math.random() * a.length)];
    const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
    const store = {
        get(k, d) { try { const v = localStorage.getItem('fh-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
        set(k, v) { try { localStorage.setItem('fh-' + k, JSON.stringify(v)); } catch (e) { /* privat */ } },
    };
    const hash = s => { let h = 2166136261; for (const c of String(s)) { h ^= c.codePointAt(0); h = Math.imul(h, 16777619); } return h >>> 0; };

    async function api(act, data) {
        const opt = data ? { method: 'POST', body: Object.entries(data).reduce((f, [k, v]) => (f.append(k, v), f), new FormData()) } : {};
        const r = await fetch('furhub.php?api=' + act, opt);
        const j = await r.json().catch(() => ({ error: 'Keine Antwort vom Server.' }));
        if (!r.ok || j.error) throw new Error(j.error || 'Fehler');
        return j;
    }
    let toastT;
    function toast(msg, bad) {
        const t = $('#fhToast');
        t.textContent = msg; t.hidden = false; t.className = 'fh-toast' + (bad ? ' bad' : '');
        clearTimeout(toastT); toastT = setTimeout(() => { t.hidden = true; }, 3200);
    }

    // =====================================================================
    // Farben
    // =====================================================================
    const hex2hsl = hex => {
        let r = parseInt(hex.slice(1, 3), 16) / 255, g = parseInt(hex.slice(3, 5), 16) / 255, b = parseInt(hex.slice(5, 7), 16) / 255;
        const mx = Math.max(r, g, b), mn = Math.min(r, g, b); let h = 0, s = 0; const l = (mx + mn) / 2;
        if (mx !== mn) { const d = mx - mn; s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn); h = mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; h *= 60; }
        return [h, s * 100, l * 100];
    };
    const hsl2hex = (h, s, l) => {
        s /= 100; l /= 100; const k = n => (n + h / 30) % 12, a = s * Math.min(l, 1 - l);
        const f = n => Math.round(255 * (l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1)))).toString(16).padStart(2, '0');
        return '#' + f(0) + f(8) + f(4);
    };
    const shade = (hex, dl) => { const [h, s, l] = hex2hsl(hex); return hsl2hex(h, s, clamp(l + dl, 0, 100)); };
    const jitter = (hex, dh = 14, ds = 10, dl = 8) => { const [h, s, l] = hex2hsl(hex); return hsl2hex((h + (Math.random() * 2 - 1) * dh + 360) % 360, clamp(s + (Math.random() * 2 - 1) * ds, 0, 100), clamp(l + (Math.random() * 2 - 1) * dl, 4, 96)); };

    // =====================================================================
    // Arten & Avatar-Zeichner (SVG)
    // =====================================================================
    const SPECIES = {
        fox:      ['🦊 Fuchs', { ears: 'pointy', muzzle: 'long', marking: 'none', extra: 'none', fluff: true, main: '#e8742a', second: '#3a2a22', muzzleC: '#fff4e6', inner: '#ffd2b0', eye: '#f5b014', nose: '#2a1a14', markC: '#ffffff' }],
        wolf:     ['🐺 Wolf', { ears: 'pointy', muzzle: 'long', marking: 'none', extra: 'none', fluff: true, main: '#8a8f99', second: '#5b606b', muzzleC: '#e6e6ea', inner: '#c9c3c8', eye: '#f2c14e', nose: '#1d1d22', markC: '#5b606b' }],
        husky:    ['🐕 Husky', { ears: 'pointy', muzzle: 'long', marking: 'blaze', extra: 'none', fluff: true, main: '#4b5563', second: '#2d3440', muzzleC: '#f3f4f6', inner: '#f3d2d8', eye: '#5ec8ff', nose: '#1d1d22', markC: '#f3f4f6' }],
        dog:      ['🐶 Hund', { ears: 'floppy', muzzle: 'medium', marking: 'none', extra: 'none', fluff: false, main: '#c58a4a', second: '#8a5a2b', muzzleC: '#f2dfc4', inner: '#e3a98a', eye: '#6b3f1d', nose: '#2a1a14', markC: '#8a5a2b' }],
        cat:      ['🐱 Katze', { ears: 'pointy', muzzle: 'short', marking: 'stripes', extra: 'none', fluff: false, main: '#f0a24f', second: '#c46d1e', muzzleC: '#fff1df', inner: '#ffc2c2', eye: '#7bd88f', nose: '#e8798f', markC: '#c46d1e' }],
        lion:     ['🦁 Löwe', { ears: 'round', muzzle: 'medium', marking: 'none', extra: 'mane', fluff: false, main: '#e0b265', second: '#9b5d1e', muzzleC: '#f7e3bd', inner: '#c98b4a', eye: '#d99a1e', nose: '#7a4a2a', markC: '#9b5d1e' }],
        tiger:    ['🐯 Tiger', { ears: 'round', muzzle: 'medium', marking: 'stripes', extra: 'none', fluff: true, main: '#f08a24', second: '#1f1f1f', muzzleC: '#ffffff', inner: '#ffffff', eye: '#e6c229', nose: '#e67a8a', markC: '#1f1f1f' }],
        rabbit:   ['🐰 Hase', { ears: 'long', muzzle: 'short', marking: 'none', extra: 'none', fluff: true, main: '#d9c3a5', second: '#b59b7b', muzzleC: '#fbf3e6', inner: '#ffb6c8', eye: '#6b4226', nose: '#ff8fa8', markC: '#b59b7b' }],
        redpanda: ['🐼 Roter Panda', { ears: 'round', muzzle: 'short', marking: 'cheeks', extra: 'none', fluff: true, main: '#c8502a', second: '#3a1d12', muzzleC: '#ffffff', inner: '#ffffff', eye: '#3b2314', nose: '#1d1414', markC: '#ffffff' }],
        raccoon:  ['🦝 Waschbär', { ears: 'round', muzzle: 'medium', marking: 'mask', extra: 'none', fluff: true, main: '#8d8d8d', second: '#4a4a4a', muzzleC: '#f0f0f0', inner: '#e8e8e8', eye: '#3b2a1a', nose: '#1d1d1d', markC: '#2b2b2b' }],
        otter:    ['🦦 Otter', { ears: 'small', muzzle: 'short', marking: 'none', extra: 'none', fluff: false, main: '#7a5034', second: '#5a3a24', muzzleC: '#d9b48f', inner: '#a77a58', eye: '#2b1b10', nose: '#2b1b10', markC: '#d9b48f' }],
        deer:     ['🦌 Hirsch', { ears: 'big', muzzle: 'medium', marking: 'spots', extra: 'antlers', fluff: false, main: '#a86b3c', second: '#6e4423', muzzleC: '#f3e1c9', inner: '#f3c9b0', eye: '#3b2314', nose: '#2b1b10', markC: '#f3e1c9' }],
        dragon:   ['🐉 Drache', { ears: 'none', muzzle: 'long', marking: 'freckles', extra: 'horns', fluff: false, main: '#4caf7a', second: '#2f6e4c', muzzleC: '#bfe8c8', inner: '#bfe8c8', eye: '#ffcf33', nose: '#2f6e4c', markC: '#2f6e4c', eyeShape: 'sharp' }],
        bat:      ['🦇 Fledermaus', { ears: 'big', muzzle: 'short', marking: 'none', extra: 'tuft', fluff: true, main: '#4a3b5c', second: '#2c2238', muzzleC: '#6d5a82', inner: '#c98bbd', eye: '#ff4d6d', nose: '#2c2238', markC: '#2c2238' }],
        hyena:    ['🐾 Hyäne', { ears: 'round', muzzle: 'long', marking: 'spots', extra: 'tuft', fluff: false, main: '#c9a46a', second: '#3b2f22', muzzleC: '#3b2f22', inner: '#8a6a44', eye: '#e0a32e', nose: '#1d1414', markC: '#5a4630' }],
        bird:     ['🐦 Vogel', { ears: 'none', muzzle: 'beak', marking: 'blaze', extra: 'tuft', fluff: true, main: '#4aa3e8', second: '#1f5f9a', muzzleC: '#ffb547', inner: '#ffffff', eye: '#1d1d22', nose: '#ffb547', markC: '#ffffff' }],
        protogen: ['🤖 Protogen', { ears: 'pointy', muzzle: 'long', marking: 'none', extra: 'visor', fluff: false, main: '#e9eef5', second: '#5aa9ff', muzzleC: '#d6dde8', inner: '#5aa9ff', eye: '#31e2ff', nose: '#22262e', markC: '#5aa9ff' }],
        mouse:    ['🐭 Maus', { ears: 'round', muzzle: 'short', marking: 'none', extra: 'none', fluff: false, main: '#b9b2ad', second: '#8e8681', muzzleC: '#eeeae7', inner: '#ffc2cf', eye: '#1d1d22', nose: '#ff8fa8', markC: '#8e8681' }],
        skunk:    ['🦨 Stinktier', { ears: 'small', muzzle: 'medium', marking: 'blaze', extra: 'tuft', fluff: true, main: '#26262b', second: '#ffffff', muzzleC: '#3a3a40', inner: '#5a5a62', eye: '#7c5cff', nose: '#111111', markC: '#ffffff' }],
    };
    const BGS = ['#1d3557', '#3a2e5c', '#264d3b', '#5c2e45', '#2e4a5c', '#4a3b1f', '#203040', '#40203a', '#1f3f3f', '#30254d'];
    const ACC_COLORS = ['#e63946', '#f3c262', '#4cc9f0', '#7bd88f', '#c77dff', '#ff8fab', '#ffffff', '#ff9f1c'];
    const LABELS = {
        ears: { pointy: 'Spitz', round: 'Rund', long: 'Lang (Hase)', floppy: 'Schlapp', big: 'Riesig', small: 'Klein', none: 'Keine' },
        muzzle: { long: 'Lange Schnauze', medium: 'Mittel', short: 'Kurz (Katze)', beak: 'Schnabel' },
        marking: { none: 'Keine', mask: 'Maske', stripes: 'Streifen', spots: 'Flecken', blaze: 'Blesse', cheeks: 'Wangenflecken', freckles: 'Sommersprossen' },
        extra: { none: 'Nichts', horns: 'Hörner', antlers: 'Geweih', mane: 'Mähne', fins: 'Flossen/Kämme', tuft: 'Haarbüschel', visor: 'Visier' },
        acc: { none: 'Nichts', glasses: 'Brille', bandana: 'Halstuch', bow: 'Schleife', collar: 'Halsband', headphones: 'Kopfhörer', flower: 'Blume', earring: 'Ohrring', scarf: 'Schal' },
        eyeShape: { round: 'Rund', happy: 'Fröhlich', sleepy: 'Verschlafen', sharp: 'Scharf' },
    };
    const COLOR_LABELS = { bg: 'Hintergrund', main: 'Fell', second: 'Zweitfarbe', muzzleC: 'Schnauze', inner: 'Ohr innen', eye: 'Augen', nose: 'Nase', markC: 'Zeichnung', accC: 'Accessoire' };

    function defaultLook(sp = 'fox') {
        const p = SPECIES[sp][1];
        return Object.assign({ species: sp, eyeShape: 'round', acc: 'none', accC: '#4cc9f0', bg: '#1d3557', blush: true }, JSON.parse(JSON.stringify(p)));
    }

    let svgId = 0;
    /** Kopf einer Figur als SVG. expr: normal | happy | boop | sleep | sad */
    const EXMAP = { neutral: 'normal', surprised: 'boop', sleepy: 'sleep' };
    function sonaSVG(L, expr = 'normal') {
        expr = EXMAP[expr] || expr;
        L = Object.assign(defaultLook(), L || {});
        const id = 'fh' + (++svgId);
        const M = (d, fill, extra = '') => `<path d="${d}" fill="${fill}" ${extra}/>`;
        const mir = d => d.replace(/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/g, (m, x, y) => (200 - parseFloat(x)) + ' ' + y);   // spiegeln an x = 100
        const both = (d, fill, extra = '') => M(d, fill, extra) + M(mir(d), fill, extra);
        const ink = '#1a1420';
        const o = [];
        o.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" class="fh-sona">`);
        o.push(`<defs><clipPath id="${id}h"><ellipse cx="100" cy="112" rx="64" ry="58"/></clipPath><radialGradient id="${id}g" cx="50%" cy="35%" r="70%"><stop offset="0" stop-color="${shade(L.bg, 12)}"/><stop offset="1" stop-color="${L.bg}"/></radialGradient></defs>`);
        o.push(`<rect width="200" height="200" rx="28" fill="url(#${id}g)"/>`);
        // Körper
        o.push(M('M 34 200 Q 44 158 100 156 Q 156 158 166 200 Z', L.main));
        o.push(`<ellipse cx="100" cy="190" rx="28" ry="22" fill="${L.muzzleC}"/>`);
        // Mähne / Flossen hinter dem Kopf
        if (L.extra === 'mane') {
            let pts = []; for (let i = 0; i < 32; i++) { const a = i / 32 * Math.PI * 2, r = i % 2 ? 76 : 90; pts.push((100 + Math.cos(a) * r).toFixed(1) + ',' + (110 + Math.sin(a) * r * 0.92).toFixed(1)); }
            o.push(`<polygon points="${pts.join(' ')}" fill="${L.second}"/>`);
        }
        if (L.extra === 'fins') o.push(both('M 42 96 L 8 70 L 22 96 L 4 104 L 26 116 L 10 130 L 44 128 Z', L.second));
        // Ohren
        const ears = {
            pointy: [['M 50 92 L 46 14 L 94 60 Z', L.main], ['M 58 80 L 55 32 L 84 62 Z', L.inner]],
            big:    [['M 56 94 L 16 4 L 98 56 Z', L.main], ['M 60 84 L 30 22 L 86 58 Z', L.inner]],
            long:   [['M 66 70 C 50 40 50 0 68 -2 C 86 0 88 40 84 68 Z', L.main], ['M 70 62 C 60 40 60 10 69 8 C 78 10 80 40 79 62 Z', L.inner]],
        }[L.ears];
        if (ears) ears.forEach(([d, c]) => o.push(both(d, c)));
        const circ = (x, y, r, c) => `<circle cx="${x}" cy="${y}" r="${r}" fill="${c}"/><circle cx="${200 - x}" cy="${y}" r="${r}" fill="${c}"/>`;
        if (L.ears === 'round') o.push(circ(52, 58, 24, L.main) + circ(52, 58, 13, L.inner));
        if (L.ears === 'small') o.push(circ(50, 76, 14, L.main) + circ(50, 76, 7, L.inner));
        // Wangen-Fluff
        if (L.fluff) o.push(both('M 44 118 L 20 128 L 40 134 L 22 148 L 46 146 L 34 160 L 62 150 Z', L.main));
        // Kopf
        o.push(`<ellipse cx="100" cy="112" rx="64" ry="58" fill="${L.main}"/>`);
        // Zeichnung (auf den Kopf beschnitten)
        const mk = [];
        const mc = L.markC;
        if (L.marking === 'mask') mk.push(M('M 30 104 Q 100 78 170 104 Q 156 128 122 118 Q 100 112 78 118 Q 44 128 30 104 Z', mc));
        if (L.marking === 'stripes') { mk.push(M('M 100 54 L 93 80 L 107 80 Z', mc)); mk.push(both('M 74 60 L 80 84 L 88 66 Z', mc)); mk.push(both('M 36 106 L 60 112 L 38 120 Z', mc)); mk.push(both('M 38 128 L 58 130 L 42 138 Z', mc)); }
        if (L.marking === 'spots') [[70, 70, 6], [130, 66, 5], [52, 96, 5], [150, 98, 6], [114, 58, 4], [86, 60, 3]].forEach(([x, y, r]) => mk.push(`<circle cx="${x}" cy="${y}" r="${r}" fill="${mc}"/>`));
        if (L.marking === 'blaze') mk.push(M('M 100 50 Q 86 92 100 132 Q 114 92 100 50 Z', mc));
        if (L.marking === 'cheeks') { mk.push(both('M 36 118 Q 58 96 82 118 Q 60 150 36 118 Z', mc)); mk.push(both('M 74 84 Q 82 76 90 84 Q 82 92 74 84 Z', mc)); }
        if (L.marking === 'freckles') [[64, 128], [70, 134], [58, 134], [136, 128], [130, 134], [142, 134]].forEach(([x, y]) => mk.push(`<circle cx="${x}" cy="${y}" r="2.2" fill="${mc}"/>`));
        if (mk.length) o.push(`<g clip-path="url(#${id}h)">${mk.join('')}</g>`);
        // Schnauze & Nase
        const eyeY = 106;
        if (L.muzzle === 'long') o.push(`<ellipse cx="100" cy="140" rx="34" ry="26" fill="${L.muzzleC}"/>`);
        if (L.muzzle === 'medium') o.push(`<ellipse cx="100" cy="142" rx="29" ry="21" fill="${L.muzzleC}"/>`);
        if (L.muzzle === 'short') o.push(`<ellipse cx="89" cy="143" rx="15" ry="12" fill="${L.muzzleC}"/><ellipse cx="111" cy="143" rx="15" ry="12" fill="${L.muzzleC}"/>`);
        if (L.muzzle === 'beak') o.push(`<g class="fh-nose">${M('M 80 126 Q 100 118 120 126 L 100 158 Z', L.muzzleC)}${M('M 86 140 L 114 140 L 100 158 Z', shade(L.muzzleC, -12))}</g>`);
        else {
            const ny = L.muzzle === 'short' ? 132 : 128, s = L.muzzle === 'short' ? 0.75 : 1;
            o.push(`<g class="fh-nose" style="transform-origin:100px ${ny + 5}px"><path d="M ${100 - 11 * s} ${ny} Q 100 ${ny - 6 * s} ${100 + 11 * s} ${ny} Q ${100 + 5 * s} ${ny + 11 * s} 100 ${ny + 12 * s} Q ${100 - 5 * s} ${ny + 11 * s} ${100 - 11 * s} ${ny} Z" fill="${L.nose}"/><ellipse cx="${100 - 3 * s}" cy="${ny + 1}" rx="${3.2 * s}" ry="${1.8 * s}" fill="#fff" opacity=".45"/></g>`);
        }
        // Mund
        if (L.muzzle !== 'beak') {
            const my = L.muzzle === 'short' ? 147 : 148;
            if (expr === 'boop') o.push(`<ellipse cx="100" cy="${my + 3}" rx="6" ry="7" fill="${ink}"/><ellipse cx="100" cy="${my + 6}" rx="4" ry="3" fill="#ff7a9c"/>`);
            else if (expr === 'happy') o.push(`<path d="M 86 ${my - 2} Q 100 ${my + 16} 114 ${my - 2} Z" fill="${ink}"/><path d="M 93 ${my + 6} Q 100 ${my + 12} 107 ${my + 6} Q 100 ${my + 2} 93 ${my + 6} Z" fill="#ff7a9c"/>`);
            else if (expr === 'sad') o.push(`<path d="M 90 ${my + 6} Q 100 ${my - 3} 110 ${my + 6}" stroke="${ink}" stroke-width="2.6" fill="none" stroke-linecap="round"/>`);
            else if (expr === 'angry') o.push(`<path d="M 86 ${my + 7} Q 100 ${my - 4} 114 ${my + 7}" stroke="${ink}" stroke-width="3.4" fill="none" stroke-linecap="round"/><path d="M 93 ${my + 3} L 95 ${my + 7} L 97 ${my + 3}" fill="#fff"/>`);
            else o.push(`<path d="M 88 ${my} Q 94 ${my + 7} 100 ${my} Q 106 ${my + 7} 112 ${my}" stroke="${ink}" stroke-width="2.6" fill="none" stroke-linecap="round"/>`);
        }
        // Augen
        const eye = x => {
            const shape = expr === 'normal' || expr === 'sad' || expr === 'angry' ? L.eyeShape : expr;
            if (L.extra === 'visor') return '';
            if (shape === 'happy') return `<path d="M ${x - 12} ${eyeY + 4} Q ${x} ${eyeY - 10} ${x + 12} ${eyeY + 4}" stroke="${ink}" stroke-width="4" fill="none" stroke-linecap="round"/>`;
            if (shape === 'sleep') return `<path d="M ${x - 12} ${eyeY} Q ${x} ${eyeY + 8} ${x + 12} ${eyeY}" stroke="${ink}" stroke-width="4" fill="none" stroke-linecap="round"/>`;
            if (shape === 'boop') { const d = x < 100 ? 1 : -1; return `<path d="M ${x - 10 * d} ${eyeY - 8} L ${x + 8 * d} ${eyeY} L ${x - 10 * d} ${eyeY + 8}" stroke="${ink}" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`; }
            let s = '';
            if (shape === 'sharp') {
                s += `<path d="M ${x - 15} ${eyeY + 2} Q ${x} ${eyeY - 14} ${x + 15} ${eyeY - 2} Q ${x} ${eyeY + 12} ${x - 15} ${eyeY + 2} Z" fill="#fff"/>`;
                s += `<ellipse cx="${x}" cy="${eyeY}" rx="8" ry="9" fill="${L.eye}"/><ellipse cx="${x}" cy="${eyeY}" rx="2.4" ry="8" fill="${ink}"/>`;
            } else {
                s += `<ellipse cx="${x}" cy="${eyeY}" rx="13" ry="15" fill="#fff"/><circle cx="${x}" cy="${eyeY + 1}" r="10" fill="${L.eye}"/><circle cx="${x}" cy="${eyeY + 1}" r="5.2" fill="${ink}"/>`;
            }
            s += `<circle cx="${x + 3.5}" cy="${eyeY - 4}" r="3.2" fill="#fff"/><circle cx="${x - 3}" cy="${eyeY + 5}" r="1.4" fill="#fff" opacity=".8"/>`;
            if (shape === 'sleepy') s += `<path d="M ${x - 15} ${eyeY - 16} L ${x + 15} ${eyeY - 16} L ${x + 15} ${eyeY + 1} Q ${x} ${eyeY - 4} ${x - 15} ${eyeY + 1} Z" fill="${L.main}"/>`;
            if (expr === 'sad') s += `<path d="M ${x - 12} ${eyeY - 18 + (x < 100 ? 4 : -2)} L ${x + 10} ${eyeY - 18 + (x < 100 ? -2 : 4)}" stroke="${ink}" stroke-width="3" stroke-linecap="round"/>`;
            if (expr === 'angry') s += x < 100 ? `<path d="M ${x - 14} ${eyeY - 22} L ${x + 11} ${eyeY - 12}" stroke="${ink}" stroke-width="4.5" stroke-linecap="round"/>` : `<path d="M ${x - 11} ${eyeY - 12} L ${x + 14} ${eyeY - 22}" stroke="${ink}" stroke-width="4.5" stroke-linecap="round"/>`;
            return s;
        };
        o.push(eye(74) + eye(126));
        // Visier (Protogen)
        if (L.extra === 'visor') {
            o.push(`<rect x="34" y="84" width="132" height="46" rx="23" fill="#0d1117"/>`);
            const g = L.eye, sw = 'stroke-width="5" stroke-linecap="round" fill="none"';
            const glyph = expr === 'happy' || expr === 'boop' ? (x => `<path d="M ${x - 12} 112 L ${x} 100 L ${x + 12} 112" stroke="${g}" ${sw}/>`)
                : expr === 'sleep' ? (x => `<path d="M ${x - 12} 106 L ${x + 12} 106" stroke="${g}" ${sw}/>`)
                : expr === 'sad' ? (x => `<path d="M ${x - 12} 100 L ${x} 112 L ${x + 12} 100" stroke="${g}" ${sw}/>`)
                : expr === 'angry' ? (x => `<path d="M ${x - 12} ${x < 100 ? 98 : 112} L ${x + 12} ${x < 100 ? 112 : 98}" stroke="${g}" ${sw}/>`)
                : (x => `<rect x="${x - 9}" y="96" width="18" height="18" rx="4" fill="${g}"/>`);
            o.push(`<g filter="drop-shadow(0 0 4px ${g})">${glyph(74)}${glyph(126)}${expr === 'boop' ? `<path d="M 92 122 L 100 117 L 108 122" stroke="${g}" stroke-width="3" fill="none"/>` : ''}</g>`);
        }
        // Wangen
        if (L.blush || expr === 'boop' || expr === 'happy') o.push(`<ellipse cx="58" cy="128" rx="11" ry="6" fill="#ff7aa2" opacity="${expr === 'boop' ? 0.7 : 0.4}"/><ellipse cx="142" cy="128" rx="11" ry="6" fill="#ff7aa2" opacity="${expr === 'boop' ? 0.7 : 0.4}"/>`);
        // Schlappohren vor dem Kopf
        if (L.ears === 'floppy') o.push(both('M 46 66 C 20 70 14 120 30 140 C 40 150 52 132 56 112 C 60 92 60 72 46 66 Z', L.second));
        // Hörner, Geweih, Büschel
        if (L.extra === 'horns') o.push(both('M 70 66 Q 56 34 38 20 Q 52 46 60 74 Z', shade(L.second, 18)));
        if (L.extra === 'antlers') o.push(both('M 74 62 L 62 30 L 48 14 M 62 30 L 74 14 M 66 42 L 44 38', 'none', `stroke="${shade(L.second, 25)}" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"`));
        if (L.extra === 'tuft') o.push(M('M 84 58 Q 90 36 100 30 Q 98 44 104 50 Q 112 34 122 32 Q 116 46 116 58 Z', L.second));
        // Accessoires
        const a = L.accC;
        if (L.acc === 'glasses') o.push(`<g fill="rgba(255,255,255,.12)" stroke="${a}" stroke-width="4"><circle cx="74" cy="${eyeY}" r="17"/><circle cx="126" cy="${eyeY}" r="17"/></g><path d="M 91 ${eyeY - 2} Q 100 ${eyeY - 8} 109 ${eyeY - 2}" stroke="${a}" stroke-width="4" fill="none"/>`);
        if (L.acc === 'bandana') o.push(M('M 46 162 Q 100 176 154 162 L 100 200 Z', a) + `<path d="M 60 170 L 140 170" stroke="${shade(a, -15)}" stroke-width="2" stroke-dasharray="4 5"/>`);
        if (L.acc === 'collar') o.push(`<rect x="54" y="160" width="92" height="12" rx="6" fill="${a}"/><circle cx="100" cy="180" r="8" fill="#f3c262" stroke="#b8862a" stroke-width="2"/>`);
        if (L.acc === 'scarf') o.push(M('M 44 160 Q 100 182 156 160 L 158 176 Q 100 196 42 176 Z', a) + M('M 120 176 L 136 200 L 150 200 L 136 172 Z', shade(a, -12)));
        if (L.acc === 'bow') o.push(`<g transform="translate(140 58) rotate(15)">${M('M 0 0 L -18 -12 L -18 12 Z', a)}${M('M 0 0 L 18 -12 L 18 12 Z', a)}<circle r="5" fill="${shade(a, -15)}"/></g>`);
        if (L.acc === 'headphones') o.push(`<path d="M 38 108 Q 40 34 100 32 Q 160 34 162 108" stroke="${a}" stroke-width="9" fill="none"/><rect x="26" y="96" width="20" height="34" rx="9" fill="${shade(a, -10)}"/><rect x="154" y="96" width="20" height="34" rx="9" fill="${shade(a, -10)}"/>`);
        if (L.acc === 'flower') o.push(`<g transform="translate(62 64)">${[0, 72, 144, 216, 288].map(d => `<ellipse rx="6" ry="10" cy="-9" fill="${a}" transform="rotate(${d})"/>`).join('')}<circle r="5" fill="#ffd166"/></g>`);
        if (L.acc === 'earring') o.push(`<circle cx="150" cy="78" r="6" fill="none" stroke="#f3c262" stroke-width="3"/><circle cx="150" cy="86" r="3" fill="${a}"/>`);
        o.push('</svg>');
        return o.join('');
    }

    // Eigene Bilder je Emotion
    const EMO = { neutral: ['😐', 'Normal'], happy: ['😊', 'Fröhlich'], sad: ['🥺', 'Traurig'], angry: ['😠', 'Wütend'], surprised: ['😳', 'Überrascht'], sleepy: ['😴', 'Müde'] };
    const safeImg = (u, allowData = true) => (typeof u === 'string' && (/^furhub\.php\?img=[0-9a-f]{24}$/.test(u) || (allowData && /^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$/.test(u)))) ? u : '';
    function imgFor(look, emo) {
        if (!look || look.mode !== 'custom' || !look.images) return '';
        const im = look.images;
        return safeImg(im[emo]) || safeImg(im.neutral) || Object.values(im).map(u => safeImg(u)).find(Boolean) || '';
    }
    /** Gesicht einer Figur: eigenes Bild der Emotion oder gezeichneter Avatar */
    function face(look, emo = 'neutral') {
        const u = imgFor(look, emo);
        return u ? `<img class="fh-face-img" src="${esc(u)}" alt="" draggable="false">` : sonaSVG(look, emo);
    }
    const blobToData = b => new Promise(res => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(b); });
    async function prepImage(file, max) {
        if (!/^image\/(png|jpeg|webp|gif)$/.test(file.type)) throw new Error('Nur PNG, JPG, WebP oder GIF.');
        if (file.size > 2.5e6) throw new Error('Das Bild ist zu groß (max. 2,5 MB).');
        if (file.type === 'image/gif') { if (!FH.me && file.size > 1.2e6) throw new Error('GIFs ohne Anmeldung bitte unter 1,2 MB.'); return file; }   // Animation behalten
        const url = URL.createObjectURL(file);
        try {
            const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('Bild nicht lesbar.')); i.src = url; });
            const f = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
            const c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * f); c.height = Math.round(img.naturalHeight * f);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            return await new Promise(res => c.toBlob(b => res(b), 'image/webp', 0.88));
        } finally { URL.revokeObjectURL(url); }
    }
    async function uploadBlob(blob, kind) {
        const fd = new FormData(); fd.append('file', blob, 'bild'); fd.append('kind', kind);
        const r = await fetch('furhub.php?api=img_upload', { method: 'POST', body: fd });
        const j = await r.json().catch(() => ({ error: 'Upload fehlgeschlagen.' }));
        if (!r.ok || j.error) throw new Error(j.error || 'Upload fehlgeschlagen.');
        return j.url;
    }
    /** Bild hochladen (angemeldet → Server, sonst als Daten-URL im Browser) */
    async function uploadImage(file, kind) {
        const blob = await prepImage(file, FH.me ? 768 : 320);
        return FH.me ? uploadBlob(blob, kind) : blobToData(blob);
    }
    /** Daten-URLs (aus der Zeit vor dem Anmelden) auf den Server bringen */
    async function ensureServerImages(images, kind) {
        if (!FH.me || !images) return images;
        for (const k of Object.keys(images)) if (String(images[k]).startsWith('data:')) images[k] = await uploadBlob(await (await fetch(images[k])).blob(), kind);
        return images;
    }
    /** Sechs Felder (je Emotion) zum Hochladen – für Fursona und Tamagotchi */
    function emoSlots(box, getImages, kind, onChange) {
        const input = document.createElement('input');
        input.type = 'file'; input.accept = 'image/png,image/jpeg,image/webp,image/gif'; input.hidden = true;
        box.after(input);
        const render = () => {
            const im = getImages();
            box.innerHTML = Object.entries(EMO).map(([e, [ic, l]]) => `<div class="emo-slot ${safeImg(im[e]) ? 'has' : ''}" data-e="${e}"><button type="button" class="emo-pic" title="${l}: Bild wählen">${safeImg(im[e]) ? `<img src="${esc(im[e])}" alt="">` : `<span>${ic}</span>`}</button><small>${ic} ${l}</small>${safeImg(im[e]) ? `<button type="button" class="emo-x" data-x="${e}" title="Entfernen">×</button>` : ''}</div>`).join('')
                + `<div class="emo-slot all"><button type="button" class="emo-pic" data-all title="Ein Bild für alle Emotionen"><span>⬆</span></button><small>Eins für alle</small></div>`;
        };
        const take = async (file, targets) => {
            box.classList.add('busy');
            try {
                const url = await uploadImage(file, kind);
                const im = getImages(); targets.forEach(e => { im[e] = url; });
                onChange(); render(); unlock('custom');
            } catch (err) { toast(err.message, true); } finally { box.classList.remove('busy'); }
        };
        box.addEventListener('click', e => {
            const x = e.target.closest('[data-x]');
            if (x) { delete getImages()[x.dataset.x]; onChange(); render(); return; }
            const all = e.target.closest('[data-all]'), slot = e.target.closest('.emo-slot:not(.all)');
            if (all || slot) { input.dataset.slotTarget = all ? 'all' : slot.dataset.e; input.value = ''; input.click(); }
        });
        input.addEventListener('change', () => { if (input.files[0]) take(input.files[0], input.dataset.slotTarget === 'all' ? Object.keys(EMO) : [input.dataset.slotTarget]); });
        box.addEventListener('dragover', e => { e.preventDefault(); });
        box.addEventListener('drop', e => {
            e.preventDefault();
            const slot = e.target.closest('.emo-slot'), f = e.dataTransfer.files[0];
            if (slot && f) take(f, slot.classList.contains('all') ? Object.keys(EMO) : [slot.dataset.e]);
        });
        render();
        return { render };
    }

    // =====================================================================
    // Erfolge
    // =====================================================================
    const ACH = {
        boop1: ['👃', 'Erster Boop', 'Eine Nase geboopt'], boop100: ['💯', 'Boop-Profi', '100 Boops'], boop1000: ['🏅', 'Boop-Legende', '1.000 Boops'],
        combo: ['⚡', 'Combo!', '25 Boops ohne Pause'], awoo: ['🐺', 'Awoo!', 'Einmal kräftig geheult'], sounds: ['🎼', 'Orchester', 'Alle Tierlaute gespielt'],
        sona: ['🎨', 'Schöpfer*in', 'Eine Fursona gespeichert oder heruntergeladen'], like: ['💖', 'Fan', 'Eine Community-Sona geliked'],
        bingo: ['🎪', 'BINGO!', 'Eine Bingo-Reihe vollständig'], quiz: ['❓', 'Selbsterkenntnis', 'Das Tier-Quiz gemacht'],
        pet: ['🍖', 'Fürsorglich', 'Das Tamagotchi gefüttert'], pet5: ['⭐', 'Großgezogen', 'Tamagotchi auf Stufe 5'],
        hatch: ['🐣', 'Geschlüpft', 'Ein Ei ausgebrütet'], adult: ['🦊', 'Erwachsen', 'Tamagotchi ist ausgewachsen'], tidy: ['🧹', 'Putzteufel', '10 Häufchen weggeräumt'],
        shop: ['🛒', 'Shopping-Tour', 'Etwas im Shop gekauft'], hat: ['🎩', 'Modisch', 'Ein Accessoire aufgesetzt'], custom: ['🖼️', 'Eigene Bilder', 'Ein eigenes Bild hochgeladen'],
        card: ['🃏', 'Kartenkünstler*in', 'Eine Karte gespeichert oder exportiert'], catch: ['🍖', 'Fangkünstler*in', '50 Punkte beim Leckerli fangen'],
        memory: ['🧠', 'Gutes Gedächtnis', 'Memory gelöst'], whack: ['👃', 'Nasen-Ninja', '30 Nasen gebooped'], ear: ['👂', 'Gutes Gehör', '8 von 10 Tierlauten erkannt'], wheel: ['🎡', 'Dreh am Rad', 'Das Glücksrad gedreht'],
        owo: ['💬', 'OwO, was ist das?', 'Den Übersetzer benutzt'], paw: ['✍️', 'Pfotenabdruck', 'Im Gästebuch unterschrieben'],
        konami: ['🎮', 'Retro-Fluff', 'Den Konami-Code eingegeben'], secret: ['🦊', 'Neugierfuchs', 'Den Fuchs 10× angeklickt'], night: ['🌙', 'Nachteule', 'Zwischen 0 und 4 Uhr vorbeigeschaut'],
    };
    const achs = store.get('ach', {});
    function unlock(k) {
        if (achs[k] || !ACH[k]) return;
        achs[k] = Date.now(); store.set('ach', achs);
        toast('🏆 Erfolg: ' + ACH[k][1] + ' – ' + ACH[k][2]);
        confetti(18);
        renderAch();
    }
    function renderAch() {
        const g = $('#achGrid'); if (!g) return;
        g.innerHTML = Object.entries(ACH).map(([k, [ic, t, d]]) => `<div class="ach ${achs[k] ? 'on' : ''}" title="${esc(t + ': ' + d)}"><span>${achs[k] ? ic : '🔒'}</span><b>${esc(t)}</b><small>${esc(d)}</small></div>`).join('');
        $('#achCount').textContent = Object.keys(achs).filter(k => ACH[k]).length + ' / ' + Object.keys(ACH).length;
    }

    // =====================================================================
    // Effekte
    // =====================================================================
    function confetti(n = 40, chars = ['🐾', '✨', '💖', '⭐', '🦊', '🐺']) {
        for (let i = 0; i < n; i++) {
            const s = document.createElement('span');
            s.className = 'fh-confetti'; s.textContent = rnd(chars);
            s.style.left = Math.random() * 100 + 'vw';
            s.style.animationDuration = 2 + Math.random() * 2.5 + 's';
            s.style.animationDelay = Math.random() * 0.8 + 's';
            s.style.fontSize = 14 + Math.random() * 18 + 'px';
            document.body.append(s);
            setTimeout(() => s.remove(), 5500);
        }
    }
    function floatText(x, y, txt, cls = '') {
        const s = document.createElement('span');
        s.className = 'fh-float ' + cls; s.textContent = txt;
        s.style.left = x + 'px'; s.style.top = y + 'px';
        document.body.append(s);
        setTimeout(() => s.remove(), 1100);
    }

    // =====================================================================
    // Tabs
    // =====================================================================
    const loaded = {};
    function showTab(t) {
        if (!$(`[data-panel="${t}"]`)) t = 'start';
        $$('.fh-panel').forEach(p => { p.hidden = p.dataset.panel !== t; });
        $$('#fhTabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
        if (location.hash.slice(1) !== t) history.replaceState(null, '', '#' + t);
        if (!loaded[t]) { loaded[t] = true; (INIT[t] || (() => {}))(); }
        if (SHOW[t]) SHOW[t]();
        if (t === 'start' && loaded.start && sonas === null) renderSotd();
    }
    $$('#fhTabs button').forEach(b => b.addEventListener('click', () => showTab(b.dataset.tab)));
    document.addEventListener('click', e => { const g = e.target.closest('[data-goto]'); if (g) { showTab(g.dataset.goto); window.scrollTo({ top: $('#fhTabs').offsetTop - 10, behavior: 'smooth' }); } });

    // =====================================================================
    // Zähler oben
    // =====================================================================
    let stats = FH.stats || { total: {} };
    function renderCounters() {
        $('#fhCounters').innerHTML = Object.entries(FH.reactions || {}).map(([k, [ic, l]]) => `<div class="fh-counter" data-k="${k}"><span>${ic}</span><b>${(stats.total[k] || 0).toLocaleString('de-DE')}</b><small>${esc(l)}</small></div>`).join('');
    }

    // =====================================================================
    // Community-Daten (für Sona des Tages, Boop-Auswahl, Galerie)
    // =====================================================================
    let sonas = null;
    async function loadSonas(force) {
        if (sonas && !force) return sonas;
        try { sonas = (await api('sonas')).sonas; } catch (e) { sonas = []; }
        return sonas;
    }
    async function renderSotd() {
        const list = await loadSonas();
        const box = $('#sotd');
        if (!list.length) { box.innerHTML = '<span class="muted">Noch keine Community-Sonas. <a href="#sona" data-goto="sona">Sei die erste!</a></span>'; return; }
        const s = list[hash(new Date().toDateString()) % list.length];
        box.innerHTML = `<div class="sotd-face" data-face="${s.user_id}">${face(s.look, 'happy')}</div><div><b>${esc(s.name)}</b><small>${esc(s.species)}${s.pronouns ? ' · ' + esc(s.pronouns) : ''}</small><small>von ${esc(s.owner)}</small><button type="button" class="fh-btn sm" data-boop-sona="${s.user_id}">👃 Boopen</button></div>`;
    }
    // Fremde Sonas boopen (Community, Sona des Tages)
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-boop-sona]');
        if (!b || !sonas) return;
        const s = sonas.find(x => x.user_id === +b.dataset.boopSona); if (!s) return;
        const holder = (b.closest('.com-card, .sotd-body') || document).querySelector('.com-face, .sotd-face');
        if (holder) { holder.innerHTML = face(s.look, 'surprised'); holder.classList.remove('booped'); void holder.offsetWidth; holder.classList.add('booped'); clearTimeout(holder._t); holder._t = setTimeout(() => { holder.innerHTML = face(s.look, holder.classList.contains('sotd-face') ? 'happy' : 'neutral'); }, 600); }
        react('boop'); boopCount(); sfx.boop();
        floatText(e.pageX - 10, e.pageY - 30, rnd(['boop!', '*boop*', 'eep!', '>w<']));
    });

    // Zufallslink aus dem Furry-Link-Hub
    function randLink() {
        const L = FH.links || [], box = $('#randLink');
        if (!L.length) { box.innerHTML = '<span class="muted">Keine Links gefunden.</span>'; $('#randLinkBtn').hidden = true; return; }
        const l = rnd(L);
        box.innerHTML = `<a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">${esc(l.title)}</a><small>${esc(l.category)}</small>`;
    }

    // =====================================================================
    // Gemeinsame Reaktionen (Boop, Awoo, Headpat, Umarmung)
    // =====================================================================
    let pending = {}, flushT = null;
    let myLocal = store.get('boops', 0);
    function react(key, n = 1) {
        pending[key] = (pending[key] || 0) + n;
        stats.total[key] = (stats.total[key] || 0) + n;
        if (stats.mine) { stats.mine[key] = (stats.mine[key] || 0) + n; stats.today[key] = (stats.today[key] || 0) + n; }
        renderCounters(); renderMine();
        clearTimeout(flushT); flushT = setTimeout(flush, 1200);
    }
    async function flush() {
        const p = pending; pending = {};
        for (const [k, n] of Object.entries(p)) {
            try { const r = await api('react', { key: k, n }); stats = r.stats; } catch (e) { /* offline – egal */ }
        }
        renderCounters(); renderMine(); renderTop();
    }
    window.addEventListener('beforeunload', () => {
        for (const [k, n] of Object.entries(pending)) if (n && navigator.sendBeacon) { const f = new FormData(); f.append('key', k); f.append('n', n); navigator.sendBeacon('furhub.php?api=react', f); }
    });
    /** Eigene Boops zählen (für Erfolge) */
    function boopCount() {
        myLocal++; store.set('boops', myLocal);
        if (myLocal >= 1) unlock('boop1');
        if (myLocal >= 100) unlock('boop100');
        if (myLocal >= 1000) unlock('boop1000');
        return myLocal;
    }
    function renderMine() {
        const box = $('#myBoops'); if (!box) return;
        const m = stats.mine;
        box.innerHTML = m ? Object.entries(FH.reactions).map(([k, [ic, l]]) => `<div><span>${ic}</span><b>${(m[k] || 0).toLocaleString('de-DE')}</b><small>${esc(l)} · heute ${(stats.today[k] || 0).toLocaleString('de-DE')}</small></div>`).join('')
            : `<div><span>👃</span><b>${myLocal.toLocaleString('de-DE')}</b><small>Boops in diesem Browser</small></div><p class="muted small"><a href="admin.php?next=furhub.php">Anmelden</a>, um in die Bestenliste zu kommen.</p>`;
    }
    function renderTop() {
        const box = $('#boopTop'); if (!box) return;
        box.innerHTML = (stats.top || []).map((t, i) => `<li><span class="medal">${['🥇', '🥈', '🥉'][i] || (i + 1) + '.'}</span><b style="color:${esc(t.color)}">${esc(t.name)}</b><span>${t.n.toLocaleString('de-DE')}</span></li>`).join('') || '<li class="muted">Noch niemand – sei die erste Nase!</li>';
    }

    // =====================================================================
    // Fursona-Baukasten
    // =====================================================================
    const NAME_A = ['Ka', 'Ri', 'Zu', 'Fen', 'Mi', 'Lo', 'Sha', 'Ty', 'Ko', 'Ra', 'Ve', 'Nyx', 'Ash', 'Em', 'Ju', 'Ki', 'Lu', 'Mo', 'No', 'Pi', 'Sa', 'Ta', 'Wi', 'Yo', 'Ze', 'Ari', 'Bri', 'Cy', 'Dra', 'Eli'];
    const NAME_B = ['ro', 'ka', 'rin', 'li', 'ne', 'xa', 'zu', 'mi', 'ra', 'ko', 'vy', 'shi', 'ndo', 'lyn', 'ber', 'tas', 'nix', 'mo', 'ri', 'sk', 'ven', 'dra', 'lo', 'ki'];
    const NAME_WORDS = ['Ember', 'Frost', 'Luna', 'Nova', 'Shadow', 'Sky', 'Storm', 'Mocha', 'Pixel', 'Rune', 'Vex', 'Zephyr', 'Juniper', 'Toffee', 'Cinder', 'Maple', 'Orbit', 'Nebula', 'Kiwi', 'Biscuit', 'Echo', 'Sable', 'Clover', 'Glitch', 'Saffron'];
    const TITLES = ['aus dem Nebelwald', 'vom Mondsee', 'der Sternwarte', 'aus dem Kaffeeduft', 'vom Nordlicht', 'der Pixelwälder', 'aus dem Glitzerbau', 'vom Funkturm', 'aus dem Mitternachts-Café', 'der Wolkenberge'];
    const T_PERS = ['verspielt', 'schüchtern', 'frech', 'verträumt', 'neugierig', 'loyal', 'chaotisch', 'gemütlich', 'abenteuerlustig', 'sarkastisch', 'warmherzig', 'nerdig', 'ruhig', 'energiegeladen', 'kreativ'];
    const T_LIKES = ['liebt Kaffee', 'hört Synthwave', 'beobachtet Sterne', 'sammelt Sticker', 'backt Zimtschnecken', 'spielt Rhythmusspiele', 'zeichnet nachts', 'liebt Regentage', 'baut Lego', 'tanzt auf Cons', 'liest Fantasy', 'kocht scharf', 'mag Plüschtiere', 'fotografiert Wolken'];
    const T_QUIRKS = ['summt beim Zeichnen', 'verliert ständig Stifte', 'wedelt mit dem Schwanz, wenn nervös', 'hortet Snacks', 'redet mit Pflanzen', 'macht Wortwitze', 'schläft überall ein', 'kann nicht nein zu Headpats sagen', 'kennt jedes Meme', 'trägt immer eine Decke'];
    const genName = () => Math.random() < 0.45 ? rnd(NAME_WORDS) + (Math.random() < 0.3 ? ' ' + rnd(TITLES) : '') : rnd(NAME_A) + rnd(NAME_B) + (Math.random() < 0.25 ? ' ' + rnd(TITLES) : '');
    const genTraits = () => [rnd(T_PERS), rnd(T_PERS.filter(x => x)), rnd(T_LIKES), rnd(T_QUIRKS)].filter((v, i, a) => a.indexOf(v) === i);

    function randomLook(sp) {
        sp = sp || rnd(Object.keys(SPECIES));
        const L = defaultLook(sp);
        ['main', 'second', 'muzzleC', 'eye', 'markC'].forEach(k => { L[k] = jitter(L[k], k === 'eye' ? 40 : 14); });
        if (Math.random() < 0.2) L.main = hsl2hex(Math.random() * 360, 40 + Math.random() * 45, 40 + Math.random() * 25);   // Fantasiefarbe
        L.bg = rnd(BGS);
        L.acc = Math.random() < 0.55 ? rnd(FH.enums.acc.slice(1)) : 'none';
        L.accC = rnd(ACC_COLORS);
        L.eyeShape = SPECIES[sp][1].eyeShape || (Math.random() < 0.75 ? 'round' : rnd(['sharp', 'sleepy']));
        L.blush = Math.random() < 0.6;
        return L;
    }

    let sona = null, sonaExpr = 'neutral';
    function currentLook() { return sona ? sona.look : (store.get('draft', null) || {}).look || null; }
    function sonaDraft() {
        const d = store.get('draft', null);
        if (d) return d;
        if (FH.mySona) return { name: FH.mySona.name, species: FH.mySona.species, pronouns: FH.mySona.pronouns, bio: FH.mySona.bio, ref_url: FH.mySona.ref_url, look: FH.mySona.look, traits: FH.mySona.traits };
        const L = randomLook();
        return { name: genName(), species: SPECIES[L.species][0].replace(/^\S+\s/, ''), pronouns: '', bio: '', ref_url: '', look: L, traits: genTraits() };
    }
    function initSona() {
        sona = sonaDraft();
        const f = $('#sonaForm');
        $('#speciesSel').innerHTML = '<option value="">— Vorlage wählen —</option>' + Object.entries(SPECIES).map(([k, [l]]) => `<option value="${k}">${l}</option>`).join('');
        $('#sonaColorsIn').innerHTML = Object.entries(COLOR_LABELS).map(([k, l]) => `<label class="col"><input type="color" data-c="${k}"><span>${l}</span></label>`).join('');
        $('#sonaShapes').innerHTML = Object.entries(LABELS).map(([k, opts]) => `<label>${{ ears: 'Ohren', muzzle: 'Schnauze', marking: 'Zeichnung', extra: 'Extra', acc: 'Accessoire', eyeShape: 'Augen' }[k]}<select data-s="${k}">${Object.entries(opts).map(([v, t]) => `<option value="${v}">${t}</option>`).join('')}</select></label>`).join('');
        const fill = () => {
            ['name', 'species', 'pronouns', 'bio', 'ref_url'].forEach(k => { f.elements[k].value = sona[k] || ''; });
            $$('[data-c]', f).forEach(i => { i.value = sona.look[i.dataset.c] || '#888888'; });
            $$('[data-s]', f).forEach(s => { s.value = sona.look[s.dataset.s] || s.options[0].value; });
            f.elements.blush.checked = !!sona.look.blush; f.elements.fluff.checked = !!sona.look.fluff;
            $('#speciesSel').value = sona.look.species && SPECIES[sona.look.species] ? sona.look.species : '';
            setMode(sona.look.mode === 'custom' ? 'custom' : 'svg');
            renderTraits(); draw();
        };
        const setMode = m => {
            sona.look.mode = m; if (!sona.look.images || Array.isArray(sona.look.images)) sona.look.images = {};
            $$('[data-mode-s]').forEach(b => b.classList.toggle('on', b.dataset.modeS === m));
            $('#sonaSvgOpts').hidden = m === 'custom'; $('#sonaCustomOpts').hidden = m !== 'custom';
            $('#sonaSvgDl').hidden = m === 'custom'; $('#sonaColors').hidden = m === 'custom';
        };
        $$('[data-mode-s]').forEach(b => b.addEventListener('click', () => { setMode(b.dataset.modeS); draw(); }));
        emoSlots($('[data-emo-slots="sona"]'), () => (sona.look.images = (sona.look.images && !Array.isArray(sona.look.images)) ? sona.look.images : {}), 'sona', () => draw());
        const draw = () => {
            $('#sonaSvg').innerHTML = face(sona.look, sonaExpr);
            $('#sonaTitle').innerHTML = `<b>${esc(sona.name || 'Namenlos')}</b><small>${esc(sona.species || '')}${sona.pronouns ? ' · ' + esc(sona.pronouns) : ''}</small>`;
            $('#sonaTraitsView').innerHTML = (sona.traits || []).map(t => `<span>${esc(t)}</span>`).join('');
            store.set('draft', sona);
        };
        const renderTraits = () => {
            $('#sonaTraits').innerHTML = (sona.traits || []).map((t, i) => `<span class="chip"><input value="${esc(t)}" data-t="${i}" maxlength="40"><button type="button" data-tdel="${i}" aria-label="Entfernen">×</button></span>`).join('') + '<button type="button" class="chip add" id="traitAdd">＋</button>';
        };
        f.addEventListener('input', e => {
            const t = e.target;
            if (t.dataset.c) sona.look[t.dataset.c] = t.value;
            else if (t.dataset.s) sona.look[t.dataset.s] = t.value;
            else if (t.dataset.t !== undefined) sona.traits[+t.dataset.t] = t.value;
            else if (t.name === 'blush' || t.name === 'fluff') sona.look[t.name] = t.checked;
            else if (t.name === 'preset') {
                if (!t.value) return;
                const keepBg = sona.look.bg, keepAcc = [sona.look.acc, sona.look.accC], keep = [sona.look.mode, sona.look.images];
                sona.look = Object.assign(defaultLook(t.value), { bg: keepBg, acc: keepAcc[0], accC: keepAcc[1], mode: keep[0], images: keep[1] });
                sona.species = SPECIES[t.value][0].replace(/^\S+\s/, '');
                fill(); return;
            } else if (t.name) sona[t.name] = t.value;
            draw();
        });
        f.addEventListener('click', e => {
            if (e.target.dataset.tdel !== undefined) { sona.traits.splice(+e.target.dataset.tdel, 1); renderTraits(); draw(); }
            if (e.target.id === 'traitAdd' && sona.traits.length < 6) { sona.traits.push(''); renderTraits(); $(`[data-t="${sona.traits.length - 1}"]`).focus(); }
        });
        $('#nameGen').addEventListener('click', () => { sona.name = genName(); f.elements.name.value = sona.name; draw(); });
        $('#traitGen').addEventListener('click', () => { sona.traits = genTraits(); renderTraits(); draw(); });
        $('#sonaRandom').addEventListener('click', () => { const L = Object.assign(randomLook(), { mode: 'svg', images: sona.look.images || {} }); sona = Object.assign(sona, { look: L, name: genName(), species: SPECIES[L.species][0].replace(/^\S+\s/, ''), traits: genTraits() }); fill(); });
        $('#sonaColors').addEventListener('click', () => { const r = randomLook(sona.look.species && SPECIES[sona.look.species] ? sona.look.species : undefined); ['main', 'second', 'muzzleC', 'inner', 'eye', 'nose', 'markC', 'accC', 'bg'].forEach(k => { sona.look[k] = r[k]; }); fill(); });
        $$('[data-expr]').forEach(b => b.addEventListener('click', () => { sonaExpr = b.dataset.expr; $$('[data-expr]').forEach(x => x.classList.toggle('on', x === b)); draw(); }));
        const fname = () => (sona.name || 'fursona').replace(/[äöüÄÖÜß]/g, c => ({ ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'Ae', Ö: 'Oe', Ü: 'Ue', ß: 'ss' }[c])).replace(/[^\w-]+/g, '_');
        $('#sonaSvgDl').addEventListener('click', () => { download(new Blob([sonaSVG(sona.look, sonaExpr)], { type: 'image/svg+xml' }), fname() + '.svg'); unlock('sona'); });
        $('#sonaPng').addEventListener('click', () => {
            const img = new Image(), custom = imgFor(sona.look, sonaExpr);
            img.onload = () => {
                const c = document.createElement('canvas'); c.width = c.height = 1024;
                const g = c.getContext('2d'), f = Math.min(1024 / img.naturalWidth, 1024 / img.naturalHeight);
                if (custom) g.drawImage(img, (1024 - img.naturalWidth * f) / 2, (1024 - img.naturalHeight * f) / 2, img.naturalWidth * f, img.naturalHeight * f);
                else g.drawImage(img, 0, 0, 1024, 1024);
                c.toBlob(b => { download(b, fname() + '_' + sonaExpr + '.png'); unlock('sona'); });
            };
            img.src = custom || 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(sonaSVG(sona.look, sonaExpr));
        });
        f.addEventListener('submit', async e => {
            e.preventDefault();
            try {
                await ensureServerImages(sona.look.images, 'sona');
                const r = await api('sona_save', { data: JSON.stringify(Object.assign({}, sona, { traits: (sona.traits || []).filter(Boolean) })) });
                if (sona.look.mode === 'custom' && r.sona.look.mode !== 'custom') toast('Die Bilder konnten nicht übernommen werden.', true);
                FH.mySona = r.sona; sonas = null; toast('💾 Gespeichert! Deine Sona ist jetzt in der Community zu sehen.'); unlock('sona');
                $('#sonaDelete').hidden = false; loaded.community = false;
            } catch (err) { toast(err.message, true); }
        });
        const del = $('#sonaDelete');
        if (del) {
            del.hidden = !FH.mySona;
            del.addEventListener('click', async () => {
                if (!confirm('Deine Sona aus der Community entfernen? (Der Entwurf hier bleibt.)')) return;
                try { await api('sona_delete', {}); FH.mySona = null; sonas = null; del.hidden = true; loaded.community = false; toast('Entfernt.'); } catch (err) { toast(err.message, true); }
            });
        }
        fill();
    }
    function download(blob, name) {
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.append(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }

    // =====================================================================
    // Community
    // =====================================================================
    async function initCommunity() {
        const grid = $('#comGrid');
        grid.innerHTML = '<div class="muted">Lädt …</div>';
        const list = await loadSonas(true);
        const render = () => {
            const q = $('#comSearch').value.toLowerCase().trim(), sort = $('#comSort').value;
            let l = list.filter(s => !q || [s.name, s.species, s.owner, s.pronouns, s.bio, ...(s.traits || [])].join(' ').toLowerCase().includes(q));
            l = l.slice().sort(sort === 'likes' ? (a, b) => b.likes - a.likes : sort === 'name' ? (a, b) => a.name.localeCompare(b.name, 'de') : (a, b) => b.updated - a.updated);
            $('#comCount').textContent = l.length + ' Sonas';
            grid.innerHTML = l.map(s => `<article class="com-card" data-u="${s.user_id}">
                <div class="com-face">${face(s.look)}</div>
                <div class="com-body"><b>${esc(s.name)}</b><small>${esc(s.species)}${s.pronouns ? ' · ' + esc(s.pronouns) : ''}</small>
                <div class="com-traits">${(s.traits || []).map(t => `<span>${esc(t)}</span>`).join('')}</div>
                ${s.bio ? `<p>${esc(s.bio)}</p>` : ''}
                <div class="com-foot"><span class="owner" style="color:${esc(s.owner_color)}">von ${esc(s.owner)}</span>
                ${s.ref_url ? `<a href="${esc(s.ref_url)}" target="_blank" rel="noopener noreferrer nofollow" class="fh-btn sm">📄 Ref</a>` : ''}
                <button type="button" class="fh-btn sm" data-boop-sona="${s.user_id}">👃</button>
                <button type="button" class="fh-btn sm like ${s.liked ? 'on' : ''}" data-like="${s.user_id}" ${s.mine || !FH.me ? 'disabled' : ''} title="${s.mine ? 'Deine eigene Sona' : FH.me ? 'Gefällt mir' : 'Zum Liken anmelden'}">💖 <i>${s.likes}</i></button>
                ${FH.canModerate && !s.mine ? `<button type="button" class="fh-btn sm danger" data-mod-del="${s.user_id}" title="Entfernen (Moderation)">✕</button>` : ''}</div></div></article>`).join('')
                || `<div class="fh-card empty">Noch keine Sonas hier. ${FH.me ? '<a href="#sona" data-goto="sona">Bau deine und speichere sie!</a>' : 'Melde dich an und zeig deine Fursona!'}</div>`;
        };
        let view = 'sonas', shared = null;
        const renderCards = async () => {
            if (!shared) { grid.innerHTML = '<div class="muted">Lädt …</div>'; try { shared = (await api('cards_shared')).cards; } catch (e) { shared = []; } }
            const q = $('#comSearch').value.toLowerCase().trim();
            const l = shared.filter(c => !q || [c.data.name, c.data.subtitle, c.owner, c.data.flavor].join(' ').toLowerCase().includes(q));
            $('#comCount').textContent = l.length + ' Karten';
            grid.innerHTML = l.map(c => `<article class="com-card cardc" data-cid="${c.id}"><div class="com-face card-mini ${c.data.holo ? 'holo' : ''}">${K.cardSVG ? K.cardSVG(c.data, (list.find(x => x.user_id === c.user_id) || {}).look) : ''}</div>
                <div class="com-body"><b>${esc(c.data.name || 'Karte')}</b><small>${esc(c.data.subtitle || '')}</small><div class="com-foot"><span class="owner">von ${esc(c.owner)}</span>
                <button type="button" class="fh-btn sm" data-card-png="${c.id}">⬇ PNG</button>${FH.canModerate ? `<button type="button" class="fh-btn sm danger" data-card-mod="${c.id}">✕</button>` : ''}</div></div></article>`).join('')
                || '<div class="fh-card empty">Noch keine geteilten Karten. Im Karten-Editor „in der Community zeigen“ anhaken!</div>';
        };
        const rerender = () => (view === 'cards' ? renderCards() : render());
        $$('[data-com]').forEach(b => b.onclick = () => { view = b.dataset.com; $$('[data-com]').forEach(x => x.classList.toggle('on', x === b)); $('#comSort').hidden = view === 'cards'; rerender(); });
        $('#comSearch').oninput = rerender; $('#comSort').onchange = rerender;
        grid.onclick = async e => {
            const lb = e.target.closest('[data-like]');
            if (lb && !lb.disabled) {
                try { const r = await api('sona_like', { user_id: lb.dataset.like }); const s = list.find(x => x.user_id === +lb.dataset.like); s.liked = r.liked; s.likes = r.likes; lb.classList.toggle('on', r.liked); lb.querySelector('i').textContent = r.likes; if (r.liked) { unlock('like'); floatText(e.pageX, e.pageY - 20, '💖', 'heart'); } } catch (err) { toast(err.message, true); }
            }
            const cp = e.target.closest('[data-card-png]');
            if (cp && K.cardPNG) { const c = shared.find(x => x.id === +cp.dataset.cardPng); K.cardPNG(c.data, (list.find(x => x.user_id === c.user_id) || {}).look); }
            const cm = e.target.closest('[data-card-mod]');
            if (cm && confirm('Diese Karte entfernen?')) { try { await api('card_delete', { id: cm.dataset.cardMod, mod: 1 }); shared = shared.filter(x => x.id !== +cm.dataset.cardMod); renderCards(); } catch (err) { toast(err.message, true); } }
            const md = e.target.closest('[data-mod-del]');
            if (md && confirm('Diese Sona aus der Community entfernen?')) {
                try { await api('sona_delete', { user_id: md.dataset.modDel }); list.splice(list.findIndex(x => x.user_id === +md.dataset.modDel), 1); render(); } catch (err) { toast(err.message, true); }
            }
        };
        render();
    }

    // =====================================================================
    // Con-Bingo
    // =====================================================================
    function initBingo() {
        let b = store.get('bingo', null);
        const fresh = () => { const items = FH.bingo.slice().sort(() => Math.random() - 0.5).slice(0, 24); items.splice(12, 0, '🐾 Frei'); return { items, marked: [12], bingos: 0 }; };
        if (!b || !b.items || b.items.length !== 25) b = fresh();
        const lines = [];
        for (let i = 0; i < 5; i++) { lines.push([0, 1, 2, 3, 4].map(j => i * 5 + j)); lines.push([0, 1, 2, 3, 4].map(j => j * 5 + i)); }
        lines.push([0, 6, 12, 18, 24], [4, 8, 12, 16, 20]);
        const render = () => {
            const done = lines.filter(l => l.every(i => b.marked.includes(i)));
            const inLine = new Set(done.flat());
            $('#bingo').innerHTML = b.items.map((t, i) => `<button type="button" class="bc ${b.marked.includes(i) ? 'on' : ''} ${inLine.has(i) ? 'line' : ''} ${i === 12 ? 'free' : ''}" data-i="${i}">${esc(t)}</button>`).join('');
            $('#bingoInfo').textContent = b.marked.length - 1 + ' / 24 erlebt · ' + done.length + ' Bingo' + (done.length === 1 ? '' : 's');
            if (done.length > (b.bingos || 0)) { toast('🎉 BINGO! 🎉'); confetti(60); sfx.awoo(); unlock('bingo'); }
            b.bingos = done.length; store.set('bingo', b);
        };
        $('#bingo').addEventListener('click', e => {
            const c = e.target.closest('.bc'); if (!c || c.dataset.i === '12') return;
            const i = +c.dataset.i, k = b.marked.indexOf(i);
            if (k >= 0) b.marked.splice(k, 1); else { b.marked.push(i); sfx.boop(); }
            render();
        });
        $('#bingoNew').addEventListener('click', () => { if (b.marked.length > 1 && !confirm('Neue Karte? Die Markierungen gehen verloren.')) return; b = fresh(); render(); });
        $('#bingoPrint').addEventListener('click', () => window.print());
        render();
    }

    // =====================================================================
    // Quiz
    // =====================================================================
    function initQuiz() {
        const box = $('#quiz');
        let step = 0, score = {};
        const start = () => {
            const last = store.get('quiz', null);
            box.innerHTML = `<div class="quiz-start"><div class="quiz-big">🦊🐺🐱🦦🐉</div><h2>Welches Tier steckt in dir?</h2><p class="muted">${FH.quiz.length} Fragen, ehrlich antworten – ganz ohne Wissenschaft, mit viel Herz.</p>
                ${last ? `<p>Letztes Ergebnis: <b>${esc(FH.quizResults[last][0] + ' ' + FH.quizResults[last][1])}</b></p>` : ''}<button type="button" class="fh-btn gold" id="quizGo">Los geht’s!</button></div>`;
            $('#quizGo').onclick = () => { step = 0; score = {}; q(); };
        };
        const q = () => {
            const [text, answers] = FH.quiz[step];
            box.innerHTML = `<div class="quiz-prog"><i style="width:${step / FH.quiz.length * 100}%"></i></div><div class="quiz-n">Frage ${step + 1} von ${FH.quiz.length}</div><h2>${esc(text)}</h2>
                <div class="quiz-a">${answers.map((a, i) => `<button type="button" class="fh-btn" data-a="${i}">${esc(a[0])}</button>`).join('')}</div>`;
            $$('[data-a]', box).forEach(b => b.onclick = () => {
                Object.entries(answers[+b.dataset.a][1]).forEach(([k, v]) => { score[k] = (score[k] || 0) + v; });
                step++; step < FH.quiz.length ? q() : result();
            });
        };
        const result = () => {
            const best = Object.entries(score).sort((a, b) => b[1] - a[1] || Math.random() - 0.5)[0][0];
            const [ic, name, desc] = FH.quizResults[best];
            store.set('quiz', best); unlock('quiz'); confetti(30, [ic, '✨', '🐾']);
            const preset = { fox: 'fox', wolf: 'wolf', cat: 'cat', otter: 'otter', raccoon: 'raccoon', dragon: 'dragon', redpanda: 'redpanda', rabbit: 'rabbit' }[best];
            const top = Object.entries(score).sort((a, b) => b[1] - a[1]).slice(0, 3);
            box.innerHTML = `<div class="quiz-res"><div class="quiz-face">${sonaSVG(randomLook(preset), 'happy')}</div><div><div class="quiz-n">Du bist …</div><h2>${ic} ${esc(name)}</h2><p>${esc(desc)}</p>
                <div class="quiz-bars">${top.map(([k, v]) => `<div><span>${FH.quizResults[k][0]} ${esc(FH.quizResults[k][1])}</span><i style="width:${v / top[0][1] * 100}%"></i></div>`).join('')}</div>
                <div class="quiz-a"><button type="button" class="fh-btn gold" id="quizUse">🎨 Als Sona-Vorlage nehmen</button><button type="button" class="fh-btn" id="quizAgain">↺ Nochmal</button></div></div></div>`;
            $('#quizAgain').onclick = start;
            $('#quizUse').onclick = () => {
                showTab('sona');
                const sel = $('#speciesSel'); sel.value = preset; sel.dispatchEvent(new Event('input', { bubbles: true }));
            };
        };
        start();
    }

    // =====================================================================
    // Soundboard (WebAudio-Synthese)
    // =====================================================================
    let AC = null, master = null;
    const ac = () => {
        if (!AC) { AC = new (window.AudioContext || window.webkitAudioContext)(); master = AC.createGain(); master.connect(AC.destination); }
        if (AC.state === 'suspended') AC.resume();
        master.gain.value = (store.get('vol', 60) / 100) * 0.9;
        return AC;
    };
    const env = (g, t, a, peak, d) => { g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(peak, t + a); g.gain.exponentialRampToValueAtTime(0.0001, t + a + d); };
    function osc(type, f0, curve, dur, peak = 0.3, filter) {
        const c = ac(), t = c.currentTime, o = c.createOscillator(), g = c.createGain();
        o.type = type; o.frequency.setValueAtTime(f0, t);
        curve.forEach(([dt, f]) => o.frequency.exponentialRampToValueAtTime(f, t + dt));
        let node = o;
        if (filter) { const fl = c.createBiquadFilter(); fl.type = filter[0]; fl.frequency.value = filter[1]; fl.Q.value = filter[2] || 1; o.connect(fl); node = fl; }
        node.connect(g); g.connect(master); env(g, t, Math.min(0.08, dur / 4), peak, dur); o.start(t); o.stop(t + dur + 0.2);
        return o;
    }
    function noise(dur, filter, peak = 0.3, lfo) {
        const c = ac(), t = c.currentTime, buf = c.createBuffer(1, c.sampleRate * dur, c.sampleRate), d = buf.getChannelData(0);
        for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
        const src = c.createBufferSource(), fl = c.createBiquadFilter(), g = c.createGain();
        src.buffer = buf; fl.type = filter[0]; fl.frequency.value = filter[1]; fl.Q.value = filter[2] || 0.7;
        src.connect(fl); fl.connect(g); g.connect(master); env(g, t, 0.05, peak, dur - 0.05);
        if (lfo) { const l = c.createOscillator(), lg = c.createGain(); l.frequency.value = lfo; lg.gain.value = peak * 0.9; l.connect(lg); lg.connect(g.gain); l.start(t); l.stop(t + dur); }
        src.start(t);
    }
    const sfx = {
        awoo() { const o = osc('sawtooth', 280, [[0.35, 520], [1.1, 470], [1.6, 330]], 1.6, 0.22, ['lowpass', 1400, 2]); const c = ac(), v = c.createOscillator(), vg = c.createGain(); v.frequency.value = 5.5; vg.gain.value = 9; v.connect(vg); vg.connect(o.frequency); v.start(); v.stop(c.currentTime + 1.7); },
        bark() { [0, 0.22].forEach(d => setTimeout(() => { osc('square', 260, [[0.12, 150]], 0.14, 0.25, ['lowpass', 1200]); noise(0.12, ['bandpass', 900, 1], 0.2); }, d * 1000)); },
        meow() { osc('sawtooth', 480, [[0.18, 820], [0.6, 420]], 0.65, 0.2, ['bandpass', 1100, 3]); },
        purr() { noise(1.4, ['lowpass', 220, 1], 0.5, 24); },
        chirp() { osc('sine', 700, [[0.05, 1300], [0.1, 900], [0.15, 1500]], 0.18, 0.2); },
        mrrp() { osc('triangle', 380, [[0.08, 620], [0.2, 560], [0.32, 760]], 0.35, 0.25, ['lowpass', 1800]); },
        hiss() { noise(0.9, ['highpass', 3000, 0.5], 0.25); },
        squeak() { [0, 0.15].forEach(d => setTimeout(() => osc('sine', 1300, [[0.08, 2100]], 0.1, 0.18), d * 1000)); },
        yip() { osc('square', 900, [[0.07, 1500], [0.12, 1000]], 0.13, 0.15, ['lowpass', 2600]); },
        roar() { osc('sawtooth', 110, [[0.4, 80], [1.2, 60]], 1.2, 0.3, ['lowpass', 600, 4]); noise(1.2, ['lowpass', 700, 1], 0.3); },
        boop() { osc('sine', 620, [[0.07, 980]], 0.09, 0.15); },
        hoot() { [0, 0.45].forEach(d => setTimeout(() => osc('sine', 420, [[0.15, 380]], 0.3, 0.25), d * 1000)); },
    };
    const SOUNDS = [['awoo', '🐺', 'Awoo'], ['bark', '🐶', 'Wuff'], ['meow', '🐱', 'Miau'], ['purr', '😺', 'Schnurren'], ['mrrp', '🐈', 'Mrrp'], ['chirp', '🐦', 'Zwitschern'],
                    ['yip', '🦊', 'Fuchs-Yip'], ['hiss', '🐍', 'Fauchen'], ['squeak', '🐭', 'Quietschen'], ['roar', '🐉', 'Brüllen'], ['hoot', '🦉', 'Huhu'], ['boop', '👃', 'Boop']];
    function initSounds() {
        const played = new Set(store.get('played', []));
        $('#sounds').innerHTML = SOUNDS.map(([k, ic, l], i) => `<button type="button" class="snd" data-snd="${k}"><span>${ic}</span><b>${l}</b>${i < 10 ? `<kbd>${(i + 1) % 10}</kbd>` : ''}</button>`).join('');
        const play = k => {
            sfx[k]();
            const b = $(`[data-snd="${k}"]`); b.classList.remove('hit'); void b.offsetWidth; b.classList.add('hit');
            played.add(k); store.set('played', [...played]);
            if (k === 'awoo') { unlock('awoo'); react('awoo'); }
            if (played.size >= SOUNDS.length) unlock('sounds');
        };
        $('#sounds').addEventListener('click', e => { const b = e.target.closest('[data-snd]'); if (b) play(b.dataset.snd); });
        $('#vol').value = store.get('vol', 60);
        $('#vol').addEventListener('input', e => { store.set('vol', +e.target.value); if (master) master.gain.value = e.target.value / 100 * 0.9; });
        document.addEventListener('keydown', e => {
            if ($('[data-panel="sounds"]').hidden || e.target.closest('input, textarea, select')) return;
            const n = '1234567890'.indexOf(e.key);
            if (n >= 0 && SOUNDS[n]) play(SOUNDS[n][0]);
        });
    }

    // =====================================================================
    // OwO-Übersetzer
    // =====================================================================
    const FACES = [' OwO', ' UwU', ' >w<', ' ^w^', ' :3', ' (≧◡≦)', ' *wedelt*', ' nyaa~'];
    const MODES = {
        uwu: ['💕 UwU', t => {
            let s = t.replace(/[rl]/g, 'w').replace(/[RL]/g, 'W').replace(/n([aeiou])/g, 'ny$1').replace(/N([aeiou])/g, 'Ny$1').replace(/ove\b/g, 'uv');
            s = s.replace(/\b(\p{L})(\p{L}{2,})/gu, (m, a, b) => (hash(m) % 7 === 0 ? a + '-' + a.toLowerCase() + b : m));
            s = s.replace(/([.!?])(\s|$)/g, (m, p, sp) => p + (hash(m + s.length) % 3 === 0 ? FACES[hash(p + sp + s.length) % FACES.length] : '') + sp);
            return s + (/[.!?]\s*$/.test(s) ? '' : FACES[hash(t) % FACES.length]);
        }],
        dog: ['🐶 Hundisch', t => animalize(t, ['Wuff', 'Wau', 'Arf', 'Bork', 'Wuffwuff', 'Awoo', 'Yip', 'Grrr'])],
        cat: ['🐱 Katzisch', t => animalize(t, ['Miau', 'Mrrp', 'Mau', 'Nyah', 'Prrr', 'Mew', 'Mrrow'])],
        dragon: ['🐉 Drachisch', t => animalize(t, ['Rawr', 'Grrah', 'Hrrm', 'Rooar', 'Skrahh', 'Hssss'], ' *speit Feuer*')],
        otter: ['🦦 Otterisch', t => animalize(t, ['Squeak', 'Iiik', 'Chirp', 'Mlem', 'Hehe'], ' *hält Pfötchen*')],
        paw: ['🐾 Pfötchen', t => t.split(/(\s+)/).map(w => (w.trim() ? w : ' 🐾 ')).join('')],
    };
    function animalize(t, words, extra = '') {
        return t.replace(/\p{L}[\p{L}'-]*/gu, w => {
            let x = words[hash(w.toLowerCase()) % words.length];
            if (w.length > 7) x += '-' + words[hash(w + '2') % words.length].toLowerCase();
            if (w === w.toUpperCase() && w.length > 1) return x.toUpperCase();
            return w[0] === w[0].toUpperCase() ? x : x.toLowerCase();
        }) + (t.trim() && extra && hash(t) % 2 ? extra : '');
    }
    function initOwo() {
        let mode = store.get('owoMode', 'uwu');
        const run = () => {
            const t = $('#owoIn').value || $('#owoIn').placeholder;
            $('#owoOut').textContent = MODES[mode][1](t);
            if ($('#owoIn').value.length > 3) unlock('owo');
        };
        $('#owoModes').innerHTML = Object.entries(MODES).map(([k, [l]]) => `<button type="button" class="fh-btn sm" data-mode="${k}">${l}</button>`).join('');
        const setMode = m => { mode = m; store.set('owoMode', m); $$('[data-mode]').forEach(b => b.classList.toggle('on', b.dataset.mode === m)); run(); };
        $('#owoModes').addEventListener('click', e => { const b = e.target.closest('[data-mode]'); if (b) setMode(b.dataset.mode); });
        $('#owoIn').addEventListener('input', run);
        $('#owoCopy').addEventListener('click', () => navigator.clipboard && navigator.clipboard.writeText($('#owoOut').textContent).then(() => toast('⧉ Kopiert – viel Spaß im Chat!')));
        setMode(mode);
    }

    // =====================================================================
    // Glossar
    // =====================================================================
    function initGlossary() {
        const render = () => {
            const q = $('#glossSearch').value.toLowerCase().trim();
            const l = FH.glossary.filter(([t, d]) => !q || (t + ' ' + d).toLowerCase().includes(q));
            $('#glossCount').textContent = l.length + (l.length === 1 ? ' Begriff' : ' Begriffe');
            $('#gloss').innerHTML = l.map(([t, d]) => `<div class="gl"><b>${esc(t)}</b><p>${esc(d)}</p></div>`).join('') || '<p class="muted">Nichts gefunden. Vielleicht ist es ein Geheimwort. 🤫</p>';
        };
        $('#glossSearch').addEventListener('input', render);
        render();
    }

    // =====================================================================
    // Pfotenabdrücke
    // =====================================================================
    const pawSvg = c => `<svg viewBox="0 0 64 64" class="paw-ic"><g fill="${esc(c)}"><ellipse cx="32" cy="42" rx="14" ry="12"/><ellipse cx="14" cy="26" rx="6" ry="8"/><ellipse cx="26" cy="16" rx="6" ry="8"/><ellipse cx="38" cy="16" rx="6" ry="8"/><ellipse cx="50" cy="26" rx="6" ry="8"/></g></svg>`;
    function renderPaws(list) {
        $('#paws').innerHTML = list.map((p, i) => `<div class="paw" style="--pc:${esc(p.paw)};--rot:${(hash(p.id) % 7) - 3}deg">${pawSvg(p.paw)}<div><p>${esc(p.msg)}</p><small>${esc(p.name)} · ${new Date(p.ts * 1000).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })}</small></div>${p.can_delete ? `<button type="button" data-gdel="${p.id}" title="Löschen">×</button>` : ''}</div>`).join('')
            || '<div class="fh-card empty">Noch keine Pfotenabdrücke. Mach den ersten! 🐾</div>';
    }
    async function initPaws() {
        try { renderPaws((await api('gb')).entries); } catch (e) { $('#paws').textContent = e.message; }
        const f = $('#pawForm');
        if (f) f.addEventListener('submit', async e => {
            e.preventDefault();
            try { renderPaws((await api('gb_add', { msg: f.elements.msg.value, paw: f.elements.paw.value })).entries); f.elements.msg.value = ''; unlock('paw'); confetti(12, ['🐾']); } catch (err) { toast(err.message, true); }
        });
        $('#paws').addEventListener('click', async e => {
            const b = e.target.closest('[data-gdel]');
            if (b && confirm('Diesen Pfotenabdruck löschen?')) { try { renderPaws((await api('gb_delete', { id: b.dataset.gdel })).entries); } catch (err) { toast(err.message, true); } }
        });
    }

    // =====================================================================
    // Easter Eggs
    // =====================================================================
    const KONAMI = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];
    let kpos = 0, typed = '';
    document.addEventListener('keydown', e => {
        kpos = e.key === KONAMI[kpos] || e.key.toLowerCase() === KONAMI[kpos] ? kpos + 1 : (e.key === KONAMI[0] ? 1 : 0);
        if (kpos === KONAMI.length) { kpos = 0; confetti(120, ['🐾', '🐾', '🦊', '🐺', '🐱', '🦦', '🐉', '✨']); toast('🎮 Pfotenregen! ↑↑↓↓←→←→BA'); unlock('konami'); sfx.roar(); }
        if (e.target.closest('input, textarea, select')) return;
        if (e.key === ' ' && !$('[data-panel="tama"]').hidden && K.tamaBoop) { e.preventDefault(); K.tamaBoop(); }
        if (e.key.length === 1) {
            typed = (typed + e.key.toLowerCase()).slice(-8);
            if (typed.endsWith('awoo')) { typed = ''; sfx.awoo(); react('awoo'); unlock('awoo'); toast('🐺 AWOOOOO!'); }
            if (typed.endsWith('boop')) { typed = ''; showTab('tama'); if (K.tamaBoop) K.tamaBoop(); }
            if (typed.endsWith('uwu') || typed.endsWith('owo')) { typed = ''; floatText(innerWidth / 2 + scrollX - 30, scrollY + innerHeight / 2, rnd(['OwO', 'UwU', '>w<']), 'big'); }
        }
    });
    let logoClicks = 0, logoT;
    $('#fhLogo').addEventListener('click', e => {
        logoClicks++; clearTimeout(logoT); logoT = setTimeout(() => { logoClicks = 0; }, 3000);
        $('#fhLogo').classList.remove('spin'); void $('#fhLogo').offsetWidth; $('#fhLogo').classList.add('spin');
        sfx.yip();
        if (logoClicks === 10) { unlock('secret'); toast('🦊 Du hast den geheimen Fuchs gefunden! Er sagt: „Ring-ding-ding?“'); confetti(40, ['🦊']); }
    });
    // Pfotenspur
    let trail = store.get('trail', false), lastTrail = 0;
    const setTrail = on => { trail = on; store.set('trail', on); $('#pawTrail').classList.toggle('active', on); };
    $('#pawTrail').addEventListener('click', () => setTrail(!trail));
    setTrail(trail);
    document.addEventListener('mousemove', e => {
        if (!trail || Date.now() - lastTrail < 90) return;
        lastTrail = Date.now();
        const s = document.createElement('span'); s.className = 'fh-trail'; s.textContent = '🐾';
        s.style.left = e.pageX + 'px'; s.style.top = e.pageY + 'px';
        s.style.transform = `translate(-50%,-50%) rotate(${Math.atan2(e.movementY, e.movementX) * 180 / Math.PI + 90}deg)`;
        document.body.append(s); setTimeout(() => s.remove(), 900);
    });
    if (new Date().getHours() < 4) unlock('night');

    // =====================================================================
    // Start
    // =====================================================================
    const INIT = { start: () => { renderSotd(); randLink(); renderAch(); }, sona: initSona, community: initCommunity, bingo: initBingo, quiz: initQuiz, sounds: initSounds, owo: initOwo, glossary: initGlossary, paws: initPaws };
    const SHOW = {};
    // Werkzeugkasten für die Module (Tamagotchi, Karten, Minispiele)
    const K = window.FHK = {
        FH, $, $$, esc, rnd, clamp, store, hash, api, toast, confetti, floatText, download, unlock, react, boopCount, sfx, SOUNDS,
        sonaSVG, face, imgFor, safeImg, EMO, emoSlots, uploadImage, ensureServerImages, randomLook, defaultLook, SPECIES, genName, jitter, shade,
        currentLook: () => currentLook(), loadSonas, showTab: t => showTab(t), INIT, SHOW, renderMine, renderTop,
    };
    $('#randLinkBtn').addEventListener('click', randLink);
    renderCounters();
    document.addEventListener('DOMContentLoaded', () => {
        showTab(location.hash.slice(1) || 'start');
        window.addEventListener('hashchange', () => showTab(location.hash.slice(1)));
    });
    // Zähler alle 30 s auffrischen (sieht man andere boopen)
    setInterval(async () => { if (document.hidden) return; try { const r = await api('stats'); stats = r.stats; renderCounters(); renderTop(); renderMine(); } catch (e) { /* egal */ } }, 30000);
})();
