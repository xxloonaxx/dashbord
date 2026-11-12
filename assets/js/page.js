/**
 * Gemeinsame Funktionen der eigenen Seiten:
 *   Dialoge ([data-open] / [data-fill] / [data-close]), Rückfragen (form[data-confirm], form[data-prompt]),
 *   Suche & Tag-Filter ([data-filter], [data-chips]), Sortierung ([data-sort]), „mehr anzeigen“ (.clamp), AJAX-Helfer.
 */
window.PageUI = (function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    let lastTrigger = null;

    function fill(form, data) {
        Object.entries(data || {}).forEach(([k, v]) => {
            const el = form.elements[k];
            if (!el) return;
            if (el instanceof RadioNodeList && el[0] && el[0].type === 'checkbox') { const vals = (Array.isArray(v) ? v : [v]).map(String); [...el].forEach(c => { c.checked = vals.includes(c.value); }); return; }
            if (el instanceof RadioNodeList) { el.value = v; return; }
            if (el.type === 'checkbox') { el.checked = !!v && v !== '0'; return; }
            el.value = Array.isArray(v) ? v.join(', ') : (v ?? '');
        });
    }

    function open(id, trigger) {
        const m = document.getElementById(id);
        if (!m) return;
        const form = m.querySelector('form.pm-box');
        if (form) {
            form.reset();
            $$('input[type=hidden][data-default]', form).forEach(h => { h.value = h.dataset.default; });
            if (trigger && trigger.dataset.fill) fill(form, JSON.parse(trigger.dataset.fill));
            $$('textarea[data-format], input[data-format]', form).forEach(t => t.feReset && t.feReset());
        }
        if (trigger && trigger.dataset.title) { const t = m.querySelector('.pm-title'); if (t) t.textContent = trigger.dataset.title; }
        lastTrigger = trigger || null;
        m.hidden = false;
        document.body.style.overflow = 'hidden';
        const first = m.querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), textarea, select');
        if (first) first.focus();
    }

    function close(m) {
        m.hidden = true;
        if (!$$('.pm:not([hidden])').length) document.body.style.overflow = '';
        if (lastTrigger) lastTrigger.focus();
    }

    document.addEventListener('click', e => {
        const t = e.target.closest('[data-open]');
        if (t) { e.preventDefault(); open(t.dataset.open, t); return; }
        const c = e.target.closest('[data-close]');
        if (c) { close(c.closest('.pm')); return; }
        if (e.target.classList && e.target.classList.contains('pm')) close(e.target);
    });
    document.addEventListener('keydown', e => {
        const m = $$('.pm:not([hidden])').pop();
        if (!m) return;
        if (e.key === 'Escape') close(m);
        if ((e.ctrlKey || e.metaKey) && (e.key === 'Enter' || e.key === 's')) {
            const f = e.target.closest && e.target.closest('form');
            if (f && m.contains(f)) { e.preventDefault(); f.requestSubmit(); }
        }
    });

    // Rückfragen vor dem Absenden
    document.addEventListener('submit', e => {
        const f = e.target;
        if (f.dataset.confirm && !confirm(f.dataset.confirm)) { e.preventDefault(); return; }
        if (f.dataset.prompt) {
            const v = prompt(f.dataset.prompt, f.dataset.promptValue || '');
            if (v === null || (!v.trim() && !f.dataset.promptEmpty)) { e.preventDefault(); return; }
            f.elements[f.dataset.promptField].value = v;
        }
        const btn = f.querySelector('button[type=submit], button:not([type])');
        if (btn) setTimeout(() => { if (!e.defaultPrevented) btn.disabled = true; }, 0);
    }, true);

    // Suche + Tags
    function applyFilter(sel) {
        const input = $('[data-filter="' + sel + '"]');
        const chips = $('[data-chips="' + sel + '"]');
        const q = input ? input.value.trim().toLowerCase() : '';
        const tag = chips ? (($('.chip.on', chips) || {}).dataset || {}).tag || '' : '';
        let shown = 0;
        $$(sel).forEach(el => {
            const ok = (!q || (el.dataset.search || el.textContent.toLowerCase()).includes(q)) && (!tag || (el.dataset.tags || '').includes('|' + tag + '|'));
            el.hidden = !ok;
            if (ok) shown++;
        });
        const empty = $('[data-empty-for="' + sel + '"]');
        if (empty) empty.hidden = shown > 0 || !$$(sel).length;
        // leere Monats-Überschriften im Tagebuch ausblenden
        $$('.jr-month').forEach(h => { let n = h.nextElementSibling, any = false; while (n && !n.classList.contains('jr-month')) { if (n.classList.contains('jr-entry') && !n.hidden) any = true; n = n.nextElementSibling; } h.hidden = !any; });
    }
    $$('[data-filter]').forEach(i => i.addEventListener('input', () => applyFilter(i.dataset.filter)));
    $$('[data-chips]').forEach(box => box.addEventListener('click', e => {
        const c = e.target.closest('.chip');
        if (!c) return;
        $$('.chip', box).forEach(x => x.classList.toggle('on', x === c));
        applyFilter(box.dataset.chips);
    }));

    // Sortierung (Notizen)
    $$('[data-sort]').forEach(sel => {
        const grid = $(sel.dataset.sort);
        const key = 'sort:' + location.search;
        try { const saved = localStorage.getItem(key); if (saved) sel.value = saved; } catch (e) {}
        const run = () => {
            const k = sel.value;
            const items = [...grid.children];
            items.sort((a, b) => {
                const p = (+b.dataset.pinned || 0) - (+a.dataset.pinned || 0);
                if (p) return p;
                if (k === 'title') return (a.dataset.title || '').localeCompare(b.dataset.title || '', 'de');
                if (k === 'color') return (a.dataset.color || '').localeCompare(b.dataset.color || '') || (+b.dataset.updated - +a.dataset.updated);
                return (+b.dataset[k] || 0) - (+a.dataset[k] || 0);
            }).forEach(x => grid.append(x));
            try { localStorage.setItem(key, k); } catch (e) {}
        };
        sel.addEventListener('change', run);
        if (sel.value !== 'updated') run();
    });

    // Lange Texte kürzen
    function clamps() {
        $$('.clamp:not(.done)').forEach(el => {
            el.classList.add('done');
            if (el.scrollHeight <= el.clientHeight + 4) return;
            el.classList.add('cut');
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'more-btn'; b.textContent = '▾ ganz anzeigen';
            b.addEventListener('click', () => { const o = el.classList.toggle('open'); b.textContent = o ? '▴ weniger' : '▾ ganz anzeigen'; });
            el.after(b);
        });
    }
    window.addEventListener('load', clamps);
    clamps();

    // Hinweis-Parameter aus der Adresse entfernen, Dialog nach dem Speichern ggf. wieder öffnen
    const url = new URL(location.href);
    const reopen = url.searchParams.get('open');
    if (url.searchParams.has('msg') || url.searchParams.has('error') || reopen) {
        ['msg', 'error', 'open'].forEach(k => url.searchParams.delete(k));
        history.replaceState(null, '', url);
    }
    if (reopen && /^\w+$/.test(reopen)) open(reopen);

    function ajax(fields) {
        const body = new URLSearchParams(Object.assign({}, fields, { ajax: '1' }));
        return fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body })
            .then(async r => {
                let d = null;
                try { d = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort vom Server (HTTP ' + r.status + '). Bitte Seite neu laden.'); }
                if (!r.ok || d.error) throw new Error(d.error || 'Fehler ' + r.status);
                return d;
            });
    }

    return { open, ajax, applyFilter };
})();
