/* VRC Nexus – 🎁 Rückblick (Woche/Monat/Jahr) & Freunde-Pflege (Jahrestage, Geburtstage, Inaktive, Namensverlauf). */
(function () {
    const UI = window.NexusUI;
    const box = document.getElementById('recapBox');
    if (!UI || !box) return;
    const esc = UI.esc;
    const MOBILE = document.body.classList.contains('mobile');
    const hm = s => { s = Math.max(0, Math.round(s)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? h + ' h ' + m + ' min' : m + ' min'; };
    let days = UI.store.get('recapDays', 7), view = UI.store.get('recapView', 'recap'), inactiveDays = 90, nameQ = '';
    const DAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    function bars(vals, labels) {
        const max = Math.max(1, ...vals);
        return '<div class="rc-bars">' + vals.map((v, i) => '<div title="' + esc(labels[i] + ': ' + hm(v)) + '"><i style="height:' + Math.max(2, v / max * 100) + '%"></i><small>' + esc(labels[i]) + '</small></div>').join('') + '</div>';
    }
    function renderRecap(d) {
        const title = { 7: 'Deine Woche', 30: 'Dein Monat', 365: 'Dein Jahr – VRChat Wrapped' }[d.days] || 'Letzte ' + d.days + ' Tage';
        return '<div class="rc-hero"><h2>🎁 ' + esc(title) + '</h2><div class="rc-kpis">'
            + [['⏱', hm(d.playtime), 'Spielzeit'], ['📅', d.active_days, 'aktive Tage'], ['🌐', d.worlds_count, 'Welten'], ['👋', d.people_count, 'Leute getroffen'], ['📸', d.shots, 'Fotos'], ['💛', d.new_friends.length, 'neue Freunde']]
                .map(k => '<div><span>' + k[0] + '</span><b>' + esc(String(k[1])) + '</b><small>' + k[2] + '</small></div>').join('') + '</div>'
            + (d.longest ? '<p>🏆 Längste Sitzung: <b>' + hm(d.longest.seconds) + '</b> in <b>' + esc(d.longest.name) + '</b> (' + esc(UI.isoDT(d.longest.start * 1000)) + ')' + (d.best_day ? ' · Lieblingstag: <b>' + d.best_day + '</b> · meist um <b>' + d.best_hour + ' Uhr</b>' : '') + '</p>' : '<p class="muted">Noch keine Spielzeit im Zeitraum – die Auswertung kommt aus dem Spiel-Log.</p>')
            + '</div><div class="rc-grid">'
            + '<section class="panel"><h3>🌐 Top-Welten</h3>' + (d.top_worlds.length ? d.top_worlds.map((w, i) => '<div class="rc-row"' + (w.id ? ' data-world="' + esc(w.id) + '"' : '') + '><b>' + (i + 1) + '.</b> ' + esc(w.name) + '<span>' + hm(w.seconds) + ' · ' + w.visits + '×</span></div>').join('') : '<p class="muted">–</p>') + '</section>'
            + '<section class="panel"><h3>👥 Am meisten Zeit mit</h3>' + (d.top_people.length ? d.top_people.map((p, i) => '<div class="rc-row"' + (p.id ? ' data-user="' + esc(p.id) + '"' : '') + '><b>' + (i + 1) + '.</b> ' + (p.friend ? '💛 ' : '') + esc(p.name) + '<span>' + hm(p.seconds) + ' · ' + p.meets + '×</span></div>').join('') : '<p class="muted">–</p>') + '</section>'
            + '<section class="panel"><h3>🎭 Avatare</h3>' + (d.avatars.length ? d.avatars.map(a => '<div class="rc-row">' + esc(a.name) + '<span>' + a.n + '× gewechselt</span></div>').join('') : '<p class="muted">Keine Avatarwechsel im Log.</p>') + '</section>'
            + '<section class="panel"><h3>📊 Wann du spielst</h3>' + bars(d.by_day, DAYS) + bars(d.by_hour, d.by_hour.map((_, i) => String(i))) + '</section>'
            + '<section class="panel"><h3>💛 Neue Freunde</h3>' + (d.new_friends.length ? d.new_friends.map(f => '<div class="rc-row" data-user="' + esc(f.id) + '">' + esc(f.name) + '<span>' + esc(UI.isoDT(f.ts * 1000)) + '</span></div>').join('') : '<p class="muted">–</p>') + '</section>'
            + '<section class="panel"><h3>💤 AFK-Verdacht</h3><p class="muted small">Sitzungen über 3 h, in denen niemand dazukam.</p>' + (d.afk.length ? d.afk.map(a => '<div class="rc-row">' + esc(a.name) + '<span>' + hm(a.seconds) + ' · ' + esc(UI.isoDT(a.start * 1000)) + '</span></div>').join('') : '<p class="muted">Keine.</p>') + '</section>'
            + '</div>';
    }
    function renderFriends(d) {
        return '<div class="rc-grid">'
            + '<section class="panel"><h3>🎂 Jahrestage & Geburtstage (30 Tage)</h3>' + (d.anniversaries.length ? d.anniversaries.map(a => '<div class="rc-row" data-user="' + esc(a.id) + '">' + (a.kind === 'birthday' ? '🎂 ' : '💛 ') + '<b>' + esc(a.name) + '</b>'
                + '<span>' + (a.kind === 'birthday' ? 'Geburtstag' : a.years + (a.years === 1 ? ' Jahr' : ' Jahre') + ' Freunde') + ' · ' + esc(a.date) + ' · ' + (a.in_days === 0 ? '<b>heute!</b>' : 'in ' + a.in_days + ' Tg.') + '</span></div>').join('')
                : '<p class="muted">Nichts in den nächsten 30 Tagen.</p>') + '<p class="muted small">Geburtstage: in die Notiz eines Freundes „Geburtstag 24.12.“ oder „🎂 24.12.“ schreiben.</p></section>'
            + '<section class="panel"><h3>🧹 Lange nicht online</h3><div class="nx-row"><select id="rcInact">' + [30, 90, 180, 365, 730].map(n => '<option value="' + n + '"' + (n === inactiveDays ? ' selected' : '') + '>seit ' + n + ' Tagen</option>').join('') + '</select><span class="muted small">' + d.inactive.length + ' Freunde</span></div>'
                + (d.inactive.length ? d.inactive.slice(0, 200).map(f => '<div class="rc-row" data-user="' + esc(f.id) + '">' + (f.favorite ? '⭐ ' : '') + esc(f.name) + '<span>' + f.days + ' Tage' + (f.note ? ' · 📝' : '') + '</span></div>').join('') : '<p class="muted">Niemand.</p>')
                + '<p class="muted small">Antippen öffnet das Profil – dort kannst du eine Notiz hinterlassen oder die Freundschaft beenden.</p></section>'
            + '<section class="panel"><h3>🏷 Namensverlauf</h3><input type="search" id="rcNameQ" placeholder="🔎 alter oder neuer Name" value="' + esc(nameQ) + '">'
                + (d.names.length ? d.names.map(n => '<div class="rc-row" data-user="' + esc(n.id) + '"><span class="muted">' + esc(n.old) + '</span> → <b>' + esc(n.new) + '</b><span>' + esc(UI.isoDT(n.ts * 1000)) + '</span></div>').join('') : '<p class="muted">Noch keine Namensänderungen aufgezeichnet.</p>') + '</section>'
            + '</div>';
    }
    function load() {
        box.innerHTML = '<div class="nx-row rc-bar"><span class="chips">' + [['recap', '🎁 Rückblick'], ['photos', '📸 Foto-Verlauf'], ['inst', '📈 Instanz-Verlauf'], ['myworlds', '🌍 Meine Welten'], ['friends', '💛 Freunde-Pflege']].map(v => '<button type="button" class="chip' + (view === v[0] ? ' on' : '') + '" data-rv="' + v[0] + '">' + v[1] + '</button>').join('') + '</span>'
            + (['recap', 'photos'].includes(view) ? '<span class="chips">' + [[7, 'Woche'], [30, 'Monat'], [365, 'Jahr']].map(v => '<button type="button" class="chip' + (days === v[0] ? ' on' : '') + '" data-rd="' + v[0] + '">' + v[1] + '</button>').join('') + '</span>' : '')
            + '</div><div id="rcBody"><div class="vx-loading">…</div></div>';
        const body = box.querySelector('#rcBody');
        if (view === 'recap') {
            if (MOBILE) { body.innerHTML = '<p class="muted">Der Rückblick braucht das Spiel-Log vom PC – in der Windows-App.</p>'; return; }
            UI.api('ins_recap', { days }).then(d => { body.innerHTML = renderRecap(d); }).catch(e => { body.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
        } else if (view === 'photos') {
            if (MOBILE) { body.innerHTML = '<p class="muted">Die Fotos liegen auf dem PC – in der Windows-App.</p>'; return; }
            UI.api('ins_photos', { days }).then(d => {
                body.innerHTML = d.visits.length ? d.visits.map(v => '<section class="panel ph-visit"><h3>🌐 ' + esc(v.world) + ' <small class="muted">' + esc(UI.isoDT(v.start * 1000)) + ' · ' + v.photos.length + ' Fotos</small></h3>'
                    + (v.people.length ? '<p class="small muted">mit ' + v.people.map(esc).join(', ') + '</p>' : '')
                    + '<div class="ph-strip">' + v.photos.map(p => p.exists ? '<a href="nexus.php?shot=' + encodeURIComponent(p.rel) + '" target="_blank" title="' + esc(p.name) + '"><img loading="lazy" src="nexus.php?shot=' + encodeURIComponent(p.rel) + '&amp;t=1" alt=""></a>' : '<span class="ph-miss" title="' + esc(p.name) + '">📷 nicht mehr da</span>').join('') + '</div></section>').join('')
                    : '<div class="nc-empty">Keine Screenshots im Zeitraum.</div>';
            }).catch(e => { body.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
        } else if (view === 'inst') {
            if (MOBILE) { body.innerHTML = '<p class="muted">Der Instanz-Verlauf kommt aus dem Spiel-Log vom PC – in der Windows-App.</p>'; return; }
            UI.api('ins_instances').then(d => {
                if (!d.visits.length) { body.innerHTML = '<div class="nc-empty">Noch keine Weltbesuche im Spiel-Log.</div>'; return; }
                body.innerHTML = '<div class="nx-row"><select id="rcInst">' + d.visits.map(v => '<option value="' + v.start + '">' + esc(UI.isoDT(v.start * 1000)) + ' · ' + esc(v.name) + ' · ' + hm(v.seconds) + '</option>').join('') + '</select></div><div id="rcInstBody"></div>';
                instLoad(d.visits[0].start);
            }).catch(e => { body.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
        } else if (view === 'myworlds') {
            UI.api('ins_myworlds').then(d => {
                const dv = (x, k) => x ? '<span class="' + (x[k] > 0 ? 'ok' : 'muted') + '">' + (x[k] > 0 ? '+' : '') + x[k].toLocaleString('de-DE') + '</span>' : '<span class="muted">–</span>';
                body.innerHTML = d.worlds.length ? '<p class="muted small">Veränderungen werden ab dem ersten Öffnen dieser Seite täglich mitgeschrieben (seit ' + esc(d.since) + ').</p><div class="rc-grid">' + d.worlds.map(w => '<section class="panel mw"><div class="mw-head" data-world="' + esc(w.id) + '"' + (/^https:/.test(w.thumb) ? ' style="background-image:url(\'' + esc(w.thumb.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"' : '') + '><b>' + esc(w.name) + '</b><span class="vx-pill">' + esc(w.status) + '</span></div>'
                    + '<table class="mw-tab"><tr><th></th><th>gesamt</th><th>seit gestern</th><th>7 Tage</th></tr><tr><td>👣 Besuche</td><td>' + w.visits.toLocaleString('de-DE') + '</td><td>' + dv(w.d1, 'v') + '</td><td>' + dv(w.d7, 'v') + '</td></tr>'
                    + '<tr><td>⭐ Favoriten</td><td>' + w.favorites.toLocaleString('de-DE') + '</td><td>' + dv(w.d1, 'f') + '</td><td>' + dv(w.d7, 'f') + '</td></tr></table>'
                    + '<div class="small">👥 gerade ' + w.occupants + (w.capacity ? ' / ' + w.capacity : '') + ' · 🔥 ' + w.heat + ' · Beliebtheit ' + w.popularity + '</div>' + spark(w.history) + '</section>').join('') + '</div>'
                    : '<div class="nc-empty">Du hast keine eigenen Welten hochgeladen.</div>';
            }).catch(e => { body.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
        } else UI.api('ins_friends', { days: inactiveDays, q: nameQ }).then(d => { body.innerHTML = renderFriends(d); }).catch(e => { body.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
    }
    box.addEventListener('click', e => {
        const v = e.target.closest('[data-rv]'); if (v) { view = v.dataset.rv; UI.store.set('recapView', view); load(); return; }
        const d = e.target.closest('[data-rd]'); if (d) { days = +d.dataset.rd; UI.store.set('recapDays', days); load(); }
    });
    box.addEventListener('change', e => { if (e.target.id === 'rcInact') { inactiveDays = +e.target.value; load(); } });
    let qt = null;
    box.addEventListener('input', e => { if (e.target.id === 'rcNameQ') { nameQ = e.target.value; clearTimeout(qt); qt = setTimeout(() => { load(); setTimeout(() => { const i = box.querySelector('#rcNameQ'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }, 300); }, 500); } });
    /** kleine Verlaufslinie der Besuche (aus den täglichen Momentaufnahmen) */
    function spark(h) {
        if (!h || h.length < 2) return '<div class="muted small">Verlauf erscheint ab dem zweiten Tag.</div>';
        const vals = h.map(x => x[1]), lo = Math.min(...vals), hi = Math.max(...vals), W = 260, H = 40;
        const pts = vals.map((v, i) => (i / (vals.length - 1) * W).toFixed(1) + ',' + (H - (hi === lo ? H / 2 : (v - lo) / (hi - lo) * (H - 4) + 2)).toFixed(1)).join(' ');
        return '<svg class="mw-spark" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Besuche der letzten ' + h.length + ' Tage"><polyline points="' + pts + '" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
    }
    function instLoad(start) {
        const b = box.querySelector('#rcInstBody');
        if (!b) return;
        UI.api('ins_instance', { start }).then(d => {
            const W = 800, H = 160, t0 = d.start, t1 = Math.max(d.end, t0 + 60), peak = Math.max(1, d.peak);
            const x = t => ((t - t0) / (t1 - t0) * W).toFixed(1), y = n => (H - n / peak * (H - 10)).toFixed(1);
            let path = 'M0,' + H, last = 0;
            for (const [t, n] of d.points) { path += ' L' + x(t) + ',' + y(last) + ' L' + x(t) + ',' + y(n); last = n; }
            path += ' L' + W + ',' + y(last);
            b.innerHTML = '<div class="rc-hero"><h2>📈 ' + esc(d.name || 'Instanz') + '</h2><div class="rc-kpis">'
                + [['👥', d.peak, 'Höchststand' + (d.peak_at ? ' um ' + new Date(d.peak_at * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '')], ['➗', String(d.avg).replace('.', ','), 'im Schnitt'], ['🧑‍🤝‍🧑', d.unique, 'verschiedene Leute'], ['⏱', hm(d.end - d.start), 'Dauer']]
                    .map(k => '<div><span>' + k[0] + '</span><b>' + esc(String(k[1])) + '</b><small>' + k[2] + '</small></div>').join('') + '</div></div>'
                + '<section class="panel"><svg class="in-chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Spielerzahl über die Zeit"><path d="' + path + '" class="in-line"/></svg>'
                + '<div class="in-axis"><span>' + new Date(t0 * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + '</span><span>' + new Date(t1 * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + '</span></div></section>'
                + '<section class="panel"><h3>Kommen & Gehen</h3>' + (d.events.length ? d.events.slice().reverse().map(e => '<div class="rc-row"' + (e.id ? ' data-user="' + esc(e.id) + '"' : '') + '>' + (e.kind === 'player_join' ? '➡' : '⬅') + ' ' + esc(e.name)
                    + '<span>' + new Date(e.ts * 1000).toLocaleTimeString('de-DE') + ' · ' + e.count + ' da</span></div>').join('') : '<p class="muted">Niemand kam oder ging.</p>') + '</section>';
        }).catch(e => { b.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
    }
    box.addEventListener('change', e => { if (e.target.id === 'rcInst') instLoad(e.target.value); });
    window.NexusRecap = { open: load };
    const pane = document.querySelector('[data-pane=recap]');
    if (pane && !pane.hidden) load();
})();
