/* VRC Nexus – 🛡 Mod-Zentrale (Beitrittsanfragen-Assistent, Warnliste, geplante Beiträge, Export) und 📚 Sammlungen (Welten/Avatare mit Tags). */
(function () {
    const UI = window.NexusUI;
    if (!UI) return;
    const esc = UI.esc;
    const $ = (s, r) => (r || document).querySelector(s);
    const toast = (m, bad) => UI.toast(m, bad);
    const fail = e => toast('⚠ ' + e.message, true);
    const LEVEL = { info: ['ℹ️', 'Hinweis'], warn: ['⚠️', 'Verwarnung'], ban: ['⛔', 'Bann-Grund'] };

    // ======================= 🛡 Mod-Zentrale =======================
    const mbox = document.getElementById('modBox');
    let groups = null, group = UI.store.get('modGroup', ''), mview = UI.store.get('modView', 'requests');
    function modOpen() {
        if (!mbox) return;
        if (!groups) {
            mbox.innerHTML = '<div class="vx-loading">Lade Gruppen …</div>';
            UI.api('groups').then(d => { groups = d.groups.filter(g => g.mod || g.owner || g.staff); if (!groups.some(g => g.id === group)) group = groups[0] ? groups[0].id : ''; modRender(); }).catch(e => { mbox.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
            return;
        }
        modRender();
    }
    function modRender() {
        if (!groups.length) { mbox.innerHTML = '<div class="nc-empty">Du hast in keiner Gruppe Moderationsrechte (mindestens Kicken).</div>'; return; }
        mbox.innerHTML = '<div class="nx-row rc-bar"><select id="modGroup">' + groups.map(g => '<option value="' + esc(g.id) + '"' + (g.id === group ? ' selected' : '') + '>' + esc(g.name) + (g.owner ? ' 👑' : '') + '</option>').join('') + '</select>'
            + '<span class="chips">' + [['requests', '📥 Beitrittsanfragen'], ['warn', '📒 Warnliste'], ['sched', '📢 Geplante Beiträge']].map(v => '<button type="button" class="chip' + (mview === v[0] ? ' on' : '') + '" data-mv="' + v[0] + '">' + v[1] + '</button>').join('') + '</span>'
            + '<a class="btn btn-sm" href="nexus.php?mod_export=' + encodeURIComponent(group) + '">⬇ Protokoll + Warnliste (CSV)</a></div><div id="modBody"><div class="vx-loading">…</div></div>';
        const body = $('#modBody');
        if (mview === 'requests') UI.api('mod_requests', { group }).then(d => { body.innerHTML = reqHtml(d.items); }).catch(e => { body.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
        if (mview === 'warn') UI.api('mod_warns').then(d => { body.innerHTML = warnHtml(d.warns); }).catch(fail);
        if (mview === 'sched') UI.api('mod_sched').then(d => { body.innerHTML = schedHtml(d.posts); }).catch(fail);
    }
    const SUG = { accept: ['✅', 'eher annehmen', 'ok'], reject: ['⛔', 'eher ablehnen', 'bad'], check: ['🔎', 'genauer ansehen', ''] };
    function reqHtml(items) {
        if (!items.length) return '<div class="nc-empty">Keine offenen Beitrittsanfragen. 🎉</div>';
        return '<p class="muted small">Die Einschätzung ist nur ein Vorschlag aus Vertrauensstufe, Kontoalter, Freundschaft, früheren Begegnungen und deiner Warnliste – entscheiden tust du.</p>'
            + items.map(r => { const s = SUG[r.suggestion]; return '<div class="md-req s-' + r.suggestion + '"><div class="md-who" data-user="' + esc(r.id) + '"><b>' + esc(r.name) + '</b>'
                + '<span class="muted small">' + esc(r.trust) + (r.age_days !== null ? ' · Konto ' + (r.age_days > 365 ? Math.floor(r.age_days / 365) + ' J.' : r.age_days + ' Tg.') : '') + (r.age_verified ? ' · 18+' : '') + (r.pronouns ? ' · ' + esc(r.pronouns) : '') + '</span>'
                + (r.bio ? '<div class="small md-bio">' + esc(r.bio) + '</div>' : '') + '</div>'
                + '<div class="md-sug"><b>' + s[0] + ' ' + s[1] + '</b><div class="small">' + r.why.map(esc).join(' · ') + '</div>'
                + r.warn.map(w => '<div class="small bad">' + LEVEL[w.level][0] + ' ' + esc(w.note) + '</div>').join('') + '</div>'
                + '<div class="md-act"><button type="button" class="btn btn-sm btn-gold" data-req="accept" data-id="' + esc(r.id) + '">Annehmen</button><button type="button" class="btn btn-sm" data-req="reject" data-id="' + esc(r.id) + '">Ablehnen</button>'
                + '<button type="button" class="nc-link" data-warnadd="' + esc(r.id) + '" data-name="' + esc(r.name) + '">📒 notieren</button></div></div>'; }).join('');
    }
    function warnHtml(l) {
        return '<form class="nc-card nx-row" id="modWarnF"><input type="text" name="user" placeholder="usr_… (aus dem Profil: ⋯ → ID kopieren)" required style="flex:1"><input type="text" name="name" placeholder="Name" style="width:140px">'
            + '<select name="level">' + Object.entries(LEVEL).map(([k, v]) => '<option value="' + k + '"' + (k === 'warn' ? ' selected' : '') + '>' + v.join(' ') + '</option>').join('') + '</select>'
            + '<input type="text" name="note" placeholder="Grund / Notiz" required style="flex:2"><button class="btn btn-sm btn-gold">＋ Eintragen</button></form>'
            + (l.length ? l.map(w => '<div class="rc-row"><span>' + LEVEL[w.level][0] + '</span><b data-user="' + esc(w.user) + '">' + esc(w.name) + '</b> ' + esc(w.note)
                + '<span>' + esc(UI.isoDT(w.ts * 1000)) + ' <button type="button" class="nc-x" data-warndel="' + esc(w.id) + '">✕</button></span></div>').join('') : '<p class="muted">Die Warnliste ist leer. Einträge erscheinen auch im Beitrittsanfragen-Assistenten.</p>');
    }
    function schedHtml(l) {
        const def = new Date(Date.now() + 3600000); def.setMinutes(0, 0, 0);
        const local = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        return '<form class="nc-card" id="modSchedF"><h4>📢 Beitrag einplanen</h4><div class="nx-row"><input type="text" name="title" maxlength="120" placeholder="Titel" required style="flex:1"><input type="datetime-local" name="at" value="' + local(def) + '" required></div>'
            + '<textarea name="text" rows="4" maxlength="2000" placeholder="Text" required style="width:100%"></textarea>'
            + '<div class="nx-row"><select name="visibility"><option value="group">nur Gruppe</option><option value="public">öffentlich</option></select><label class="check small"><input type="checkbox" name="notify"> Mitglieder benachrichtigen</label><button class="btn btn-sm btn-gold">Einplanen</button></div>'
            + '<p class="muted small">VRC Nexus muss zur geplanten Zeit laufen (auch im Infobereich reicht).</p></form>'
            + (l.length ? l.slice().sort((a, b) => b.at - a.at).map(p => '<div class="rc-row"><b>' + esc(p.title) + '</b> <span class="muted small">' + esc(p.group_name) + '</span><span>' + esc(UI.isoDT(p.at * 1000)) + ' · '
                + (p.status === 'wartet' ? '⏳' : p.status === 'veröffentlicht' ? '✔' : '✖ ' + esc(p.error)) + ' ' + esc(p.status) + ' <button type="button" class="nc-x" data-scheddel="' + esc(p.id) + '">✕</button></span></div>').join('') : '');
    }
    if (mbox) {
        mbox.addEventListener('change', e => { if (e.target.id === 'modGroup') { group = e.target.value; UI.store.set('modGroup', group); modRender(); } });
        mbox.addEventListener('click', e => {
            const v = e.target.closest('[data-mv]'); if (v) { mview = v.dataset.mv; UI.store.set('modView', mview); modRender(); return; }
            const r = e.target.closest('[data-req]');
            if (r) return UI.api('group_request', { id: group, user: r.dataset.id, decision: r.dataset.req }).then(d => { toast(d.msg); r.closest('.md-req').remove(); }).catch(fail);
            const wa = e.target.closest('[data-warnadd]');
            if (wa) { const note = prompt('Notiz zu ' + wa.dataset.name + ':'); if (note) UI.api('mod_warn_add', { user: wa.dataset.warnadd, name: wa.dataset.name, note, level: 'warn', group }).then(d => { toast(d.msg); modRender(); }).catch(fail); return; }
            const wd = e.target.closest('[data-warndel]');
            if (wd && confirm('Eintrag löschen?')) return UI.api('mod_warn_delete', { id: wd.dataset.warndel }).then(() => modRender()).catch(fail);
            const sd = e.target.closest('[data-scheddel]');
            if (sd && confirm('Geplanten Beitrag löschen?')) return UI.api('mod_sched_delete', { id: sd.dataset.scheddel }).then(() => modRender()).catch(fail);
        });
        mbox.addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target;
            if (f.id === 'modWarnF') UI.api('mod_warn_add', { user: f.user.value.trim(), name: f.name.value, level: f.level.value, note: f.note.value, group }).then(d => { toast(d.msg); modRender(); }).catch(fail);
            if (f.id === 'modSchedF') {
                const g = groups.find(x => x.id === group);
                UI.api('mod_sched_add', { group, group_name: g ? g.name : '', title: f.title.value, text: f.text.value, at: Math.floor(new Date(f.at.value).getTime() / 1000), visibility: f.visibility.value, notify: f.notify.checked ? '1' : '' })
                    .then(d => { toast(d.msg); modRender(); }).catch(fail);
            }
        });
    }

    // ======================= 📚 Sammlungen =======================
    const cbox = document.getElementById('collBox');
    let ckind = UI.store.get('collKind', 'world'), ctag = '', cq = '';
    function collOpen() {
        if (!cbox) return;
        UI.api('coll_list', { kind: ckind, tag: ctag, q: cq }).then(d => {
            cbox.innerHTML = '<div class="nx-row rc-bar"><span class="chips">' + [['world', '🌐 Welten'], ['avatar', '🎭 Avatare']].map(v => '<button type="button" class="chip' + (ckind === v[0] ? ' on' : '') + '" data-ck="' + v[0] + '">' + v[1] + '</button>').join('') + '</span>'
                + '<form class="nx-row" id="collAdd"><input type="text" name="wid" placeholder="ID oder Link einfügen (wrld_… / avtr_…)" style="width:300px" required><input type="text" name="tags" placeholder="Tags, mit Komma" style="width:160px"><button class="btn btn-sm btn-gold">＋</button></form></div>'
                + '<div class="nx-row"><input type="search" id="collQ" placeholder="🔎 suchen" value="' + esc(cq) + '"><span class="chips">' + '<button type="button" class="chip' + (!ctag ? ' on' : '') + '" data-ct="">alle</button>'
                + d.tags.map(t => '<button type="button" class="chip' + (ctag === t.tag ? ' on' : '') + '" data-ct="' + esc(t.tag) + '">#' + esc(t.tag) + ' <small>' + t.n + '</small></button>').join('') + '</span>'
                + '<button type="button" class="btn btn-sm" id="collRnd">🎲 Zufall' + (ctag ? ' aus #' + esc(ctag) : '') + '</button></div>'
                + (d.items.length ? '<div class="cl-grid">' + d.items.map(x => '<div class="cl-item"><div class="cl-img"' + (/^https:/.test(x.thumb) ? ' style="background-image:url(\'' + esc(x.thumb.replace(/['"()\\\s]/g, encodeURIComponent)) + '\')"' : '') + ' data-' + (x.kind === 'world' ? 'world' : 'avatar') + '="' + esc(x.id) + '"></div>'
                    + '<b>' + esc(x.name) + '</b><span class="muted small">' + esc(x.author) + (x.quest_poor ? ' · <span class="bad" title="' + esc(x.perf.map(p => p.platform + ': ' + p.rating).join(', ')) + '">⚠ Quest: Very Poor</span>' : '') + '</span>'
                    + '<div class="cl-tags">' + x.tags.map(t => '<span>#' + esc(t) + '</span>').join('') + '</div>' + (x.note ? '<div class="small">' + esc(x.note) + '</div>' : '')
                    + '<div class="nx-row"><button type="button" class="nc-link" data-cedit="' + esc(x.id) + '">✏️ Tags/Notiz</button><button type="button" class="nc-x" data-cdel="' + esc(x.id) + '">✕</button></div></div>').join('') + '</div>'
                    : '<div class="nc-empty">Noch nichts gesammelt. Füge oben eine ID ein oder nutze „📚 Sammlung“ im Welt-/Avatar-Fenster.</div>');
        }).catch(e => { cbox.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; });
    }
    if (cbox) {
        cbox.addEventListener('click', e => {
            const k = e.target.closest('[data-ck]'); if (k) { ckind = k.dataset.ck; ctag = ''; UI.store.set('collKind', ckind); collOpen(); return; }
            const t = e.target.closest('[data-ct]'); if (t) { ctag = t.dataset.ct; collOpen(); return; }
            if (e.target.closest('#collRnd')) return UI.api('coll_random', { kind: ckind, tag: ctag }).then(d => (d.item.kind === 'world' ? UI.openWorld : UI.openAvatar)(d.item.id)).catch(fail);
            const av = e.target.closest('[data-avatar]'); if (av) return UI.openAvatar(av.dataset.avatar);
            const ed = e.target.closest('[data-cedit]');
            if (ed) return UI.api('coll_has', { id: ed.dataset.cedit }).then(d => { const tags = prompt('Tags (mit Komma):', d.item.tags.join(', ')); if (tags === null) return; const note = prompt('Notiz:', d.item.note || ''); return UI.api('coll_update', { id: d.item.id, tags, note: note ?? d.item.note }).then(() => collOpen()); }).catch(fail);
            const del = e.target.closest('[data-cdel]');
            if (del && confirm('Aus der Sammlung entfernen?')) UI.api('coll_remove', { id: del.dataset.cdel }).then(() => collOpen()).catch(fail);
        });
        cbox.addEventListener('submit', e => { e.preventDefault(); const f = e.target; if (f.id === 'collAdd') UI.api('coll_add', { id: f.wid.value, tags: f.tags.value }).then(d => { toast(d.msg); collOpen(); }).catch(fail); });
        let qt = null;
        cbox.addEventListener('input', e => { if (e.target.id === 'collQ') { cq = e.target.value; clearTimeout(qt); qt = setTimeout(() => { collOpen(); setTimeout(() => { const i = $('#collQ'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }, 250); }, 400); } });
    }
    /** Knopf „📚 Sammlung“ im Welt-/Avatar-Fenster */
    function decorate(id) {
        const btns = document.querySelector('#userBody .vx-u-btns') || document.querySelector('#userBody .vx-w-head');
        if (!btns || document.getElementById('collBtn')) return;
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'btn btn-sm'; b.id = 'collBtn'; b.textContent = '📚 Sammlung';
        UI.api('coll_has', { id }).then(d => { if (d.item) b.textContent = '📚 in Sammlung' + (d.item.tags.length ? ' (#' + d.item.tags.join(' #') + ')' : ''); }).catch(() => {});
        b.onclick = () => { const tags = prompt('Zur Sammlung hinzufügen – Tags (mit Komma, optional):', ''); if (tags === null) return; UI.api('coll_add', { id, tags }).then(d => { toast(d.msg); b.textContent = '📚 in Sammlung'; }).catch(fail); };
        btns.appendChild(b);
    }
    window.NexusColl = { open: collOpen, decorate };
    window.NexusMod = { open: modOpen };
    for (const [pane, fn] of [['mod', modOpen], ['coll', collOpen]]) { const p = document.querySelector('[data-pane=' + pane + ']'); if (p && !p.hidden) fn(); }
})();
