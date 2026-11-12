Widgets.register('countdowns', function (el) {
    const tick = () => el.querySelectorAll('.cd-left').forEach(s => {
        let d = Math.max(0, Math.floor(+s.dataset.ts - Date.now() / 1000));
        const days = Math.floor(d / 86400); d %= 86400;
        const p = n => String(n).padStart(2, '0');
        s.textContent = days > 0 ? days + ' T ' + p(Math.floor(d / 3600)) + ':' + p(Math.floor(d % 3600 / 60)) : p(Math.floor(d / 3600)) + ':' + p(Math.floor(d % 3600 / 60)) + ':' + p(d % 60);
    });
    tick();
    const t = setInterval(tick, 1000);
    return () => clearInterval(t);
});
