Widgets.register('photo', function (el) {
    const box = el.querySelector('.ph'); if (!box) return;
    const imgs = JSON.parse(box.dataset.imgs || '[]'), [a, b] = box.querySelectorAll('img'), n = box.querySelector('.ph-n');
    [a, b].forEach(i => { i.style.objectFit = box.dataset.fit; });
    let i = 0, front = a;
    const show = k => {
        i = (k + imgs.length) % imgs.length;
        const back = front === a ? b : a;
        back.onload = () => { back.classList.add('on'); front.classList.remove('on'); front = back; };
        back.onerror = () => { setTimeout(() => show(i + 1), 200); };
        back.src = imgs[i];
        n.textContent = (i + 1) + ' / ' + imgs.length;
    };
    show(0);
    let t = setInterval(() => show(i + 1), +box.dataset.sec * 1000);
    const restart = () => { clearInterval(t); t = setInterval(() => show(i + 1), +box.dataset.sec * 1000); };
    box.querySelector('.ph-prev').addEventListener('click', () => { show(i - 1); restart(); });
    box.querySelector('.ph-next').addEventListener('click', () => { show(i + 1); restart(); });
    box.addEventListener('dblclick', () => window.open(imgs[i], '_blank', 'noopener'));
    return () => clearInterval(t);
});
