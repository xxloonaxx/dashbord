Widgets.register('progress', function (el) {
    const box = el.querySelector('.pg'); if (!box) return;
    const own = box.dataset.own ? JSON.parse(box.dataset.own) : null, work = box.dataset.work ? box.dataset.work.split('-').map(Number) : null;
    const draw = () => {
        const n = new Date(), mins = n.getHours() * 60 + n.getMinutes() + n.getSeconds() / 60;
        const day = work ? Math.min(1, Math.max(0, (mins - work[0]) / (work[1] - work[0]))) : mins / 1440;
        const wd = (n.getDay() + 6) % 7;
        const week = (wd + mins / 1440) / 7;
        const dim = new Date(n.getFullYear(), n.getMonth() + 1, 0).getDate();
        const month = (n.getDate() - 1 + mins / 1440) / dim;
        const y0 = new Date(n.getFullYear(), 0, 1), y1 = new Date(n.getFullYear() + 1, 0, 1);
        const year = (n - y0) / (y1 - y0);
        const rows = [[work ? 'Arbeitstag' : 'Tag', day], ['Woche', week], [n.toLocaleDateString('de-DE', { month: 'long' }), month], ['Jahr ' + n.getFullYear(), year]];
        if (own) rows.push([own[0], Math.min(1, Math.max(0, (n / 1000 - own[1]) / (own[2] - own[1])))]);
        box.innerHTML = rows.map(([l, p]) => '<div class="pg-r"><span>' + l.replace(/</g, '&lt;') + '</span><b>' + (p * 100).toFixed(p < 0.1 ? 1 : 0) + ' %</b></div><div class="pg-b"><i style="width:' + (p * 100).toFixed(2) + '%"></i></div>').join('');
    };
    draw();
    const t = setInterval(draw, 30000);
    return () => clearInterval(t);
});
