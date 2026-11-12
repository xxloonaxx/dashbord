/* Budget – Diagramme, Filter, Dialoge, Privatsphäre-Modus */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const eur = n => Number(n || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const store = { get(k, d) { try { const v = localStorage.getItem('bd-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('bd-' + k, JSON.stringify(v)); } catch (e) {} } };
    const B = window.BUDGET || { cats: [], months: [] };
    const typing = () => /INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '');

    // ---------- Privatsphäre ----------
    const setPrivate = on => { document.body.classList.toggle('bd-private', on); $$('.bd-privacy').forEach(b => b.classList.toggle('on', on)); store.set('private', on); };
    setPrivate(store.get('private', false));
    $$('.bd-privacy').forEach(b => b.addEventListener('click', () => setPrivate(!document.body.classList.contains('bd-private'))));

    // ---------- Dialoge ----------
    const open = id => { const m = document.getElementById(id); if (m) { m.style.display = 'flex'; const f = m.querySelector('input:not([type=hidden]), select'); if (f) setTimeout(() => f.focus(), 30); } };
    const closeAll = () => $$('.modal-overlay').forEach(m => { m.style.display = 'none'; });
    document.addEventListener('click', e => {
        const o = e.target.closest('[data-open]');
        if (o) open(o.dataset.open);
        if (e.target.closest('[data-close]') || e.target.classList.contains('modal-overlay')) closeAll();
        const en = e.target.closest('[data-entry]');
        if (en && $('#editEntryModal')) {
            const x = JSON.parse(en.dataset.entry);
            ['entry_id', 'section', 'title', 'amount', 'category', 'status', 'docs', 'date'].forEach(k => { const el = $('#ee_' + k); if (el) el.value = x[k === 'entry_id' ? 'id' : k] ?? ''; });
            open('editEntryModal');
        }
        const ru = e.target.closest('[data-rule]');
        if (ru) { const x = JSON.parse(ru.dataset.rule); $('#rm_match').value = x.match; $('#rm_category').value = x.category; $('#rm_section').value = ''; open('ruleModal'); }
        const go = e.target.closest('[data-goal]');
        if (go) {
            const x = JSON.parse(go.dataset.goal), f = $('#goalModal form');
            f.reset(); f.goal_id.value = x.goal_id || ''; f.name.value = x.name || ''; f.icon.value = x.icon || '🎯'; f.target.value = x.target || ''; f.deadline.value = x.deadline || '';
            $('.bd-newonly', f).hidden = !!x.goal_id;
            open('goalModal');
        }
    });
    document.addEventListener('submit', e => { const c = e.target.dataset.confirm; if (c && !confirm(c)) e.preventDefault(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeAll();
        if (typing() || e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.key === 'n' || e.key === 'N') { const t = $('#new_title'); if (t) { e.preventDefault(); t.focus(); t.scrollIntoView({ block: 'center', behavior: 'smooth' }); } }
        if (e.key === '/') { const s = $('#bdSearch'); if (s) { e.preventDefault(); s.focus(); } }
        if (e.key === 'p' || e.key === 'P') setPrivate(!document.body.classList.contains('bd-private'));
    });

    // ---------- Schnell-Eingabe ----------
    const sec = $('#new_section');
    if (sec) {
        const cat = $('#new_category'), done = $('#new_status_done');
        const DEF = { income: 'Lohn / Abschlag', other: '', fixed: 'Fixkosten', subs: 'Abo' };
        let touched = false;
        cat.addEventListener('input', () => { touched = cat.value !== ''; });
        const sync = () => { if (!touched) cat.value = DEF[sec.value] || ''; done.textContent = sec.value === 'income' ? 'Erhalten' : 'Bezahlt'; };
        sec.addEventListener('change', sync);
        sync();
    }

    // ---------- Filter, Suche, Sortierung ----------
    const search = $('#bdSearch');
    if (search) {
        const fcat = $('#bdCat'), fstat = $('#bdStatus'), fsort = $('#bdSort');
        fsort.value = store.get('sort', 'date-desc');
        const apply = () => {
            const q = search.value.trim().toLowerCase(), c = fcat.value, st = fstat.value, so = fsort.value;
            let shown = 0, total = 0;
            $$('.bd-section').forEach(secEl => {
                const box = $('.bd-entries', secEl);
                const rows = $$('.bd-entry', box);
                rows.sort((a, b) => {
                    if (so === 'date-asc') return a.dataset.ts - b.dataset.ts;
                    if (so === 'amount-desc') return b.dataset.amount - a.dataset.amount;
                    if (so === 'amount-asc') return a.dataset.amount - b.dataset.amount;
                    if (so === 'title') return a.dataset.title.localeCompare(b.dataset.title, 'de');
                    return b.dataset.ts - a.dataset.ts;
                }).forEach(r => box.append(r));
                let vis = 0;
                rows.forEach(r => {
                    const ok = (!q || q.split(/\s+/).every(w => r.dataset.text.includes(w))) && (!c || r.dataset.cat === c) && (!st || r.dataset.status === st);
                    r.hidden = !ok;
                    if (ok) vis++;
                });
                total += rows.length; shown += vis;
                let nh = $('.bd-nohit', box);
                if (rows.length && !vis) { if (!nh) { nh = document.createElement('div'); nh.className = 'bd-nohit'; nh.textContent = 'Keine Treffer in diesem Bereich.'; box.append(nh); } nh.hidden = false; }
                else if (nh) nh.hidden = true;
            });
            $('#bdCount').textContent = (q || c || st) ? shown + ' von ' + total + ' Buchungen' : total + ' Buchungen';
            $$('#catLegend button').forEach(b => b.classList.toggle('on', b.dataset.cat === c));
        };
        [search, fcat, fstat].forEach(el => el.addEventListener('input', apply));
        fsort.addEventListener('change', () => { store.set('sort', fsort.value); apply(); });
        apply();
        window.bdFilterCat = cat => { fcat.value = fcat.value === cat ? '' : cat; apply(); if (fcat.value) $('.bd-toolbar').scrollIntoView({ behavior: 'smooth' }); };
    }

    // Abschnitte einklappen (gemerkt)
    const collapsed = store.get('collapsed', {});
    $$('.bd-section').forEach(s => {
        if (collapsed[s.id]) s.classList.add('collapsed');
        $('.bd-collapse', s).addEventListener('click', e => {
            s.classList.toggle('collapsed');
            e.currentTarget.setAttribute('aria-expanded', String(!s.classList.contains('collapsed')));
            collapsed[s.id] = s.classList.contains('collapsed');
            store.set('collapsed', collapsed);
        });
    });

    // ---------- Donut: Ausgaben nach Kategorie ----------
    const donut = $('#catDonut');
    if (donut && B.cats.length) {
        const total = B.cats.reduce((a, c) => a + c.value, 0);
        const max = 9;
        // Farben nach Rang statt nach Name, damit benachbarte Stücke gut unterscheidbar sind
        const PAL = ['#38d5e6', '#f3c262', '#bd93f9', '#50fa7b', '#ff79c6', '#ff9f43', '#7aa2f7', '#2dd4bf', '#f87171'];
        let cats = B.cats.slice(0, max).map((c, i) => Object.assign({}, c, { color: PAL[i % PAL.length] }));
        if (B.cats.length > max) cats.push({ name: 'Weitere', value: B.cats.slice(max).reduce((a, c) => a + c.value, 0), color: '#64748b', icon: '…', rest: true });
        const R = 52, r = 34, cx = 60, cy = 60;
        let a0 = -Math.PI / 2, svg = '';
        const pt = (rad, a) => [cx + rad * Math.cos(a), cy + rad * Math.sin(a)];
        cats.forEach((c, i) => {
            const frac = c.value / total, a1 = a0 + Math.max(frac * Math.PI * 2 - 0.012, 0.001);
            const large = a1 - a0 > Math.PI ? 1 : 0;
            const [x0, y0] = pt(R, a0), [x1, y1] = pt(R, a1), [x2, y2] = pt(r, a1), [x3, y3] = pt(r, a0);
            const d = frac >= 0.999 ? `M${cx - R},${cy}a${R},${R} 0 1,0 ${R * 2},0a${R},${R} 0 1,0 -${R * 2},0M${cx - r},${cy}a${r},${r} 0 1,1 ${r * 2},0a${r},${r} 0 1,1 -${r * 2},0`
                : `M${x0},${y0}A${R},${R} 0 ${large} 1 ${x1},${y1}L${x2},${y2}A${r},${r} 0 ${large} 0 ${x3},${y3}Z`;
            svg += `<path d="${d}" fill="${esc(c.color)}" fill-rule="evenodd" data-i="${i}"><title>${esc(c.name)}: ${eur(c.value)} € (${Math.round(frac * 100)} %)</title></path>`;
            a0 += frac * Math.PI * 2;
        });
        svg += `<text x="60" y="58" class="money">${esc(eur(total).replace(/,\d+$/, ''))} €</text><text x="60" y="69" class="s">AUSGABEN</text>`;
        donut.innerHTML = svg;
        $('#catLegend').innerHTML = cats.map((c, i) => `<button type="button" data-i="${i}" data-cat="${esc(c.rest ? '' : c.name)}"><i style="background:${esc(c.color)}"></i><span>${esc(c.icon + ' ' + c.name)}</span><b class="money">${eur(c.value)} €</b><em>${Math.round(c.value / total * 100)} %</em></button>`).join('');
        const hi = i => { donut.classList.toggle('dim', i !== null); $$('path', donut).forEach(p => p.classList.toggle('hi', +p.dataset.i === i)); };
        $$('#catLegend button, #catDonut path').forEach(el => {
            el.addEventListener('mouseenter', () => hi(+el.dataset.i));
            el.addEventListener('mouseleave', () => hi(null));
            el.addEventListener('click', () => { const c = cats[+el.dataset.i]; if (c && !c.rest && window.bdFilterCat) window.bdFilterCat(c.name); });
        });
    }

    // ---------- Balken: Monate ----------
    const mc = $('#monthChart');
    if (mc) {
        const M = B.months;
        if (!M.length) { mc.innerHTML = '<div class="empty-msg">Noch keine Daten.</div>'; return; }
        const W = Math.max(520, mc.clientWidth || 0, M.length * 70), H = 260, pl = 54, pr = 10, pt = 14, pb = 34;
        const max = Math.max(1, ...M.map(m => Math.max(m.income, m.expense))), min = Math.min(0, ...M.map(m => m.net));
        const span = max - min, y = v => pt + (max - v) / span * (H - pt - pb);
        const step = (W - pl - pr) / M.length, bw = Math.min(26, step / 3);
        let g = '';
        const ticks = 4;
        for (let i = 0; i <= ticks; i++) { const v = min + span * i / ticks; g += `<line class="grid" x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}"/><text x="${pl - 6}" y="${y(v) + 3}" text-anchor="end" class="money">${Math.round(v).toLocaleString('de-DE')}</text>`; }
        g += `<line x1="${pl}" x2="${W - pr}" y1="${y(0)}" y2="${y(0)}" stroke="rgba(56,213,230,0.35)"/>`;
        let line = '';
        M.forEach((m, i) => {
            const x = pl + step * i + step / 2;
            const label = m.name.replace(/^(\S{3})\S*\s+\d\d(\d\d)$/, '$1 $2');
            g += `<g class="m${m.current ? ' cur' : ''}"><title>${esc(m.name)}\nEinnahmen ${eur(m.income)} €\nAusgaben ${eur(m.expense)} € (fix ${eur(m.fixed)} €, sonstige ${eur(m.other)} €)\nNetto ${eur(m.net)} €</title>`
                + `<rect x="${x - bw - 2}" y="${y(m.income)}" width="${bw}" height="${Math.max(0, y(0) - y(m.income))}" fill="#50fa7b" opacity="0.85"/>`
                + `<rect x="${x + 2}" y="${y(m.fixed)}" width="${bw}" height="${Math.max(0, y(0) - y(m.fixed))}" fill="#38d5e6" opacity="0.85"/>`
                + `<rect x="${x + 2}" y="${y(m.fixed + m.other)}" width="${bw}" height="${Math.max(0, y(m.fixed) - y(m.fixed + m.other))}" fill="#f3c262" opacity="0.85"/>`
                + `<text x="${x}" y="${H - 12}" text-anchor="middle">${esc(label)}</text></g>`;
            line += (i ? 'L' : 'M') + x + ',' + y(m.net);
        });
        g += `<path d="${line}" fill="none" stroke="#fff" stroke-width="1.5" stroke-dasharray="4 3"/>`;
        M.forEach((m, i) => { g += `<circle cx="${pl + step * i + step / 2}" cy="${y(m.net)}" r="3.5" fill="${m.net >= 0 ? '#bd93f9' : '#ff5555'}" stroke="#fff" stroke-width="1"><title>Netto ${esc(m.name)}: ${eur(m.net)} €</title></circle>`; });
        mc.innerHTML = `<svg viewBox="0 0 ${W} ${H}" style="min-width:${W}px" role="img" aria-label="Einnahmen und Ausgaben pro Monat">${g}</svg>`;
    }
})();
