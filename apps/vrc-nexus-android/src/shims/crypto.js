'use strict';
/* crypto-Ersatz: Zufall über WebCrypto, SHA-1 in JavaScript (nur für Kennungen, nicht für Sicherheit). */
function sha1(bytes) {
    const ml = bytes.length, words = [];
    const withPad = new Uint8Array(((ml + 9 + 63) >> 6) << 6);
    withPad.set(bytes); withPad[ml] = 0x80;
    const dv = new DataView(withPad.buffer);
    dv.setUint32(withPad.length - 4, ml * 8);
    let [a, b, c, d, e] = [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476, 0xc3d2e1f0];
    const w = new Uint32Array(80), rol = (x, n) => (x << n) | (x >>> (32 - n));
    for (let i = 0; i < withPad.length; i += 64) {
        for (let t = 0; t < 16; t++) w[t] = dv.getUint32(i + t * 4);
        for (let t = 16; t < 80; t++) w[t] = rol(w[t - 3] ^ w[t - 8] ^ w[t - 14] ^ w[t - 16], 1);
        let [A, B, C, D, E] = [a, b, c, d, e];
        for (let t = 0; t < 80; t++) {
            const f = t < 20 ? (B & C) | (~B & D) : t < 40 ? B ^ C ^ D : t < 60 ? (B & C) | (B & D) | (C & D) : B ^ C ^ D;
            const k = t < 20 ? 0x5a827999 : t < 40 ? 0x6ed9eba1 : t < 60 ? 0x8f1bbcdc : 0xca62c1d6;
            const tmp = (rol(A, 5) + f + E + k + w[t]) >>> 0;
            E = D; D = C; C = rol(B, 30) >>> 0; B = A; A = tmp;
        }
        a = (a + A) >>> 0; b = (b + B) >>> 0; c = (c + C) >>> 0; d = (d + D) >>> 0; e = (e + E) >>> 0;
    }
    words.push(a, b, c, d, e);
    return Buffer.from(words.flatMap(x => [x >>> 24, (x >>> 16) & 255, (x >>> 8) & 255, x & 255]));
}
module.exports = {
    randomBytes(n) { const b = new Uint8Array(n); globalThis.crypto.getRandomValues(b); return Buffer.from(b); },
    createHash(alg) {
        if (alg !== 'sha1') throw new Error('Nur sha1 verfügbar.');
        const parts = [];
        const h = { update(x) { parts.push(typeof x === 'string' ? Buffer.from(x, 'utf8') : Buffer.from(x)); return h; }, digest(enc) { const out = sha1(Buffer.concat(parts)); return enc ? out.toString(enc) : out; } };
        return h;
    },
    timingSafeEqual(a, b) { if (a.length !== b.length) return false; let r = 0; for (let i = 0; i < a.length; i++) r |= a[i] ^ b[i]; return r === 0; },
};
