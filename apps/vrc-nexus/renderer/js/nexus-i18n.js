/* VRC Nexus – Sprachen (Deutsch, English, Français, 日本語) + Ecke unten rechts: Flaggen-Menü & Theme-Wahl.
 * Die Oberfläche ist auf Deutsch geschrieben; dieser Übersetzer ersetzt bekannte Texte (Wörterbuch in i18n-dict.js)
 * direkt im DOM – auch bei allem, was später neu gezeichnet wird. Inhalte von Leuten (Namen, Chat, Bios) bleiben, wie sie sind. */
(function () {
    const LANGS = {
        de: ['Deutsch', 'de-DE'],
        en: ['English', 'en-GB'],
        fr: ['Français', 'fr-FR'],
        ja: ['日本語', 'ja-JP'],
    };
    // Flaggen als SVG – Windows zeigt Flaggen-Emojis nur als Buchstaben
    const FLAG = {
        de: '<svg viewBox="0 0 5 3"><rect width="5" height="1" fill="#000"/><rect y="1" width="5" height="1" fill="#D00"/><rect y="2" width="5" height="1" fill="#FFCE00"/></svg>',
        en: '<svg viewBox="0 0 60 30"><clipPath id="i18nUk"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath><path d="M0,0 v30 h60 v-30 z" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#i18nUk)" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></svg>',
        fr: '<svg viewBox="0 0 3 2"><rect width="1" height="2" fill="#0055A4"/><rect x="1" width="1" height="2" fill="#fff"/><rect x="2" width="1" height="2" fill="#EF4135"/></svg>',
        ja: '<svg viewBox="0 0 3 2"><rect width="3" height="2" fill="#fff"/><circle cx="1.5" cy="1" r="0.6" fill="#BC002D"/></svg>',
    };
    const ui = (window.VRCX && window.VRCX.ui) || {};
    let lang = LANGS[ui['ui.lang']] ? ui['ui.lang'] : 'de';
    try { const l = localStorage.getItem('nexusLang'); if (!ui['ui.lang'] && LANGS[l]) lang = l; } catch (e) { /* egal */ }

    const D = () => (window.NexusDict || {})[lang] || null;
    const RX = () => ((window.NexusDict || {}).rx || {})[lang] || [];
    // Zeichen am Anfang/Ende, die nicht übersetzt werden (Emojis, Pfeile, Doppelpunkt …)
    const HEAD = /^[^\p{L}\p{N}„"(]+/u, TAIL = /[\s:…→←▾▸▶◀·.!?)"“]+$/u;
    const SKIP = 'script, style, textarea, code, pre, .fmt, [contenteditable], [data-no-i18n], .vx-feed-txt i, .hm-pre, .sb-msg, .hm-log';

    /** übersetzt einen Text (mit Emojis/Satzzeichen drumherum); null = unbekannt */
    function tr(text, depth = 0) {
        const d = D();
        if (!d || !text) return null;
        const t = text.trim().replace(/\s+/g, ' ');     // Zeilenumbrüche/Einrückung aus dem HTML zusammenfassen
        if (!t) return null;
        if (d[t] !== undefined) return d[t];
        const h = (t.match(HEAD) || [''])[0], rest = t.slice(h.length);
        const tl = (rest.match(TAIL) || [''])[0], core = rest.slice(0, rest.length - tl.length);
        if (core && d[core] !== undefined) return h + d[core] + tl;
        // Zahlen als Platzhalter: „seit 4 h 5 min“ → Schlüssel „seit # h # min“
        const nums = [];
        const k = (core || t).replace(/\d+(?:[.,:]\d+)*/g, m => { nums.push(m); return '#'; });
        if (nums.length && d[k] !== undefined) { let i = 0; return (core ? h : '') + d[k].replace(/#/g, () => nums[i++] ?? '') + (core ? tl : ''); }
        for (const [re, rep] of RX()) { if (re.test(core)) return h + core.replace(re, rep) + tl; }
        // zusammengesetzt: „5 Freunde · abgeglichen vor 3 min“ → Teile einzeln; „Gute Nacht, Luna“ → nur der bekannte Anfang
        if (depth < 2 && core.includes(' · ')) {
            let hit = false;
            const parts = core.split(' · ').map(p => { const x = tr(p, depth + 1); if (x !== null) hit = true; return x === null ? p : x; });
            if (hit) return h + parts.join(' · ') + tl;
        }
        // mehrere Sätze: jeder einzeln (alle müssen bekannt sein)
        if (depth < 2 && /[.!?] \S/.test(core)) {
            const parts = core.split(/(?<=[.!?]) (?=\S)/);
            if (parts.length > 1) { const xs = parts.map(p => tr(p, depth + 1)); if (xs.every(x => x !== null)) return h + xs.join(' ') + tl; }
        }
        const c = /^([^,]{2,40}), (.+)$/.exec(core);
        if (depth < 2 && c) { const x = tr(c[1], depth + 1); if (x !== null) return h + x + ', ' + c[2] + tl; }
        return null;
    }
    // Datums-/Zahlenformat der gewählten Sprache (für toLocaleString & Co.)
    window.nxLoc = () => LANGS[lang][1];
    window.t = s => (lang === 'de' ? s : tr(s) || s);

    const ORIG = new WeakMap();          // Textknoten → deutscher Originaltext
    const ATTR = ['placeholder', 'title', 'aria-label', 'data-tip'];
    function doText(n) {
        if (n.parentElement && n.parentElement.closest(SKIP)) return;
        let o = ORIG.get(n);
        if (o === undefined || (n.nodeValue !== o && n.nodeValue !== n.__tr)) { o = n.nodeValue; ORIG.set(n, o); }   // Inhalt wurde von der App geändert
        if (lang === 'de') { if (n.nodeValue !== o) n.nodeValue = o; return; }
        const x = tr(o);
        if (x === null) { if (n.nodeValue !== o) n.nodeValue = o; return; }
        const v = o.replace(o.trim(), x);
        n.__tr = v;
        if (n.nodeValue !== v) n.nodeValue = v;
    }
    function doAttrs(el) {
        if (el.closest(SKIP)) return;
        for (const a of ATTR) {
            if (!el.hasAttribute(a)) continue;
            const k = 'i18n' + a.replace(/-/g, '');
            let o = el.dataset[k];
            const cur = el.getAttribute(a);
            if (o === undefined || (cur !== o && cur !== el['__tr' + k])) { o = cur; el.dataset[k] = o; }
            const x = lang === 'de' ? o : tr(o) || o;
            el['__tr' + k] = x;
            if (cur !== x) el.setAttribute(a, x);
        }
        if (el.tagName === 'INPUT' && /^(button|submit)$/i.test(el.type) && el.value) {
            if (el.dataset.i18nvalue === undefined) el.dataset.i18nvalue = el.value;
            const o = el.dataset.i18nvalue, x = lang === 'de' ? o : tr(o) || o;
            if (el.value !== x) el.value = x;
        }
    }
    let busy = false;
    function walk(root) {
        if (!root) return;
        busy = true;
        try {
            if (root.nodeType === 3) { doText(root); return; }
            if (root.nodeType !== 1 || root.closest && root.closest(SKIP)) return;
            doAttrs(root);
            const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
                acceptNode: n => n.nodeType === 1 ? (n.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT) : (n.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP),
            });
            let n;
            while ((n = w.nextNode())) { if (n.nodeType === 3) doText(n); else doAttrs(n); }
        } finally { busy = false; }
    }
    const obs = new MutationObserver(list => {
        if (busy) return;
        busy = true;
        try {
            for (const m of list) {
                if (m.type === 'characterData') doText(m.target);
                else if (m.type === 'attributes') doAttrs(m.target);
                else m.addedNodes.forEach(n => { busy = false; walk(n); busy = true; });
            }
        } finally { busy = false; }
        obs.takeRecords();
    });

    function apply() {
        document.documentElement.lang = lang;
        document.documentElement.dataset.lang = lang;
        walk(document.body);
        const t = document.querySelector('title');
        if (t) t.textContent = 'VRC Nexus';
    }
    function setLang(l, save) {
        if (!LANGS[l]) return;
        lang = l;
        try { localStorage.setItem('nexusLang', l); } catch (e) { /* egal */ }
        if (window.VRCX && window.VRCX.ui) window.VRCX.ui['ui.lang'] = l;   // sonst setzt ein späteres applyUi die alte Sprache zurück
        apply();
        corner();
        if (save !== false && window.NexusUI) window.NexusUI.api('tools_settings_save', { data: JSON.stringify({ 'ui.lang': l }) }).catch(() => {});
    }

    // ---------- Ecke unten rechts: Flaggen + Theme ----------
    let box = null;
    function corner() {
        if (document.body.hasAttribute('data-i18n-nocorner')) return;   // z. B. SteamVR-Overlay
        if (!box) {
            box = document.createElement('div');
            box.className = 'i18n-corner';
            box.setAttribute('data-no-i18n', '');
            document.body.append(box);
            box.addEventListener('click', e => {
                const b = e.target.closest('button');
                if (!b) return;
                if (b.dataset.open) { const m = box.querySelector('.i18n-menu[data-m="' + b.dataset.open + '"]'); const was = !m.hidden; box.querySelectorAll('.i18n-menu').forEach(x => { x.hidden = true; }); m.hidden = was; box.querySelectorAll('[data-open]').forEach(x => x.setAttribute('aria-expanded', String(!m.hidden && x === b))); if (!m.hidden) { const f = m.querySelector('button.on') || m.querySelector('button'); if (f) f.focus(); } return; }
                if (b.dataset.lang) { setLang(b.dataset.lang); return; }
                if (b.dataset.theme) setTheme(b.dataset.theme);
            });
            document.addEventListener('click', e => { if (box && !box.contains(e.target)) box.querySelectorAll('.i18n-menu').forEach(x => { x.hidden = true; }); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && box) box.querySelectorAll('.i18n-menu').forEach(x => { x.hidden = true; }); });
        }
        const open = [...box.querySelectorAll('.i18n-menu')].filter(x => !x.hidden).map(x => x.dataset.m)[0] || '';
        const TH = (window.NexusApp && window.NexusApp.THEMES) || null;
        const cur = document.documentElement.dataset.themeChoice || document.documentElement.dataset.theme || 'neon';
        const L = { de: ['Sprache', 'Theme'], en: ['Language', 'Theme'], fr: ['Langue', 'Thème'], ja: ['言語', 'テーマ'] }[lang];
        const TN = { neon: { en: '🌃 Neon', fr: '🌃 Néon', ja: '🌃 ネオン' }, midnight: { en: '🌌 Midnight', fr: '🌌 Minuit', ja: '🌌 ミッドナイト' }, snow: { en: '❄️ Snow', fr: '❄️ Neige', ja: '❄️ スノー' }, sakura: { en: '🌸 Sakura', fr: '🌸 Sakura', ja: '🌸 さくら' }, auto: { en: '🌗 Automatic', fr: '🌗 Automatique', ja: '🌗 自動' } };
        box.innerHTML = '<div class="i18n-menu" role="menu" data-m="lang"' + (open === 'lang' ? '' : ' hidden') + '><b>' + L[0] + '</b>' + Object.entries(LANGS).map(([k, v]) => '<button type="button" data-lang="' + k + '" class="' + (k === lang ? 'on' : '') + '"><i>' + FLAG[k] + '</i>' + v[0] + '</button>').join('') + '</div>'
            + (TH ? '<div class="i18n-menu" role="menu" data-m="theme"' + (open === 'theme' ? '' : ' hidden') + '><b>' + L[1] + '</b>' + Object.entries(TH).map(([k, v]) => '<button type="button" data-theme="' + k + '" class="' + (k === cur ? 'on' : '') + '"><i class="th-sw">' + v[2].map(c => '<span style="background:' + c + '"></span>').join('') + '</i>' + ((TN[k] || {})[lang] || v[0]) + '<small>' + (v[1] === 'light' ? '☀' : v[1] === 'auto' ? '◐' : '☾') + '</small></button>').join('') + '</div>' : '')
            + '<span class="i18n-btns">' + (TH ? '<button type="button" class="i18n-fab" data-open="theme" title="' + L[1] + '" aria-label="' + L[1] + '" aria-haspopup="true" aria-expanded="' + (open === 'theme') + '">🎨</button>' : '') + '<button type="button" class="i18n-fab" data-open="lang" title="' + L[0] + '" aria-label="' + L[0] + ': ' + LANGS[lang][0] + '" aria-haspopup="true" aria-expanded="' + (open === 'lang') + '"><i>' + FLAG[lang] + '</i></button></span>';
    }
    function setTheme(k) {
        const app = window.NexusApp;
        if (!app || !app.THEMES[k]) return;
        const s = Object.assign({}, (window.VRCX && window.VRCX.ui) || {}, { 'ui.theme': k });
        if (window.VRCX) window.VRCX.ui = s;
        app.applyUi(s);
        corner();
        if (window.NexusUI) window.NexusUI.api('tools_settings_save', { data: JSON.stringify({ 'ui.theme': k }) }).catch(() => {});
    }

    function start() {
        if (ui['ui.lang']) { try { localStorage.setItem('nexusLang', lang); } catch (e) { /* egal */ } }   // für Fenster ohne Einstellungen (Overlay)
        apply();
        obs.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTR.concat(['value']) });
        corner();
        // Sprache im Hauptfenster gewechselt → andere Fenster (SteamVR-Overlay) ziehen nach
        window.addEventListener('storage', e => { if (e.key === 'nexusLang' && LANGS[e.newValue] && e.newValue !== lang) setLang(e.newValue, false); });
        // Theme-Liste kommt aus nexus-app.js (lädt später)
        setTimeout(corner, 0);
    }
    window.NexusI18n = {
        get lang() { return lang; }, LANGS, set: setLang, t: window.t, locale: () => LANGS[lang][1], refresh: () => walk(document.body), corner: () => { if (box) corner(); },
        /** Hilfe beim Übersetzen: alle sichtbaren deutschen Texte ohne Übersetzung */
        missing() {
            const out = new Set();
            const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            let n;
            while ((n = w.nextNode())) { if (n.parentElement && !n.parentElement.closest(SKIP) && (ORIG.get(n) || n.nodeValue).trim() && tr(ORIG.get(n) || n.nodeValue) === null) out.add((ORIG.get(n) || n.nodeValue).trim()); }
            document.querySelectorAll('[placeholder], [title]').forEach(el => ['placeholder', 'title'].forEach(a => { const v = el.dataset['i18n' + a] || el.getAttribute(a); if (v && tr(v) === null) out.add(v); }));
            return [...out];
        },
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
