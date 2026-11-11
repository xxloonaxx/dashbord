/* Dialoge zum Hinzufügen/Bearbeiten von Links und Kategorien in den Link-Hubs */
(function () {
    const linkModal = document.getElementById('hubLinkModal');
    const catModal = document.getElementById('hubCatModal');
    if (!linkModal || !catModal) return;

    const lf = linkModal.querySelector('form');
    const cf = catModal.querySelector('form');
    const newCatLabel = lf.querySelector('.hub-new-cat');
    // Felder immer über .elements ansprechen (form.title / form.name sind eigene Formular-Eigenschaften!)
    const L = n => lf.elements[n];
    const C = n => cf.elements[n];
    let lastFocus = null;

    function open(modal, focusEl) {
        lastFocus = document.activeElement;
        modal.hidden = false;
        (focusEl || modal.querySelector('input:not([type=hidden])')).focus();
    }
    function close() {
        [linkModal, catModal].forEach(m => m.hidden = true);
        if (lastFocus) lastFocus.focus();
    }

    function toggleNewCat() {
        const isNew = L('category').value === '__new__';
        newCatLabel.hidden = !isNew;
        L('new_category').required = isNew;
        if (isNew) L('new_category').focus();
    }

    function openLink(data) {
        const editing = !!data && data.index !== undefined;
        lf.reset();
        L('hub_action').value = editing ? 'edit_link' : 'add_link';
        L('orig_category').value = editing ? data.category : '';
        L('orig_index').value = editing ? data.index : '';
        linkModal.querySelector('.hub-modal-title').textContent = editing ? '// Link bearbeiten' : '// Link hinzufügen';
        if (data) {
            ['title', 'url', 'desc', 'icon'].forEach(k => { if (data[k] !== undefined) L(k).value = data[k]; });
            if (data.category) L('category').value = data.category;
        }
        // Gibt es noch keine Kategorie, direkt "Neue Kategorie" vorschlagen
        if (L('category').options.length === 1) L('category').value = '__new__';
        lf.querySelectorAll('.hub-icons button').forEach(b => b.classList.toggle('sel', b.textContent === L('icon').value));
        toggleNewCat();
        open(linkModal, L('title'));
    }

    function openCategory(oldName) {
        cf.reset();
        C('hub_action').value = oldName ? 'rename_category' : 'add_category';
        C('old_name').value = oldName || '';
        C('name').value = oldName || '';
        catModal.querySelector('.hub-modal-title').textContent = oldName ? '// Kategorie umbenennen' : '// Neue Kategorie';
        open(catModal);
        C('name').select();
    }

    document.addEventListener('click', e => {
        const t = e.target.closest('[data-hub-open], [data-hub-edit], [data-hub-rename], [data-hub-close]');
        if (!t) {
            if (e.target === linkModal || e.target === catModal) close();
            return;
        }
        e.preventDefault();
        if (t.dataset.hubClose !== undefined) return close();
        if (t.dataset.hubEdit) return openLink(JSON.parse(t.dataset.hubEdit));
        if (t.dataset.hubRename) return openCategory(t.dataset.hubRename);
        if (t.dataset.hubOpen === 'category') return openCategory('');
        openLink(t.dataset.category ? { category: t.dataset.category } : null);
    });

    L('category').addEventListener('change', toggleNewCat);
    lf.querySelector('.hub-icons').addEventListener('click', e => {
        if (e.target.tagName !== 'BUTTON') return;
        L('icon').value = e.target.textContent;
        lf.querySelectorAll('.hub-icons button').forEach(b => b.classList.toggle('sel', b === e.target));
    });

    // Titel aus der Adresse vorschlagen, wenn noch leer (z. B. "protondb.com" -> "Protondb")
    L('url').addEventListener('blur', () => {
        // "example.com" ohne https:// akzeptieren (sonst blockiert der Browser das Speichern)
        const v = L('url').value.trim();
        if (v && !/^[a-z][a-z0-9+.-]*:\/\//i.test(v)) L('url').value = 'https://' + v;
        if (L('title').value.trim() || !L('url').value.trim()) return;
        try {
            const host = new URL(/^\w+:\/\//.test(L('url').value) ? L('url').value : 'https://' + L('url').value).hostname.replace(/^www\./, '');
            const name = host.split('.').slice(-2, -1)[0] || host;
            L('title').value = name.charAt(0).toUpperCase() + name.slice(1);
        } catch (err) { /* ungültige Adresse – Browser meldet das beim Speichern */ }
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && (!linkModal.hidden || !catModal.hidden)) { e.stopImmediatePropagation(); close(); }
        // "n" = neuer Link (außer beim Tippen)
        if (e.key === 'n' && linkModal.hidden && catModal.hidden && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            openLink(null);
        }
    }, true);

    // Hinweis nach dem Speichern nach ein paar Sekunden ausblenden und URL aufräumen
    const flash = document.querySelector('.hub-flash');
    if (flash) {
        history.replaceState(null, '', location.pathname);
        if (flash.classList.contains('ok')) setTimeout(() => flash.remove(), 4000);
    }
})();
