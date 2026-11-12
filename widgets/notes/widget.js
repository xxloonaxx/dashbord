Widgets.register('notes', function (el) {
    const f = el.querySelector('.nt-form'), b = el.querySelector('.nt-edit'), t = el.querySelector('.nt-text');
    if (!f || !b) return;
    b.addEventListener('click', () => { f.hidden = false; b.hidden = true; if (t) t.hidden = true; f.querySelector('textarea').focus(); });
    f.querySelector('.nt-cancel').addEventListener('click', () => { f.hidden = true; b.hidden = false; if (t) t.hidden = false; });
});
