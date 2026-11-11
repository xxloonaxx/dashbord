/* Startseite: Uhr, Suche (Web + lokal), Bearbeiten-Modus (Drag & Drop, Dialoge) */
(function () {
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
    const store = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };

    // ---------- Uhr & Begrüßung ----------
    const clock = $('#liveClock');
    const tick = () => { const n = new Date(); clock.textContent = [n.getHours(), n.getMinutes(), n.getSeconds()].map(x => String(x).padStart(2, '0')).join(':'); };
    tick(); setInterval(tick, 1000);
    const greet = $('#greeting');
    if (greet) {
        const h = new Date().getHours();
        const t = h < 5 ? 'Gute Nacht' : h < 11 ? 'Guten Morgen' : h < 17 ? 'Guten Tag' : h < 22 ? 'Guten Abend' : 'Gute Nacht';
        greet.textContent = t + ' // ' + new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' }) + ' // ' + greet.textContent;
    }

    // ---------- Suche ----------
    const ENGINES = { google: 'https://www.google.com/search', duckduckgo: 'https://duckduckgo.com/', ecosia: 'https://www.ecosia.org/search', bing: 'https://www.bing.com/search' };
    const NAMES = { google: 'Google', duckduckgo: 'DuckDuckGo', ecosia: 'Ecosia', bing: 'Bing' };
    const form = $('#searchForm'), input = $('#searchInput'), select = $('#search-engine'), btn = $('#searchBtn'), hint = $('#searchHint');
    const tiles = $$('.tile-wrap'), links = $$('.sub-item'), sections = $$('#sections .cat-section');
    const linkBox = $('#linkResults'), noRes = $('#noResults');

    function setEngine(engine) {
        const internal = engine === 'internal';
        btn.textContent = internal ? 'Öffnen' : 'Suchen';
        input.placeholder = internal ? 'Seiten & Links durchsuchen…' : 'Suchbegriff eingeben…';
        hint.innerHTML = internal ? 'Lokale Suche · <kbd>Enter</kbd> öffnet den ersten Treffer · <kbd>Esc</kbd> leeren'
            : 'Tippen zeigt sofort passende Seiten & Links · <kbd>/</kbd> Suche · <kbd>Esc</kbd> leeren · <kbd>Enter</kbd> Websuche (' + NAMES[engine] + ')';
        if (!internal) form.action = ENGINES[engine];
        store('searchEngine', engine);
    }
    function filter() {
        const terms = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
        let hits = 0, linkHits = 0;
        tiles.forEach(w => {
            const t = w.querySelector('.tile').dataset.searchText;
            const show = !terms.length || terms.every(x => t.includes(x));
            w.hidden = !show;
            if (show && terms.length) hits++;
        });
        sections.forEach(s => { s.hidden = terms.length > 0 && !s.querySelector('.tile-wrap:not([hidden])'); });
        links.forEach(a => {
            const show = terms.length > 0 && terms.every(x => a.dataset.searchText.includes(x));
            a.hidden = !show;
            if (show) linkHits++;
        });
        linkBox.hidden = !linkHits;
        $('#linkCount').textContent = linkHits;
        noRes.hidden = !(terms.length && !hits && !linkHits);
    }
    const first = () => [...tiles.filter(w => !w.hidden).map(w => w.querySelector('.tile')), ...links.filter(a => !a.hidden)][0];
    form.addEventListener('submit', e => {
        const q = input.value.trim();
        if (select.value === 'internal') {
            e.preventDefault();
            const hit = first();
            if (hit && q) { if (hit.target === '_blank') window.open(hit.href, '_blank', 'noopener'); else location.href = hit.href; }
        } else if (!q) e.preventDefault();
    });
    select.addEventListener('change', () => { setEngine(select.value); input.focus(); });
    input.addEventListener('input', filter);
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { input.value = ''; filter(); }
        if (e.key === 'ArrowDown') { const h = first(); if (h) { e.preventDefault(); h.focus(); } }
    });
    document.addEventListener('keydown', e => {
        if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) || $('.modal-overlay.open')) return;
        if (e.key === '/') { e.preventDefault(); input.focus(); }
        if (e.key === 'e' && window.IDX.canManage) location.href = window.IDX.edit ? 'index.php' : 'index.php?edit=1';
    });
    const saved = store('searchEngine');
    if (saved && select.querySelector('option[value="' + saved + '"]')) select.value = saved;
    setEngine(select.value);

    if (!window.IDX.edit) return;

    // ---------- Bearbeiten-Modus ----------
    const openModal = m => { $$('.modal-overlay.open').forEach(x => x.classList.remove('open')); m.classList.add('open'); const f = m.querySelector('input[type=text]'); if (f) f.focus(); };
    const closeAll = () => $$('.modal-overlay.open').forEach(x => x.classList.remove('open'));
    document.addEventListener('click', e => { if (e.target.closest('[data-close]') || e.target.classList.contains('modal-overlay')) closeAll(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });
    // Im Bearbeiten-Modus öffnet ein Klick auf die Kachel den Dialog statt der Seite
    $$('.tile').forEach(t => t.addEventListener('click', e => { e.preventDefault(); const b = t.parentElement.querySelector('[data-page-edit]'); if (b) b.click(); }));

    // Seiten-Dialog
    const pf = $('#pageForm');
    const F = n => pf.elements[n];
    function syncPageForm() {
        const tpl = (pf.querySelector('input[name=template]:checked') || {}).value;
        const isNew = !F('id').value;
        $('#urlRow').hidden = !((isNew && tpl === 'external') || pf.dataset.template === 'external');
        F('url').required = !$('#urlRow').hidden;
        $('#rolesRow').hidden = F('access').value !== 'roles';
    }
    $$('[data-open="newPage"]').forEach(b => b.addEventListener('click', () => {
        pf.reset();
        F('id').value = '';
        pf.dataset.template = '';
        $('#pageFormTitle').textContent = '// Neue Seite';
        $('#tplPick').hidden = false; $('#fileNote').hidden = true; $('#pageDelete').hidden = true;
        syncPageForm();
        openModal($('#pageModal'));
    }));
    $$('[data-page-edit]').forEach(b => b.addEventListener('click', () => {
        const p = JSON.parse(b.dataset.pageEdit);
        pf.reset();
        F('id').value = p.id;
        pf.dataset.template = p.template;
        $('#pageFormTitle').textContent = '// ' + p.title + ' bearbeiten';
        $('#tplPick').hidden = true;
        $('#fileNote').hidden = p.kind !== 'file';
        $('#pageDelete').hidden = p.kind !== 'custom';
        ['title', 'description', 'icon', 'url'].forEach(k => F(k).value = p[k] || '');
        F('category_id').value = p.category_id || 0;
        F('access').value = p.access;
        F('hidden').checked = p.hidden == 1;
        F('show_live').checked = p.show_live == 1;
        const allowed = String(p.allowed_roles || '').split(',');
        $$('input[name="roles[]"]', pf).forEach(cb => cb.checked = allowed.includes(cb.value));
        syncPageForm();
        openModal($('#pageModal'));
    }));
    pf.addEventListener('change', syncPageForm);
    // "example.org" ohne https:// akzeptieren (sonst blockiert der Browser das Speichern)
    F('url').addEventListener('blur', () => { const v = F('url').value.trim(); if (v && !/^https?:\/\//i.test(v)) F('url').value = 'https://' + v; });
    // Titel-Vorschlag aus der Vorlage
    $$('input[name=template]', pf).forEach(r => r.addEventListener('change', () => { if (!F('icon').value) F('icon').placeholder = r.parentElement.querySelector('b').textContent.trim().split(' ')[0]; }));

    // Kategorie-Dialog
    const cf = $('#catForm');
    $$('[data-cat-edit]').forEach(b => b.addEventListener('click', () => {
        cf.reset();
        const c = b.dataset.catEdit === 'new' ? null : JSON.parse(b.dataset.catEdit);
        cf.elements.id.value = c ? c.id : '';
        cf.elements.name.value = c ? c.name : '';
        cf.elements.icon.value = c ? c.icon : '';
        $('#catFormTitle').textContent = c ? '// Kategorie bearbeiten' : '// Neue Kategorie';
        openModal($('#catModal'));
    }));

    // ---------- Sortieren (Drag & Drop) ----------
    const state = $('#saveState');
    let dragged = null;
    function saveOrder() {
        const order = $$('#sections .cat-section').map(s => ({ cat: +s.dataset.cat, pages: $$('.tile-wrap', s).map(w => +w.dataset.id) }));
        state.textContent = 'speichere…'; state.className = 'saving';
        const body = new URLSearchParams({ action: 'reorder', order: JSON.stringify(order) });
        fetch('index.php', { method: 'POST', body, credentials: 'same-origin' })
            .then(r => r.json()).then(d => { state.textContent = d.ok ? '✓ gespeichert' : 'Fehler beim Speichern'; state.className = d.ok ? 'saved' : ''; })
            .catch(() => { state.textContent = 'Fehler beim Speichern'; state.className = ''; });
    }
    $$('.tile-wrap').forEach(w => {
        w.addEventListener('dragstart', e => { dragged = w; w.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', w.dataset.id); });
        w.addEventListener('dragend', () => { w.classList.remove('dragging'); $$('.drop-active').forEach(x => x.classList.remove('drop-active')); if (dragged) saveOrder(); dragged = null; });
    });
    $$('.cards').forEach(list => {
        list.addEventListener('dragover', e => {
            if (!dragged) return;
            e.preventDefault();
            list.classList.add('drop-active');
            const after = $$('.tile-wrap:not(.dragging)', list).find(w => { const r = w.getBoundingClientRect(); return e.clientY < r.top + r.height / 2 || (e.clientY < r.bottom && e.clientX < r.left + r.width / 2); });
            const hint = list.querySelector('.drop-hint');
            if (hint) hint.remove();
            if (after) list.insertBefore(dragged, after); else list.appendChild(dragged);
        });
        list.addEventListener('dragleave', e => { if (!list.contains(e.relatedTarget)) list.classList.remove('drop-active'); });
        list.addEventListener('drop', e => e.preventDefault());
    });
    // Kategorien verschieben
    $$('[data-move-cat]').forEach(b => b.addEventListener('click', () => {
        const sec = b.closest('.cat-section');
        if (b.dataset.moveCat === 'up' && sec.previousElementSibling) sec.parentElement.insertBefore(sec, sec.previousElementSibling);
        if (b.dataset.moveCat === 'down' && sec.nextElementSibling) sec.parentElement.insertBefore(sec.nextElementSibling, sec);
        saveOrder();
    }));
    if (location.hash === '#new') setTimeout(() => $('[data-open="newPage"]').click(), 200);
    // Direkt zur Seite springen, wenn ?edit=1#page-ID
    if (location.hash.startsWith('#page-')) { const b = document.querySelector(location.hash + ' [data-page-edit]'); if (b) setTimeout(() => b.click(), 200); }
})();
