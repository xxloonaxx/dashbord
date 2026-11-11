/* Kalender – Detailfenster, Termin-Formular, Drag & Drop, Filter, Tastatur */
(function () {
    const C = window.CAL || { items: {} };
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
    if (C.admin) document.body.classList.add('is-admin');

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmtDay = d => new Date(d + 'T12:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    // ---------- Dialoge ----------
    let lastFocus = null;
    function open(m) { lastFocus = document.activeElement; $$('.modal-overlay.open').forEach(x => x.classList.remove('open')); m.classList.add('open'); const f = m.querySelector('input:not([type=hidden]), button'); if (f) f.focus(); }
    function closeAll() { $$('.modal-overlay.open').forEach(x => x.classList.remove('open')); if (lastFocus) lastFocus.focus(); }
    document.addEventListener('click', e => {
        if (e.target.closest('[data-close]') || e.target.classList.contains('modal-overlay')) closeAll();
    });

    // ---------- Detailansicht ----------
    const typeLabel = { event: 'Termin', holiday: 'Feiertag', countdown: 'Countdown' };
    function showDetail(key) {
        const it = C.items[key];
        if (!it) return;
        const m = $('#detailModal');
        $('#dType').textContent = (typeLabel[it.type] || '') + (it.category && it.type === 'event' ? ' · ' + it.category : '') + (it.private ? ' · 🔒 privat' : '');
        $('#dTitle').textContent = (it.icon ? it.icon + ' ' : '') + it.title;
        $('#dTitle').style.setProperty('--dc', it.color);
        $('#dWhen').textContent = it.when;
        const rows = [];
        if (it.recurrence && it.recurrence !== 'Einmalig') rows.push(['Wiederholung', esc(it.recurrence)]);
        if (it.location) rows.push(['Ort', esc(it.location) + ' · <a href="https://www.openstreetmap.org/search?query=' + encodeURIComponent(it.location) + '" target="_blank" rel="noopener noreferrer">Karte ↗</a>']);
        if (it.url) rows.push(['Link', '<a href="' + esc(it.url) + '" target="_blank" rel="noopener noreferrer">' + esc(it.url) + '</a>']);
        if (it.description) rows.push([it.type === 'countdown' ? 'Notiz' : 'Notizen', esc(it.description)]);
        if (it.type === 'countdown') rows.push(['Hinweis', 'Kommt von der Countdown-Seite und wird dort verwaltet.']);
        $('#dList').innerHTML = rows.map(([k, v]) => '<dt>' + k + '</dt><dd>' + v + '</dd>').join('');

        const a = $('#dActions');
        a.innerHTML = '';
        if (it.type === 'countdown') a.innerHTML = '<a class="btn btn-sm btn-gold" href="countdown.php">⏳ Zum Countdown</a>';
        if (it.type === 'event' && C.admin) {
            a.innerHTML = '<button type="button" class="btn btn-sm btn-gold" data-act="edit">✎ Bearbeiten</button>'
                + '<button type="button" class="btn btn-sm" data-act="dup">⧉ Duplizieren</button>'
                + (it.rec !== 'none' ? '<button type="button" class="btn btn-sm btn-danger" data-act="del-one">Nur diesen Termin löschen</button>' : '')
                + '<button type="button" class="btn btn-sm btn-danger" data-act="del-all">' + (it.rec !== 'none' ? 'Ganze Serie löschen' : 'Löschen') + '</button>';
            a.onclick = e => {
                const act = (e.target.closest('[data-act]') || {}).dataset?.act;
                if (act === 'edit') openForm(it, false);
                if (act === 'dup') openForm(it, true);
                if (act === 'del-one' && confirm('Nur den Termin am ' + fmtDay(it.day) + ' löschen?')) del(it.id, 'single', it.day);
                if (act === 'del-all' && confirm(it.rec !== 'none' ? 'Die ganze Serie „' + it.title + '“ löschen?' : 'Termin „' + it.title + '“ löschen?')) del(it.id, 'all', '');
            };
        }
        open(m);
    }
    function del(id, scope, day) {
        const f = $('#deleteForm');
        f.elements.id.value = id; f.elements.scope.value = scope; f.elements.day.value = day;
        f.submit();
    }

    document.addEventListener('click', e => {
        const el = e.target.closest('[data-key]');
        if (el && !e.target.closest('.modal-overlay')) { e.stopPropagation(); showDetail(el.dataset.key); return; }
        if (el && e.target.closest('#dayModal')) { showDetail(el.dataset.key); }
    });

    // ---------- "+N mehr" / Tag ----------
    function showDay(day) {
        const cell = $('.day[data-day="' + day + '"]');
        const keys = cell ? $$('[data-key]', cell).filter(x => !x.closest('.layer-hidden, .cat-hidden')).map(x => x.dataset.key) : [];
        $('#dayTitle').textContent = '// ' + fmtDay(day);
        $('#dayList').innerHTML = keys.length ? keys.map(k => {
            const it = C.items[k];
            return '<button type="button" class="side-item" style="--c:' + esc(it.color) + '" data-key="' + esc(k) + '"><span>' + esc(it.when) + '</span><b>' + esc((it.icon ? it.icon + ' ' : '') + it.title) + '</b></button>';
        }).join('') : '<div class="side-empty">Keine Einträge.</div>';
        const nb = $('#dayNew');
        if (nb) nb.onclick = () => openForm({ start_date: day, end_date: day }, true);
        open($('#dayModal'));
    }
    $$('[data-more]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); showDay(b.dataset.more); }));

    // ---------- Termin-Formular ----------
    const form = $('#eventForm');
    let prevStart = null;
    function syncForm() {
        if (!form) return;
        form.classList.toggle('all-day', form.all_day.checked);
        form.classList.toggle('no-rec', form.recurrence.value === 'none');
    }
    function openForm(it, asNew) {
        if (!form) return;
        form.reset();
        const editing = it && it.id && !asNew;
        form.elements.id.value = editing ? it.id : '';
        $('#eventFormTitle').textContent = editing ? '// Termin bearbeiten' : (it && it.id ? '// Termin duplizieren' : '// Neuer Termin');
        const set = (n, v) => { if (v !== undefined && v !== null) form.elements[n].value = v; };
        const d = (it && it.start_date) || C.anchor;
        set('start_date', d); set('end_date', (it && it.end_date) || d);
        if (it) {
            set('title', it.title); set('location', it.location); set('url', it.url); set('description', it.description);
            set('start_time', it.start_time); set('end_time', it.end_time);
            if (it.category_id !== undefined) set('category_id', it.category_id || 0);
            if (it.rec) set('recurrence', it.rec);
            set('recur_interval', it.recur_interval); set('recur_until', it.recur_until);
            if (it.all_day !== undefined) form.all_day.checked = !!it.all_day;
            if (it.priv !== undefined) form.private.checked = !!it.priv;
        }
        $('#seriesNote').hidden = !(editing && it.rec && it.rec !== 'none');
        prevStart = form.start_date.value;
        syncForm();
        open($('#eventModal'));
        form.elements.title.focus();
    }
    if (form) {
        form.all_day.addEventListener('change', syncForm);
        form.recurrence.addEventListener('change', syncForm);
        // Ende verschiebt sich mit dem Beginn (Dauer bleibt erhalten)
        form.start_date.addEventListener('focus', () => { prevStart = form.start_date.value; });
        form.start_date.addEventListener('change', () => {
            const ns = form.start_date.value;
            if (!ns) return;
            if (prevStart && form.end_date.value) {
                const days = Math.round((new Date(form.end_date.value + 'T12:00') - new Date(prevStart + 'T12:00')) / 86400000);
                const e = new Date(ns + 'T12:00'); e.setDate(e.getDate() + Math.max(0, days));
                form.end_date.value = e.toISOString().slice(0, 10);
            }
            if (!form.end_date.value || form.end_date.value < ns) form.end_date.value = ns;
            prevStart = ns;
        });
        form.start_time.addEventListener('change', () => {
            if (form.end_date.value === form.start_date.value && form.end_time.value <= form.start_time.value) {
                const [h, m] = form.start_time.value.split(':').map(Number);
                form.end_time.value = String(Math.min(23, h + 1)).padStart(2, '0') + ':' + String(m).padStart(2, '0');
            }
        });
        form.addEventListener('submit', e => {
            const s = form.start_date.value + ' ' + (form.all_day.checked ? '00:00' : form.start_time.value);
            const en = (form.end_date.value || form.start_date.value) + ' ' + (form.all_day.checked ? '23:59' : form.end_time.value);
            if (en < s) { e.preventDefault(); alert('Das Ende liegt vor dem Beginn.'); }
        });
        const nb = $('#newEventBtn');
        if (nb) nb.addEventListener('click', () => openForm({ start_date: C.anchor >= C.today || C.view !== 'month' ? C.anchor : C.today }, true));
        // Klick in eine freie Stelle eines Tages = neuer Termin an diesem Tag
        $$('.month .day, .week-allday .day').forEach(cell => cell.addEventListener('click', e => {
            if (e.target.closest('[data-key], .more')) return;
            openForm({ start_date: cell.dataset.day, end_date: cell.dataset.day }, true);
        }));
        // Klick in die Zeitleiste = neuer Termin zu dieser Uhrzeit
        $$('.week-col').forEach(col => col.addEventListener('click', e => {
            if (e.target.closest('[data-key]')) return;
            const hourPx = parseFloat(getComputedStyle($('.week')).getPropertyValue('--hour')) || 44;
            const h = Math.max(0, Math.min(22, Math.floor((e.clientY - col.getBoundingClientRect().top) / hourPx)));
            const p = n => String(n).padStart(2, '0');
            openForm({ start_date: col.dataset.day, end_date: col.dataset.day, start_time: p(h) + ':00', end_time: p(h + 1) + ':00' }, true);
        }));
    }

    // ---------- Drag & Drop (Monat, einmalige Termine) ----------
    let dragId = null;
    $$('.chip[draggable=true]').forEach(ch => {
        ch.addEventListener('dragstart', e => { dragId = ch.dataset.id; ch.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragId); });
        ch.addEventListener('dragend', () => { ch.classList.remove('dragging'); $$('.drop-target').forEach(x => x.classList.remove('drop-target')); });
    });
    $$('.month .day').forEach(cell => {
        cell.addEventListener('dragover', e => { if (dragId) { e.preventDefault(); cell.classList.add('drop-target'); } });
        cell.addEventListener('dragleave', () => cell.classList.remove('drop-target'));
        cell.addEventListener('drop', e => {
            e.preventDefault();
            if (!dragId) return;
            const f = $('#moveForm');
            f.elements.id.value = dragId; f.to_day.value = cell.dataset.day;
            f.submit();
        });
    });

    // ---------- Ebenen & Kategorien filtern (wird im Browser gemerkt) ----------
    const store = (k, v) => { try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } };
    const hiddenLayers = new Set(store('calHiddenLayers') || []);
    const hiddenCats = new Set(store('calHiddenCats') || []);
    function applyFilters() {
        $$('[data-layer]').forEach(el => el.classList.toggle('layer-hidden', hiddenLayers.has(el.dataset.layer)));
        $$('[data-layer=event]').forEach(el => el.classList.toggle('cat-hidden', hiddenCats.has(el.dataset.cat)));
        $$('[data-layer-toggle]').forEach(cb => cb.checked = !hiddenLayers.has(cb.dataset.layerToggle));
        $$('[data-cat-toggle]').forEach(cb => cb.checked = !hiddenCats.has(cb.dataset.catToggle));
    }
    $$('[data-layer-toggle]').forEach(cb => cb.addEventListener('change', () => { cb.checked ? hiddenLayers.delete(cb.dataset.layerToggle) : hiddenLayers.add(cb.dataset.layerToggle); store('calHiddenLayers', [...hiddenLayers]); applyFilters(); }));
    $$('[data-cat-toggle]').forEach(cb => cb.addEventListener('change', () => { cb.checked ? hiddenCats.delete(cb.dataset.catToggle) : hiddenCats.add(cb.dataset.catToggle); store('calHiddenCats', [...hiddenCats]); applyFilters(); }));
    applyFilters();

    // ---------- Woche: Jetzt-Linie & Startposition ----------
    const grid = $('#weekGrid');
    if (grid) {
        const hourPx = parseFloat(getComputedStyle($('.week')).getPropertyValue('--hour')) || 44;
        const line = $('#nowLine');
        const place = () => { if (line) { const n = new Date(); line.style.top = ((n.getHours() * 60 + n.getMinutes()) / 60 * hourPx) + 'px'; } };
        place(); setInterval(place, 60000);
        const firstBlock = $$('.block', grid).map(b => parseFloat(b.style.top)).sort((a, b) => a - b)[0];
        grid.scrollTop = Math.max(0, Math.min(firstBlock ?? 7 * hourPx, 7 * hourPx) - hourPx / 2);
    }

    // ---------- Tastatur ----------
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') return closeAll();
        if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) || e.ctrlKey || e.metaKey || e.altKey || $('.modal-overlay.open')) return;
        const go = id => { const a = $(id); if (a) location.href = a.href; };
        const views = { m: 'month', w: 'week', a: 'agenda', j: 'year' };
        if (e.key === 'ArrowLeft') go('#prevBtn');
        else if (e.key === 'ArrowRight') go('#nextBtn');
        else if (e.key === 't') go('#todayBtn');
        else if (views[e.key]) location.href = 'calendar.php?view=' + views[e.key] + '&date=' + C.anchor;
        else if (e.key === 'n' && C.admin) { e.preventDefault(); openForm({ start_date: C.anchor }, true); }
        else if (e.key === '/') { e.preventDefault(); $('#calSearch').focus(); }
    });

    // Hinweis-URL aufräumen
    if (/[?&](msg|error)=/.test(location.search)) {
        const u = new URL(location.href); u.searchParams.delete('msg'); u.searchParams.delete('error'); history.replaceState(null, '', u);
    }
})();
