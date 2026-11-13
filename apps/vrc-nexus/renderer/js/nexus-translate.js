/* VRC Nexus – Übersetzen (DeepL / Google) und Chatbox-Extras. Gemeinsam für Dashboard (assets/js) und Apps (renderer/js). */
(function () {
    if (!window.VRCX || !window.VRCX.connected && !document.getElementById('ncChat') && !document.getElementById('clientPane')) return;
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        Object.entries(data || {}).forEach(([k, v]) => body.append(k, v ?? ''));
        return fetch('nexus.php', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body }).then(async r => {
            let d; try { d = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort (HTTP ' + r.status + ').'); }
            if (!r.ok || d.error) throw new Error(d.error || 'Fehler ' + r.status);
            return d;
        });
    }
    function toast(msg, bad) {
        if (window.NexusUI) return window.NexusUI.toast(msg, bad);
        $$('.vx-toast').forEach(x => x.parentNode.removeChild(x));
        const t = document.createElement('div'); t.className = 'vx-toast' + (bad ? ' bad' : ''); t.textContent = msg; document.body.append(t);
        setTimeout(() => t.classList.add('out'), 2600); setTimeout(() => t.parentNode && t.parentNode.removeChild(t), 3200);
    }
    let st = null;
    const status = () => (st ? Promise.resolve(st) : api('tr_status').then(d => (st = d)).catch(() => (st = { provider: '', prefs: {}, langs: [] })));
    const langOpts = (sel) => (st.langs || []).map(l => '<option value="' + esc(l.code) + '"' + (l.code === sel ? ' selected' : '') + '>' + esc(l.name) + '</option>').join('');

    // ======================= Markieren → 🌐 =======================
    let bubble = null, pop = null;
    function hideBubble() { if (bubble) { bubble.parentNode && bubble.parentNode.removeChild(bubble); bubble = null; } }
    function selectionText() {
        const s = window.getSelection();
        const t = s ? String(s).trim() : '';
        if (!t || t.length < 2 || t.length > 3000) return '';
        const a = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentNode);
        if (a && a.closest && a.closest('input, textarea, .tr-pop, .tr-bubble')) return '';
        return t;
    }
    function showPopup(text, x, y) {
        if (pop) pop.parentNode && pop.parentNode.removeChild(pop);
        pop = document.createElement('div');
        pop.className = 'tr-pop';
        pop.innerHTML = '<div class="tr-head">🌐 Übersetzung <select class="tr-lang"></select><button type="button" class="tr-x" title="Schließen">×</button></div><div class="tr-src">' + esc(text.slice(0, 400)) + (text.length > 400 ? ' …' : '')
            + '</div><div class="tr-out">⏳ übersetze …</div><div class="tr-foot"><span class="tr-by"></span><button type="button" class="btn btn-sm tr-copy">⧉ Kopieren</button></div>';
        document.body.append(pop);
        pop.style.left = Math.max(8, Math.min(x, window.scrollX + document.documentElement.clientWidth - 380)) + 'px';
        pop.style.top = (y + 12) + 'px';
        const run = target => {
            pop.querySelector('.tr-out').textContent = '⏳ übersetze …';
            api('translate', { text, target }).then(r => {
                pop.querySelector('.tr-out').textContent = r.text;
                pop.querySelector('.tr-by').textContent = (r.provider === 'deepl' ? 'DeepL' : 'Google') + (r.detected ? ' · aus ' + r.detected : '');
            }).catch(e => { pop.querySelector('.tr-out').innerHTML = '<span class="nx-bad">⚠ ' + esc(e.message) + '</span>'; });
        };
        status().then(() => {
            const sel = pop.querySelector('.tr-lang');
            sel.innerHTML = langOpts((st.prefs || {}).ui_target || 'DE');
            sel.onchange = () => run(sel.value);
            run(sel.value);
        });
        pop.querySelector('.tr-x').onclick = () => { pop.parentNode.removeChild(pop); pop = null; };
        pop.querySelector('.tr-copy').onclick = () => navigator.clipboard && navigator.clipboard.writeText(pop.querySelector('.tr-out').textContent).then(() => toast('Kopiert.'));
    }
    document.addEventListener('mouseup', e => {
        if (e.target.closest('.tr-bubble, .tr-pop')) return;
        setTimeout(() => {
            hideBubble();
            const t = selectionText();
            if (!t || !st || !st.provider || (st.prefs && st.prefs.select_button === false)) return;
            const r = window.getSelection().getRangeAt(0).getBoundingClientRect();
            bubble = document.createElement('button');
            bubble.type = 'button'; bubble.className = 'tr-bubble'; bubble.textContent = '🌐'; bubble.title = 'Übersetzen (Alt+T)';
            bubble.style.left = (window.scrollX + r.right + 4) + 'px'; bubble.style.top = (window.scrollY + r.top - 6) + 'px';
            bubble.onmousedown = ev => ev.preventDefault();
            bubble.onclick = () => { hideBubble(); showPopup(t, window.scrollX + r.left, window.scrollY + r.bottom); };
            document.body.append(bubble);
        }, 10);
    });
    document.addEventListener('keydown', e => {
        if (e.altKey && e.key.toLowerCase() === 't') {
            const t = selectionText();
            if (!t) return;
            e.preventDefault();
            const r = window.getSelection().getRangeAt(0).getBoundingClientRect();
            hideBubble(); showPopup(t, window.scrollX + r.left, window.scrollY + r.bottom);
        }
        if (e.key === 'Escape' && pop) { pop.parentNode.removeChild(pop); pop = null; }
    });
    document.addEventListener('mousedown', e => { if (pop && !e.target.closest('.tr-pop')) { pop.parentNode.removeChild(pop); pop = null; } });

    // ======================= Einstellungs-Bereich =======================
    function panel(box) {
        box.innerHTML = '<div class="vx-loading">Lade …</div>';
        st = null;
        status().then(() => {
            const p = st.prefs || {};
            box.innerHTML = '<p class="vx-small">Übersetzt Chatbox-Nachrichten und Texte in VRC Nexus. Die Texte gehen dafür an den gewählten Anbieter. Schlüssel werden verschlüsselt gespeichert.</p>'
                + '<div class="tr-keys">'
                + keyRow('deepl', 'DeepL', st.deepl, 'deepl.com → „DeepL API Free“: 500.000 Zeichen/Monat kostenlos')
                + keyRow('google', 'Google Cloud Translation', st.google, 'console.cloud.google.com → „Cloud Translation API“ aktivieren → API-Schlüssel')
                + '</div><form class="tr-prefs">'
                + '<label class="field">Bevorzugter Anbieter</label><select name="provider"><option value="auto">automatisch (DeepL, sonst Google)</option><option value="deepl"' + (p.provider === 'deepl' ? ' selected' : '') + '>DeepL</option><option value="google"' + (p.provider === 'google' ? ' selected' : '') + '>Google</option></select>'
                + '<label class="field">Chatbox übersetzen nach</label><select name="chat_target">' + langOpts(p.chat_target || 'EN-US') + '</select>'
                + '<label class="field">Was in die Chatbox kommt</label><select name="chat_mode"><option value="only">nur die Übersetzung</option><option value="both"' + (p.chat_mode === 'both' ? ' selected' : '') + '>Original / Übersetzung</option><option value="both_nl"' + (p.chat_mode === 'both_nl' ? ' selected' : '') + '>Original und Übersetzung in zwei Zeilen</option></select>'
                + '<label class="field">Texte in VRC Nexus übersetzen nach</label><select name="ui_target">' + langOpts(p.ui_target || 'DE') + '</select>'
                + '<label class="vx-auto"><input type="checkbox" name="chat_default"' + (p.chat_default ? ' checked' : '') + '> Chatbox-Nachrichten standardmäßig übersetzen</label>'
                + '<label class="vx-auto"><input type="checkbox" name="select_button"' + (p.select_button !== false ? ' checked' : '') + '> 🌐-Knopf beim Markieren von Text (sonst nur Alt+T)</label>'
                + '</form><p class="vx-small">Tipp: Text irgendwo markieren → 🌐 klicken oder <kbd>Alt</kbd>+<kbd>T</kbd>.</p>';
            box.querySelector('.tr-prefs').addEventListener('change', e => {
                const f = e.currentTarget, d = {};
                ['provider', 'chat_target', 'chat_mode', 'ui_target'].forEach(k => { d[k] = f[k].value; });
                ['chat_default', 'select_button'].forEach(k => { d[k] = f[k].checked ? '1' : '0'; });
                api('tr_prefs', d).then(r => { st = r; toast(r.msg); syncChat(); }).catch(x => toast(x.message, true));
            });
            box.querySelectorAll('[data-trsave]').forEach(b => b.addEventListener('click', () => {
                const pr = b.dataset.trsave, inp = box.querySelector('[data-trkey="' + pr + '"]');
                api('tr_key', { provider: pr, key: b.dataset.del ? '' : inp.value }).then(r => { st = r; toast(r.msg); panel(box); syncChat(); }).catch(x => toast(x.message, true));
            }));
            box.querySelectorAll('[data-trtest]').forEach(b => b.addEventListener('click', () => {
                b.disabled = true;
                api('tr_test', { provider: b.dataset.trtest }).then(r => toast(r.msg)).catch(x => toast(x.message, true)).finally(() => { b.disabled = false; });
            }));
        });
    }
    function keyRow(id, name, masked, hint) {
        return '<div class="tr-key"><b>' + esc(name) + '</b> ' + (masked ? '<span class="nx-ok">✔ ' + esc(masked) + '</span>' : '<span class="vx-small">kein Schlüssel</span>')
            + '<div class="nx-row"><input type="password" data-trkey="' + id + '" placeholder="' + (masked ? 'neuen Schlüssel eintragen …' : 'Schlüssel einfügen …') + '" autocomplete="off" style="flex:1;min-width:200px">'
            + '<button type="button" class="btn btn-sm btn-gold" data-trsave="' + id + '">Speichern</button>'
            + (masked ? '<button type="button" class="btn btn-sm" data-trtest="' + id + '">Testen</button><button type="button" class="btn btn-sm btn-danger" data-trsave="' + id + '" data-del="1">Entfernen</button>' : '')
            + '</div><div class="vx-small">' + esc(hint) + '</div></div>';
    }

    // ======================= Chatbox-Extras (Spiel & OSC / Client) =======================
    const EMOJI = ['🦊', '🐺', '🐾', '❤️', '✨', '😂', '🥺', '👋', '🎉', '💤', '🎮', '🎧', '☕', '🍕', '🔥', '👍', '(｡•ᴗ•｡)', '(╯°□°)╯', 'ʕ•ᴥ•ʔ', '¯\\_(ツ)_/¯', 'OwO', 'UwU'];
    let chatDone = false;
    function enhanceChat() {
        const chat = document.getElementById('ncChat');
        if (!chat || chat.dataset.tr) return;
        chat.dataset.tr = '1';
        const ta = chat.querySelector('textarea[name=text]');
        ta.removeAttribute('maxlength');
        ta.placeholder = 'Text für die Chatbox – /n = neue Zeile, Platzhalter wie {zeit} {welt} {spieler} {freunde} gehen auch';
        const extra = document.createElement('div');
        extra.className = 'tr-chat';
        extra.innerHTML = '<div class="tr-emoji">' + EMOJI.map(e => '<button type="button" data-emo="' + esc(e) + '">' + esc(e) + '</button>').join('') + '</div>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="translate"> 🌐 übersetzen nach</label><select name="target" class="tr-target"></select>'
            + '<button type="button" class="btn btn-sm tr-preview" title="Übersetzung vorab ansehen">👁 Vorschau</button></div>'
            + '<div class="nc-row"><label class="check small"><input type="checkbox" name="split" checked> lange Nachrichten aufteilen</label>'
            + '<label class="check small"><input type="checkbox" name="autotyping" checked> Tipp-Anzeige beim Schreiben</label>'
            + '<label class="small">senden</label><select name="delay"><option value="0">sofort</option><option value="10">in 10 s</option><option value="30">in 30 s</option><option value="60">in 1 min</option><option value="300">in 5 min</option><option value="900">in 15 min</option></select>'
            + '<button type="button" class="btn btn-sm" data-clear title="Chatbox in VRChat leeren">🧹 Chatbox leeren</button></div>'
            + '<div class="tr-out vx-small" hidden></div><div class="tr-hist"></div>';
        chat.querySelector('.nc-row').before(extra);
        chat.addEventListener('click', e => {
            const em = e.target.closest('[data-emo]');
            if (em) { const p = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0, p) + em.dataset.emo + ta.value.slice(p); ta.focus(); ta.dispatchEvent(new Event('input')); }
            const h = e.target.closest('[data-hist]');
            if (h) { ta.value = h.dataset.hist; ta.focus(); ta.dispatchEvent(new Event('input')); }
            if (e.target.closest('[data-clear]')) send({ kind: 'chatbox', clear: '1', text: ' ' }).then(() => toast('Chatbox geleert.'));
            if (e.target.closest('.tr-preview')) {
                const out = extra.querySelector('.tr-out');
                out.hidden = false; out.textContent = '⏳ …';
                api('translate', { text: ta.value, target: chat.target.value }).then(r => { out.textContent = '🌐 ' + r.text; }).catch(x => { out.textContent = '⚠ ' + x.message; });
            }
        });
        // Tipp-Anzeige automatisch
        let typingOn = false, typingT = null;
        ta.addEventListener('input', () => {
            const n = chat.querySelector('#ncChatN');
            if (n) n.textContent = [...ta.value].length + (chat.split.checked ? ' Zeichen' : ' / 144');
            if (!chat.autotyping.checked) return;
            if (!typingOn && ta.value.trim()) { typingOn = true; send({ kind: 'typing', on: '1' }).catch(() => {}); }
            clearTimeout(typingT);
            typingT = setTimeout(() => { if (typingOn) { typingOn = false; send({ kind: 'typing', on: '0' }).catch(() => {}); } }, 4000);
        });
        chat.addEventListener('submit', () => { typingOn = false; clearTimeout(typingT); remember(ta.value); }, true);
        syncChat();
        renderHist();
    }
    function send(d) { return api(window.VRCX.app ? 'client_osc' : 'client_osc', d); }
    function syncChat() {
        const chat = document.getElementById('ncChat');
        if (!chat || !chat.target) return;
        status().then(() => {
            const p = st.prefs || {};
            chat.target.innerHTML = langOpts(p.chat_target || 'EN-US');
            chat.translate.checked = !!p.chat_default && !!st.provider;
            chat.translate.disabled = !st.provider;
            chat.translate.parentNode.title = st.provider ? '' : 'Erst einen DeepL- oder Google-Schlüssel eintragen';
        });
    }
    // Verlauf der eigenen Chatbox-Nachrichten (lokal im Browser bzw. in der App)
    const HKEY = 'vrcx-chat-hist';
    const hist = () => { try { return JSON.parse(localStorage.getItem(HKEY) || '[]'); } catch (e) { return []; } };
    function remember(t) { t = String(t || '').trim(); if (!t) return; const h = hist().filter(x => x !== t); h.unshift(t); try { localStorage.setItem(HKEY, JSON.stringify(h.slice(0, 20))); } catch (e) { /* voll */ } setTimeout(renderHist, 50); }
    function renderHist() {
        const box = document.querySelector('#ncChat .tr-hist');
        if (!box) return;
        const h = hist();
        box.innerHTML = h.length ? '<span class="vx-small">Zuletzt:</span> ' + h.slice(0, 8).map(t => '<button type="button" class="chip" data-hist="' + esc(t) + '" title="' + esc(t) + '">' + esc(t.length > 28 ? t.slice(0, 27) + '…' : t) + '</button>').join('') : '';
    }
    // Der Spiel-&-OSC-Bereich wird erst beim Öffnen gebaut → beobachten
    new MutationObserver(() => { if (document.getElementById('ncChat') && !document.getElementById('ncChat').dataset.tr) enhanceChat(); }).observe(document.body, { childList: true, subtree: true });
    enhanceChat();
    status();

    { const tp = document.getElementById('trPanel'); if (tp && !tp.closest('#setBox')) panel(tp); }
    window.NexusTr = { panel, translate: (text, target) => api('translate', { text, target }), refresh: () => { st = null; return status(); } };
})();
