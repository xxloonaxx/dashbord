/* VRC Nexus Desktop – neue Seiten: Freunde-Orte, Spielerliste, Instanz-Aktivität, Freundesnetz, Werkzeuge, Einstellungen. */
(function () {
    const UI = window.NexusUI;
    if (!UI) return;
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const { api, toast, esc, img } = UI;
    const fail = box => x => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; };
    const loading = (box, t) => { box.innerHTML = '<div class="vx-loading">' + esc(t || 'Lade …') + '</div>'; };
    const t2 = ts => ts ? new Date(ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '';
    const dt = ts => ts ? new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
    const size = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB';
    const MOBILE = !!window.VRCX.mobile;
    const dl = href => (window.NexusMobile ? window.NexusMobile.download(href) : (location.href = href));
    const run = (act, data, after) => api(act, data).then(r => { if (r.msg) toast(r.msg); if (after) after(r); return r; }).catch(x => { toast(x.message, true); throw x; });

    // ======================= Aussehen aus den Einstellungen =======================
    function applyUi(s) {
        const b = document.body;
        b.classList.toggle('no-anim', s['ui.animations'] === false);
        b.classList.toggle('no-bg', s['ui.background'] === false);
        b.classList.toggle('no-img', s['ui.show_images'] === false);
        b.classList.toggle('sticky-menu', s['ui.sticky_menu'] !== false);
        b.classList.toggle('wide', !!s['ui.wide']);
        b.classList.remove('menu-icons', 'menu-text', 'font-system', 'font-mono');
        if (s['ui.menu_style'] === 'icons') b.classList.add('menu-icons');
        if (s['ui.menu_style'] === 'text') b.classList.add('menu-text');
        if (s['ui.font'] === 'system') b.classList.add('font-system');
        if (s['ui.font'] === 'mono') b.classList.add('font-mono');
        const a = /^#[0-9a-f]{6}$/i.test(s['ui.accent'] || '') ? s['ui.accent'] : '#38d5e6';
        const rgb = [1, 3, 5].map(i => parseInt(a.slice(i, i + 2), 16)).join(', ');
        const r = document.documentElement.style;
        r.setProperty('--accent-cyan', a);
        r.setProperty('--border-color', 'rgba(' + rgb + ', 0.3)');
        r.setProperty('--accent-glow', 'rgba(' + rgb + ', 0.3)');
        document.body.style.zoom = (Math.max(70, Math.min(150, +s['ui.zoom'] || 100)) / 100).toString();
    }
    applyUi(window.VRCX.ui || {});
    if (MOBILE) {
        document.body.classList.add('mobile');
        $$('#tabs [data-tab=client], #tabs [data-tab=players], #tabs [data-tab=activity], #clientStandalone').forEach(el => el.parentNode.removeChild(el));
    } else $$('#tabs [data-mobile]').forEach(el => el.parentNode.removeChild(el));

    // Direkt-Zugriff: Knopf im Menü + Strg+D
    $('#directBtn').addEventListener('click', () => UI.readClipboard(false));
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'd') { e.preventDefault(); UI.readClipboard(false); }
        if (e.key === 'Escape') $$('#tabs .vx-mgroup.open').forEach(x => x.classList.remove('open'));
    });

    // ======================= Seitenwechsel =======================
    let current = '', plTimer = null, netTimer = null;
    function open(name, sub) {
        current = name;
        clearInterval(plTimer); plTimer = null;
        clearInterval(netTimer); netTimer = null;
        if (name === 'locations') renderLocations();
        if (name === 'players') { loadPlayers(); plTimer = setInterval(() => { if (!document.hidden && !document.activeElement.classList.contains('pl-memo')) loadPlayers(); }, 4000); }
        if (name === 'activity') loadActivity('');
        if (name === 'network') loadNetwork(false);
        if (name === 'tools') renderTools();
        if (name === 'settings') loadSettings();
        if (name === 'remote') { loadRemote(); plTimer = setInterval(() => { if (!document.hidden && !document.activeElement.closest('#remBox form')) loadRemote(); }, 5000); }
        if (name === 'client' && sub) setTimeout(() => { const b = $('#ncSub [data-sub="' + sub + '"]'); if (b) b.click(); }, 300);
        if (name === 'gallery' && sub === 'inventory') setTimeout(() => { const b = $('#galKinds [data-gk=inventory]'); if (b) b.click(); }, 600);
    }

    // ======================= Freunde-Orte =======================
    function renderLocations() {
        const box = $('#locBox'), st = UI.state();
        if (!st) return loading(box);
        const s = window.VRCX.ui || {};
        const groups = new Map();
        for (const f of st.friends) {
            let key, title, loc = f.loc || {};
            if (f.state === 'online' && loc.kind === 'world') { key = loc.location; title = loc.world_name || loc.world; }
            else if (f.state === 'online') { key = s['social.loc_private'] === false ? 'p:' + f.id : 'private'; title = '🔒 Private Welt / unterwegs'; }
            else if (f.state === 'active' && s['social.loc_active'] !== false) { key = 'active'; title = '💻 Online auf der Website / App'; }
            else continue;
            if (!groups.has(key)) groups.set(key, { key, title, loc, people: [] });
            groups.get(key).people.push(f);
        }
        const list = [...groups.values()].sort((a, b) => (a.key === 'active') - (b.key === 'active') || (a.key === 'private') - (b.key === 'private') || b.people.length - a.people.length);
        const me = st.me.location;
        box.innerHTML = '<div class="nx-row"><b>' + list.reduce((n, g) => n + g.people.length, 0) + '</b> Freunde an <b>' + list.filter(g => g.loc.kind === 'world').length + '</b> Orten'
            + '<button type="button" class="btn btn-sm" id="locRefresh">⟳ Aktualisieren</button></div>'
            + (list.length ? list.map(g => '<div class="loc-inst"><div class="loc-head">'
                + (g.loc.world_thumb ? img(g.loc.world_thumb, 'loc-thumb', '') : '')
                + '<div style="flex:1;min-width:200px"><b ' + (g.loc.world ? 'data-world="' + esc(g.loc.world) + '"' : '') + '>' + esc(g.title) + '</b>' + (g.loc.location === me ? ' <span class="vx-pill rep">du bist hier</span>' : '')
                + '<div class="vx-small">' + (g.loc.kind === 'world' ? esc(g.loc.label) : '') + ' · ' + g.people.length + ' Freund' + (g.people.length === 1 ? '' : 'e') + '</div></div>'
                + (g.loc.kind === 'world' && g.loc.access !== 'Invite' ? '<a class="btn btn-sm btn-gold" href="' + esc(UI.launch(g.loc.location)) + '">▶ Beitreten</a> <button type="button" class="btn btn-sm" data-inst="' + esc(g.loc.location) + '">ℹ Instanz</button>' : '')
                + '</div><div class="loc-people">' + g.people.map(f => '<span class="loc-p" data-user="' + esc(f.id) + '">' + img(f.image, 'vx-av', f.name) + UI.statusDot(f.status) + '<b style="color:' + esc(f.trust[1]) + '">' + esc(f.name) + '</b>'
                    + (f.favorite ? ' ⭐' : '') + ' <small class="vx-small">' + esc(UI.dur(f.since)) + '</small></span>').join('') + '</div></div>').join('')
                : '<div class="vx-empty">Gerade ist niemand online.</div>');
        $('#locRefresh').onclick = () => UI.load(true, true).then(renderLocations);
    }
    document.addEventListener('click', e => {
        const u = e.target.closest('#locBox [data-user], #plBox [data-user], #actBox [data-user], #toolsBox [data-user]');
        if (u && !e.target.closest('a, input, button')) { UI.openUser(u.dataset.user); return; }
        const w = e.target.closest('#locBox [data-world], #actBox [data-world], #toolsBox [data-world]');
        if (w) { UI.openWorld(w.dataset.world); return; }
        const i = e.target.closest('#locBox [data-inst], #plBox [data-inst], #actBox [data-inst]');
        if (i) UI.openInstance(i.dataset.inst);
    });
    // nach jedem Abgleich neu zeichnen
    setInterval(() => { if (current === 'locations') renderLocations(); }, 15000);

    // ======================= Spielerliste =======================
    let plSort = { key: 'since', dir: 1 }, plData = null;
    const TR = ['VRChat-Team', 'Trusted User', 'Known User', 'User', 'New User', 'Visitor', 'Nuisance', '?'];
    function loadPlayers() { return api('tools_players').then(d => { plData = d; renderPlayers(); }).catch(fail($('#plBox'))); }
    function plRow(p, left) {
        return '<tr class="' + (left ? 'pl-left' : '') + '" data-user="' + esc(p.id) + '">'
            + '<td class="pl-av">' + img(p.image, 'vx-av', '') + '</td>'
            + '<td title="' + esc(left ? 'gegangen ' + t2(p.left) : 'seit ' + t2(p.since)) + '">' + (left ? '⬅ ' + t2(p.left) : esc(UI.dur(p.since))) + '</td>'
            + '<td><span class="pl-name" style="color:' + esc(p.trust[1]) + '">' + esc(p.name) + '</span>' + (p.pronouns ? ' <span class="vx-pron">' + esc(p.pronouns) + '</span>' : '') + (!p.id ? ' <span class="vx-small">(ohne ID)</span>' : '') + '</td>'
            + '<td><span style="color:' + esc(p.trust[1]) + '">' + esc(p.trust[0] === '?' ? (p.loaded ? '' : '…') : p.trust[0]) + '</span></td>'
            + '<td>' + (p.status ? UI.statusDot(p.status) + ' ' : '') + '<span class="vx-small">' + esc(p.status_desc) + '</span></td>'
            + '<td class="pl-icons">' + p.icons.map(i => '<span title="' + esc(i[2]) + '">' + i[1] + '</span>').join('') + '</td>'
            + '<td>' + esc(p.platform) + '</td>'
            + '<td class="vx-small">' + p.langs.map(esc).join('<br>') + '</td>'
            + '<td class="pl-links">' + p.links.map(l => '<a href="' + esc(l) + '" target="_blank" rel="noopener" title="' + esc(l) + '">🔗</a>').join('') + '</td>'
            + '<td class="pl-note" title="' + esc(p.note) + '">' + esc(p.note.slice(0, 80)) + '</td>'
            + '<td>' + (p.id ? '<input class="pl-memo" data-memo="' + esc(p.id) + '" value="' + esc(p.memo) + '" placeholder="Memo …" maxlength="4000">' : '') + '</td></tr>';
    }
    function renderPlayers() {
        const d = plData, box = $('#plBox');
        if (!d) return;
        const q = ($('#plQ') || {}).value || '';
        if (!d.running && !d.players.length) {
            box.innerHTML = '<div class="nx-panel"><h2>👥 Spielerliste</h2><p>VRChat läuft gerade nicht' + (d.logs ? '' : ' – und das Mitlesen der VRChat-Logs ist ausgeschaltet (Spiel &amp; OSC → Einstellungen)') + '.</p>'
                + '<p class="vx-small">Sobald du in einer Instanz bist, siehst du hier, wer da ist – mit VRChat+, Altersverifizierung, Rang, Plattform, Sprachen, Links, VRChat-Notiz und eigenem Memo.</p></div>';
            return;
        }
        const k = plSort.key, dir = plSort.dir;
        const val = p => k === 'name' ? p.name.toLowerCase() : k === 'rank' ? TR.indexOf(p.trust[0]) : k === 'icons' ? -p.icons.length : k === 'platform' ? p.platform : p.since;
        const rows = d.players.filter(p => !q || (p.name + ' ' + p.memo + ' ' + p.note + ' ' + p.langs.join(' ')).toLowerCase().includes(q.toLowerCase()))
            .sort((a, b) => { const x = val(a), y = val(b); return (x < y ? -1 : x > y ? 1 : 0) * dir; });
        const cnt = id => d.players.filter(p => p.icons.some(i => i[0] === id)).length;
        const focused = document.activeElement && document.activeElement.id === 'plQ';
        box.innerHTML = '<div class="nx-panel"><div class="pl-head"><h2>🌐 <span ' + (d.world_id ? 'data-world="' + esc(d.world_id) + '" style="cursor:pointer"' : '') + '>' + esc(d.world_name || 'Unbekannte Welt') + '</span></h2>'
            + (d.loc ? '<span class="vx-pill">' + esc(d.loc.access) + '</span><span class="vx-pill">' + esc(d.loc.region) + '</span><span class="vx-small">#' + esc(d.loc.name) + '</span>' : '')
            + '<span class="vx-small">seit ' + esc(t2(d.since)) + ' (' + esc(UI.dur(d.since)) + ')</span>'
            + (d.owner ? '<span class="vx-small">👑 ' + (d.owner.id.startsWith('usr_') ? '<a href="#" data-user="' + esc(d.owner.id) + '">' + esc(d.owner.name || 'Besitzer') + '</a>' : esc(d.owner.name)) + '</span>' : '')
            + (d.world_id ? '<button type="button" class="btn btn-sm" data-inst="' + esc(d.world_id + ':' + d.instance) + '">ℹ Instanz</button>' : '') + '</div>'
            + '<div class="nx-row"><b>' + d.players.length + '</b> Spieler · 🤝 ' + cnt('friend') + ' Freunde · ➕ ' + cnt('vrcplus') + ' VRC+ · 🔞 ' + cnt('age') + ' 18+ verifiziert'
            + (d.queue ? ' · <span class="vx-small">lade ' + d.queue + ' Profile …</span>' : '') + (!d.fetch ? ' · <span class="vx-small">Profile laden ist aus (Einstellungen → Sozial)</span>' : '')
            + '<input type="search" id="plQ" placeholder="Filtern (Name, Memo, Sprache) …" value="' + esc(q) + '" style="margin-left:auto;min-width:220px"></div></div>'
            + '<div class="pl-wrap"><table class="nx-list"><thead><tr><th>Avatar</th><th data-sort="since">Zeit</th><th data-sort="name">Anzeigename</th><th data-sort="rank">Rang</th><th>Status</th><th data-sort="icons">Symbole</th>'
            + '<th data-sort="platform">Plattform</th><th>Sprache</th><th>Bio-Links</th><th>VRChat-Notiz</th><th>Memo</th></tr></thead><tbody>'
            + (rows.map(p => plRow(p, false)).join('') || '<tr><td colspan="11" class="vx-empty">Niemand gefunden.</td></tr>')
            + (d.left.length ? '<tr><td colspan="11" class="vx-small" style="padding-top:12px">⬅ Haben die Instanz verlassen</td></tr>' + d.left.map(p => plRow(p, true)).join('') : '')
            + '</tbody></table></div>'
            + '<p class="pl-legend">🫵 du · 👑 Instanz-Besitzer · 🤝 Freund · ⭐ Favorit · ➕ VRChat+ · 🔞 altersverifiziert 18+ · 🪪 altersverifiziert · 🌱 Early Adopter · 🛠 VRChat-Team · ⚠ als Störenfried markiert'
            + ' — Memos werden lokal gespeichert (Enter oder Feld verlassen).</p>';
        if (focused) { const i = $('#plQ'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }
    }
    $('#plBox').addEventListener('click', e => {
        const th = e.target.closest('th[data-sort]');
        if (th) { plSort = { key: th.dataset.sort, dir: plSort.key === th.dataset.sort ? -plSort.dir : 1 }; renderPlayers(); }
    });
    $('#plBox').addEventListener('input', e => { if (e.target.id === 'plQ') renderPlayers(); });
    $('#plBox').addEventListener('change', e => {
        const m = e.target.closest('[data-memo]');
        if (m) run('tools_memo', { id: m.dataset.memo, memo: m.value }).then(() => { const p = plData && plData.players.find(x => x.id === m.dataset.memo); if (p) p.memo = m.value; }).catch(() => {});
    });
    $('#plBox').addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('.pl-memo')) e.target.blur(); });

    // ======================= Instanz-Aktivität =======================
    function loadActivity(day) {
        const box = $('#actBox');
        loading(box, 'Lade Aktivität …');
        api('tools_activity', { day }).then(d => {
            const span = d.to - d.from;
            const total = d.segments.reduce((n, s) => n + s.end - s.start, 0);
            box.innerHTML = '<div class="nx-panel"><h2>📈 Instanz-Aktivität</h2><div class="nx-row"><input type="date" id="actDay" value="' + esc(d.day) + '">'
                + '<select id="actDays"><option value="">Tage mit Aktivität …</option>' + d.days.map(x => '<option>' + esc(x) + '</option>').join('') + '</select>'
                + '<span class="vx-small">' + d.segments.length + ' Instanzen · ' + UI.hm(total) + ' im Spiel</span></div>'
                + '<div class="act-tl">' + d.segments.map(s => '<div class="act-seg" title="' + esc(s.world_name + ' · ' + t2(s.start) + '–' + t2(s.end) + ' · bis zu ' + s.max + ' Spieler') + '" data-seg="' + d.segments.indexOf(s) + '" style="left:'
                    + ((s.start - d.from) / span * 100).toFixed(3) + '%;width:' + Math.max(0.15, (s.end - s.start) / span * 100).toFixed(3) + '%;opacity:' + (0.45 + Math.min(1, s.max / 20) * 0.55).toFixed(2) + '">' + esc(s.world_name) + '</div>').join('') + '</div>'
                + '<div class="act-hours">' + [0, 3, 6, 9, 12, 15, 18, 21, 24].map(h => '<span>' + h + ' h</span>').join('') + '</div></div>'
                + '<div class="nx-panel"><table class="nx-list"><thead><tr><th>Von</th><th>Bis</th><th>Dauer</th><th>Welt</th><th>Zugang</th><th>Spieler (max.)</th><th>Getroffen</th><th></th></tr></thead><tbody>'
                + (d.segments.slice().reverse().map(s => '<tr><td>' + t2(s.start) + '</td><td>' + t2(s.end) + '</td><td>' + UI.hm(s.end - s.start) + '</td><td><a href="#" data-world="' + esc(s.world_id) + '">' + esc(s.world_name) + '</a></td><td>' + esc(s.access)
                    + '</td><td>' + s.max + '</td><td class="vx-small">' + esc(s.people.slice(0, 12).join(', ')) + (s.people.length > 12 ? ' …' : '') + '</td><td>' + (s.location && s.access !== 'Invite' ? '<a class="btn btn-sm" href="' + esc(UI.launch(s.location)) + '">▶</a>' : '') + '</td></tr>').join('')
                    || '<tr><td colspan="8" class="vx-empty">An diesem Tag nichts aufgezeichnet (VRChat-Logs werden unter „Spiel &amp; OSC“ mitgelesen).</td></tr>')
                + '</tbody></table></div>';
            $('#actDay').onchange = e => loadActivity(e.target.value);
            $('#actDays').onchange = e => { if (e.target.value) loadActivity(e.target.value); };
        }).catch(fail(box));
    }

    // ======================= Gemeinsame Freunde (Netz) =======================
    let net = null;
    function loadNetwork(start) {
        const box = $('#netBox');
        if (!box.firstChild) loading(box, 'Lade Freundesnetz …');
        api('tools_network', { start: start ? 1 : '' }).then(d => {
            drawNetwork(d);
            if (d.busy || start) { clearInterval(netTimer); netTimer = setInterval(() => { if (current === 'network') api('tools_network', {}).then(x => { drawNetwork(x); if (!x.busy) { clearInterval(netTimer); netTimer = null; } }); }, 4000); }
        }).catch(fail(box));
    }
    function drawNetwork(d) {
        const box = $('#netBox');
        if (!$('#netCanvas')) {
            box.innerHTML = '<div class="nx-panel"><h2>🕸 Gemeinsame Freunde</h2><div class="nx-row"><span id="netInfo" class="vx-small"></span><button type="button" class="btn btn-sm btn-gold" id="netStart">⟳ Netz aufbauen / aktualisieren</button>'
                + '<input type="search" id="netQ" placeholder="Freund suchen …"></div><p class="vx-small">Fragt für jeden Freund bei VRChat ab, welche deiner Freunde er auch kennt (langsam, etwa 1–2 Freunde pro Sekunde; Ergebnisse werden gespeichert). Linien = kennen sich. Ziehen zum Verschieben, Mausrad zum Zoomen, Klick öffnet das Profil.</p>'
                + '<div style="position:relative"><canvas id="netCanvas"></canvas><div class="net-info" id="netTip" hidden></div></div></div>';
            $('#netStart').onclick = () => loadNetwork(true);
            $('#netQ').oninput = () => { if (net) net.q = $('#netQ').value.toLowerCase(); };
            setupCanvas();
        }
        $('#netInfo').innerHTML = '<b>' + d.nodes.length + '</b> Freunde · <b>' + d.edges.length + '</b> Verbindungen · abgefragt ' + d.done + '/' + d.total + (d.busy ? ' · <span class="nx-ok">lädt …</span>' : '');
        const old = net ? new Map(net.nodes.map(n => [n.id, n])) : new Map();
        const W = 1000, H = 640;
        const nodes = d.nodes.map((n, i) => old.get(n.id) ? Object.assign(old.get(n.id), n) : Object.assign({}, n, { x: W / 2 + Math.cos(i * 2.4) * (120 + i), y: H / 2 + Math.sin(i * 2.4) * (120 + i), vx: 0, vy: 0 }));
        const byId = new Map(nodes.map(n => [n.id, n]));
        net = Object.assign(net || { zoom: 1, ox: 0, oy: 0, q: '' }, { nodes, edges: d.edges.map(([a, b]) => [byId.get(a), byId.get(b)]).filter(e => e[0] && e[1]), heat: 300 });
    }
    function setupCanvas() {
        const c = $('#netCanvas'), ctx = c.getContext('2d');
        let drag = null, hover = null, moved = false;
        const pos = e => { const r = c.getBoundingClientRect(); return { x: (e.clientX - r.left) * (1000 / r.width), y: (e.clientY - r.top) * (640 / r.height) }; };
        const world = p => ({ x: (p.x - net.ox) / net.zoom, y: (p.y - net.oy) / net.zoom });
        const hit = p => { const w = world(p); return net.nodes.find(n => (n.x - w.x) ** 2 + (n.y - w.y) ** 2 < (6 + Math.sqrt(n.deg) * 2) ** 2 + 30); };
        c.width = 1000; c.height = 640;
        c.onmousedown = e => { const p = pos(e); moved = false; drag = { node: hit(p), p, ox: net.ox, oy: net.oy }; };
        c.onmousemove = e => {
            const p = pos(e);
            if (drag) {
                moved = true;
                if (drag.node) { const w = world(p); drag.node.x = w.x; drag.node.y = w.y; drag.node.vx = drag.node.vy = 0; net.heat = Math.max(net.heat, 60); }
                else { net.ox = drag.ox + p.x - drag.p.x; net.oy = drag.oy + p.y - drag.p.y; }
            }
            hover = hit(p);
            const tip = $('#netTip');
            tip.hidden = !hover;
            if (hover) { tip.textContent = hover.name + ' · ' + hover.deg + ' gemeinsame'; tip.style.left = (e.offsetX + 12) + 'px'; tip.style.top = (e.offsetY + 12) + 'px'; }
        };
        c.onmouseup = () => { if (drag && drag.node && !moved) UI.openUser(drag.node.id); drag = null; };
        c.onmouseleave = () => { drag = null; $('#netTip').hidden = true; };
        c.onwheel = e => { e.preventDefault(); const p = pos(e), f = e.deltaY < 0 ? 1.15 : 1 / 1.15; net.ox = p.x - (p.x - net.ox) * f; net.oy = p.y - (p.y - net.oy) * f; net.zoom *= f; };
        const step = () => {
            if (!document.body.contains(c)) return;
            if (net && current === 'network') {
                const N = net.nodes;
                if (net.heat > 0) {
                    net.heat--;
                    for (let i = 0; i < N.length; i++) {
                        const a = N[i];
                        for (let j = i + 1; j < N.length; j++) {
                            const b = N[j]; let dx = a.x - b.x, dy = a.y - b.y; let d2 = dx * dx + dy * dy || 1;
                            if (d2 > 90000) continue;
                            const f = 900 / d2; dx *= f; dy *= f; a.vx += dx; a.vy += dy; b.vx -= dx; b.vy -= dy;
                        }
                        a.vx += (500 - a.x) * 0.002; a.vy += (320 - a.y) * 0.002;
                    }
                    for (const [a, b] of net.edges) { const dx = b.x - a.x, dy = b.y - a.y, d = Math.sqrt(dx * dx + dy * dy) || 1, f = (d - 70) * 0.004; a.vx += dx * f; a.vy += dy * f; b.vx -= dx * f; b.vy -= dy * f; }
                    for (const n of N) { n.vx *= 0.82; n.vy *= 0.82; n.x += Math.max(-8, Math.min(8, n.vx)); n.y += Math.max(-8, Math.min(8, n.vy)); }
                }
                ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, 1000, 640);
                ctx.setTransform(net.zoom, 0, 0, net.zoom, net.ox, net.oy);
                const acc = getComputedStyle(document.documentElement).getPropertyValue('--accent-cyan').trim() || '#38d5e6';
                ctx.lineWidth = 0.6 / net.zoom;
                for (const [a, b] of net.edges) {
                    const hl = hover && (a === hover || b === hover);
                    ctx.strokeStyle = hl ? acc : 'rgba(120,160,190,0.18)'; ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
                }
                ctx.font = (11 / Math.max(0.6, net.zoom)) + 'px Segoe UI, sans-serif';
                for (const n of N) {
                    const r = 4 + Math.sqrt(n.deg) * 1.6, match = net.q && n.name.toLowerCase().includes(net.q);
                    ctx.fillStyle = match ? '#f3c262' : n.color || acc;
                    ctx.beginPath(); ctx.arc(n.x, n.y, match ? r + 4 : r, 0, 7); ctx.fill();
                    if (n.fav) { ctx.strokeStyle = '#f3c262'; ctx.lineWidth = 1.5 / net.zoom; ctx.stroke(); ctx.lineWidth = 0.6 / net.zoom; }
                    if (net.zoom > 1.3 || n.deg > 6 || n === hover || match) { ctx.fillStyle = '#e2e8f0'; ctx.fillText(n.name, n.x + r + 2, n.y + 4); }
                }
            }
            requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    }

    // ======================= Werkzeuge =======================
    const TOOLS = [
        ['Bilder', [['shots', '📷', 'Screenshot-Manager', 'Screenshots ansehen, Welt & Mitspieler anzeigen und eintragen'], ['inventory', '🎒', 'Inventar', 'Bilder, Emojis, Sticker und Inventar (Galerie)']]],
        ['Verknüpfungen', [['f:photos', '🖼', 'VRChat-Fotos', 'Öffnet deinen VRChat-Fotoordner'], ['f:steam', '🎞', 'Steam-Screenshots', 'Öffnet deinen Steam-Screenshot-Ordner für VRChat'],
            ['f:nexus', '📁', 'VRC-Nexus-Daten', 'Öffnet den Datenordner dieser App'], ['f:vrchat', '📂', 'VRChat-Daten', 'Öffnet den VRChat-Datenordner (LocalLow)'],
            ['f:logs', '📜', 'VRChat-Logs', 'Öffnet den Ordner mit den VRChat-Logdateien'], ['f:crashes', '💥', 'Absturzberichte', 'Öffnet den Ordner mit VRChat-Crash-Dumps']]],
        ['Gruppe', [['calendar', '📅', 'Kalender', 'Gruppen-Events'], ['blocked', '⛔', 'Blockierte Gruppen', 'Blockierte Gruppen ansehen und freigeben'],
            ['ginvites', '✉', 'Gruppen-Einladungen', 'Offene Gruppen-Einladungen annehmen, ablehnen oder blockieren']]],
        ['Systemwerkzeuge', [['config', '⚙', 'VRChat-Konfiguration', 'config.json bearbeiten (Cache, Kamera, Fotos …)'], ['launch', '🚀', 'Startoptionen', 'VRChat mit eigenen Startoptionen starten'],
            ['registry', '🗄', 'VRChat-Registry-Sicherung', 'Einstellungen von VRChat sichern und wiederherstellen'], ['auto', '🤖', 'Auto-Status & Einladungen', 'Status automatisch ändern, Einladungs-Anfragen automatisch beantworten']]],
        ['Marktplatz', [['shop', '🛒', 'VRChat-Store', 'Shops von Creators ansehen'], ['purchases', '🧾', 'Kaufverlauf', 'Deine Käufe im Store und Marktplatz']]],
        ['Sonstiges', [['messages', '✏', 'Einladungsnachrichten', 'Texte für Einladungen und Antworten bearbeiten'], ['reports', '🚩', 'Meine Meldungen', 'Eingereichte Meldungen ansehen und löschen'],
            ['news', '📢', 'Info-Push', 'Ankündigungen von VRChat'], ['streamdeck', '🎛', 'Stream Deck', 'Plugin einrichten: Status, Chatbox, OSC per Tastendruck']]],
        ['Export', [['discord', '💬', 'Discord-Namen', 'Discord-Namen deiner VRChat-Freunde finden'], ['memos', '📝', 'Memos exportieren', 'Memos als VRChat-Notizen speichern oder als CSV'],
            ['x:friends', '📋', 'Freundesliste exportieren', 'Freundesliste als CSV'], ['x:avatars', '🎭', 'Eigene Avatare exportieren', 'Deine Avatare als CSV']]],
    ];
    // Auf Android: nur, was ohne PC funktioniert
    const PC_ONLY = new Set(['shots', 'f:photos', 'f:steam', 'f:nexus', 'f:vrchat', 'f:logs', 'f:crashes', 'config', 'launch', 'registry', 'auto', 'streamdeck']);
    if (MOBILE) TOOLS.forEach(sec => { sec[1] = sec[1].filter(t => !PC_ONLY.has(t[0])); });
    for (let i = TOOLS.length - 1; i >= 0; i--) if (!TOOLS[i][1].length) TOOLS.splice(i, 1);
    const closedSecs = new Set(UI.store.get('toolsClosed', []));
    function renderTools() {
        const box = $('#toolsBox');
        box.innerHTML = TOOLS.map(([title, items]) => '<div class="nx-sec' + (closedSecs.has(title) ? ' closed' : '') + '" data-sec="' + esc(title) + '"><h3>' + esc(title) + '</h3><div class="nx-tiles">'
            + items.map(([id, ic, t, s]) => '<button type="button" class="nx-tile" data-tool="' + id + '"><span>' + ic + '</span><div><b>' + esc(t) + '</b><small>' + esc(s) + '</small></div>' + (id === 'ginvites' ? '<i class="nx-badge" id="ginvBadge" hidden></i>' : '') + '</button>').join('')
            + '</div></div>').join('');
        api('tools_group_invites').then(d => { const b = $('#ginvBadge'); if (b && d.groups.length) { b.hidden = false; b.textContent = d.groups.length; } }).catch(() => {});
    }
    function toolView(title, html) {
        $('#toolsBox').innerHTML = '<div class="nx-panel"><h2><button type="button" class="btn btn-sm nx-back" data-tool="back">← Werkzeuge</button>' + title + '</h2><div id="toolBody">' + (html || '<div class="vx-loading">Lade …</div>') + '</div></div>';
        return $('#toolBody');
    }
    $('#toolsBox').addEventListener('click', e => {
        const h = e.target.closest('.nx-sec > h3');
        if (h) { const s = h.parentNode; s.classList.toggle('closed'); if (s.classList.contains('closed')) closedSecs.add(s.dataset.sec); else closedSecs.delete(s.dataset.sec); UI.store.set('toolsClosed', [...closedSecs]); return; }
        const b = e.target.closest('[data-tool]');
        if (b) tool(b.dataset.tool);
    });
    function tool(id) {
        if (id === 'back') return renderTools();
        if (id.startsWith('f:')) return run('tools_folder', { which: id.slice(2) }).catch(() => {});
        if (id === 'x:friends') { dl('nexus.php?export=friends'); return; }
        if (id === 'x:avatars') { toast('Avatare werden abgefragt …'); dl('nexus.php?tools_export=avatars'); return; }
        const jump = { inventory: ['gallery', 'inventory'], calendar: ['calendar'], shop: ['shop'], purchases: ['shop'], news: ['news'] }[id];
        if (jump) { UI.tab(jump[0], jump[1]); if (id === 'purchases') setTimeout(() => { const p = $('#shopBox .sh-purch, #shopBox table'); if (p) p.scrollIntoView({ behavior: 'smooth' }); }, 1200); return; }
        ({ shots: toolShots, blocked: () => toolGroups('blocked'), ginvites: () => toolGroups('invites'), config: toolConfig, launch: toolLaunch, registry: toolRegistry, auto: toolAuto,
            messages: () => toolMessages('message'), reports: toolReports, streamdeck: toolStreamDeck, discord: toolDiscord, memos: toolMemos })[id]();
    }

    // --- Screenshot-Manager ---
    function toolShots(q) {
        const body = toolView('📷 Screenshot-Manager');
        api('tools_shots', { q: q || '' }).then(d => {
            body.innerHTML = '<div class="nx-row"><span class="nx-mono">' + esc(d.dir) + '</span><button type="button" class="btn btn-sm" data-act="folder">📂 Ordner öffnen</button>'
                + '<form id="shotQ" class="vx-inline" style="margin-left:auto"><input type="search" name="q" value="' + esc(q || '') + '" placeholder="Welt, Spieler, Dateiname …"><button class="btn btn-sm">Suchen</button></form></div>'
                + (!d.exists ? '<div class="vx-error">⚠ Ordner nicht gefunden – in den Einstellungen → Medien anpassen.</div>' : '')
                + '<p class="vx-small">' + d.shots.length + ' von ' + d.total + ' Bildern. Mit „📌“ markierte haben Welt-/Spieler-Infos.</p>'
                + '<div class="shot-grid">' + d.shots.map(s => '<div class="shot" data-shot="' + esc(s.rel) + '"><img loading="lazy" src="nexus.php?shot=' + encodeURIComponent(s.rel) + '&t=1" alt="">'
                    + '<div>' + (s.has_meta ? '📌 ' : '') + '<b>' + esc(s.world || s.name) + '</b><small>' + esc(dt(s.ts)) + ' · ' + size(s.size) + (s.players ? ' · 👥 ' + s.players : '') + '</small></div></div>').join('') + '</div>';
            $('#shotQ').onsubmit = e => { e.preventDefault(); toolShots(e.target.q.value); };
            body.querySelector('[data-act=folder]').onclick = () => run('tools_folder', { which: 'photos' }).catch(() => {});
            body.querySelector('.shot-grid').onclick = e => { const s = e.target.closest('[data-shot]'); if (s) shotDetail(s.dataset.shot); };
        }).catch(fail(body));
    }
    function shotDetail(rel) {
        UI.openModal('<div class="vx-loading">Lade …</div>');
        api('tools_shot', { rel }).then(d => {
            const m = d.meta || { players: [], raw: {} };
            const html = () => '<img class="shot-big" src="nexus.php?shot=' + encodeURIComponent(rel) + '" alt=""><h3 style="margin:0">' + esc(d.name) + '</h3>'
                + '<p class="vx-small">' + (m.width ? m.width + '×' + m.height + ' · ' : '') + (m.source ? 'Infos von: ' + esc(m.source) : 'Keine Welt-Infos gespeichert') + (m.created ? ' · ' + esc(m.created) : '') + '</p>'
                + (m.world ? '<p>🌐 ' + (m.world_id ? '<a href="#" data-world="' + esc(m.world_id) + '">' + esc(m.world) + '</a>' : esc(m.world)) + (m.instance ? ' <span class="nx-mono">' + esc(m.instance) + '</span>' : '') + '</p>' : '')
                + (m.author ? '<p>📷 ' + esc(m.author) + '</p>' : '')
                + (m.players.length ? '<p>👥 ' + m.players.map(p => p.id ? '<a href="#" data-user="' + esc(p.id) + '">' + esc(p.name) + '</a>' : esc(p.name)).join(', ') + '</p>' : '')
                + (Object.keys(m.raw || {}).length ? '<details><summary class="vx-small">Weitere Daten</summary><pre class="nx-mono">' + esc(JSON.stringify(m.raw, null, 2)) + '</pre></details>' : '')
                + '<div class="nx-row"><button type="button" class="btn btn-sm btn-gold" data-s="open">🖼 Öffnen</button><button type="button" class="btn btn-sm" data-s="folder">📂 Im Ordner zeigen</button>'
                + (/\.png$/i.test(rel) ? '<button type="button" class="btn btn-sm" data-s="meta" title="Schreibt die aktuelle Welt und die Spieler, die gerade da sind, ins Bild">📌 Aktuelle Welt &amp; Spieler eintragen</button>' : '') + '</div>';
            const ub = $('#userBody');
            ub.innerHTML = html();
            ub.onclick = e => {
                const b = e.target.closest('[data-s]');
                if (!b) return;
                if (b.dataset.s === 'meta') { if (confirm('Die aktuelle Welt und Mitspieler in dieses Bild schreiben?')) run('tools_shot_meta', { rel }, r => { m.source = r.meta.source; Object.assign(m, r.meta); ub.innerHTML = html(); }).catch(() => {}); }
                else run('tools_shot_open', { rel, folder: b.dataset.s === 'folder' ? 1 : '' }).catch(() => {});
            };
        }).catch(x => { $('#userBody').innerHTML = '<div class="vx-error">⚠ ' + esc(x.message) + '</div>'; });
    }

    // --- Gruppen ---
    function toolGroups(kind) {
        const body = toolView(kind === 'blocked' ? '⛔ Blockierte Gruppen' : '✉ Gruppen-Einladungen');
        api(kind === 'blocked' ? 'tools_group_blocked' : 'tools_group_invites').then(d => {
            body.innerHTML = d.groups.length ? '<table class="nx-list"><tbody>' + d.groups.map(g => '<tr><td style="width:56px">' + img(g.icon, 'vx-av', '') + '</td><td><b>' + esc(g.name) + '</b><div class="vx-small">' + esc(g.short) + ' · ' + g.members + ' Mitglieder</div></td><td style="text-align:right">'
                + (kind === 'blocked' ? '<button type="button" class="btn btn-sm" data-g="unblock" data-id="' + esc(g.id) + '">Freigeben</button>'
                    : '<button type="button" class="btn btn-sm btn-gold" data-g="accept" data-id="' + esc(g.id) + '">✔ Beitreten</button> <button type="button" class="btn btn-sm" data-g="decline" data-id="' + esc(g.id) + '">✖ Ablehnen</button> <button type="button" class="btn btn-sm btn-danger" data-g="block" data-id="' + esc(g.id) + '">⛔ Blockieren</button>')
                + '</td></tr>').join('') + '</tbody></table>' : '<div class="vx-empty">' + (kind === 'blocked' ? 'Du hast keine Gruppen blockiert.' : 'Keine offenen Gruppen-Einladungen.') + '</div>';
            body.onclick = e => { const b = e.target.closest('[data-g]'); if (b && (b.dataset.g !== 'block' || confirm('Gruppe blockieren?'))) run('tools_group_act', { kind: b.dataset.g, id: b.dataset.id }, () => toolGroups(kind)).catch(() => {}); };
        }).catch(fail(body));
    }

    // --- config.json ---
    function toolConfig() {
        const body = toolView('⚙ VRChat-Konfiguration (config.json)');
        api('tools_config').then(d => {
            body.innerHTML = '<p class="vx-small">Datei: <span class="nx-mono">' + esc(d.file) + '</span> ' + (d.exists ? '' : '(noch nicht vorhanden – wird angelegt)') + '. Änderungen wirken beim nächsten Start von VRChat. Vorher wird config.json.bak angelegt.</p>'
                + '<form class="nx-form" id="cfgForm">' + d.fields.map(([k, type, label, hint]) => '<div>' + (type === 'bool'
                    ? '<label class="vx-auto"><input type="checkbox" name="' + k + '" value="1"' + (d.data[k] ? ' checked' : '') + '> ' + esc(label) + '</label><input type="hidden" name="' + k + '" value="">'
                    : '<label class="field">' + esc(label) + '</label><input type="' + (type === 'number' ? 'number' : 'text') + '" step="any" name="' + k + '" value="' + esc(d.data[k] ?? '') + '">') + (hint ? '<div class="nx-hint">' + esc(hint) + '</div>' : '') + '</div>').join('')
                + '<div class="nx-wide"><button class="btn btn-gold">💾 Speichern</button></div></form>'
                + '<details style="margin-top:12px"><summary class="vx-small">Aktueller Inhalt</summary><pre class="nx-mono">' + esc(JSON.stringify(d.data, null, 2)) + '</pre></details>';
            $('#cfgForm').onsubmit = e => {
                e.preventDefault();
                const data = {};
                for (const [k, v] of new FormData(e.target)) if (!(k in data) || v) data[k] = v;
                run('tools_config_save', data, () => toolConfig()).catch(() => {});
            };
        }).catch(fail(body));
    }

    // --- Startoptionen ---
    function toolLaunch() {
        const body = toolView('🚀 Startoptionen');
        api('tools_overview').then(o => {
            const c = o.launch, ch = k => c[k] ? ' checked' : '';
            body.innerHTML = '<form class="nx-form" id="lnForm">'
                + '<div><label class="field">Starten über</label><select name="mode"><option value="steam">Steam</option><option value="exe"' + (c.mode === 'exe' ? ' selected' : '') + '>VRChat.exe direkt</option></select></div>'
                + '<div><label class="field">Pfad zu VRChat.exe (nur „direkt“)</label><input type="text" name="exe" value="' + esc(c.exe) + '" placeholder="C:\\Program Files (x86)\\Steam\\steamapps\\common\\VRChat\\VRChat.exe"></div>'
                + '<div><label class="vx-auto"><input type="checkbox" name="no_vr" value="1"' + ch('no_vr') + '> Desktop-Modus (--no-vr)</label></div>'
                + '<div><label class="field">Profil (--profile)</label><input type="number" name="profile" min="0" max="99" value="' + c.profile + '"><div class="nx-hint">0 = Standard; andere Nummern für Zweitkonten</div></div>'
                + '<div><label class="field">FPS-Grenze (--fps)</label><input type="number" name="fps" min="0" max="1000" value="' + c.fps + '"><div class="nx-hint">0 = Standard (90)</div></div>'
                + '<div><label class="field">Fenstergröße</label><div class="nx-row" style="margin:0"><input type="number" name="width" value="' + c.width + '" placeholder="Breite" style="width:45%"> × <input type="number" name="height" value="' + c.height + '" placeholder="Höhe" style="width:45%"></div></div>'
                + '<div><label class="field">Vollbild</label><select name="fullscreen"><option value="">Standard</option><option value="1"' + (c.fullscreen === '1' ? ' selected' : '') + '>Vollbild</option><option value="0"' + (c.fullscreen === '0' ? ' selected' : '') + '>Fenster</option></select></div>'
                + '<div><label class="vx-auto"><input type="checkbox" name="debug_gui" value="1"' + ch('debug_gui') + '> Debug-Menü (--enable-debug-gui)</label><label class="vx-auto"><input type="checkbox" name="sdk_logs" value="1"' + ch('sdk_logs') + '> SDK-Logs</label></div>'
                + '<div><label class="vx-auto"><input type="checkbox" name="watch_worlds" value="1"' + ch('watch_worlds') + '> --watch-worlds</label><label class="vx-auto"><input type="checkbox" name="watch_avatars" value="1"' + ch('watch_avatars') + '> --watch-avatars</label><div class="nx-hint">Für Creator: lokale Test-Builds neu laden</div></div>'
                + '<div><label class="field">OSC (--osc=Eingang:IP:Ausgang)</label><input type="text" name="osc" value="' + esc(c.osc) + '" placeholder="9000:127.0.0.1:9001"></div>'
                + '<div class="nx-wide"><label class="field">Weitere Optionen</label><input type="text" name="extra" value="' + esc(c.extra) + '" placeholder="z. B. --affinity=FFFF"></div>'
                + '<div class="nx-wide"><div class="nx-hint">Ergibt: <span class="nx-mono" id="lnArgs">' + esc(o.launch_args || '(keine)') + '</span></div></div>'
                + '<div class="nx-wide nx-row"><button class="btn">💾 Speichern</button><button type="button" class="btn btn-gold" id="lnGo">▶ VRChat jetzt starten</button></div></form>';
            const form = $('#lnForm');
            const data = () => Object.fromEntries(new FormData(form));
            form.onsubmit = e => { e.preventDefault(); run('tools_launch_save', data(), r => { $('#lnArgs').textContent = r.args || '(keine)'; }).catch(() => {}); };
            $('#lnGo').onclick = () => run('tools_launch_save', data()).then(() => run('tools_launch', {})).catch(() => {});
        }).catch(fail(body));
    }

    // --- Registry ---
    function toolRegistry() {
        const body = toolView('🗄 VRChat-Registry-Sicherung');
        api('tools_reg_list').then(d => {
            body.innerHTML = '<p class="vx-small">VRChat speichert viele Einstellungen (Grafik, Audio, Komfort, Avatar-Einstellungen, Kamera …) in der Windows-Registry unter <span class="nx-mono">HKCU\\Software\\VRChat\\VRChat</span>. '
                + 'Hier kannst du sie als .reg-Datei sichern und später zurückspielen (z. B. nach einer Neuinstallation). Vor jedem Wiederherstellen wird automatisch eine Sicherung angelegt.</p>'
                + (!d.windows ? '<div class="vx-error">⚠ Das geht nur unter Windows.</div>' : '')
                + '<div class="nx-row"><input type="text" id="regLabel" placeholder="Bezeichnung (optional)" maxlength="30"><button type="button" class="btn btn-gold" id="regNew">💾 Jetzt sichern</button><button type="button" class="btn btn-sm" id="regDir">📂 Ordner</button></div>'
                + (d.list.length ? '<table class="nx-list"><thead><tr><th>Sicherung</th><th>Datum</th><th>Größe</th><th></th></tr></thead><tbody>' + d.list.map(r => '<tr><td class="nx-mono">' + esc(r.name) + '</td><td>' + dt(r.ts) + '</td><td>' + size(r.size)
                    + '</td><td style="text-align:right"><button type="button" class="btn btn-sm" data-r="restore" data-n="' + esc(r.name) + '">↩ Wiederherstellen</button> <button type="button" class="btn btn-sm btn-danger" data-r="delete" data-n="' + esc(r.name) + '">🗑</button></td></tr>').join('') + '</tbody></table>'
                    : '<div class="vx-empty">Noch keine Sicherung.</div>');
            $('#regNew').onclick = () => run('tools_reg_backup', { label: $('#regLabel').value }, toolRegistry).catch(() => {});
            $('#regDir').onclick = () => run('tools_folder', { which: 'registry' }).catch(() => {});
            body.querySelectorAll('[data-r]').forEach(b => b.onclick = () => {
                const restore = b.dataset.r === 'restore';
                if (!confirm(restore ? 'VRChat-Einstellungen aus „' + b.dataset.n + '“ wiederherstellen? VRChat muss dafür beendet sein.' : 'Sicherung löschen?')) return;
                run(restore ? 'tools_reg_restore' : 'tools_reg_delete', { name: b.dataset.n }, toolRegistry).catch(() => {});
            });
        }).catch(fail(body));
    }

    // --- Auto-Status ---
    function toolAuto() {
        const body = toolView('🤖 Auto-Status & Einladungen');
        api('tools_overview').then(o => {
            const a = o.auto;
            const st = (name, v, none) => '<select name="' + name + '">' + (none ? '<option value="">– nichts ändern –</option>' : '') + [['join me', '🔵 Join Me'], ['active', '🟢 Online'], ['ask me', '🟠 Ask Me'], ['busy', '🔴 Beschäftigt']]
                .map(([k, l]) => '<option value="' + k + '"' + (v === k ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>';
            body.innerHTML = '<p class="vx-small">Funktioniert, solange VRC Nexus läuft (auch im Infobereich) und die VRChat-Logs mitgelesen werden. Status-Änderungen höchstens alle 20 Sekunden.</p><form class="nx-form" id="autoForm">'
                + '<div><label class="field">Wenn VRChat startet → Status</label>' + st('start_status', a.start_status, true) + '<input type="text" name="start_desc" maxlength="32" value="' + esc(a.start_desc) + '" placeholder="Statustext (optional)" style="margin-top:4px"></div>'
                + '<div><label class="field">Wenn VRChat beendet wird → Status</label>' + st('quit_status', a.quit_status, true) + '<input type="text" name="quit_desc" maxlength="32" value="' + esc(a.quit_desc) + '" placeholder="Statustext (optional)" style="margin-top:4px"></div>'
                + '<div class="nx-wide"><label class="vx-auto"><input type="checkbox" name="alone" value="1"' + (a.alone ? ' checked' : '') + '> Status nach Gesellschaft umschalten</label></div>'
                + '<div><label class="field">Allein in der Instanz</label>' + st('alone_status', a.alone_status) + '</div>'
                + '<div><label class="field">Mit anderen (ab … Spielern)</label><div class="nx-row" style="margin:0">' + st('company_status', a.company_status) + '<input type="number" name="company_min" min="1" max="80" value="' + a.company_min + '" style="width:70px"></div></div>'
                + '<div class="nx-wide"><label class="vx-auto"><input type="checkbox" name="private_busy" value="1"' + (a.private_busy ? ' checked' : '') + '> In Invite-Instanzen immer „Beschäftigt“</label></div>'
                + '<div><label class="field">Einladungs-Anfragen automatisch beantworten</label><select name="invite"><option value="off">aus</option><option value="favorites"' + (a.invite === 'favorites' ? ' selected' : '') + '>nur ⭐ Favoriten einladen</option><option value="friends"' + (a.invite === 'friends' ? ' selected' : '') + '>alle Freunde einladen</option></select>'
                + '<div class="nx-hint">Wird alle 2 Minuten geprüft; lädt in deine aktuelle Instanz ein.</div></div>'
                + '<div><label class="vx-auto"><input type="checkbox" name="invite_joinme_only" value="1"' + (a.invite_joinme_only ? ' checked' : '') + '> … nur wenn mein Status „Join Me“ ist</label></div>'
                + '<div class="nx-wide"><button class="btn btn-gold">💾 Speichern</button></div></form>';
            $('#autoForm').onsubmit = e => {
                e.preventDefault();
                const f = e.target, data = Object.fromEntries(new FormData(f));
                ['alone', 'private_busy', 'invite_joinme_only'].forEach(k => { data[k] = f[k].checked ? '1' : '0'; });
                run('tools_auto_save', data).catch(() => {});
            };
        }).catch(fail(body));
    }

    // --- Einladungsnachrichten ---
    function toolMessages(type) {
        const body = toolView('✏ Einladungsnachrichten');
        api('tools_messages', { type }).then(d => {
            body.innerHTML = '<div class="chips" style="margin-bottom:10px">' + Object.entries(d.types).map(([k, l]) => '<button type="button" class="chip' + (k === type ? ' on' : '') + '" data-mt="' + k + '">' + esc(l) + '</button>').join('') + '</div>'
                + '<p class="vx-small">12 Plätze pro Art. Nach dem Ändern ist ein Platz 60 Minuten gesperrt (VRChat).</p><table class="nx-list"><tbody>'
                + d.messages.map(m => '<tr><td style="width:40px">#' + m.slot + '</td><td><input type="text" maxlength="64" value="' + esc(m.message) + '" data-slot="' + m.slot + '" style="width:100%"' + (m.cooldown || !m.can ? ' disabled' : '') + '></td><td style="width:170px">'
                    + (m.cooldown ? '<span class="vx-small">🔒 noch ' + m.cooldown + ' min</span>' : '<button type="button" class="btn btn-sm" data-save="' + m.slot + '">💾 Speichern</button>') + '</td></tr>').join('') + '</tbody></table>';
            body.onclick = e => {
                const c = e.target.closest('[data-mt]'); if (c) return toolMessages(c.dataset.mt);
                const s = e.target.closest('[data-save]');
                if (s) run('tools_message_save', { type, slot: s.dataset.save, message: body.querySelector('[data-slot="' + s.dataset.save + '"]').value }, () => toolMessages(type)).catch(() => {});
            };
        }).catch(fail(body));
    }

    // --- Meldungen ---
    function toolReports() {
        const body = toolView('🚩 Meine Meldungen');
        api('tools_reports').then(d => {
            body.innerHTML = d.reports.length ? '<table class="nx-list"><thead><tr><th>Datum</th><th>Art</th><th>Gemeldet</th><th>Kategorie / Grund</th><th>Status</th><th></th></tr></thead><tbody>' + d.reports.map(r => '<tr><td>' + esc(UI.isoDT(r.created)) + '</td><td>' + esc(r.type)
                + '</td><td>' + (r.target_id.startsWith('usr_') ? '<a href="#" data-user="' + esc(r.target_id) + '">' + esc(r.target) + '</a>' : r.target_id.startsWith('wrld_') ? '<a href="#" data-world="' + esc(r.target_id) + '">' + esc(r.target) + '</a>' : esc(r.target))
                + '</td><td>' + esc(r.category) + ' · <span class="vx-small">' + esc(r.reason) + '</span></td><td>' + esc(r.status) + '</td><td><button type="button" class="btn btn-sm btn-danger" data-del="' + esc(r.id) + '">🗑</button></td></tr>').join('') + '</tbody></table>'
                : '<div class="vx-empty">Du hast keine Meldungen eingereicht.</div>';
            body.onclick = e => { const b = e.target.closest('[data-del]'); if (b && confirm('Meldung zurückziehen/löschen?')) run('tools_report_delete', { id: b.dataset.del }, () => toolReports()).catch(() => {}); };
        }).catch(fail(body));
    }

    // --- Exporte ---
    function toolDiscord() {
        const body = toolView('💬 Discord-Namen deiner Freunde');
        api('tools_discord_names').then(d => {
            body.innerHTML = '<p class="vx-small">Gesucht wird in Bio und Profil-Links (Freunde, deren Profil schon geladen wurde, z. B. über die Spielerliste oder das Profil-Fenster). Es wird nichts an Discord gesendet.</p>'
                + '<div class="nx-row"><a class="btn btn-sm" href="nexus.php?tools_export=discord">⬇ CSV</a><span class="vx-small">' + d.rows.length + ' gefunden</span></div>'
                + (d.rows.length ? '<table class="nx-list"><thead><tr><th>VRChat</th><th>Discord</th></tr></thead><tbody>' + d.rows.map(r => '<tr><td><a href="#" data-user="' + esc(r.id) + '">' + esc(r.name) + '</a></td><td>'
                    + (/^https:/.test(r.discord) ? '<a href="' + esc(r.discord) + '" target="_blank" rel="noopener">' + esc(r.discord) + '</a>' : esc(r.discord)) + '</td></tr>').join('') + '</tbody></table>' : '<div class="vx-empty">Nichts gefunden.</div>');
        }).catch(fail(body));
    }
    function toolMemos() {
        const body = toolView('📝 Memos exportieren');
        body.innerHTML = '<p>Deine Memos (Notizen in VRC Nexus) lassen sich als CSV sichern oder als <b>VRChat-Notiz</b> ins Profil übertragen (sichtbar nur für dich, max. 256 Zeichen, langsam: etwa 1 pro Sekunde).</p>'
            + '<div class="nx-row"><a class="btn" href="nexus.php?tools_export=memos">⬇ Als CSV</a><button type="button" class="btn btn-gold" id="memoVrc">☁ Als VRChat-Notizen speichern</button>'
            + '<label class="vx-auto"><input type="checkbox" id="memoOw"> vorhandene VRChat-Notizen überschreiben</label></div>';
        $('#memoVrc').onclick = e => { if (!confirm('Alle Memos zu VRChat übertragen?')) return; e.target.disabled = true; toast('Übertrage … das kann dauern.'); run('tools_memos_to_vrc', { overwrite: $('#memoOw').checked ? 1 : '' }).finally(() => { e.target.disabled = false; }).catch(() => {}); };
    }

    // --- Stream Deck ---
    function toolStreamDeck() {
        const body = toolView('🎛 Stream Deck');
        api('tools_settings').then(d => {
            const s = d.settings;
            body.innerHTML = '<div class="nx-row"><button type="button" class="btn btn-gold" id="sdInstall">⬇ Plugin in Stream Deck installieren</button><span class="vx-small">(öffnet VRC-Nexus.streamDeckPlugin mit der Stream-Deck-App)</span></div>'
                + '<ol class="vx-small" style="line-height:1.8"><li>Oben auf „Plugin installieren“ klicken (oder die Datei <b>VRC-Nexus.streamDeckPlugin</b> aus dem Download doppelklicken) und in der Stream-Deck-App bestätigen.</li>'
                + '<li>Hier die lokale Schnittstelle einschalten.</li><li>In der Stream-Deck-App eine VRC-Nexus-Aktion auf eine Taste ziehen und Port + Zugangsschlüssel eintragen (einmal reicht, gilt für alle Tasten).</li></ol>'
                + '<div class="set-row"><div><b>Lokale Schnittstelle</b><div class="nx-hint">Status: ' + esc(d.status.api) + '</div></div><label class="switch"><input type="checkbox" id="sdOn"' + (s['int.api'] ? ' checked' : '') + '><i></i></label></div>'
                + '<div class="set-row"><div><b>Port</b></div><input type="number" id="sdPort" value="' + s['int.api_port'] + '"></div>'
                + '<div class="set-row"><div><b>Zugangsschlüssel</b><div class="nx-hint">Nicht weitergeben – damit kann man deinen Status ändern und Chatbox-Nachrichten senden.</div></div><div class="nx-row" style="margin:0"><input type="text" readonly value="' + esc(s['int.api_token']) + '" id="sdTok" style="flex:1">'
                + '<button type="button" class="btn btn-sm" id="sdCopy">📋</button><button type="button" class="btn btn-sm" id="sdNew" title="Neuen Schlüssel erzeugen">♻</button></div></div>'
                + '<h3 style="margin-top:16px">Aktionen im Plugin</h3><ul class="vx-small" style="line-height:1.7"><li>🟢 <b>Status setzen</b> (Join Me / Online / Ask Me / Beschäftigt, mit Text)</li><li>💬 <b>Chatbox-Nachricht</b> oder gespeicherte OSC-Vorlage senden</li>'
                + '<li>🎚 <b>Avatar-Parameter</b> setzen oder umschalten (zeigt den aktuellen Zustand)</li><li>🎮 <b>OSC-Eingabe</b> (Springen, Stummschalten, Quick-Menü …)</li><li>🔄 <b>Chatbox-Rotator</b> an/aus</li>'
                + '<li>👥 <b>Freunde online</b> und <b>Spieler in der Instanz</b> als Live-Anzeige auf der Taste</li><li>▶ <b>VRChat starten</b> (mit deinen Startoptionen), 🔁 <b>Instanz neu betreten</b>, 🧭 <b>VRC Nexus öffnen</b> (beliebige Seite)</li></ul>';
            const save = () => run('tools_settings_save', { data: JSON.stringify({ 'int.api': $('#sdOn').checked, 'int.api_port': $('#sdPort').value }) }, () => setTimeout(toolStreamDeck, 400)).catch(() => {});
            $('#sdInstall').onclick = () => run('tools_streamdeck_install', {}).catch(() => {});
            $('#sdOn').onchange = save; $('#sdPort').onchange = save;
            $('#sdCopy').onclick = () => navigator.clipboard.writeText($('#sdTok').value).then(() => toast('Kopiert.'));
            $('#sdNew').onclick = () => { if (confirm('Neuen Schlüssel erzeugen? Das Plugin muss dann neu eingerichtet werden.')) run('tools_token', {}, toolStreamDeck).catch(() => {}); };
        }).catch(fail(body));
    }

    // ======================= Einstellungen =======================
    let setData = null, setTab = UI.store.get('setTab', 'system');
    function loadSettings() { const box = $('#setBox'); loading(box, 'Lade Einstellungen …'); api('tools_settings').then(d => { setData = d; renderSettings(); }).catch(fail(box)); }
    function field(f, v) {
        const k = esc(f.key);
        switch (f.type) {
            case 'bool': return '<label class="switch"><input type="checkbox" data-k="' + k + '"' + (v ? ' checked' : '') + '><i></i></label>';
            case 'select': return '<select data-k="' + k + '">' + f.opts.map(o => '<option value="' + esc(o[0]) + '"' + (String(v) === String(o[0]) ? ' selected' : '') + '>' + esc(o[1]) + '</option>').join('') + '</select>';
            case 'number': return '<input type="number" data-k="' + k + '" value="' + esc(v) + '"' + (f.opts ? ' min="' + f.opts[0] + '" max="' + f.opts[1] + '"' : '') + '>';
            case 'color': return '<input type="color" data-k="' + k + '" value="' + esc(v) + '">';
            case 'time': return '<input type="time" data-k="' + k + '" value="' + esc(v) + '">';
            case 'secret': return '<div class="nx-row" style="margin:0"><input type="text" readonly value="' + esc(v) + '" style="flex:1"><button type="button" class="btn btn-sm" data-act="token">♻ Neu</button></div>';
            default: return '<input type="text" data-k="' + k + '" value="' + esc(v) + '" placeholder="' + esc(f.def) + '">';
        }
    }
    function renderSettings() {
        const d = setData, box = $('#setBox');
        const MOBILE_HIDE = /^(upd\.mode|link\.(upload|remote)|sys\.|vr\.|media\.|int\.|social\.(players|memo)|notify\.(player_|screenshot|vrc_start)|adv\.(debug|devtools)|ui\.(wide|sticky_menu))/;
        const visible = f => !MOBILE || !MOBILE_HIDE.test(f.key);
        const tabsShown = Object.keys(d.schema.tabs).filter(t => d.schema.fields.some(f => f.tab === t && visible(f)));
        if (!tabsShown.includes(setTab)) setTab = tabsShown[0];
        const fields = d.schema.fields.filter(f => f.tab === setTab && visible(f));
        const secs = [...new Set(fields.map(f => f.section))];
        const extra = {
            system: '<div class="set-sec"><h3>Aktionen</h3><div class="nx-row"><button type="button" class="btn btn-sm" data-act="backup">💾 Datenbank jetzt sichern</button><button type="button" class="btn btn-sm" data-act="f:backups">📂 Sicherungen</button>'
                + '<button type="button" class="btn btn-sm" data-act="f:nexus">📂 Datenordner</button></div></div>',
            link: '<div class="set-sec"><h3>Geräte-Token</h3><div id="linkBox"><div class="vx-loading">Lade …</div></div></div>',
            notify: '<div class="set-sec"><h3>Test</h3><button type="button" class="btn btn-sm" data-act="test">🔔 Test-Benachrichtigung (alle eingeschalteten Wege)</button></div>',
            int: '<div class="set-sec"><h3>Status</h3><p class="vx-small">Lokale Schnittstelle: ' + esc(d.status.api) + ' · Discord: ' + esc(d.status.rpc) + '</p></div>',
            adv: '<div class="set-sec"><h3>Daten &amp; Wartung</h3><div class="nx-row"><button type="button" class="btn btn-sm" data-act="cache">🧹 Profil-Zwischenspeicher leeren</button><button type="button" class="btn btn-sm" data-act="vacuum">🗜 Datenbank optimieren</button>'
                + '<a class="btn btn-sm" href="nexus.php?tools_export=settings">⬇ Einstellungen exportieren</a><label class="btn btn-sm">⬆ Importieren<input type="file" accept=".json" id="setImport" hidden></label>'
                + '<button type="button" class="btn btn-sm btn-danger" data-act="reset">↺ Alles zurücksetzen</button></div></div>',
        }[setTab] || '';
        box.innerHTML = '<div class="nx-panel"><h2>⚙ Einstellungen</h2><div class="set-tabs">' + Object.entries(d.schema.tabs).filter(([k]) => tabsShown.includes(k)).map(([k, l]) => '<button type="button" data-st="' + k + '" class="' + (k === setTab ? 'on' : '') + '">' + esc(l) + '</button>').join('') + '</div>'
            + (setTab === 'tr' ? '<div id="trPanel"></div>' : '') + (setTab === 'tr' ? [] : secs).map(sec => '<div class="set-sec"><h3>' + esc(sec) + '</h3>' + fields.filter(f => f.section === sec).map(f => '<div class="set-row"><div><b>' + esc(f.label) + '</b>' + (f.hint ? '<div class="nx-hint">' + esc(f.hint) + '</div>' : '') + '</div>' + field(f, d.settings[f.key]) + '</div>').join('') + '</div>').join('')
            + (setTab === 'system' ? '<div class="set-sec"><h3>Version &amp; Updates</h3><div id="updBox"><div class="vx-loading">…</div></div></div>' : '')
            + (MOBILE ? (['adv', 'link'].includes(setTab) ? extra : '') : extra) + '<p class="vx-small">Änderungen werden sofort gespeichert.</p></div>';
        if (setTab === 'tr' && window.NexusTr) window.NexusTr.panel($('#trPanel'));
        if (setTab === 'link') linkPanel();
        if (setTab === 'system') updatePanel();
    }
    $('#setBox').addEventListener('click', e => {
        const t = e.target.closest('[data-st]');
        if (t) { setTab = t.dataset.st; UI.store.set('setTab', setTab); renderSettings(); return; }
        const a = e.target.closest('[data-act]');
        if (!a) return;
        const act = a.dataset.act;
        if (act.startsWith('f:')) return run('tools_folder', { which: act.slice(2) }).catch(() => {});
        if (act === 'reset' && !confirm('Alle Einstellungen auf Standard zurücksetzen?')) return;
        if (act === 'token' && !confirm('Neuen Zugangsschlüssel erzeugen?')) return;
        run({ backup: 'tools_backup', test: 'tools_test_notify', cache: 'tools_cache_clear', vacuum: 'tools_vacuum', reset: 'tools_settings_reset', token: 'tools_token' }[act], {}, () => { if (['reset', 'token'].includes(act)) loadSettings(); }).catch(() => {});
    });
    $('#setBox').addEventListener('change', e => {
        if (e.target.id === 'setImport') {
            const f = e.target.files[0];
            if (f) f.text().then(t => run('tools_settings_save', { data: t }, r => { setData.settings = r.settings; applyUi(r.settings); renderSettings(); })).catch(() => toast('Datei ungültig.', true));
            return;
        }
        const el = e.target.closest('[data-k]');
        if (!el) return;
        const v = el.type === 'checkbox' ? el.checked : el.value;
        api('tools_settings_save', { data: JSON.stringify({ [el.dataset.k]: v }) }).then(r => {
            setData.settings = r.settings;
            window.VRCX.ui = Object.assign(window.VRCX.ui || {}, r.settings);
            applyUi(r.settings);
            toast('Gespeichert.');
            if (el.dataset.k.startsWith('int.')) setTimeout(() => api('tools_settings').then(d => { setData = d; if (setTab === 'int') renderSettings(); }), 500);
        }).catch(x => { toast(x.message, true); renderSettings(); });
    });

    // ======================= Menü mit Untermenüs (wie VRCX „⋯“) =======================
    function menu(anchor, items) {
        $$('.nx-menu').forEach(m => m.remove());
        const build = list => '<div class="nx-menu">' + list.map((it, i) => it === '-' ? '<hr>' : '<div class="nx-mi' + (it.bad ? ' bad' : '') + '" role="menuitem" tabindex="0" data-mi="' + i + '"><span>' + (it.icon || '') + '</span>' + esc(it.label)
            + (it.sub ? '<i>›</i>' + build(it.sub).replace('class="nx-menu"', 'class="nx-menu nx-sub"') : '') + '</div>').join('') + '</div>';
        const wrap = document.createElement('div');
        wrap.innerHTML = build(items);
        const m = wrap.firstChild;
        document.body.append(m);
        const r = anchor.getBoundingClientRect();
        m.style.top = (window.scrollY + r.bottom + 4) + 'px';
        m.style.left = Math.max(8, window.scrollX + r.right - m.offsetWidth) + 'px';
        if (r.right + 200 > innerWidth) m.classList.add('nx-left');
        m.addEventListener('click', e => {
            const b = e.target.closest('.nx-mi[data-mi]');
            if (!b) return;
            const path = [];
            for (let x = b; x && x !== m; x = x.parentNode) if (x.dataset && x.dataset.mi !== undefined) path.unshift(+x.dataset.mi);
            let it = { sub: items };
            for (const i of path) it = it.sub[i];
            if (it.sub) { e.stopPropagation(); b.classList.toggle('open'); return; }
            m.remove();
            it.fn();
        });
        setTimeout(() => document.addEventListener('click', function off(e) { if (!m.contains(e.target)) { m.remove(); document.removeEventListener('click', off); } }), 0);
    }
    const copy = (text, what) => (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => toast(what + ' kopiert.')).catch(() => prompt(what + ':', text));
    const shareItems = (url, name, id) => [{ icon: '⧉', label: 'Link kopieren', fn: () => copy(url, 'Link') }, { icon: '⧉', label: 'Name kopieren', fn: () => copy(name, 'Name') }, { icon: '⧉', label: 'ID kopieren', fn: () => copy(id, 'ID') }];
    const instRows = list => list.length ? '<table class="nx-list"><thead><tr><th>Welt</th><th>Zugang</th><th>Region</th><th>Zuerst</th><th>Zuletzt</th><th></th></tr></thead><tbody>' + list.map(i => '<tr><td><a href="#" data-world="' + esc(i.world_id) + '">' + esc(i.world_name || i.world_id) + '</a></td><td>'
        + esc(i.access) + '</td><td>' + esc(i.region) + '</td><td>' + dt(i.first) + '</td><td>' + dt(i.last) + '</td><td>' + (i.access !== 'Invite' ? '<a class="btn btn-sm" href="' + esc(UI.launch(i.location)) + '" title="Beitreten">▶</a>' : '')
        + ' <button type="button" class="btn btn-sm" data-inst="' + esc(i.location) + '">ℹ</button></td></tr>').join('') + '</tbody></table>'
        : '<div class="vx-empty">Keine früheren Instanzen im Spiel-Log (wird nur aufgezeichnet, solange VRC Nexus die VRChat-Logs mitliest).</div>';

    // ======================= JSON-Ansicht =======================
    function jsonTree(v, key, depth) {
        const k = key !== undefined ? '<span class="js-k">"' + esc(key) + '"</span>: ' : '';
        if (v === null) return '<div class="js-row">' + k + '<span class="js-null">null</span></div>';
        if (typeof v === 'string') return '<div class="js-row">' + k + '<span class="js-s">"' + esc(v) + '"</span></div>';
        if (typeof v === 'number') return '<div class="js-row">' + k + '<span class="js-n">' + v + '</span></div>';
        if (typeof v === 'boolean') return '<div class="js-row">' + k + '<span class="js-b">' + v + '</span></div>';
        const arrOf = Array.isArray(v), ents = arrOf ? v.map((x, i) => [i, x]) : Object.entries(v).sort((a, b) => a[0].localeCompare(b[0]));
        return '<details class="js-o"' + (depth < 2 ? ' open' : '') + '><summary>' + k + '<span class="js-p">' + (arrOf ? '[' : '{') + '</span><span class="js-c">' + (arrOf ? '…]' : '…}') + '</span></summary>'
            + '<div class="js-in">' + ents.map(([kk, x]) => jsonTree(x, arrOf ? undefined : kk, depth + 1)).join('') + '</div><span class="js-p">' + (arrOf ? ']' : '}') + '</span></details>';
    }

    // ======================= Profil: Reiter + Menü =======================
    const U_TABS = [['info', 'Info'], ['mutuals', 'Gemeinsame Freunde'], ['groups', 'Gruppen'], ['worlds', 'Welten'], ['favworlds', 'Lieblingswelten'], ['avatars', 'Avatare'], ['activity', 'Aktivität'], ['instances', 'Frühere Instanzen'], ['json', 'JSON']];
    function userExtra(u) {
        const body = $('#userBody'), head = body.querySelector('.vx-u-head');
        if (!head || body.querySelector('.u-tabs')) return;
        const info = document.createElement('div');
        info.className = 'u-pane'; info.dataset.up = 'info';
        while (head.nextSibling) info.append(head.nextSibling);
        const bar = document.createElement('div');
        bar.className = 'u-tabs';
        bar.innerHTML = U_TABS.map(([k, l], i) => '<button type="button" data-ut="' + k + '" class="' + (i ? '' : 'on') + '">' + esc(l) + '</button>').join('');
        const more = document.createElement('button');
        more.type = 'button'; more.className = 'btn btn-sm u-more'; more.textContent = '⋯'; more.title = 'Mehr';
        head.append(more);
        body.append(bar, info);
        more.addEventListener('click', () => menu(more, [
            { icon: '⟳', label: 'Aktualisieren', fn: () => UI.openUser(u.id) },
            { icon: '↗', label: 'Teilen', sub: shareItems('https://vrchat.com/home/user/' + u.id, u.name, u.id) },
            '-',
            { icon: '🕘', label: 'Frühere Instanzen zeigen', fn: () => uTab(u, 'instances') },
            { icon: '{ }', label: 'JSON anzeigen', fn: () => uTab(u, 'json') },
            '-',
            { icon: '⛔', label: 'Blockieren', bad: true, fn: () => { const b = body.querySelector('[data-mod=block]'); if (b) b.click(); } },
        ]));
        bar.addEventListener('click', e => { const b = e.target.closest('[data-ut]'); if (b) uTab(u, b.dataset.ut); });
    }
    function uTab(u, t) {
        const body = $('#userBody');
        $$('.u-tabs [data-ut]', body).forEach(b => b.classList.toggle('on', b.dataset.ut === t));
        $$('.u-pane', body).forEach(p => { p.hidden = p.dataset.up !== t; });
        let pane = body.querySelector('.u-pane[data-up="' + t + '"]');
        if (pane && t !== 'json') return;
        if (!pane) { pane = document.createElement('div'); pane.className = 'u-pane'; pane.dataset.up = t; body.append(pane); }
        loading(pane);
        const card = (o, attr) => '<button type="button" class="vx-card" ' + attr + '="' + esc(o.id) + '">' + img(o.thumb, 'vx-card-img', o.name) + '<span class="vx-card-body"><b>' + esc(o.name) + '</b><small>'
            + esc(o.author ? 'von ' + o.author : '') + '</small><span class="vx-card-meta">' + (o.occupants ? '<span>🟢 ' + UI.num(o.occupants) + '</span>' : '') + (o.favorites ? '<span>⭐ ' + UI.num(o.favorites) + '</span>' : '')
            + (o.status && o.status !== 'public' ? '<span>' + esc(o.status) + '</span>' : '') + '</span></span></button>';
        const err = d => d.error ? '<div class="vx-error">⚠ ' + esc(d.error) + '</div>' : '';
        if (t === 'groups') {
            pane.innerHTML = (u.groups || []).length ? '<div class="u-glist">' + u.groups.map(g => '<button type="button" class="loc-p" data-opengroup="' + esc(g.id) + '">' + img(g.icon, 'vx-av', g.name) + '<b>' + esc(g.name) + '</b>'
                + (g.rep ? ' <span class="vx-pill rep">★</span>' : '') + '<small class="vx-small">' + g.members + ' Mitgl.</small></button>').join('') + '</div>' : '<div class="vx-empty">Keine (öffentlichen) Gruppen.</div>';
            return;
        }
        if (t === 'json') {
            api('tools_user_json', { id: u.id }).then(d => {
                const raw = JSON.stringify(d.user, null, 2);
                pane.innerHTML = '<div class="nx-row"><button type="button" class="btn btn-sm" data-j="reload" title="Neu laden">⟳</button><button type="button" class="btn btn-sm" data-j="dl" title="Herunterladen">⬇</button>'
                    + '<button type="button" class="btn btn-sm" data-j="copy">⧉ Kopieren</button><button type="button" class="btn btn-sm" data-j="all">alles aufklappen</button></div><div class="js-view">' + jsonTree({ user: d.user }, undefined, 0) + '</div>';
                pane.onclick = e => {
                    const b = e.target.closest('[data-j]'); if (!b) return;
                    if (b.dataset.j === 'reload') uTab(u, 'json');
                    if (b.dataset.j === 'copy') copy(raw, 'JSON');
                    if (b.dataset.j === 'all') $$('details', pane).forEach(x => { x.open = true; });
                    if (b.dataset.j === 'dl') { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([raw], { type: 'application/json' })); a.download = (u.name || 'user').replace(/[^\w-]+/g, '_') + '.json'; a.click(); }
                };
            }).catch(fail(pane));
            return;
        }
        api('tools_user_tab', { id: u.id, tab: t }).then(d => {
            if (t === 'mutuals') pane.innerHTML = err(d) + (d.users ? '<p class="vx-small">' + d.users.length + ' gemeinsame Freunde</p><div class="u-glist">' + d.users.map(m => '<span class="loc-p" data-user="' + esc(m.id) + '">' + img(m.image, 'vx-av', m.name)
                + UI.statusDot(m.state === 'offline' ? 'offline' : m.status) + '<b>' + esc(m.name) + '</b></span>').join('') + '</div>' : '');
            if (t === 'worlds' || t === 'favworlds') pane.innerHTML = err(d) + (d.worlds ? (d.worlds.length ? '<div class="vx-cards">' + d.worlds.map(w => card(w, 'data-world')).join('') + '</div>'
                : '<div class="vx-empty">' + (t === 'worlds' ? 'Keine veröffentlichten Welten.' : 'Keine öffentlichen Lieblingswelten (nur sichtbar, wenn die Gruppe öffentlich ist).') + '</div>') : '');
            if (t === 'avatars') pane.innerHTML = err(d) + (d.avatars ? (d.avatars.length ? '<div class="vx-cards">' + d.avatars.map(a => card(a, 'data-avatar-open')).join('') + '</div>' : '<div class="vx-empty">Keine öffentlichen Avatare.</div>') : '');
            if (t === 'instances') pane.innerHTML = '<p class="vx-small">Instanzen, in denen du ' + esc(u.name) + ' getroffen hast (aus deinem Spiel-Log).</p>' + instRows(d.instances);
            if (t === 'activity') {
                const m = d.meets, s = d.stats || {}, max = Math.max(1, ...d.days.map(x => x.n));
                pane.innerHTML = '<div class="vx-kpis sm"><div><b>' + m.count + '</b><span>Begegnungen im Spiel</span></div><div><b>' + m.worlds + '</b><span>Welten zusammen</span></div><div><b>' + (m.last ? UI.ago(m.last) : '–') + '</b><span>zuletzt getroffen</span></div>'
                    + '<div><b>' + UI.hm(s.week || 0) + '</b><span>online (7 Tage)</span></div><div><b>' + UI.hm(s.month || 0) + '</b><span>online (30 Tage)</span></div></div>'
                    + '<h4>Ereignisse pro Tag (60 Tage)</h4><div class="u-bars">' + (d.days.length ? d.days.map(x => '<i style="height:' + Math.max(4, x.n / max * 100) + '%" title="' + esc(x.d + ': ' + x.n) + '"></i>').join('') : '<span class="vx-small">Noch nichts aufgezeichnet.</span>') + '</div>'
                    + '<h4>Arten</h4><p class="vx-small">' + d.types.map(x => esc(x.type) + ': ' + x.n).join(' · ') + '</p>';
            }
            pane.onclick = e => { const a = e.target.closest('[data-avatar-open]'); if (a) UI.openAvatar(a.dataset.avatarOpen); };
        }).catch(fail(pane));
    }

    // ======================= Gruppe: „⋯“-Menü =======================
    function groupExtra(g, reload) {
        const btns = $('#groupDetail .vx-gd-head .vx-u-btns');
        if (!btns || btns.querySelector('.g-more')) return;
        const more = document.createElement('button');
        more.type = 'button'; more.className = 'btn btn-sm g-more'; more.textContent = '⋯'; more.title = 'Mehr';
        btns.append(more);
        const url = g.short ? 'https://vrc.group/' + g.short : 'https://vrchat.com/home/group/' + g.id;
        more.addEventListener('click', () => menu(more, [
            { icon: '⟳', label: 'Aktualisieren', fn: reload },
            { icon: '↗', label: 'Teilen', sub: shareItems(url, g.name, g.id) },
            '-',
            { icon: '🕘', label: 'Frühere Instanzen zeigen', fn: () => {
                const pane = $('#gPane');
                $$('.vx-gd-tabs .chip').forEach(x => x.classList.remove('on'));
                loading(pane);
                api('tools_group_instances', { id: g.id }).then(d => { pane.innerHTML = '<h3>🕘 Frühere Instanzen dieser Gruppe</h3>' + instRows(d.instances); }).catch(fail(pane));
            } },
            '-',
            { icon: '⛔', label: 'Gruppe blockieren', bad: true, fn: () => { if (confirm('Gruppe „' + g.name + '“ blockieren? Sie kann dich dann nicht mehr einladen.')) run('tools_group_act', { kind: 'block', id: g.id }).catch(() => {}); } },
        ]));
    }
    $('#groupDetail') && $('#groupDetail').addEventListener('click', e => { const i = e.target.closest('#gPane [data-inst]'); if (i) UI.openInstance(i.dataset.inst); const w = e.target.closest('#gPane [data-world]'); if (w) { e.preventDefault(); UI.openWorld(w.dataset.world); } });

    // ======================= Dashboard-Verbindung =======================
    function linkPanel() {
        const box = $('#linkBox');
        if (!box) return;
        api('tools_link_status').then(d => {
            box.innerHTML = '<p class="vx-small">' + (MOBILE ? 'Mit dem Dashboard kannst du vom Handy aus die Chatbox und Avatar-Parameter an deinem PC steuern (dort muss VRC Nexus mit Dashboard-Verbindung laufen) und Memos abgleichen.'
                    : 'Verbindet diese App mit deinem GHOST-NODE-Dashboard: Spiel-Log und Live-Status erscheinen dort unter Nexus → 🖥️ Client, Chatbox-Befehle aus dem Dashboard (oder vom Handy) werden hier ausgeführt, Memos werden abgeglichen.') + '</p>'
                + '<div class="set-row"><div><b>Status</b><div class="nx-hint">' + (d.at ? 'zuletzt ' + UI.ago(d.at) : '') + (d.sent ? ' · ' + d.sent + ' Ereignisse hochgeladen' : '') + (d.received ? ' · ' + d.received + ' Befehle ausgeführt' : '') + '</div></div>'
                + '<div class="' + (d.ok ? 'nx-ok' : 'vx-small') + '">' + (d.ok ? '✔ ' : '') + esc(d.msg) + (d.user ? ' · ' + esc(d.user) + ' (' + esc(d.device) + ')' : '') + '</div></div>'
                + '<div class="set-row"><div><b>Geräte-Token</b><div class="nx-hint">Im Dashboard: Nexus → 🖥️ Client → Geräte → „Gerät hinzufügen“. Wird verschlüsselt gespeichert.</div></div>'
                + '<div class="nx-row" style="margin:0"><input type="password" id="linkTok" placeholder="' + (d.token ? esc(d.token) : 'gnx_…') + '" autocomplete="off" style="flex:1"><button type="button" class="btn btn-sm btn-gold" data-l="save">Speichern</button>'
                + (d.token ? '<button type="button" class="btn btn-sm btn-danger" data-l="del">Entfernen</button>' : '') + '</div></div>'
                + '<div class="nx-row"><button type="button" class="btn btn-sm" data-l="test">🔌 Verbindung testen</button>' + (MOBILE ? '' : '<button type="button" class="btn btn-sm" data-l="sync">⟳ Jetzt abgleichen</button>') + '</div>';
            box.onclick = e => {
                const b = e.target.closest('[data-l]');
                if (!b) return;
                const l = b.dataset.l;
                if (l === 'save' || l === 'del') return run('tools_link_token', { token: l === 'del' ? '' : $('#linkTok').value }, linkPanel).catch(() => {});
                b.disabled = true;
                run(l === 'test' ? 'tools_link_test' : 'tools_link_sync', {}, linkPanel).catch(() => {}).finally(() => { b.disabled = false; });
            };
        }).catch(fail(box));
    }

    // ======================= PC-Fernbedienung (Android → Dashboard → PC) =======================
    const INPUTS = [['Jump', '⤴ Springen'], ['Voice', '🎙 Mikro'], ['QuickMenuToggleLeft', '☰ Quick-Menü'], ['Run', '🏃 Rennen'], ['PanicButton', '🛑 Panik']];
    let remKeep = null;
    function loadRemote() {
        const box = $('#remBox');
        api('tools_remote_state').then(d => {
            const st = d.state || {}, fresh = st && st.fresh;
            const params = Object.entries(st.params || {}).filter(([, v]) => typeof v === 'boolean').slice(0, 24);
            const keep = remKeep || { text: '', translate: false };
            box.innerHTML = '<div class="nx-panel"><h2>🎮 PC-Fernbedienung</h2>'
                + (!d.state ? '<p>Noch kein PC verbunden. Am PC in VRC Nexus: Einstellungen → 🔗 Dashboard → Token eintragen und „Mit dem Dashboard verbinden“ einschalten.</p>'
                    : '<p>' + (fresh ? '<span class="nx-ok">● PC online</span>' : '<span class="nx-bad">● PC nicht erreichbar</span> (zuletzt ' + UI.ago(st.updated) + ')') + ' · '
                    + (st.running ? '🌐 <b>' + esc(st.world_name || '–') + '</b> · 👥 ' + (st.players || []).length + ' Spieler' : 'VRChat läuft nicht') + (st.osc ? ' · OSC aktiv' : '') + '</p>')
                + '<form id="remChat" class="nx-form"><div class="nx-wide"><label class="field">💬 Chatbox</label><textarea name="text" rows="3" placeholder="Text für die Chatbox am PC – /n = neue Zeile">' + esc(keep.text) + '</textarea></div>'
                + '<div class="nx-wide nx-row"><label class="vx-auto"><input type="checkbox" name="translate"' + (keep.translate ? ' checked' : '') + '> 🌐 übersetzen</label><label class="vx-auto"><input type="checkbox" name="split" checked> lange aufteilen</label>'
                + '<label class="vx-auto"><input type="checkbox" name="sound"> Ton</label><button class="btn btn-gold">Senden</button><button type="button" class="btn btn-sm" data-r="clear">🧹 Leeren</button></div></form>'
                + '<h3>Eingaben</h3><div class="nx-row">' + INPUTS.map(([k, l]) => '<button type="button" class="btn btn-sm" data-in="' + k + '">' + l + '</button>').join('')
                + '<button type="button" class="btn btn-sm" data-ty="1">⌨ tippt …</button><button type="button" class="btn btn-sm" data-ty="0">⌨ aus</button></div>'
                + (params.length ? '<h3>Avatar-Parameter</h3><div class="nx-row">' + params.map(([k, v]) => '<button type="button" class="chip' + (v ? ' on' : '') + '" data-p="' + esc(k) + '" data-v="' + (v ? 0 : 1) + '">' + (v ? '✔ ' : '') + esc(k) + '</button>').join('') + '</div>' : '')
                + ((d.presets || []).length ? '<h3>Schnellknöpfe</h3><div class="nx-row">' + d.presets.map(p => '<button type="button" class="btn btn-sm" data-pre="' + p.id + '">' + esc(p.label) + '</button>').join('') + '</div>' : '')
                + '<h3>Zuletzt</h3><div class="vx-small">' + (d.osc || []).slice(0, 8).map(o => esc(UI.clock(o.created)) + ' ' + esc(o.kind) + ' ' + esc((o.payload && (o.payload.text || o.payload.name)) || '') + ' – ' + esc(o.status)).join('<br>') + '</div></div>';
            const f = $('#remChat');
            f.oninput = () => { remKeep = { text: f.text.value, translate: f.translate.checked }; };
            f.onsubmit = e => {
                e.preventDefault();
                if (!f.text.value.trim()) return;
                run('tools_remote_send', { kind: 'chatbox', text: f.text.value, translate: f.translate.checked ? '1' : '', split: f.split.checked ? '1' : '', sound: f.sound.checked ? '1' : '' }, () => { remKeep = null; loadRemote(); }).catch(() => {});
            };
            box.onclick = e => {
                const b = e.target.closest('[data-in], [data-ty], [data-p], [data-pre], [data-r]');
                if (!b) return;
                let d2;
                if (b.dataset.in) d2 = { kind: 'input', name: b.dataset.in, value: '1', pulse: '1' };
                else if (b.dataset.ty) d2 = { kind: 'typing', on: b.dataset.ty };
                else if (b.dataset.p) d2 = { kind: 'param', name: b.dataset.p, type: 'bool', value: b.dataset.v };
                else if (b.dataset.r) d2 = { kind: 'chatbox', clear: '1', text: ' ' };
                else return toast('Schnellknöpfe bitte im Dashboard benutzen.');
                run('tools_remote_send', d2, () => setTimeout(loadRemote, 1500)).catch(() => {});
            };
        }).catch(x => { box.innerHTML = '<div class="nx-panel"><h2>🎮 PC-Fernbedienung</h2><div class="vx-error">⚠ ' + esc(x.message) + '</div><p class="vx-small">Einstellungen → 🔗 Dashboard: Adresse und Geräte-Token eintragen, Verbindung einschalten.</p></div>'; });
    }

    // ======================= Updates =======================
    const md = t => esc(t).replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/^## (.*)$/gm, '<b>$1</b>').replace(/\n/g, '<br>');
    function updateHtml(d, full) {
        const plat = d.platform === 'android' ? 'Android' : 'Windows';
        return '<div class="set-row"><div><b>Installiert</b><div class="nx-hint">' + plat + (d.checked ? ' · zuletzt geprüft ' + UI.ago(d.checked) : '') + '</div></div><div>VRC Nexus <b>' + esc(d.current) + '</b></div></div>'
            + (d.available ? '<div class="upd-new"><b>⬆ Version ' + esc(d.version) + ' ist verfügbar</b>' + (d.published ? ' <span class="vx-small">vom ' + esc(UI.isoDT(d.published)) + '</span>' : '')
                + (full && d.notes ? '<details><summary class="vx-small">Was ist neu?</summary><div class="vx-small upd-notes">' + md(d.notes) + '</div></details>' : '')
                + '<div class="nx-row"><button type="button" class="btn btn-gold" data-upd="install"' + (d.busy ? ' disabled' : '') + '>' + (d.platform === 'android' ? '⬇ APK herunterladen' : d.busy ? '⏳ ' + esc(d.progress) : '⬆ Jetzt aktualisieren') + '</button>'
                + (d.page ? '<a class="btn btn-sm" href="' + esc(d.page) + '" target="_blank" rel="noopener">Release ansehen</a>' : '') + '</div></div>'
                : '<p class="vx-small">' + (d.version ? '✔ Das ist die neueste Version.' : 'Noch nicht geprüft.') + '</p>')
            + '<div class="nx-row"><button type="button" class="btn btn-sm" data-upd="check">🔎 Nach Updates suchen</button></div>';
    }
    function updatePanel() {
        const box = $('#updBox');
        if (!box) return;
        api('update_status').then(d => { box.innerHTML = updateHtml(d, true); }).catch(fail(box));
    }
    let updPoll = null;
    function updAction(kind, box) {
        if (kind === 'check') return run('update_check', {}, d => { if (box) box.innerHTML = updateHtml(d, true); updBanner(d); }).catch(() => {});
        if (!confirm(MOBILE ? 'Neue APK herunterladen? Danach antippen und „Installieren“ wählen.' : 'Update jetzt installieren? VRC Nexus wird dafür kurz beendet und neu gestartet.')) return;
        run('update_install', {}).then(() => {
            if (MOBILE) return;
            clearInterval(updPoll);
            updPoll = setInterval(() => api('update_status').then(d => { if (box) box.innerHTML = updateHtml(d, true); updBanner(d); }).catch(() => {}), 1000);
        }).catch(() => {});
    }
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-upd]');
        if (!b) return;
        if (b.dataset.upd === 'later') { UI.store.set('updLater', b.dataset.v); updBanner(null); return; }
        updAction(b.dataset.upd, $('#updBox'));
    });
    function updBanner(d) {
        let bar = $('#updBar');
        if (!d || !d.available || (UI.store.get('updLater', '') === d.version && !d.busy)) { if (bar) bar.parentNode.removeChild(bar); return; }
        if (!bar) { bar = document.createElement('div'); bar.id = 'updBar'; bar.className = 'upd-bar'; const t = $('#tabs'); t.parentNode.insertBefore(bar, t.nextSibling); }
        bar.innerHTML = '⬆ <b>VRC Nexus ' + esc(d.version) + '</b> ist verfügbar (du hast ' + esc(d.current) + ').'
            + '<button type="button" class="btn btn-sm btn-gold" data-upd="install"' + (d.busy ? ' disabled' : '') + '>' + (MOBILE ? '⬇ APK laden' : d.busy ? '⏳ ' + esc(d.progress) : 'Jetzt aktualisieren') + '</button>'
            + '<button type="button" class="btn btn-sm" onclick="NexusUI.tab(\'settings\')">Was ist neu?</button><button type="button" class="btn btn-sm" data-upd="later" data-v="' + esc(d.version) + '">Später</button>';
    }
    setTimeout(() => api('update_status').then(updBanner).catch(() => {}), 1500);
    setInterval(() => api('update_status').then(updBanner).catch(() => {}), 10 * 60 * 1000);

    window.NexusApp = { open, applyUi, userExtra, groupExtra, menu };
    // Seite war vor dem Laden dieses Skripts schon offen?
    const vis = $$('.vx-tab').find(p => !p.hidden);
    if (vis) open(vis.dataset.pane, UI.store.get('tabSub', ''));
})();
