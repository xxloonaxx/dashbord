/* Discord-Zentrale – Oberfläche. Alle Daten kommen als JSON von discord.php; Texte werden immer maskiert. */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const img = (u, cls) => /^https:\/\//.test(u || '') ? '<img class="' + cls + '" src="' + esc(u) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '<span class="' + cls + ' dc-noimg">🤖</span>';
    const dt = ts => ts ? new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '–';
    const d = s => { const x = new Date(s); return isNaN(x) ? '' : x.toLocaleDateString('de-DE'); };
    const num = n => Number(n || 0).toLocaleString('de-DE');
    const store = { get(k, def) { try { const v = localStorage.getItem('dc-' + k); return v === null ? def : JSON.parse(v); } catch (e) { return def; } }, set(k, v) { try { localStorage.setItem('dc-' + k, JSON.stringify(v)); } catch (e) {} } };

    function api(act, data) {
        const body = new URLSearchParams();
        body.append('act', act);
        if (state.guild && !(data && 'guild' in data)) body.append('guild', state.guild);
        Object.entries(data || {}).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach(x => body.append(k + '[]', x)); else body.append(k, v ?? ''); });
        return fetch('discord.php', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body }).then(async r => {
            let j;
            try { j = await r.json(); } catch (e) { throw new Error('Unerwartete Antwort vom Server (HTTP ' + r.status + ').'); }
            if (!r.ok || j.error) throw new Error(j.error || 'Fehler ' + r.status);
            return j;
        });
    }
    function postForm(act, form, extra) {
        const body = new URLSearchParams(new FormData(form));
        body.append('act', act);
        if (state.guild && !body.has('guild')) body.append('guild', state.guild);
        Object.entries(extra || {}).forEach(([k, v]) => body.set(k, v));
        return fetch('discord.php', { method: 'POST', credentials: 'same-origin', body }).then(async r => { const j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || 'Fehler'); return j; });
    }
    function toast(msg, bad) {
        $$('.dc-toast').forEach(x => x.parentNode.removeChild(x));
        const t = document.createElement('div');
        t.className = 'dc-toast' + (bad ? ' bad' : '');
        t.textContent = msg;
        document.body.append(t);
        setTimeout(() => t.classList.add('out'), 2800);
        setTimeout(() => t.remove(), 3400);
    }
    const loading = (box, t) => { box.innerHTML = '<div class="dc-loading">' + esc(t || 'Lade …') + '</div>'; };
    const fail = box => x => { box.innerHTML = '<div class="dc-error">⚠ ' + esc(x.message) + '</div>'; };
    const state = { guild: '', channels: [], roles: [], members: [], loaded: {} };
    // Datum aus <input type=datetime-local> (Ortszeit des Browsers) eindeutig als UTC übertragen
    const toISO = v => { if (!v) return ''; const t = new Date(v); return isNaN(t) ? '' : t.toISOString(); };

    // =============== Einrichtung ===============
    if (!window.DC.configured) {
        $('#setupForm').addEventListener('submit', e => {
            e.preventDefault();
            const b = e.target.querySelector('button'); b.disabled = true; b.textContent = 'Prüfe …';
            api('setup_save', { token: e.target.token.value }).then(() => location.reload())
                .catch(x => { const err = $('#setupErr'); err.hidden = false; err.textContent = '⚠ ' + x.message; b.disabled = false; b.textContent = 'Verbinden'; });
        });
        return;
    }

    // =============== Dialog ===============
    const modal = $('#dcModal');
    const openModal = html => { $('#dcModalBody').innerHTML = html; modal.hidden = false; document.body.style.overflow = 'hidden'; };
    const closeModal = () => { modal.hidden = true; document.body.style.overflow = ''; };
    modal.addEventListener('click', e => { if (e.target === modal || e.target.closest('[data-close]')) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });
    document.addEventListener('click', e => { const c = e.target.closest('[data-copy]'); if (c) { e.preventDefault(); (navigator.clipboard ? navigator.clipboard.writeText(c.dataset.copy) : Promise.reject()).then(() => toast('Kopiert.')).catch(() => prompt('Kopieren:', c.dataset.copy)); } });

    // =============== Reiter ===============
    const TABS = $$('#tabs [data-tab]').map(b => b.dataset.tab);
    function tab(name) {
        if (!TABS.includes(name)) name = 'overview';
        $$('#tabs [data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === name));
        $$('.dc-pane').forEach(p => { p.hidden = p.dataset.pane !== name; });
        store.set('tab', name);
        const loaders = { overview: loadOverview, messages: () => loadMessages(false), members: () => loadMembers(false), roles: loadRoles, channels: loadChannelsTab, events: loadEvents, mod: loadMod, scheduled: loadScheduled, settings: renderSettings };
        if (loaders[name] && !state.loaded[name]) { state.loaded[name] = true; loaders[name](); }
    }
    $('#tabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) tab(b.dataset.tab); });

    // =============== Start ===============
    function init() {
        api('init', { guild: '' }).then(r => {
            state.bot = r.bot;
            state.guild = r.guild;
            state.defaultChannel = r.channel;
            $('#botInfo').innerHTML = img(r.bot.avatar, 'dc-botav') + '<b>' + esc(r.bot.name) + '</b>';
            const sel = $('#guildSel');
            sel.innerHTML = r.guilds.length ? r.guilds.map(g => '<option value="' + esc(g.id) + '"' + (g.id === r.guild ? ' selected' : '') + '>' + esc(g.name) + '</option>').join('') : '<option value="">– kein Server –</option>';
            if (r.sent) toast('⏰ ' + r.sent + ' geplante Nachricht(en) gesendet.');
            if (!r.guilds.length) {
                $$('.dc-pane').forEach(p => { p.hidden = p.dataset.pane !== 'settings'; });
                $('#globalErr').hidden = false;
                $('#globalErr').innerHTML = '⚠ Der Bot ist noch auf keinem Server. <a href="' + esc(r.bot.invite) + '" target="_blank" rel="noopener noreferrer">Jetzt einladen ↗</a> und danach die Seite neu laden.';
                renderSettings();
                return;
            }
            loadChannels().then(() => tab(store.get('tab', 'overview')));
        }).catch(x => { $('#globalErr').hidden = false; $('#globalErr').textContent = '⚠ ' + x.message; });
    }
    $('#guildSel').addEventListener('change', e => {
        state.guild = e.target.value;
        state.loaded = {};
        api('settings_save', { guild: state.guild }).catch(() => {});
        loadChannels().then(() => tab(store.get('tab', 'overview')));
    });
    function loadChannels() {
        return api('channels').then(r => {
            state.channels = r.channels;
            const cats = r.channels.filter(c => c.type === 4);
            const writable = r.channels.filter(c => c.type === 0 || c.type === 5);
            const opt = c => '<option value="' + esc(c.id) + '">' + esc(c.icon + ' ' + c.name) + '</option>';
            const grouped = [...writable.filter(c => !c.parent).map(opt), ...cats.map(cat => { const k = writable.filter(c => c.parent === cat.id); return k.length ? '<optgroup label="' + esc(cat.name) + '">' + k.map(opt).join('') + '</optgroup>' : ''; })].join('');
            $$('.dc-chsel').forEach(s => { const keep = s.value; const first = s.closest('#settingsForm') ? '<option value="">– keiner –</option>' : ''; s.innerHTML = first + grouped; if (keep) s.value = keep; });
            const last = store.get('ch-' + state.guild, '');
            const cs = $('#composer select[name=channel]');
            if (last && writable.some(c => c.id === last)) cs.value = last;
            $('#settingsForm select[name=channel]').value = state.defaultChannel || '';
            $$('.dc-catsel').forEach(s => { s.innerHTML = '<option value="">– keine –</option>' + cats.map(c => '<option value="' + esc(c.id) + '">' + esc(c.name) + '</option>').join(''); });
            if (!$('[data-pane=messages]').hidden) loadMessages(false);
        }).catch(x => toast(x.message, true));
    }

    // =============== Übersicht ===============
    function loadOverview() {
        const box = $('#ovBox');
        loading(box, 'Lade Server …');
        api('overview').then(r => {
            const g = r.guild, s = r.stats || {};
            state.roles = r.roles;
            fillRoleFilter();
            let h = (g.banner ? '<div class="dc-banner" style="background-image:url(\'' + esc(g.banner) + '\')"></div>' : '')
                + '<div class="dc-ghead">' + img(g.icon, 'dc-gicon') + '<div><h2>' + esc(g.name) + '</h2><small>Erstellt am ' + esc(new Date(g.created * 1000).toLocaleDateString('de-DE')) + ' · Boost-Stufe ' + g.tier + '</small>'
                + (g.description ? '<p>' + esc(g.description) + '</p>' : '') + '</div></div>'
                + '<div class="dc-kpis"><div><b>' + num(g.members) + '</b><span>Mitglieder</span></div><div><b class="on">' + num(g.online) + '</b><span>gerade online</span></div>'
                + '<div><b>' + num(g.boosts) + '</b><span>Boosts</span></div><div><b>' + g.channels + '</b><span>Kanäle</span></div><div><b>' + g.roles + '</b><span>Rollen</span></div>'
                + '<div><b>' + g.emojis + '</b><span>Emojis</span></div>' + (s.loaded ? '<div><b>' + num(s.humans) + ' / ' + num(s.bots) + '</b><span>Menschen / Bots</span></div><div><b>' + s.boosters + '</b><span>Booster</span></div>'
                + (s.timeouts ? '<div><b class="bad">' + s.timeouts + '</b><span>im Timeout</span></div>' : '') : '') + '</div><div class="dc-cols">';
            if (s.error) h += '<div class="panel"><div class="panel-title">👥 Mitglieder-Statistik</div><div class="dc-error">⚠ ' + esc(s.error) + '</div><p class="dc-small">Für Mitgliederlisten im Developer-Portal unter Bot → <b>Server Members Intent</b> einschalten.</p></div>';
            if (s.joins) {
                const mx = Math.max(1, ...Object.values(s.joins));
                h += '<div class="panel"><div class="panel-title">📈 Neue Mitglieder pro Monat</div><div class="dc-bars">' + Object.entries(s.joins).map(([m, v]) => '<div class="dc-bar" title="' + esc(m + ': ' + v) + '"><i style="height:' + Math.max(2, v / mx * 100) + '%"></i><b>' + v + '</b><span>' + esc(m.slice(5)) + '</span></div>').join('') + '</div>'
                    + (s.loaded >= 1000 ? '<p class="dc-small">Ausgewertet: die ersten 1000 Mitglieder.</p>' : '') + '</div>';
                h += '<div class="panel"><div class="panel-title">👋 Zuletzt beigetreten</div>' + s.recent.map(m => '<div class="dc-mrow">' + img(m.avatar, 'dc-av') + '<b>' + esc(m.name) + '</b><small>' + esc(d(m.joined)) + '</small></div>').join('') + '</div>';
                const top = r.roles.map(x => Object.assign({ n: s.top_roles[x.id] || 0 }, x)).filter(x => x.n).sort((a, b) => b.n - a.n).slice(0, 10);
                if (top.length) { const m2 = Math.max(...top.map(x => x.n)); h += '<div class="panel"><div class="panel-title">🎭 Größte Rollen</div>' + top.map(x => '<div class="dc-hrow"><span style="color:' + esc(x.color || '#cfd6e0') + '">' + esc(x.name) + '</span><i><b style="width:' + (x.n / m2 * 100) + '%;background:' + esc(x.color || '#38d5e6') + '"></b></i><em>' + x.n + '</em></div>').join('') + '</div>'; }
            }
            h += '<div class="panel"><div class="panel-title"># Kanal-Arten</div>' + Object.entries(g.channel_types).map(([k, v]) => '<div class="dc-hrow"><span>' + esc(k) + '</span><em>' + v + '</em></div>').join('')
                + (g.features.length ? '<div class="dc-feat">' + g.features.map(f => '<span>' + esc(f.toLowerCase().replace(/_/g, ' ')) + '</span>').join('') + '</div>' : '') + '</div></div>';
            box.innerHTML = h;
        }).catch(fail(box));
    }
    function fillRoleFilter() {
        const sel = $('#memberRole'), keep = sel.value;
        sel.innerHTML = '<option value="">Alle Rollen</option>' + state.roles.map(r => '<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>').join('');
        sel.value = keep;
    }
    const ensureRoles = () => state.roles.length ? Promise.resolve() : api('roles').then(r => { state.roles = r.roles.filter(x => !x.everyone); fillRoleFilter(); });

    // =============== Nachrichten ===============
    const composer = $('#composer');
    const md = s => esc(s).replace(/```([\s\S]+?)```/g, '<pre>$1</pre>').replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/__(.+?)__/g, '<u>$1</u>')
        .replace(/\*(.+?)\*/g, '<i>$1</i>').replace(/~~(.+?)~~/g, '<s>$1</s>').replace(/\|\|(.+?)\|\|/g, '<span class="dc-spoiler">$1</span>').replace(/^&gt; (.*)$/gm, '<blockquote>$1</blockquote>')
        .replace(/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>').replace(/\n/g, '<br>');
    function preview() {
        const f = composer;
        $('#cCount').textContent = f.content.value.length;
        let h = '<div class="dc-pmsg">' + img(state.bot && state.bot.avatar, 'dc-av') + '<div><div class="dc-pname">' + esc(state.bot ? state.bot.name : 'Bot') + ' <span class="dc-botbadge">BOT</span> <small>heute</small></div>';
        if (f.content.value) h += '<div class="dc-ptext">' + md(f.content.value) + '</div>';
        if (f.use_embed.checked) {
            const fields = $$('.dc-field', f).map(r => ({ n: r.querySelector('[name="f_name[]"]').value, v: r.querySelector('[name="f_value[]"]').value, i: r.querySelector('[type=checkbox]').checked })).filter(x => x.n && x.v);
            h += '<div class="dc-embed" style="--ec:' + esc(f.e_color.value) + '">' + (f.e_author.value ? '<div class="dc-eauthor">' + esc(f.e_author.value) + '</div>' : '')
                + (/^https:/.test(f.e_thumb.value) ? '<img class="dc-ethumb" src="' + esc(f.e_thumb.value) + '" alt="">' : '')
                + (f.e_title.value ? '<div class="dc-etitle">' + (f.e_url.value ? '<a>' + esc(f.e_title.value) + '</a>' : esc(f.e_title.value)) + '</div>' : '')
                + (f.e_desc.value ? '<div class="dc-edesc">' + md(f.e_desc.value) + '</div>' : '')
                + (fields.length ? '<div class="dc-efields">' + fields.map(x => '<div class="' + (x.i ? 'in' : '') + '"><b>' + esc(x.n) + '</b><span>' + md(x.v) + '</span></div>').join('') + '</div>' : '')
                + (/^https:/.test(f.e_image.value) ? '<img class="dc-eimage" src="' + esc(f.e_image.value) + '" alt="">' : '')
                + (f.e_footer.value || f.e_time.checked ? '<div class="dc-efooter">' + esc(f.e_footer.value) + (f.e_footer.value && f.e_time.checked ? ' • ' : '') + (f.e_time.checked ? 'Heute' : '') + '</div>' : '') + '</div>';
        }
        if (!f.content.value && !f.use_embed.checked) h += '<div class="dc-empty">Die Vorschau erscheint, sobald du etwas schreibst.</div>';
        $('#preview').innerHTML = h + '</div></div>';
    }
    function addField(n, v, i) {
        if ($$('.dc-field', composer).length >= 25) return;
        const r = document.createElement('div');
        r.className = 'dc-field';
        r.innerHTML = '<input type="text" name="f_name[]" placeholder="Name" maxlength="256"><input type="text" name="f_value[]" placeholder="Wert" maxlength="1024"><label title="Nebeneinander"><input type="checkbox" name="f_inline[]" value="1"> ⇆</label><button type="button" class="dc-x" title="Entfernen">×</button>';
        r.querySelector('[name="f_name[]"]').value = n || '';
        r.querySelector('[name="f_value[]"]').value = v || '';
        r.querySelector('[type=checkbox]').checked = !!i;
        r.querySelector('.dc-x').onclick = () => { r.remove(); preview(); };
        $('#fieldRows').append(r);
    }
    // f_inline[] muss zu den Feldern passen – beim Senden als Index-Liste nachbauen
    const fieldData = () => { const rows = $$('.dc-field', composer); return { f_name: rows.map(r => r.querySelector('[name="f_name[]"]').value), f_value: rows.map(r => r.querySelector('[name="f_value[]"]').value), f_inline: rows.map(r => r.querySelector('[type=checkbox]').checked ? '1' : '') }; };
    $('#addField').addEventListener('click', () => { addField(); preview(); });
    $('#useEmbed').addEventListener('change', e => { $('#embedFields').hidden = !e.target.checked; preview(); });
    composer.addEventListener('input', preview);
    $('#schedToggle').addEventListener('click', () => {
        const b = $('#schedBox');
        b.hidden = !b.hidden;
        $('#sendBtn').textContent = b.hidden ? '📨 Jetzt senden' : '⏰ Planen';
        if (!b.hidden && !composer.at.value) { const t = new Date(Date.now() + 3600000); t.setMinutes(0, 0, 0); composer.at.value = new Date(t - t.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
    });
    function payloadFields() {
        const f = composer, o = {};
        new FormData(f).forEach((v, k) => { if (!k.endsWith('[]')) o[k] = v; });
        if (!f.use_embed.checked) delete o.use_embed;
        return Object.assign(o, fieldData());
    }
    composer.addEventListener('submit', e => {
        e.preventDefault();
        const sched = !$('#schedBox').hidden;
        const data = payloadFields();
        if (sched) { data.at = toISO(composer.at.value); data.channel_name = composer.channel.selectedOptions[0] ? composer.channel.selectedOptions[0].textContent : ''; }
        store.set('ch-' + state.guild, composer.channel.value);
        $('#sendBtn').disabled = true;
        api(sched ? 'schedule_save' : 'send', data).then(r => {
            toast(r.msg);
            composer.content.value = ''; composer.reply_to.value = ''; $('#replyInfo').hidden = true;
            preview();
            if (sched) state.loaded.scheduled = false; else loadMessages(false);
        }).catch(x => toast(x.message, true)).finally(() => { $('#sendBtn').disabled = false; });
    });
    // Vorlagen (lokal im Browser)
    const tpls = store.get('templates', []);
    const renderTpls = () => { $('#tplSel').innerHTML = '<option value="">📁 Vorlagen …</option>' + tpls.map((t, i) => '<option value="' + i + '">' + esc(t.name) + '</option>').join('') + (tpls.length ? '<option value="__del">🗑 Vorlage löschen …</option>' : ''); };
    renderTpls();
    $('#tplSave').addEventListener('click', () => {
        const name = prompt('Name der Vorlage:', composer.e_title.value || composer.content.value.slice(0, 30) || 'Vorlage');
        if (!name) return;
        const data = payloadFields(); delete data.channel; delete data.at; delete data.reply_to;
        tpls.push({ name, data }); store.set('templates', tpls); renderTpls(); toast('Vorlage gespeichert.');
    });
    $('#tplSel').addEventListener('change', e => {
        const v = e.target.value;
        e.target.value = '';
        if (v === '__del') { const n = prompt('Welche Vorlage löschen? Nummer:\n' + tpls.map((t, i) => (i + 1) + ') ' + t.name).join('\n')); const i = (+n || 0) - 1; if (tpls[i]) { tpls.splice(i, 1); store.set('templates', tpls); renderTpls(); } return; }
        const t = tpls[+v];
        if (!t) return;
        Object.entries(t.data).forEach(([k, val]) => { const el = composer.elements[k]; if (!el || Array.isArray(val)) return; if (el.type === 'checkbox') el.checked = !!val; else el.value = val; });
        $('#fieldRows').innerHTML = '';
        (t.data.f_name || []).forEach((n, i) => addField(n, t.data.f_value[i], t.data.f_inline[i]));
        composer.use_embed.checked = !!t.data.use_embed;
        $('#embedFields').hidden = !composer.use_embed.checked;
        preview();
    });
    // Countdown einfügen
    $('#cdBtn').addEventListener('click', () => {
        api('countdowns').then(r => {
            if (!r.items.length) return toast('Keine anstehenden Countdowns.', true);
            openModal('<h2>⏳ Countdown einfügen</h2>' + r.items.map((c, i) => '<button type="button" class="dc-pick" data-cd="' + i + '">' + esc(c.icon + ' ' + c.title) + '<small>noch ' + c.days + ' Tage · ' + esc(dt(c.ts)) + '</small></button>').join(''));
            $$('[data-cd]').forEach(b => b.onclick = () => {
                const c = r.items[+b.dataset.cd];
                composer.use_embed.checked = true; $('#embedFields').hidden = false;
                composer.e_title.value = c.icon + ' ' + c.title;
                composer.e_desc.value = 'Noch **' + c.days + ' ' + (c.days === 1 ? 'Tag' : 'Tage') + '**! 🎉\n' + dt(c.ts) + (c.note ? '\n' + c.note : '');
                composer.e_url.value = /^https:/.test(c.link) ? c.link : '';
                composer.e_color.value = '#f3c262';
                closeModal(); preview();
            });
        }).catch(x => toast(x.message, true));
    });
    // Letzte Nachrichten
    let lastMsgs = [];
    function loadMessages(older) {
        const ch = composer.channel.value, box = $('#msgBox');
        if (!ch) return;
        if (!older) loading(box, 'Lade Nachrichten …');
        api('messages', { channel: ch, before: older && lastMsgs.length ? lastMsgs[lastMsgs.length - 1].id : '' }).then(r => {
            lastMsgs = older ? lastMsgs.concat(r.messages) : r.messages;
            const emptyText = lastMsgs.filter(m => !m.bot && !m.html && !m.embeds.length && !m.files.length).length;
            box.innerHTML = (lastMsgs.length ? lastMsgs.map(m => '<div class="dc-msg' + (m.pinned ? ' pinned' : '') + '">' + img(m.avatar, 'dc-av') + '<div class="dc-mbody"><div class="dc-pname">' + esc(m.author) + (m.bot ? ' <span class="dc-botbadge">BOT</span>' : '') + ' <small>' + esc(dt(m.ts)) + (m.edited ? ' (bearbeitet)' : '') + (m.pinned ? ' 📌' : '') + '</small></div>'
                + (m.html ? '<div class="fmt dc-mtext">' + m.html + '</div>' : '') + m.embeds.map(e => '<div class="dc-embed sm" style="--ec:' + esc(e.color || '#4f545c') + '">' + (e.title ? '<div class="dc-etitle">' + esc(e.title) + '</div>' : '') + (e.desc ? '<div class="dc-edesc">' + esc(e.desc) + '</div>' : '') + '</div>').join('')
                + m.files.map(f => f.image && /^https:/.test(f.url) ? '<img class="dc-mimg" src="' + esc(f.url) + '" alt="' + esc(f.name) + '" loading="lazy">' : '<a class="dc-file" href="' + esc(f.url) + '" target="_blank" rel="noopener noreferrer">📎 ' + esc(f.name) + '</a>').join('')
                + (m.reactions.length ? '<div class="dc-reacts">' + m.reactions.map(x => '<span>' + esc(x) + '</span>').join('') + '</div>' : '') + '</div>'
                + '<span class="dc-macts"><button type="button" title="Antworten" data-reply="' + esc(m.id) + '" data-name="' + esc(m.author) + '">↩</button><button type="button" title="' + (m.pinned ? 'Lösen' : 'Anpinnen') + '" data-pin="' + esc(m.id) + '" data-on="' + (m.pinned ? '' : '1') + '">📌</button>'
                + '<button type="button" title="ID kopieren" data-copy="' + esc(m.id) + '">⧉</button><button type="button" title="Löschen" class="bad" data-del="' + esc(m.id) + '">🗑</button></span></div>').join('') : '<div class="dc-empty">Keine Nachrichten.</div>')
                + (emptyText > 3 ? '<p class="dc-small">Viele Nachrichten ohne Text? Im Developer-Portal den <b>Message Content Intent</b> einschalten.</p>' : '');
            $('#msgMore').hidden = r.messages.length < 50;
        }).catch(fail(box));
    }
    composer.channel.addEventListener('change', () => { store.set('ch-' + state.guild, composer.channel.value); loadMessages(false); });
    $('#msgReload').addEventListener('click', () => loadMessages(false));
    $('#msgMore').addEventListener('click', () => loadMessages(true));
    $('#msgBox').addEventListener('click', e => {
        const ch = composer.channel.value, b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.reply) { composer.reply_to.value = b.dataset.reply; $('#replyInfo').hidden = false; $('#replyInfo').innerHTML = '↩ Antwort an <b>' + esc(b.dataset.name) + '</b> <button type="button" class="dc-x">×</button>'; $('#replyInfo .dc-x').onclick = () => { composer.reply_to.value = ''; $('#replyInfo').hidden = true; }; composer.content.focus(); }
        if (b.dataset.pin) api('msg_pin', { channel: ch, message: b.dataset.pin, on: b.dataset.on }).then(r => { toast(r.msg); loadMessages(false); }).catch(x => toast(x.message, true));
        if (b.dataset.del && confirm('Nachricht wirklich löschen?')) api('msg_delete', { channel: ch, message: b.dataset.del }).then(r => { toast(r.msg); b.closest('.dc-msg').remove(); }).catch(x => toast(x.message, true));
    });

    // =============== Mitglieder ===============
    let memberQuery = '';
    const roleChip = id => { const r = state.roles.find(x => x.id === id); return r ? '<span class="dc-role" style="--rc:' + esc(r.color || '#99aab5') + '">' + esc(r.name) + '</span>' : ''; };
    function loadMembers(more) {
        const box = $('#memberBox');
        if (!more) { loading(box, 'Lade Mitglieder …'); state.members = []; }
        ensureRoles().then(() => api('members', { q: memberQuery, after: more && state.members.length ? state.members[state.members.length - 1].id : '' })).then(r => {
            state.members = more ? state.members.concat(r.members) : r.members;
            renderMembers();
            $('#memberMore').hidden = !!memberQuery || r.members.length < 200;
        }).catch(fail(box));
    }
    function renderMembers() {
        const role = $('#memberRole').value, nob = $('#hideBots').checked, to = $('#onlyTimeout').checked;
        const list = state.members.filter(m => (!role || m.roles.includes(role)) && (!nob || !m.bot) && (!to || m.timeout));
        $('#memberCount').textContent = list.length + ' von ' + state.members.length + ' geladen';
        $('#memberBox').innerHTML = list.length ? '<div class="dc-members">' + list.map(m => '<div class="dc-member">' + img(m.avatar, 'dc-av') + '<div class="dc-minfo"><b>' + esc(m.nick || m.name) + (m.bot ? ' <span class="dc-botbadge">BOT</span>' : '') + (m.booster ? ' 💎' : '') + (m.timeout ? ' <span class="dc-to" title="Timeout bis ' + esc(new Date(m.timeout).toLocaleString('de-DE')) + '">⏱</span>' : '') + '</b>'
            + '<small>@' + esc(m.username) + ' · beigetreten ' + esc(d(m.joined)) + ' · Konto seit ' + esc(new Date(m.created * 1000).toLocaleDateString('de-DE')) + '</small><div class="dc-roles">' + m.roles.map(roleChip).join('') + '</div></div>'
            + '<span class="dc-macts"><button type="button" data-m="nick" data-id="' + esc(m.id) + '" title="Spitzname">✎</button><button type="button" data-m="roles" data-id="' + esc(m.id) + '" title="Rollen">🎭</button>'
            + '<button type="button" data-m="timeout" data-id="' + esc(m.id) + '" title="Timeout">⏱</button><button type="button" data-m="kick" data-id="' + esc(m.id) + '" title="Kicken">👢</button><button type="button" class="bad" data-m="ban" data-id="' + esc(m.id) + '" title="Bannen">🔨</button>'
            + '<button type="button" data-copy="' + esc(m.id) + '" title="ID kopieren">⧉</button></span></div>').join('') + '</div>' : '<div class="dc-empty">Keine Mitglieder gefunden.</div>';
    }
    $('#memberSearch').addEventListener('submit', e => { e.preventDefault(); memberQuery = e.target.q.value.trim(); loadMembers(false); });
    ['#memberRole', '#hideBots', '#onlyTimeout'].forEach(s => $(s).addEventListener('change', renderMembers));
    $('#memberMore').addEventListener('click', () => loadMembers(true));
    $('#memberBox').addEventListener('click', e => {
        const b = e.target.closest('[data-m]');
        if (!b) return;
        const m = state.members.find(x => x.id === b.dataset.id), name = m.nick || m.name;
        const done = r => { toast(r.msg); closeModal(); loadMembers(false); };
        if (b.dataset.m === 'nick') {
            const n = prompt('Spitzname für ' + name + ' (leer = entfernen):', m.nick);
            if (n !== null) api('member_nick', { user: m.id, nick: n }).then(done).catch(x => toast(x.message, true));
        }
        if (b.dataset.m === 'roles') {
            openModal('<h2>🎭 Rollen von ' + esc(name) + '</h2><p class="dc-small">Häkchen setzen oder entfernen – wird sofort gespeichert.</p><div class="dc-rolepick">' + state.roles.filter(r => !r.managed).map(r => '<label style="--rc:' + esc(r.color || '#99aab5') + '"><input type="checkbox" value="' + esc(r.id) + '"' + (m.roles.includes(r.id) ? ' checked' : '') + '> ' + esc(r.name) + '</label>').join('') + '</div>');
            $$('.dc-rolepick input').forEach(c => c.addEventListener('change', () => api('member_role', { user: m.id, role: c.value, on: c.checked ? 1 : '' }).then(r => { toast(r.msg); if (c.checked) m.roles.push(c.value); else m.roles = m.roles.filter(x => x !== c.value); renderMembers(); }).catch(x => { toast(x.message, true); c.checked = !c.checked; })));
        }
        if (b.dataset.m === 'timeout') {
            openModal('<h2>⏱ Timeout für ' + esc(name) + '</h2><form id="toForm"><label class="field">Dauer</label><select name="minutes"><option value="5">5 Minuten</option><option value="60">1 Stunde</option><option value="1440">1 Tag</option><option value="10080">1 Woche</option><option value="40320">28 Tage (Maximum)</option>'
                + (m.timeout ? '<option value="0" selected>Timeout aufheben</option>' : '') + '</select><label class="field">Grund (erscheint im Audit-Log)</label><input type="text" name="reason" maxlength="400"><div class="dc-actions"><button class="btn btn-gold">Übernehmen</button></div></form>');
            $('#toForm').addEventListener('submit', ev => { ev.preventDefault(); postForm('member_timeout', ev.target, { user: m.id }).then(done).catch(x => toast(x.message, true)); });
        }
        if (b.dataset.m === 'kick' || b.dataset.m === 'ban') {
            const ban = b.dataset.m === 'ban';
            openModal('<h2>' + (ban ? '🔨 Bannen' : '👢 Kicken') + ': ' + esc(name) + '</h2><form id="mForm"><label class="field">Grund (erscheint im Audit-Log)</label><input type="text" name="reason" maxlength="400">'
                + (ban ? '<label class="field">Nachrichten der letzten … löschen</label><select name="days"><option value="0">nichts löschen</option><option value="1">1 Tag</option><option value="7">7 Tage</option></select>' : '')
                + '<div class="dc-actions"><button class="btn btn-danger">' + (ban ? 'Bannen' : 'Kicken') + '</button></div></form>');
            $('#mForm').addEventListener('submit', ev => { ev.preventDefault(); postForm(ban ? 'member_ban' : 'member_kick', ev.target, { user: m.id }).then(done).catch(x => toast(x.message, true)); });
        }
    });

    // =============== Rollen ===============
    function loadRoles() {
        const box = $('#roleBox');
        loading(box);
        api('roles').then(r => {
            state.roles = r.roles.filter(x => !x.everyone);
            fillRoleFilter();
            const count = id => state.members.filter(m => m.roles.includes(id)).length;
            box.innerHTML = '<div class="panel"><table class="dc-table"><thead><tr><th>Rolle</th><th>Mitglieder*</th><th>Eigenschaften</th><th></th></tr></thead><tbody>' + r.roles.map(x => '<tr><td><span class="dc-dot" style="background:' + esc(x.color || '#99aab5') + '"></span><b style="color:' + esc(x.color || '#dbdee1') + '">' + esc(x.name) + '</b></td>'
                + '<td>' + (x.everyone ? 'alle' : state.members.length ? count(x.id) : '–') + '</td><td>' + [x.admin ? '<span class="dc-tag bad">Admin</span>' : '', x.mod ? '<span class="dc-tag">Moderation</span>' : '', x.hoist ? '<span class="dc-tag">getrennt angezeigt</span>' : '', x.mentionable ? '<span class="dc-tag">erwähnbar</span>' : '', x.managed ? '<span class="dc-tag">von Integration</span>' : ''].join('') + '</td>'
                + '<td>' + (x.everyone ? '' : '<button type="button" class="btn btn-sm" data-showrole="' + esc(x.id) + '">Mitglieder</button> <button type="button" class="btn btn-sm" data-copy="' + esc(x.id) + '">⧉ ID</button>') + '</td></tr>').join('') + '</tbody></table>'
                + '<p class="dc-small">* gezählt unter den geladenen Mitgliedern (Reiter „Mitglieder“).</p></div>';
        }).catch(fail(box));
    }
    $('#roleBox').addEventListener('click', e => { const b = e.target.closest('[data-showrole]'); if (!b) return; tab('members'); $('#memberRole').value = b.dataset.showrole; if (state.members.length) renderMembers(); });

    // =============== Kanäle & Einladungen ===============
    function loadChannelsTab() {
        const box = $('#chanBox');
        const cats = state.channels.filter(c => c.type === 4);
        const row = c => '<div class="dc-ch"><span class="dc-chic">' + esc(c.icon) + '</span><b>' + esc(c.name) + '</b>' + (c.nsfw ? ' <span class="dc-tag bad">NSFW</span>' : '') + (c.slowmode ? ' <span class="dc-tag">🐢 ' + c.slowmode + ' s</span>' : '')
            + (c.topic ? '<small>' + esc(c.topic) + '</small>' : '') + '<span class="dc-macts">' + (c.type !== 4 ? '<button type="button" data-inv="' + esc(c.id) + '" title="Einladung erstellen">🔗</button>' : '') + '<button type="button" data-copy="' + esc(c.id) + '" title="ID kopieren">⧉</button></span></div>';
        box.innerHTML = state.channels.filter(c => !c.parent && c.type !== 4).map(row).join('')
            + cats.map(cat => '<div class="dc-cat"><div class="dc-cathead">📁 ' + esc(cat.name) + '</div>' + state.channels.filter(c => c.parent === cat.id).map(row).join('') + '</div>').join('') || '<div class="dc-empty">Keine Kanäle.</div>';
        loadInvites();
    }
    function loadInvites() {
        const box = $('#invBox');
        loading(box);
        api('invites').then(r => {
            box.innerHTML = r.invites.length ? r.invites.map(i => '<div class="dc-mrow"><code>discord.gg/' + esc(i.code) + '</code><small>#' + esc(i.channel) + ' · ' + i.uses + (i.max ? '/' + i.max : '') + ' Nutzungen · ' + (i.expires ? 'bis ' + esc(new Date(i.expires).toLocaleString('de-DE')) : 'unbegrenzt') + (i.inviter ? ' · von ' + esc(i.inviter) : '') + '</small>'
                + '<span class="dc-macts"><button type="button" data-copy="https://discord.gg/' + esc(i.code) + '" title="Kopieren">⧉</button><button type="button" class="bad" data-invdel="' + esc(i.code) + '" title="Löschen">🗑</button></span></div>').join('') : '<div class="dc-empty">Keine aktiven Einladungen.</div>';
        }).catch(fail(box));
    }
    $('#invReload').addEventListener('click', loadInvites);
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-inv]');
        if (b) {
            openModal('<h2>🔗 Einladung erstellen</h2><form id="invForm"><label class="field">Gültig für</label><select name="max_age"><option value="1800">30 Minuten</option><option value="3600">1 Stunde</option><option value="86400" selected>1 Tag</option><option value="604800">7 Tage</option><option value="0">unbegrenzt</option></select>'
                + '<label class="field">Maximale Nutzungen</label><select name="max_uses"><option value="0">unbegrenzt</option><option value="1">1</option><option value="5">5</option><option value="10">10</option><option value="25">25</option><option value="100">100</option></select><div class="dc-actions"><button class="btn btn-gold">Erstellen</button></div></form><div id="invResult"></div>');
            $('#invForm').addEventListener('submit', ev => { ev.preventDefault(); postForm('invite_create', ev.target, { channel: b.dataset.inv }).then(r => { $('#invResult').innerHTML = '<div class="dc-cron"><code>' + esc(r.url) + '</code><button type="button" class="btn btn-sm" data-copy="' + esc(r.url) + '">⧉ Kopieren</button></div>'; loadInvites(); }).catch(x => toast(x.message, true)); });
        }
        const del = e.target.closest('[data-invdel]');
        if (del && confirm('Einladung löschen?')) api('invite_delete', { code: del.dataset.invdel }).then(r => { toast(r.msg); loadInvites(); }).catch(x => toast(x.message, true));
    });
    $('#chanCreate').addEventListener('submit', e => { e.preventDefault(); postForm('channel_create', e.target).then(r => { toast(r.msg); e.target.reset(); loadChannels().then(loadChannelsTab); }).catch(x => toast(x.message, true)); });

    // =============== Events ===============
    const localDT = ts => { const t = new Date(ts * 1000); return new Date(t - t.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };
    function loadEvents() {
        const box = $('#evBox'), cal = $('#calBox');
        loading(box);
        api('events').then(r => {
            box.innerHTML = r.events.length ? r.events.sort((a, b) => a.start - b.start).map(e => '<div class="dc-event"><div class="dc-evdate"><b>' + new Date(e.start * 1000).getDate() + '</b><span>' + new Date(e.start * 1000).toLocaleDateString('de-DE', { month: 'short' }) + '</span></div>'
                + '<div><b>' + esc(e.name) + '</b>' + (e.status === 2 ? ' <span class="dc-tag on">läuft</span>' : '') + '<small>' + esc(dt(e.start)) + (e.end ? ' – ' + esc(new Date(e.end * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })) : '') + (e.location ? ' · 📍 ' + esc(e.location) : '') + ' · ⭐ ' + e.users + ' interessiert</small>'
                + (e.desc ? '<p>' + esc(e.desc.slice(0, 200)) + '</p>' : '') + '</div><span class="dc-macts">' + (window.DC.calendarEdit ? '<button type="button" data-evcal="' + esc(e.id) + '" title="In den Kalender übernehmen">📅</button>' : '') + '<button type="button" class="bad" data-evdel="' + esc(e.id) + '" title="Löschen">🗑</button></span></div>').join('')
                : '<div class="dc-empty">Keine geplanten Events.</div>';
            if (!r.calendar) { cal.innerHTML = '<div class="dc-empty">Kein Zugriff auf den Kalender.</div>'; return; }
            const future = r.calendar.filter(c => c.start > Date.now() / 1000 + 300);
            cal.innerHTML = future.length ? future.map((c, i) => '<div class="dc-mrow"><span>' + (c.type === 'countdown' ? '⏳' : '📅') + '</span><b>' + esc(c.title) + '</b><small>' + esc(dt(c.start)) + (c.location ? ' · ' + esc(c.location) : '') + '</small><span class="dc-macts"><button type="button" data-fromcal="' + i + '" title="Als Discord-Event anlegen">➜ Discord</button></span></div>').join('')
                : '<div class="dc-empty">Keine kommenden Termine im Kalender.</div>';
            $$('[data-fromcal]', cal).forEach(b => b.onclick = () => {
                const c = future[+b.dataset.fromcal], f = $('#evCreate');
                f.name.value = c.title.slice(0, 100); f.start.value = localDT(c.all_day ? c.start + 18 * 3600 : c.start); f.end.value = localDT(c.all_day ? c.start + 22 * 3600 : Math.max(c.end, c.start + 3600));
                f.location.value = c.location || ''; f.description.value = c.desc || '';
                f.scrollIntoView({ behavior: 'smooth' }); f.name.focus();
                toast('Daten übernommen – prüfen und „Auf Discord anlegen“ klicken.');
            });
        }).catch(fail(box));
    }
    $('#evCreate').addEventListener('submit', e => { e.preventDefault(); postForm('event_create', e.target, { start: toISO(e.target.start.value), end: toISO(e.target.end.value) }).then(r => { toast(r.msg); e.target.reset(); loadEvents(); }).catch(x => toast(x.message, true)); });
    $('#evBox').addEventListener('click', e => {
        const del = e.target.closest('[data-evdel]'), cal = e.target.closest('[data-evcal]');
        if (del && confirm('Event auf Discord löschen?')) api('event_delete', { event: del.dataset.evdel }).then(r => { toast(r.msg); loadEvents(); }).catch(x => toast(x.message, true));
        if (cal) api('event_to_calendar', { event: cal.dataset.evcal }).then(r => toast(r.msg)).catch(x => toast(x.message, true));
    });

    // =============== Moderation ===============
    function loadMod() {
        const bans = $('#banBox'), audit = $('#auditBox');
        loading(bans); loading(audit);
        api('bans').then(r => {
            bans.innerHTML = r.bans.length ? '<input type="search" class="dc-filter" placeholder="Banns filtern …">' + r.bans.map(b => '<div class="dc-mrow">' + img(b.avatar, 'dc-av') + '<b>' + esc(b.name) + '</b><small>' + esc(b.reason || 'ohne Grund') + '</small><span class="dc-macts"><button type="button" data-unban="' + esc(b.id) + '" data-name="' + esc(b.name) + '">Aufheben</button></span></div>').join('') : '<div class="dc-empty">Niemand gebannt. 🎉</div>';
            const f = $('.dc-filter', bans);
            if (f) f.addEventListener('input', () => $$('.dc-mrow', bans).forEach(r => { r.hidden = !r.textContent.toLowerCase().includes(f.value.toLowerCase()); }));
        }).catch(fail(bans));
        api('audit').then(r => {
            audit.innerHTML = r.entries.length ? r.entries.map(x => '<div class="dc-audit t' + (x.type >= 20 && x.type <= 23 ? ' bad' : '') + '"><time>' + esc(dt(x.ts)) + '</time><b>' + esc(x.action) + '</b><span>' + esc(x.user) + (x.target ? ' → ' + esc(x.target) : '') + (x.reason ? ' · „' + esc(x.reason) + '“' : '') + '</span></div>').join('') : '<div class="dc-empty">Keine Einträge.</div>';
        }).catch(fail(audit));
    }
    $('#banBox').addEventListener('click', e => { const b = e.target.closest('[data-unban]'); if (b && confirm('Bann von „' + b.dataset.name + '“ aufheben?')) api('member_unban', { user: b.dataset.unban }).then(r => { toast(r.msg); loadMod(); }).catch(x => toast(x.message, true)); });

    // =============== Geplant ===============
    const REP = { none: 'einmalig', daily: 'täglich', weekly: 'wöchentlich', monthly: 'monatlich' };
    function loadScheduled() {
        const box = $('#schedListBox');
        loading(box);
        api('scheduled').then(r => {
            $('#cronUrl').textContent = r.cron;
            $('#cronCopy').dataset.copy = r.cron;
            box.innerHTML = r.items.length ? r.items.map(s => '<div class="dc-sitem' + (s.paused ? ' paused' : '') + '"><div class="dc-evdate"><b>' + new Date(s.at * 1000).getDate() + '</b><span>' + new Date(s.at * 1000).toLocaleDateString('de-DE', { month: 'short' }) + '</span></div>'
                + '<div><b>' + esc(s.preview || '(Embed)') + '</b><small>' + esc(dt(s.at)) + ' · ' + esc(REP[s.repeat] || s.repeat) + ' · ' + esc(s.channel_name || s.channel) + ' · von ' + esc(s.created_by) + (s.last_sent ? ' · zuletzt ' + esc(dt(s.last_sent)) : '') + (s.paused ? ' · ⏸ pausiert' : '') + '</small>'
                + (s.last_error ? '<small class="bad">⚠ ' + esc(s.last_error) + '</small>' : '') + '</div><span class="dc-macts"><button type="button" data-sact="schedule_now" data-id="' + esc(s.id) + '" title="Jetzt senden">📨</button><button type="button" data-sact="schedule_toggle" data-id="' + esc(s.id) + '" title="' + (s.paused ? 'Fortsetzen' : 'Pausieren') + '">' + (s.paused ? '▶' : '⏸') + '</button>'
                + '<button type="button" class="bad" data-sact="schedule_delete" data-id="' + esc(s.id) + '" title="Löschen">🗑</button></span></div>').join('') : '<div class="dc-empty">Nichts geplant. Im Reiter „Nachrichten“ auf „⏰ Planen …“ klicken.</div>';
        }).catch(fail(box));
    }
    $('#schedListBox').addEventListener('click', e => {
        const b = e.target.closest('[data-sact]');
        if (!b || (b.dataset.sact === 'schedule_delete' && !confirm('Geplante Nachricht löschen?'))) return;
        api(b.dataset.sact, { id: b.dataset.id }).then(r => { toast(r.msg); loadScheduled(); }).catch(x => toast(x.message, true));
    });
    $('#cronReset').addEventListener('click', () => { if (confirm('Neuen Cron-Schlüssel erzeugen? Die alte Adresse funktioniert dann nicht mehr.')) api('cron_reset').then(r => { toast(r.msg); loadScheduled(); }).catch(x => toast(x.message, true)); });

    // =============== Einstellungen ===============
    function renderSettings() {
        const b = state.bot;
        if (!b) return;
        $('#botBox').innerHTML = '<div class="dc-mrow">' + img(b.avatar, 'dc-av lg') + '<b>' + esc(b.name) + '</b><small>ID ' + esc(b.id) + '</small></div><div class="dc-actions"><a class="btn btn-gold" href="' + esc(b.invite) + '" target="_blank" rel="noopener noreferrer">➕ Bot auf Server einladen ↗</a><button type="button" class="btn btn-sm" data-copy="' + esc(b.invite) + '">⧉ Link</button></div>';
    }
    $('#settingsForm').addEventListener('submit', e => { e.preventDefault(); api('settings_save', { channel: e.target.channel.value }).then(r => { toast(r.msg); state.defaultChannel = e.target.channel.value; }).catch(x => toast(x.message, true)); });
    $('#tokenForm').addEventListener('submit', e => { e.preventDefault(); api('setup_save', { token: e.target.token.value }).then(r => { toast(r.msg); setTimeout(() => location.reload(), 800); }).catch(x => toast(x.message, true)); });
    $('#removeBot').addEventListener('click', () => { if (confirm('Bot-Verbindung entfernen? Der Bot bleibt auf deinen Servern, die Zentrale kann ihn aber nicht mehr steuern.')) api('setup_remove').then(() => location.reload()).catch(x => toast(x.message, true)); });

    preview();
    init();
})();
