Widgets.register('pomodoro', function (el, w) {
    const box = el.querySelector('.pd'); if (!box) return;
    const KEY = 'wg-pomo-' + w.uid, DAY = new Date().toISOString().slice(0, 10);
    const dur = { work: +box.dataset.work * 60, short: +box.dataset.short * 60, long: +box.dataset.long * 60 };
    const NAMES = { work: '🍅 Arbeit', short: '☕ Kurze Pause', long: '🌴 Lange Pause' };
    let s; try { s = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { s = {}; }
    s = Object.assign({ phase: 'work', end: 0, left: dur.work, running: false, done: 0, day: DAY }, s);
    if (s.day !== DAY) { s.done = 0; s.day = DAY; }
    const save = () => { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} };
    const fg = box.querySelector('.fg'), C = 2 * Math.PI * 44;
    fg.style.strokeDasharray = C;
    const beep = () => {
        if (box.dataset.sound !== '1') return;
        try { const a = new (window.AudioContext || window.webkitAudioContext)(); [0, 0.25, 0.5].forEach(t => { const o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; o.connect(g); g.connect(a.destination); g.gain.setValueAtTime(0.15, a.currentTime + t); g.gain.exponentialRampToValueAtTime(0.001, a.currentTime + t + 0.2); o.start(a.currentTime + t); o.stop(a.currentTime + t + 0.2); }); } catch (e) {}
    };
    const next = () => {
        if (s.phase === 'work') { s.done++; s.phase = s.done % 4 === 0 ? 'long' : 'short'; } else s.phase = 'work';
        s.left = dur[s.phase]; s.running = false; s.end = 0; save();
    };
    const draw = () => {
        let left = s.running ? Math.max(0, Math.round((s.end - Date.now()) / 1000)) : s.left;
        if (s.running && left <= 0) {
            beep();
            if ('Notification' in window && Notification.permission === 'granted') try { new Notification('Fokus-Timer', { body: s.phase === 'work' ? 'Zeit für eine Pause! ☕' : 'Weiter geht\'s! 🍅' }); } catch (e) {}
            next(); left = s.left;
        }
        box.querySelector('.pd-time').textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
        box.querySelector('.pd-phase').textContent = NAMES[s.phase];
        box.querySelector('[data-pd=start]').textContent = s.running ? '⏸ Pause' : '▶ Start';
        box.classList.toggle('brk', s.phase !== 'work');
        fg.style.strokeDashoffset = C * (left / dur[s.phase]);
        box.querySelector('.pd-count').textContent = s.done ? 'Heute: ' + '🍅'.repeat(Math.min(s.done, 12)) + (s.done > 12 ? ' +' + (s.done - 12) : '') : 'Heute noch keine Runde';
    };
    box.addEventListener('click', e => {
        const b = e.target.closest('[data-pd]'); if (!b) return;
        if (b.dataset.pd === 'start') {
            if (s.running) { s.left = Math.max(0, Math.round((s.end - Date.now()) / 1000)); s.running = false; }
            else { s.end = Date.now() + s.left * 1000; s.running = true; if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission(); }
        }
        if (b.dataset.pd === 'skip') next();
        if (b.dataset.pd === 'reset') { s.phase = 'work'; s.left = dur.work; s.running = false; }
        save(); draw();
    });
    draw();
    const t = setInterval(draw, 1000);
    return () => clearInterval(t);
});
