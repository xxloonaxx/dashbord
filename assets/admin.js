/* Admin-Zentrale: Passwort-Anzeige & -Stärke, Dialoge, Zähler-Animation, Befehlspalette (Strg+K) */
(function () {
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

    // Passwort anzeigen/verbergen
    $$('[data-eye]').forEach(b => b.addEventListener('click', () => {
        const f = document.getElementById(b.dataset.eye);
        f.type = f.type === 'password' ? 'text' : 'password';
    }));

    // Passwort-Stärke
    $$('input[data-meter]').forEach(pw => {
        const wrap = pw.closest('.field-wrap');
        const bar = wrap.querySelector('.meter div'), label = wrap.querySelector('.meter-label');
        pw.addEventListener('input', () => {
            const v = pw.value;
            let s = 0;
            if (v.length >= 8) s++;
            if (v.length >= 12) s++;
            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
            if (/\d/.test(v)) s++;
            if (/[^A-Za-z0-9]/.test(v)) s++;
            const steps = [['', 'transparent', 0], ['Sehr schwach', '#ff5555', 20], ['Schwach', '#ff5555', 40], ['Okay', '#f3c262', 60], ['Gut', '#50fa7b', 80], ['Stark', '#50fa7b', 100]];
            const [t, c, w] = v ? steps[Math.max(1, s)] : steps[0];
            bar.style.width = w + '%'; bar.style.background = c;
            label.textContent = t; label.style.color = c;
        });
    });

    // Zahlen hochzählen
    $$('[data-count]').forEach(el => {
        const target = +el.dataset.count;
        if (!target || matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = target; return; }
        const t0 = performance.now();
        const step = t => { const p = Math.min(1, (t - t0) / 700); el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); };
        requestAnimationFrame(step);
    });

    // ---------- Dialoge ----------
    const closeAll = () => $$('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    document.addEventListener('click', e => { if (e.target.closest('[data-close]') || e.target.classList.contains('modal-overlay')) closeAll(); });

    // Benutzer anlegen / bearbeiten
    const uf = $('#userForm');
    if (uf) {
        const F = n => uf.elements[n];
        $$('[data-user-edit]').forEach(b => b.addEventListener('click', () => {
            uf.reset();
            const u = b.dataset.userEdit === 'new' ? null : JSON.parse(b.dataset.userEdit);
            F('id').value = u ? u.id : '';
            $('#userFormTitle').textContent = u ? '// ' + (u.display_name || u.username) + ' bearbeiten' : '// Benutzer anlegen';
            $('#pwLabel').textContent = u ? 'Neues Passwort (leer = unverändert)' : 'Passwort *';
            F('password').required = !u;
            if (u) {
                ['username', 'display_name', 'note'].forEach(k => F(k).value = u[k] || '');
                const r = uf.querySelector('input[name=role_id][value="' + u.role_id + '"]');
                if (r) r.checked = true;
                F('active').checked = u.active == 1;
                F('expires_at').value = u.expires_at || '';
                F('must_change_pw').checked = u.must_change_pw == 1;
            }
            $('#userModal').classList.add('open');
            F('username').focus();
        }));
        $('#genPw').addEventListener('click', () => {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!$%&?#+';
            const a = new Uint32Array(14);
            crypto.getRandomValues(a);
            F('password').value = Array.from(a, x => chars[x % chars.length]).join('');
        });
        $('#copyPw').addEventListener('click', () => { if (F('password').value && navigator.clipboard) navigator.clipboard.writeText(F('password').value).then(() => { $('#copyPw').textContent = '✓'; setTimeout(() => $('#copyPw').textContent = '⧉', 1500); }); });
        if (location.hash === '#new') setTimeout(() => $('#newUserBtn').click(), 150);
    }

    // Rollen: alle/keine Rechte
    $$('[data-perm-all], [data-perm-none]').forEach(b => b.addEventListener('click', () => {
        $$('input[name="perms[]"]', b.closest('form')).forEach(cb => cb.checked = b.hasAttribute('data-perm-all'));
    }));

    // Kopieren (Einladungslinks, Wiederherstellungscodes)
    const copyOk = b => { const t = b.textContent; b.textContent = '✓ kopiert'; setTimeout(() => b.textContent = t, 1500); };
    $$('[data-copy], [data-copy-text]').forEach(b => b.addEventListener('click', () => {
        const txt = b.dataset.copyText !== undefined ? b.dataset.copyText : document.querySelector(b.dataset.copy).value;
        if (navigator.clipboard) navigator.clipboard.writeText(txt).then(() => copyOk(b));
    }));

    // Benutzerliste: Suche & Filter
    const ut = $('#userTable');
    if (ut) {
        const q = $('#uSearch'), role = $('#uRole'), st = $('#uState'), cnt = $('#uCount');
        const bf = $('#bulkForm');
        const bulkUpdate = () => {
            if (!bf) return;
            const n = $$('.u-cb:checked').length;
            bf.hidden = n === 0;
            $('#bulkN').textContent = n;
        };
        const filter = () => {
            const s = q.value.toLowerCase().trim();
            let n = 0;
            $$('tbody tr', ut).forEach(tr => {
                const ok = (!s || tr.dataset.search.includes(s)) && (!role.value || tr.dataset.role === role.value) && (!st.value || tr.dataset.flags.split(' ').includes(st.value));
                tr.hidden = !ok;
                if (ok) n++;
                else { const cb = $('.u-cb', tr); if (cb) cb.checked = false; }
            });
            cnt.textContent = n + ' angezeigt';
            bulkUpdate();
        };
        [q, role, st].forEach(el => el.addEventListener('input', filter));
        if (bf) {
            $$('.u-cb').forEach(cb => cb.addEventListener('change', bulkUpdate));
            $('#uAll').addEventListener('change', e => { $$('tbody tr:not([hidden]) .u-cb', ut).forEach(cb => cb.checked = e.target.checked); bulkUpdate(); });
            $('#bulkOp').addEventListener('change', e => { $('#bulkRole').hidden = e.target.value !== 'role'; $('#bulkDate').hidden = e.target.value !== 'expire'; });
            $('#bulkClear').addEventListener('click', () => { $$('.u-cb').forEach(cb => cb.checked = false); $('#uAll').checked = false; bulkUpdate(); });
        }
        filter();
    }

    // Rechte-Matrix: Spaltenkopf schaltet die ganze Rolle
    const mx = $('table.matrix');
    if (mx) $$('thead th', mx).forEach((th, col) => {
        if (!col) return;
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const boxes = $$('tbody tr', mx).map(tr => tr.children[col]).filter(td => td && td.classList.contains('mx-c')).map(td => td.firstElementChild).filter(cb => !cb.disabled);
            const on = boxes.some(cb => !cb.checked);
            boxes.forEach(cb => { cb.checked = on; });
        });
    });

    // ---------- Befehlspalette ----------
    const pal = $('#palette');
    if (!pal || !window.PALETTE) return;
    const input = $('#paletteInput'), list = $('#paletteList');
    let sel = 0, shown = [];
    const score = (item, q) => {
        if (!q) return 1;
        const t = (item.label + ' ' + item.hint).toLowerCase();
        if (t.startsWith(q)) return 3;
        if (t.includes(q)) return 2;
        let i = 0;   // Buchstaben in Reihenfolge ("bnz" findet "Benutzer")
        for (const c of t) if (c === q[i]) i++;
        return i === q.length ? 1 : 0;
    };
    function render() {
        const q = input.value.toLowerCase().trim();
        shown = window.PALETTE.map(i => [i, score(i, q)]).filter(x => x[1] > 0).sort((a, b) => b[1] - a[1]).slice(0, 12).map(x => x[0]);
        sel = Math.min(sel, Math.max(0, shown.length - 1));
        list.innerHTML = shown.map((i, n) => '<a href="' + i.href.replace(/"/g, '&quot;') + '" class="' + (n === sel ? 'sel' : '') + '"><span></span><span></span><small></small></a>').join('') || '<div class="palette-foot">Nichts gefunden.</div>';
        $$('a', list).forEach((a, n) => { a.children[0].textContent = shown[n].icon; a.children[1].textContent = shown[n].label; a.children[2].textContent = shown[n].hint; });
    }
    const openPal = () => { pal.hidden = false; input.value = ''; sel = 0; render(); input.focus(); };
    const closePal = () => { pal.hidden = true; };
    $('#paletteBtn').addEventListener('click', openPal);
    pal.addEventListener('click', e => { if (e.target === pal) closePal(); });
    input.addEventListener('input', () => { sel = 0; render(); });
    input.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, shown.length - 1); render(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, 0); render(); }
        if (e.key === 'Enter' && shown[sel]) location.href = shown[sel].href;
    });
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal.hidden ? openPal() : closePal(); }
        if (e.key === 'Escape') { closePal(); closeAll(); }
    });
})();
