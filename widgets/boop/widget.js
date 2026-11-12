Widgets.register('boop', function (el) {
    const box = el.querySelector('.bp'); if (!box) return;
    const nose = box.querySelector('.bp-nose'), out = box.querySelector('.bp-n b');
    let total = +box.dataset.total, pending = 0, t = null;
    const flush = () => {
        if (!pending) return;
        const fd = new FormData(); fd.append('key', 'boop'); fd.append('n', pending); pending = 0;
        fetch('furhub.php?api=react', { method: 'POST', body: fd }).then(r => r.json()).then(j => { if (j.stats) { total = j.stats.total.boop; out.textContent = total.toLocaleString('de-DE'); } }).catch(() => {});
    };
    nose.addEventListener('click', () => {
        total++; pending++; out.textContent = total.toLocaleString('de-DE');
        nose.classList.remove('hit'); void nose.offsetWidth; nose.classList.add('hit');
        clearTimeout(t); t = setTimeout(flush, 1000);
    });
});
