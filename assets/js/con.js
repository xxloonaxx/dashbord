/* Con-Rechner: Reiter, Filter & Summen der Ausgaben, Live-Rechner, Packliste ohne Neuladen */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const num = v => { let s = String(v || '').replace(/[^\d,.\-]/g, ''); if (s.lastIndexOf(',') > s.lastIndexOf('.')) s = s.replace(/\./g, '').replace(',', '.'); else s = s.replace(/,/g, ''); return parseFloat(s) || 0; };
    const eur = v => (Math.round(v * 100) / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    const dec = v => (Math.round(v * 100) / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    // ---------- Reiter (merkt sich die Auswahl in der Adresse) ----------
    const tabs = $$('#cnTabs [data-tab]').map(b => b.dataset.tab);
    function show(name) {
        if (!tabs.includes(name)) name = 'overview';
        $$('#cnTabs [data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === name));
        $$('.cn-pane').forEach(p => { p.hidden = p.dataset.pane !== name; });
        if (location.hash.slice(1) !== name) history.replaceState(null, '', location.pathname + location.search.replace(/[?&](msg|error)=[^&]*/g, '').replace(/^&/, '?') + '#' + name);
        try { localStorage.setItem('con-tab', name); } catch (e) {}
    }
    $('#cnTabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) show(b.dataset.tab); });
    document.addEventListener('click', e => { const a = e.target.closest('[data-tabjump]'); if (a) { e.preventDefault(); show(a.dataset.tabjump); window.scrollTo({ top: $('#cnTabs').offsetTop - 10, behavior: 'smooth' }); } });
    let start = location.hash.slice(1);
    if (!start) { try { start = localStorage.getItem('con-tab') || ''; } catch (e) {} }
    show(start || 'overview');
    window.addEventListener('hashchange', () => show(location.hash.slice(1)));

    // ---------- Ausgaben: Filter, Sortierung, Summe ----------
    const list = $('#expList');
    function refreshExp() {
        if (!list) return;
        const cat = $('#expCat').value, st = $('#expStatus').value, q = ($('.tb-search', list.parentNode) || { value: '' }).value.trim().toLowerCase();
        const items = $$('.cn-exp', list);
        let sum = 0, n = 0;
        items.forEach(it => {
            const ok = (!cat || it.dataset.cat === cat) && (!st || it.dataset.status === st) && (!q || it.dataset.search.includes(q));
            it.hidden = !ok;
            if (ok) { sum += +it.dataset.amount; n++; }
        });
        const k = $('#expSort').value;
        items.sort((a, b) => k === 'amount' ? b.dataset.amount - a.dataset.amount : k === 'cat' ? a.dataset.cat.localeCompare(b.dataset.cat) : k === 'due' ? a.dataset.due.localeCompare(b.dataset.due) : b.dataset.date.localeCompare(a.dataset.date))
             .forEach(x => list.append(x));
        $('#expSum').innerHTML = items.length ? '<span>' + n + ' von ' + items.length + ' Ausgaben</span><b>Summe: ' + eur(sum) + '</b>' : '';
        const empty = $('[data-empty-for="#expList > .cn-exp"]');
        if (empty && items.length) empty.hidden = n > 0;
    }
    if (list) {
        ['#expCat', '#expStatus', '#expSort'].forEach(s => $(s).addEventListener('change', refreshExp));
        $('.tb-search', list.parentNode).addEventListener('input', () => setTimeout(refreshExp, 0));
        refreshExp();
    }

    // ---------- Zeitplan: nur Favoriten ----------
    const of = $('#onlyFav');
    if (of) of.addEventListener('change', () => {
        $$('.cn-sitem').forEach(i => { i.hidden = of.checked && i.dataset.fav !== '1'; });
        $$('.cn-sday').forEach(d => { d.hidden = !$$('.cn-sitem', d).some(i => !i.hidden); });
    });

    // ---------- Packliste ohne Neuladen ----------
    $$('.cn-packtoggle').forEach(f => f.addEventListener('submit', e => {
        e.preventDefault();
        const row = f.closest('.cl-item'), btn = f.querySelector('button');
        const on = !row.classList.contains('done');
        row.classList.toggle('done', on); btn.classList.toggle('on', on); btn.textContent = on ? '✓' : '';
        const body = new URLSearchParams(new FormData(f)); body.append('ajax', '1');
        fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body }).then(r => { if (!r.ok) throw 0; }).catch(() => location.reload());
        const all = $$('.cn-packs .cl-item').length, done = $$('.cn-packs .cl-item.done').length;
        const bar = $('#packBar'); if (bar) bar.style.width = (all ? done / all * 100 : 0) + '%';
        const lbl = $('[data-pane=pack] .cl-label span:last-child'); if (lbl) lbl.textContent = done + ' / ' + all + (all ? ' · ' + Math.round(done / all * 100) + ' %' : '');
        const g = f.closest('.cn-pack'); if (g) g.querySelector('.panel-title small').textContent = $$('.cl-item.done', g).length + '/' + $$('.cl-item', g).length;
    }));

    // ---------- Rechner ----------
    const today = new Date().toISOString().slice(0, 10);
    const take = (box, fill) => { const b = $('.cn-take', box); if (b) b.dataset.fill = JSON.stringify(Object.assign({ id: '', status: 'planned', currency: 'EUR', date: today, payer: 'me' }, fill)); };
    const line = (l, v, strong) => '<div class="' + (strong ? 'big' : '') + '"><span>' + l + '</span><b>' + v + '</b></div>';
    const CALC = {
        room(box, v) {
            const total = num(v.total), nights = Math.max(1, +v.nights || 1), p = Math.max(1, +v.persons || 1), tax = num(v.tax) * nights;
            const pp = total / p + tax;
            take(box, { title: 'Hotel (' + nights + ' Nächte' + (p > 1 ? ', ' + p + ' Personen' : '') + ')', amount: dec(total + tax * p), category: 'hotel' });
            return line('Pro Person', eur(pp), true) + line('Pro Person &amp; Nacht', eur(pp / nights)) + line('Zimmer pro Nacht', eur(total / nights)) + (tax ? line('Kurtaxe gesamt', eur(tax * p)) : '');
        },
        car(box, v) {
            const ev = v.ev;
            const dist = (+v.km || 0) * (+v.trip || 1), energy = dist / 100 * num(v.cons), fuel = energy * num(v.price), total = fuel + num(v.extra), p = Math.max(1, +v.persons || 1);
            take(box, { title: 'Anreise Auto (' + Math.round(dist) + ' km)', amount: dec(total), category: 'travel' });
            return line('Pro Person', eur(total / p), true) + line('Gesamt', eur(total)) + line(ev ? 'Strom' : 'Sprit', (Math.round(energy * 10) / 10).toLocaleString('de-DE') + (ev ? ' kWh' : ' l') + ' für ' + Math.round(dist) + ' km')
                + line('Kosten pro km', eur(dist ? total / dist : 0)) + '<p class="muted small">Tipp: Deutschlandticket o. Ä. zum Vergleich im Bahn-Rechner.</p>';
        },
        train(box, v) {
            const per = num(v.price) * (1 - Math.min(100, +v.discount || 0) / 100) + num(v.extra), trips = Math.max(1, +v.trips || 1), p = Math.max(1, +v.persons || 1);
            const total = per * trips * p;
            take(box, { title: 'Bahn/Flug (' + trips + ' Fahrten' + (p > 1 ? ', ' + p + ' Personen' : '') + ')', amount: dec(total), category: 'travel' });
            return line('Gesamt', eur(total), true) + line('Pro Person', eur(total / p)) + line('Pro Fahrt &amp; Person', eur(per));
        },
        daily(box, v) {
            const avail = num(v.avail), days = Math.max(1, +v.days || 1), food = num(v.food), res = Math.min(90, +v.reserve || 0) / 100;
            const usable = avail * (1 - res), perDay = usable / days, fun = perDay - food;
            return line('Pro Tag', eur(perDay), true) + line('davon Essen', eur(food)) + line('frei für Spaß / Dealers Den', '<span class="' + (fun < 0 ? 'neg' : 'pos') + '">' + eur(fun) + '</span>') + line('Reserve', eur(avail * res));
        },
        save(box, v) {
            const need = Math.max(0, num(v.need) - num(v.have));
            const days = v.until ? Math.ceil((new Date(v.until) - new Date(today)) / 86400000) : 0;
            if (days <= 0) return line('Noch aufzubringen', eur(need), true) + '<p class="muted small">Das Datum liegt in der Vergangenheit.</p>';
            return line('Pro Monat', eur(need / Math.max(1, days / 30.44)), true) + line('Pro Woche', eur(need / Math.max(1, days / 7))) + line('Pro Tag', eur(need / days)) + line('Verbleibend', days + ' Tage');
        },
        fx(box, v) {
            const a = num(v.amount), rate = num(v.rate), fee = (+v.fee || 0) / 100;
            const e = a * rate * (1 + fee);
            return line(dec(a) + ' ' + esc(v.cur), eur(e), true) + line('1 €', (rate ? (1 / rate).toLocaleString('de-DE', { maximumFractionDigits: 4 }) : '–') + ' ' + esc(v.cur)) + (fee ? line('davon Gebühr', eur(a * rate * fee)) : '')
                + '<div class="cn-fxtable">' + [5, 10, 20, 50, 100].map(x => '<span>' + x + ' ' + esc(v.cur) + ' = <b>' + eur(x * rate * (1 + fee)) + '</b></span>').join('') + '</div>';
        },
        bill(box, v) {
            const total = num(v.total), p = Math.max(1, +v.persons || 1), tip = (+v.tip || 0) / 100;
            const all = total * (1 + tip);
            take(box, { title: 'Essen (mein Anteil)', amount: dec(all / p), category: 'food', status: 'paid' });
            return line('Pro Person', eur(all / p), true) + line('Trinkgeld ' + Math.round(tip * 100) + ' %', eur(total * tip)) + line('Gesamt mit Trinkgeld', eur(all)) + line('Aufgerundet pro Person', eur(Math.ceil(all / p)));
        },
        dd(box, v) {
            const wish = +box.dataset.wish || 0, free = num(v.rest) - num(v.keep);
            return line('Dealers-Den-Limit', '<span class="' + (free < 0 ? 'neg' : 'pos') + '">' + eur(Math.max(0, free)) + '</span>', true) + line('Wunschliste offen', eur(wish))
                + line(free - wish >= 0 ? 'Danach übrig' : 'Fehlt für alle Wünsche', '<span class="' + (free - wish < 0 ? 'neg' : 'pos') + '">' + eur(Math.abs(free - wish)) + '</span>');
        },
    };
    $$('.cn-calc').forEach(box => {
        const fn = CALC[box.dataset.calc];
        if (!fn) return;
        const run = () => {
            const v = {};
            $$('input, select', box).forEach(i => { v[i.name] = i.type === 'checkbox' ? i.checked : i.value; });
            $('.cn-result', box).innerHTML = fn(box, v);
        };
        box.addEventListener('input', run);
        run();
    });
})();
