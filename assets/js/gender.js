/**
 * Gender-Modus: gendert die Oberflächentexte des Dashboards (z. B. „Freunde“ → „Freund*innen“).
 * Einstellung pro Browser; Umschalter „⚧“ neben dem Namen oben rechts. Eigene Inhalte (Notizen, Bios …) bleiben unverändert.
 */
(function () {
    const MODES = { off: ['Aus (Standard)', ''], star: ['Sternchen – Freund*innen', '*'], colon: ['Doppelpunkt – Freund:innen', ':'], gap: ['Unterstrich – Freund_innen', '_'],
                    dot: ['Mediopunkt – Freund·innen', '·'], binnen: ['Binnen-I – FreundInnen', 'I'] };
    let mode = 'off';
    try { mode = localStorage.getItem('gender-mode') || 'off'; } catch (e) {}
    if (!MODES[mode]) mode = 'off';

    // [Muster, Wortstamm, Endung]: Endung „innen“ (Mehrzahl) oder „in“ (Einzahl)
    const WORDS = [
        ['Freundesliste', 'Freund', 'innenliste'], ['Freundeskreis', 'Freund', 'innenkreis'], ['Freunden', 'Freund', 'innen'], ['Freunde', 'Freund', 'innen'], ['Freund', 'Freund', 'in'],
        ['Benutzersuche', 'Benutzer', 'innensuche'], ['Benutzer suchen', 'Benutzer', 'innen suchen'], ['Benutzern', 'Benutzer', 'innen'], ['Benutzer', 'Benutzer', 'in'],
        ['Nutzern', 'Nutzer', 'innen'], ['Nutzer', 'Nutzer', 'innen'],
        ['Administratoren', 'Administrator', 'innen'], ['Administrator', 'Administrator', 'in'], ['Moderatoren', 'Moderator', 'innen'], ['Moderator', 'Moderator', 'in'],
        ['Redakteure', 'Redakteur', 'innen'], ['Redakteur', 'Redakteur', 'in'], ['Besitzer', 'Besitzer', 'in'], ['Autoren', 'Autor', 'innen'], ['Autor', 'Autor', 'in'],
        ['Künstler', 'Künstler', 'innen'], ['Teilnehmer', 'Teilnehmer', 'innen'], ['Ersteller', 'Ersteller', 'in'], ['Booster', 'Booster', 'innen'],
    ];
    let RULES = [];
    function build() {
        const sep = MODES[mode][1];
        RULES = WORDS.map(([w, stem, end]) => {
            const suffix = sep === 'I' ? 'I' + end.slice(1) : sep + end;
            // nicht doppelt gendern („Künstler*in“ bleibt) und nur ganze Wörter
            return [new RegExp('(^|[^\\wÄÖÜäöüß])' + w.replace(/ /g, '\\s') + '(?![\\wÄÖÜäöüß*:_·])(?!I?n(nen)?\\b)', 'g'), '$1' + stem + suffix];
        });
    }
    const SKIP = 'SCRIPT,STYLE,TEXTAREA,INPUT,SELECT,OPTION,CODE,PRE,KBD';
    function skip(el) {
        for (let n = el; n && n !== document.body; n = n.parentElement) {
            if (SKIP.includes(n.tagName) && n.tagName !== 'OPTION') return true;
            if (n.classList && (n.classList.contains('fmt') || n.classList.contains('gm-ui'))) return true;
            if (n.hasAttribute && (n.hasAttribute('data-nogender') || n.isContentEditable)) return true;
        }
        return false;
    }
    const apply = s => { let o = s; for (const [re, rep] of RULES) o = o.replace(re, rep); return o; };
    const orig = new WeakMap();
    function walk(root) {
        if (mode === 'off' || !root) return;
        const tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: t => t.data.trim() && !skip(t.parentElement) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT });
        const list = [];
        while (tw.nextNode()) list.push(tw.currentNode);
        list.forEach(t => { const n = apply(t.data); if (n !== t.data) { if (!orig.has(t)) orig.set(t, t.data); t.data = n; } });
        (root.querySelectorAll ? root.querySelectorAll('[placeholder],[title],[aria-label],option') : []).forEach(el => {
            if (el.closest('.fmt,[data-nogender],.gm-ui')) return;
            ['placeholder', 'title', 'aria-label'].forEach(a => { const v = el.getAttribute(a); if (v) { const n = apply(v); if (n !== v) el.setAttribute(a, n); } });
            if (el.tagName === 'OPTION') { const n = apply(el.textContent); if (n !== el.textContent) el.textContent = n; }
        });
    }
    let queued = false;
    const obs = new MutationObserver(muts => {
        if (mode === 'off' || queued) return;
        queued = true;
        requestAnimationFrame(() => { queued = false; muts.forEach(m => m.addedNodes.forEach(n => { if (n.nodeType === 1) walk(n); else if (n.nodeType === 3 && n.parentElement) walk(n.parentElement); })); });
    });
    function set(m) {
        if (!MODES[m]) return;
        const was = mode;
        mode = m;
        try { localStorage.setItem('gender-mode', m); } catch (e) {}
        if (was !== 'off' && m !== was) { location.reload(); return; }   // Rückumstellung sauber über Neuladen
        build();
        walk(document.body);
        renderButton();
    }

    // Umschalter
    function renderButton() {
        let b = document.querySelector('.gm-btn');
        if (!b) {
            b = document.createElement('button');
            b.type = 'button';
            b.className = 'gm-btn gm-ui';
            const host = document.querySelector('.auth-corner') || document.querySelector('.user-bar');
            if (host) host.append(b); else { b.classList.add('gm-float'); document.body.append(b); }
            b.addEventListener('click', e => { e.stopPropagation(); menu(); });
        }
        b.textContent = '⚧';
        b.title = 'Gender-Modus: ' + MODES[mode][0];
        b.setAttribute('aria-label', b.title);
        b.classList.toggle('on', mode !== 'off');
    }
    function menu() {
        let m = document.querySelector('.gm-menu');
        if (m) { m.remove(); return; }
        m = document.createElement('div');
        m.className = 'gm-menu gm-ui';
        m.innerHTML = '<b>⚧ Gender-Modus</b>' + Object.entries(MODES).map(([k, [l]]) => '<label><input type="radio" name="gm" value="' + k + '"' + (k === mode ? ' checked' : '') + '> ' + l + '</label>').join('')
            + '<small>Gilt für die Texte des Dashboards in diesem Browser. Eigene Inhalte bleiben, wie sie sind.</small>';
        document.body.append(m);
        const r = document.querySelector('.gm-btn').getBoundingClientRect();
        m.style.top = Math.min(window.innerHeight - m.offsetHeight - 10, r.bottom + 6) + 'px';
        m.style.right = Math.max(10, window.innerWidth - r.right) + 'px';
        m.addEventListener('change', e => { set(e.target.value); m.remove(); });
        setTimeout(() => document.addEventListener('click', function off(e) { if (!m.contains(e.target)) { m.remove(); document.removeEventListener('click', off); } }), 0);
    }
    const css = document.createElement('style');
    css.textContent = '.gm-btn{margin-left:6px;background:rgba(4,6,9,.85);border:1px solid rgba(56,213,230,.35);color:#e2e8f0;font-size:.85rem;padding:3px 8px;cursor:pointer;line-height:1.2}'
        + '.gm-btn.on{border-color:#bd93f9;color:#bd93f9;box-shadow:0 0 8px rgba(189,147,249,.4)}.gm-btn:hover{border-color:#38d5e6}.gm-float{position:fixed;left:12px;bottom:12px;z-index:60}'
        + '.gm-menu{position:fixed;z-index:3000;background:rgba(10,16,24,.97);border:1px solid #bd93f9;padding:12px 14px;display:flex;flex-direction:column;gap:6px;font-size:.82rem;color:#e2e8f0;min-width:250px;box-shadow:0 0 20px rgba(189,147,249,.25)}'
        + '.gm-menu b{color:#bd93f9;letter-spacing:1px}.gm-menu label{display:flex;gap:6px;align-items:center;cursor:pointer}.gm-menu small{color:#64748b;font-size:.7rem;max-width:250px}';
    document.head.append(css);

    window.GenderMode = { set, get: () => mode, modes: MODES };
    const start = () => { build(); walk(document.body); renderButton(); obs.observe(document.body, { childList: true, subtree: true }); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
