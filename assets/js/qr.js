/* Kleiner QR-Code-Erzeuger (Byte-Modus, Fehlerkorrektur M, Version 1–20) – ohne externe Bibliothek.
 * Nutzung: <canvas data-qr="text"></canvas> wird automatisch gezeichnet, oder QR.draw(canvas, text). */
(function () {
    const ECC_M = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
    const BLOCKS_M = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];
    const FORMAT_M = 0;

    const rawModules = v => {
        let r = (16 * v + 128) * v + 64;
        if (v >= 2) { const n = Math.floor(v / 7) + 2; r -= (25 * n - 10) * n - 55; if (v >= 7) r -= 36; }
        return r;
    };
    const dataCodewords = v => Math.floor(rawModules(v) / 8) - ECC_M[v] * BLOCKS_M[v];

    const gmul = (x, y) => { let z = 0; for (let i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11d); z ^= ((y >>> i) & 1) * x; } return z & 0xff; };
    function rsDivisor(n) {
        const r = new Array(n).fill(0); r[n - 1] = 1; let root = 1;
        for (let i = 0; i < n; i++) {
            for (let j = 0; j < n; j++) { r[j] = gmul(r[j], root); if (j + 1 < n) r[j] ^= r[j + 1]; }
            root = gmul(root, 2);
        }
        return r;
    }
    function rsRemainder(data, div) {
        const r = new Array(div.length).fill(0);
        for (const b of data) { const f = b ^ r.shift(); r.push(0); div.forEach((c, i) => { r[i] ^= gmul(c, f); }); }
        return r;
    }
    function alignPositions(v) {
        if (v === 1) return [];
        const n = Math.floor(v / 7) + 2;
        const step = Math.ceil((v * 4 + 4) / (n * 2 - 2)) * 2;
        const res = [6];
        for (let i = 0, pos = v * 4 + 10; i < n - 1; i++, pos -= step) res.splice(1, 0, pos);
        return res;
    }

    function encode(text) {
        const bytes = Array.from(new TextEncoder().encode(text));
        let v = 1;
        for (; v <= 20; v++) if (4 + (v < 10 ? 8 : 16) + bytes.length * 8 <= dataCodewords(v) * 8) break;
        if (v > 20) throw new Error('Text zu lang für QR');
        const size = v * 4 + 17;
        // Daten-Bits
        const bits = [];
        const put = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
        put(4, 4); put(bytes.length, v < 10 ? 8 : 16); bytes.forEach(b => put(b, 8));
        const cap = dataCodewords(v) * 8;
        put(0, Math.min(4, cap - bits.length));
        put(0, (8 - bits.length % 8) % 8);
        for (let pad = 0xec; bits.length < cap; pad ^= 0xec ^ 0x11) put(pad, 8);
        const data = [];
        for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(''), 2));
        // Fehlerkorrektur & Verschachtelung
        const nb = BLOCKS_M[v], eccLen = ECC_M[v], raw = Math.floor(rawModules(v) / 8);
        const nShort = nb - raw % nb, shortLen = Math.floor(raw / nb), div = rsDivisor(eccLen);
        const blocks = [];
        for (let i = 0, k = 0; i < nb; i++) {
            const dat = data.slice(k, k + shortLen - eccLen + (i < nShort ? 0 : 1));
            k += dat.length;
            const ecc = rsRemainder(dat, div);
            if (i < nShort) dat.push(0);
            blocks.push(dat.concat(ecc));
        }
        const all = [];
        for (let i = 0; i < blocks[0].length; i++) blocks.forEach((b, j) => { if (i !== shortLen - eccLen || j >= nShort) all.push(b[i]); });

        // Raster
        const M = Array.from({ length: size }, () => new Array(size).fill(false));
        const F = Array.from({ length: size }, () => new Array(size).fill(false));
        const set = (x, y, d) => { M[y][x] = d; F[y][x] = true; };
        for (let i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
        const finder = (cx, cy) => {
            for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) {
                const x = cx + dx, y = cy + dy, d = Math.max(Math.abs(dx), Math.abs(dy));
                if (x >= 0 && x < size && y >= 0 && y < size) set(x, y, d !== 2 && d !== 4);
            }
        };
        finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
        const ap = alignPositions(v), last = ap.length - 1;
        ap.forEach((ay, i) => ap.forEach((ax, j) => {
            if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) return;
            for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
        }));
        const drawFormat = mask => {
            const d = (FORMAT_M << 3) | mask;
            let rem = d;
            for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            const b = ((d << 10) | rem) ^ 0x5412, bit = i => ((b >>> i) & 1) === 1;
            for (let i = 0; i <= 5; i++) set(8, i, bit(i));
            set(8, 7, bit(6)); set(8, 8, bit(7)); set(7, 8, bit(8));
            for (let i = 9; i < 15; i++) set(14 - i, 8, bit(i));
            for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(i));
            for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(i));
            set(8, size - 8, true);
        };
        drawFormat(0);
        if (v >= 7) {
            let rem = v;
            for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
            const b = (v << 12) | rem;
            for (let i = 0; i < 18; i++) {
                const bit = ((b >>> i) & 1) === 1, a = size - 11 + i % 3, c = Math.floor(i / 3);
                set(a, c, bit); set(c, a, bit);
            }
        }
        // Datenmodule im Zickzack
        let i = 0;
        for (let right = size - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (let vert = 0; vert < size; vert++) for (let j = 0; j < 2; j++) {
                const x = right - j, up = ((right + 1) & 2) === 0, y = up ? size - 1 - vert : vert;
                if (!F[y][x] && i < all.length * 8) { M[y][x] = ((all[i >>> 3] >>> (7 - (i & 7))) & 1) === 1; i++; }
            }
        }
        // Maske mit der geringsten Strafpunktzahl wählen
        const maskFn = [(x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, x => x % 3 === 0, (x, y) => (x + y) % 3 === 0,
            (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0, (x, y) => x * y % 2 + x * y % 3 === 0,
            (x, y) => (x * y % 2 + x * y % 3) % 2 === 0, (x, y) => ((x + y) % 2 + x * y % 3) % 2 === 0];
        const apply = m => { for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!F[y][x] && maskFn[m](x, y)) M[y][x] = !M[y][x]; };
        const penalty = () => {
            let p = 0, dark = 0;
            for (let y = 0; y < size; y++) {
                for (let run = 1, x = 1; x <= size; x++) {
                    if (x < size && M[y][x] === M[y][x - 1]) run++; else { if (run >= 5) p += run - 2; run = 1; }
                }
                for (let run = 1, x = 1; x <= size; x++) {
                    if (x < size && M[x][y] === M[x - 1][y]) run++; else { if (run >= 5) p += run - 2; run = 1; }
                }
                for (let x = 0; x < size; x++) {
                    if (M[y][x]) dark++;
                    if (x < size - 1 && y < size - 1 && M[y][x] === M[y][x + 1] && M[y][x] === M[y + 1][x] && M[y][x] === M[y + 1][x + 1]) p += 3;
                }
            }
            return p + Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
        };
        let best = 0, bestP = Infinity;
        for (let m = 0; m < 8; m++) {
            apply(m); drawFormat(m);
            const p = penalty();
            if (p < bestP) { bestP = p; best = m; }
            apply(m);
        }
        apply(best); drawFormat(best);
        return M;
    }

    function draw(canvas, text) {
        const M = encode(text), n = M.length, quiet = 4, total = n + quiet * 2;
        const px = Math.max(2, Math.floor(Math.min(canvas.width, canvas.height) / total));
        canvas.width = canvas.height = px * total;
        const g = canvas.getContext('2d');
        g.fillStyle = '#fff'; g.fillRect(0, 0, canvas.width, canvas.height);
        g.fillStyle = '#000';
        for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (M[y][x]) g.fillRect((x + quiet) * px, (y + quiet) * px, px, px);
    }

    window.QR = { encode, draw };
    document.querySelectorAll('canvas[data-qr]').forEach(c => { try { draw(c, c.dataset.qr); } catch (e) { c.replaceWith(document.createTextNode('QR nicht möglich: ' + e.message)); } });
})();
