Widgets.register('random', function (el) {
    const box = el.querySelector('.rn'); if (!box) return;
    const out = box.querySelector('.rn-out'), sub = box.querySelector('.rn-sub');
    const rnd = n => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };
    const show = (v, s) => { out.classList.remove('pop'); void out.offsetWidth; out.classList.add('pop'); out.textContent = v; sub.textContent = s; };
    box.addEventListener('click', e => {
        const d = e.target.closest('[data-dice]');
        if (d) show(rnd(+d.dataset.dice) + 1, 'W' + d.dataset.dice);
        if (e.target.closest('[data-coin]')) show(rnd(2) ? 'Kopf' : 'Zahl', '🪙 Münzwurf');
        if (e.target.closest('[data-pick]')) { const c = JSON.parse(box.dataset.choices || '[]'); if (c.length) show(c[rnd(c.length)], 'aus ' + c.length + ' Optionen'); }
    });
    box.querySelector('.rn-range').addEventListener('submit', e => {
        e.preventDefault();
        let a = Math.round(+e.target.a.value), b = Math.round(+e.target.b.value);
        if (a > b) [a, b] = [b, a];
        show(a + rnd(b - a + 1), a + ' bis ' + b);
    });
});
