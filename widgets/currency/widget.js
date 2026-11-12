Widgets.register('currency', function (el) {
    const box = el.querySelector('.cx'); if (!box) return;
    const rates = JSON.parse(box.dataset.rates), amt = box.querySelector('.cx-amt'), from = box.querySelector('.cx-from'), to = box.querySelector('.cx-to'), out = box.querySelector('.cx-out');
    // Kurse sind relativ zur Basiswährung: Betrag → Basis → Ziel
    const calc = () => {
        const v = parseFloat(String(amt.value).replace(',', '.')) || 0;
        const r = v / rates[from.value] * rates[to.value];
        out.textContent = r.toLocaleString('de-DE', { maximumFractionDigits: r >= 100 ? 2 : 4 }) + ' ' + to.value;
    };
    [amt, from, to].forEach(x => x.addEventListener('input', calc));
    box.querySelector('.cx-swap').addEventListener('click', () => { const f = from.value; from.value = to.value; to.value = f; calc(); });
    calc();
});
