/* VRC Nexus – rechte Leiste (bei Menü links): Freunde nach Status (Ich, Favoriten, Im Spiel, Online, Offline) und Gruppen mit offenen Instanzen */
(function () {
    const UI = window.NexusUI;
    if (!UI) return;
    const esc = UI.esc, store = UI.store;
    const get = (k, d) => { try { const v = JSON.parse(localStorage.getItem('vrcx-' + k)); return v === null ? d : v; } catch (e) { return d; } };
    const set = (k, v) => { try { localStorage.setItem('vrcx-' + k, JSON.stringify(v)); } catch (e) { /* egal */ } };
    let st = null, tab = get('sideTab', 'friends'), open = get('sideOpen', { me: true, fav: true, online: true, active: false, offline: false }), q = '';
    let groups = null, gAt = 0, gLoading = false, gErr = '';
    let box = null;

    function ensure() {
        if (box) return box;
        box = document.createElement('aside');
        box.className = 'vx-fside';
        box.id = 'friendSide';
        document.body.appendChild(box);
        box.addEventListener('click', onClick);
        box.addEventListener('input', e => { if (e.target.id === 'fsQ') { q = e.target.value.trim().toLowerCase(); renderList(); } });
        return box;
    }
    const sub = f => f.state === 'online' ? (f.loc && f.loc.kind === 'world' ? (f.loc.world_name || 'Welt') + (f.loc.access ? ' · ' + f.loc.access : '') : f.loc && f.loc.kind === 'private' ? 'Private Welt' : 'Im Spiel')
        : f.state === 'active' ? (f.status_desc || 'Website / App') : (f.last_login ? 'zuletzt ' + UI.ago(Math.floor(Date.parse(f.last_login) / 1000)) : 'offline');
    const row = f => '<button type="button" class="fs-row fs-' + esc(f.state) + '" data-user="' + esc(f.id) + '">' + UI.img(f.image, 'fs-img', f.name)
        + '<span class="fs-txt"><b data-no-i18n>' + UI.statusDot(f.state === 'offline' ? 'offline' : f.status) + ' ' + esc(f.name) + '</b><small data-no-i18n>' + esc(sub(f)) + '</small></span></button>';
    function section(key, title, list, count) {
        const on = !!open[key];
        return '<div class="fs-sec"><button type="button" class="fs-head" data-sec="' + key + '"><i>' + (on ? '▾' : '▸') + '</i> ' + title + ' <small>— ' + (count === undefined ? list.length : count) + '</small></button>'
            + (on ? '<div class="fs-list">' + (list.length ? list.slice(0, key === 'offline' ? 300 : 500).map(row).join('') : '<div class="fs-empty">–</div>') + '</div>' : '') + '</div>';
    }
    function renderList() {
        const el = box && box.querySelector('#fsBody');
        if (!el || !st) return;
        if (tab === 'groups') return renderGroups(el);
        const fr = (st.friends || []).filter(f => !q || f.name.toLowerCase().includes(q));
        const me = st.me || {};
        el.innerHTML = '<div class="fs-sec"><button type="button" class="fs-head" data-sec="me"><i>' + (open.me ? '▾' : '▸') + '</i> Ich</button>'
            + (open.me ? '<button type="button" class="fs-row fs-me" data-tab-go="account">' + UI.img(me.image, 'fs-img', me.name) + '<span class="fs-txt"><b data-no-i18n>' + UI.statusDot(me.status) + ' ' + esc(me.name || '') + '</b><small data-no-i18n>' + esc(me.status_desc || '') + '</small></span></button>' : '') + '</div>'
            + section('fav', 'Favoriten', fr.filter(f => f.fav_group))
            + section('online', 'Im Spiel', fr.filter(f => f.state === 'online'))
            + section('active', 'Online', fr.filter(f => f.state === 'active'))
            + section('offline', 'Offline', fr.filter(f => f.state === 'offline'));
    }
    function renderGroups(el) {
        if (!groups && !gLoading) loadGroups();
        if (!groups) { el.innerHTML = gErr ? '<div class="fs-empty">⚠ ' + esc(gErr) + '</div>' : '<div class="fs-empty">Lade …</div>'; return; }
        const by = {};
        for (const i of groups) (by[i.group_id] = by[i.group_id] || { name: i.group_name, items: [] }).items.push(i);
        const keys = Object.keys(by).filter(k => !q || by[k].name.toLowerCase().includes(q) || by[k].items.some(i => i.world.toLowerCase().includes(q)));
        el.innerHTML = keys.length ? keys.map(k => '<div class="fs-sec"><div class="fs-gname" data-no-i18n>🛡 ' + esc(by[k].name) + '</div>'
            + by[k].items.filter(i => !q || by[k].name.toLowerCase().includes(q) || i.world.toLowerCase().includes(q)).map(i => '<button type="button" class="fs-row fs-inst" data-inst="' + esc(i.loc.location) + '">' + UI.img(i.thumb, 'fs-thumb', '')
                + '<span class="fs-txt"><b data-no-i18n>' + esc(i.world || 'Welt') + '</b><small>👥 ' + i.users + (i.capacity ? ' / ' + i.capacity : '') + (i.loc.region ? ' · ' + esc(i.loc.region) : '') + '</small></span></button>').join('') + '</div>').join('')
            : '<div class="fs-empty">Gerade hat keine deiner Gruppen eine offene Instanz.</div>';
        el.insertAdjacentHTML('beforeend', '<div class="fs-foot"><button type="button" class="nc-link" data-greload>⟳ neu laden</button> <span class="muted small">' + (gAt ? UI.ago(Math.floor(gAt / 1000)) : '') + '</span></div>');
    }
    function loadGroups() {
        gLoading = true;
        UI.api('group_instances_all').then(d => { groups = d.items; gErr = ''; gAt = Date.now(); }).catch(e => { gErr = e.message; }).finally(() => { gLoading = false; render(); });
    }
    function render() {
        if (!document.body.classList.contains('fside-on')) { if (box) box.hidden = true; return; }
        ensure().hidden = false;
        if (!st) return;
        const inGame = (st.friends || []).filter(f => f.state === 'online').length;
        const gCount = groups ? new Set(groups.map(i => i.group_id)).size : null;
        box.innerHTML = '<div class="vx-grip" data-grip="fside" title="Breite ziehen"></div><div class="fs-tabs"><button type="button" class="vx-fstoggle" data-fsmini title="Leiste ein-/ausklappen">' + (document.body.classList.contains('fside-mini') ? '« Freunde' : '»') + '</button><button type="button" class="' + (tab === 'friends' ? 'on' : '') + '" data-fstab="friends">Freunde <small>(' + inGame + '/' + (st.friends || []).length + ')</small></button>'
            + '<button type="button" class="' + (tab === 'groups' ? 'on' : '') + '" data-fstab="groups">Gruppen' + (gCount !== null ? ' <small>(' + gCount + ')</small>' : '') + '</button></div>'
            + '<input type="search" id="fsQ" class="fs-q" placeholder="Suchen …" value="' + esc(q) + '"><div id="fsBody"></div>';
        renderList();
    }
    function onClick(e) {
        if (e.target.closest('[data-fsmini]')) { const on = !document.body.classList.contains('fside-mini'); document.body.classList.toggle('fside-mini', on); set('fsideMini', on); return render(); }
        const t = e.target.closest('[data-fstab]');
        if (t) { tab = t.dataset.fstab; set('sideTab', tab); if (tab === 'groups' && (!groups || Date.now() - gAt > 120000)) loadGroups(); return render(); }
        const s = e.target.closest('[data-sec]');
        if (s) { open[s.dataset.sec] = !open[s.dataset.sec]; set('sideOpen', open); return renderList(); }
        if (e.target.closest('[data-greload]')) { groups = null; return loadGroups(); }
        const u = e.target.closest('[data-user]');
        if (u) { e.preventDefault(); e.stopPropagation(); return UI.openUser(u.dataset.user); }
        const i = e.target.closest('[data-inst]');
        if (i) return UI.openInstance(i.dataset.inst);
        const g = e.target.closest('[data-tab-go]');
        if (g) return UI.tab(g.dataset.tabGo);
    }
    // Gruppen-Instanzen im Hintergrund alle 2 Minuten auffrischen, solange der Reiter offen ist
    setInterval(() => { if (tab === 'groups' && document.body.classList.contains('fside-on') && !document.hidden) loadGroups(); }, 120000);
    // ---------- Einklappen (links) & Breite ziehen (beide Leisten), gemerkt pro Gerät ----------
    function layout() {
        const b = document.body, menu = document.getElementById('tabs');
        b.classList.toggle('nav-mini', !!get('navMini', false));
        b.classList.toggle('fside-mini', !!get('fsideMini', false));
        const nw = get('navW', 0), fw = get('fsideW', 0);
        if (nw) b.style.setProperty('--nav-w', nw + 'px'); else b.style.removeProperty('--nav-w');
        if (fw) b.style.setProperty('--fside-w', fw + 'px'); else b.style.removeProperty('--fside-w');
        if (menu && !menu.querySelector('.vx-navtoggle')) {
            const t = document.createElement('button');
            t.type = 'button'; t.className = 'vx-navtoggle'; t.title = 'Menü ein-/ausklappen';
            t.addEventListener('click', e => { e.stopPropagation(); const on = !b.classList.contains('nav-mini'); b.classList.toggle('nav-mini', on); set('navMini', on); t.textContent = on ? '»' : '«'; });
            menu.appendChild(t);
            const g = document.createElement('div'); g.className = 'vx-grip vx-grip-nav'; g.dataset.grip = 'nav'; g.title = 'Breite ziehen';
            document.body.appendChild(g);   // fest an der Kante – im scrollenden Menü würde er abgeschnitten
        }
        const t = menu && menu.querySelector('.vx-navtoggle');
        if (t) t.textContent = b.classList.contains('nav-mini') ? '»' : '«';
    }
    document.addEventListener('pointerdown', e => {
        const g = e.target.closest('.vx-grip');
        if (!g) return;
        e.preventDefault();
        const which = g.dataset.grip, b = document.body;
        b.classList.add('resizing');
        const move = ev => {
            const w = which === 'nav' ? Math.max(170, Math.min(380, ev.clientX)) : Math.max(220, Math.min(520, window.innerWidth - ev.clientX));
            b.style.setProperty(which === 'nav' ? '--nav-w' : '--fside-w', w + 'px');
            set(which === 'nav' ? 'navW' : 'fsideW', w);
        };
        const up = () => { b.classList.remove('resizing'); document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    });
    // Doppelklick auf den Griff = Standardbreite
    document.addEventListener('dblclick', e => { const g = e.target.closest('.vx-grip'); if (!g) return; set(g.dataset.grip === 'nav' ? 'navW' : 'fsideW', 0); layout(); });
    layout();
    window.NexusSide = { update(state) { st = state; const a = document.activeElement; if (box && a && a.id === 'fsQ') { renderList(); return; } render(); }, render };
    void store;
})();
