/**
 * Werkzeugleiste für alle Textfelder mit data-format="text" (mehrzeilig) oder data-format="inline" (kurz).
 * Fügt Markdown/BBCode ein, zeigt eine Live-Vorschau (über preview.php) und eine Kurzhilfe.
 * Tastenkürzel: Strg+B fett, Strg+I kursiv, Strg+K Link, Strg+U unterstrichen.
 */
(function () {
    const HELP = '<div><b>Markdown</b><code># Überschrift</code><br><code>**fett**</code> <code>*kursiv*</code><br><code>~~weg~~</code> <code>==markiert==</code><br>'
        + '<code>- Punkt</code> <code>1. Nummer</code><br><code>- [ ] Aufgabe</code> <code>&gt; Zitat</code><br><code>[Text](https://…)</code><br><code>![Bild](https://…)</code><br>'
        + '<code>`Code`</code> <code>```</code>Block<code>```</code><br><code>| A | B |</code> Tabelle<br><code>||Spoiler||</code> <code>:heart:</code> <code>---</code></div>'
        + '<div><b>BBCode</b><code>[b]</code> <code>[i]</code> <code>[u]</code> <code>[s]</code><br><code>[color=red]…[/color]</code><br><code>[size=5]…[/size]</code><br>'
        + '<code>[url=https://…]Text[/url]</code><br><code>[img]https://…[/img]</code><br><code>[quote=Name]…[/quote]</code><br><code>[spoiler=Titel]…[/spoiler]</code><br>'
        + '<code>[list][*]a[*]b[/list]</code><br><code>[center]</code> <code>[code]</code> <code>[youtube]</code></div>'
        + '<div><b>HTML</b><code>&lt;b&gt;</code> <code>&lt;i&gt;</code> <code>&lt;u&gt;</code> <code>&lt;mark&gt;</code><br><code>&lt;span style="color:#f3c262"&gt;</code><br>'
        + '<code>&lt;a href="…"&gt;</code> <code>&lt;img src="…"&gt;</code><br><code>&lt;table&gt;&lt;tr&gt;&lt;td&gt;</code><br><code>&lt;details&gt;&lt;summary&gt;</code><br>'
        + '<code>&lt;kbd&gt;</code> <code>&lt;sup&gt;</code> <code>&lt;sub&gt;</code><br>Skripte &amp; Event-Handler werden automatisch entfernt.</div>';

    const TOOLS = [
        ['b', '<b>B</b>', 'Fett (Strg+B)', ['**', '**']],
        ['i', '<i>I</i>', 'Kursiv (Strg+I)', ['*', '*']],
        ['u', '<u>U</u>', 'Unterstrichen (Strg+U)', ['[u]', '[/u]']],
        ['s', '<s>S</s>', 'Durchgestrichen', ['~~', '~~']],
        ['mark', '🖍', 'Markieren', ['==', '==']],
        ['color', '🎨', 'Textfarbe'],
        ['sep'],
        ['h', 'H', 'Überschrift', 'line:## ', true],
        ['ul', '•', 'Liste', 'line:- ', true],
        ['ol', '1.', 'Nummerierte Liste', 'line:1. ', true],
        ['task', '☑', 'Aufgabenliste', 'line:- [ ] ', true],
        ['quote', '❝', 'Zitat', 'line:> ', true],
        ['sep'],
        ['link', '🔗', 'Link (Strg+K)'],
        ['img', '🖼', 'Bild', null],
        ['code', '&lt;/&gt;', 'Code'],
        ['spoiler', '▒', 'Spoiler', ['||', '||']],
        ['table', '▦', 'Tabelle', null, true],
        ['hr', '―', 'Trennlinie', null, true],
    ];

    function wrap(ta, before, after, placeholder) {
        const s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
        const sel = v.slice(s, e) || placeholder || '';
        ta.value = v.slice(0, s) + before + sel + after + v.slice(e);
        ta.focus();
        ta.setSelectionRange(s + before.length, s + before.length + sel.length);
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function insert(ta, text) {
        const s = ta.selectionStart, v = ta.value;
        ta.value = v.slice(0, s) + text + v.slice(ta.selectionEnd);
        ta.focus();
        ta.setSelectionRange(s + text.length, s + text.length);
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function prefixLines(ta, prefix) {
        const v = ta.value;
        let s = v.lastIndexOf('\n', ta.selectionStart - 1) + 1;
        let e = v.indexOf('\n', ta.selectionEnd);
        if (e < 0) e = v.length;
        let n = 0;
        const block = v.slice(s, e).split('\n').map(l => {
            if (prefix === '1. ') return (++n) + '. ' + l.replace(/^\d+\.\s/, '');
            return l.startsWith(prefix) ? l.slice(prefix.length) : prefix + l;
        }).join('\n');
        ta.value = v.slice(0, s) + block + v.slice(e);
        ta.focus();
        ta.setSelectionRange(s, s + block.length);
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function action(ta, key, multi) {
        const t = TOOLS.find(x => x[0] === key);
        if (!t) return;
        if (Array.isArray(t[3])) return wrap(ta, t[3][0], t[3][1], 'Text');
        if (typeof t[3] === 'string' && t[3].startsWith('line:')) return prefixLines(ta, t[3].slice(5));
        const sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
        if (key === 'link') {
            const url = prompt('Adresse des Links:', /^https?:\/\//.test(sel) ? sel : 'https://');
            if (!url) return;
            return wrap(ta, '[', '](' + url + ')', /^https?:\/\//.test(sel) ? 'Linktext' : (sel || 'Linktext'));
        }
        if (key === 'img') {
            const url = prompt('Adresse des Bildes (https://…):', 'https://');
            if (!url || url === 'https://') return;
            return insert(ta, '![' + (sel || 'Bild') + '](' + url + ')');
        }
        if (key === 'code') return sel.includes('\n') || (!sel && multi) ? wrap(ta, '\n```\n', '\n```\n', 'Code') : wrap(ta, '`', '`', 'Code');
        if (key === 'table') return insert(ta, '\n| Spalte 1 | Spalte 2 |\n|---|---|\n| Inhalt | Inhalt |\n');
        if (key === 'hr') return insert(ta, '\n\n---\n\n');
    }

    function attach(ta) {
        if (ta.dataset.feReady) return;
        ta.dataset.feReady = '1';
        const multi = ta.dataset.format !== 'inline';
        const box = document.createElement('div');
        box.className = 'fe' + (multi ? '' : ' fe-inline');
        const bar = document.createElement('div');
        bar.className = 'fe-bar';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', 'Formatierung');
        TOOLS.forEach(t => {
            if (!multi && (t[4] || t[0] === 'img')) return;
            if (t[0] === 'sep') { if (multi) bar.insertAdjacentHTML('beforeend', '<span class="fe-sep"></span>'); return; }
            if (t[0] === 'color') {
                const l = document.createElement('span');
                l.className = 'fe-color';
                l.title = t[2];
                l.innerHTML = t[1] + '<input type="color" value="#f3c262" tabindex="-1">';
                l.querySelector('input').addEventListener('change', e => wrap(ta, '[color=' + e.target.value + ']', '[/color]', 'Text'));
                bar.append(l);
                return;
            }
            const b = document.createElement('button');
            b.type = 'button'; b.innerHTML = t[1]; b.title = t[2]; b.setAttribute('aria-label', t[2]);
            b.addEventListener('click', () => action(ta, t[0], multi));
            bar.append(b);
        });
        const right = document.createElement('span');
        right.className = 'fe-right';
        const pv = document.createElement('button');
        pv.type = 'button'; pv.innerHTML = '👁'; pv.title = 'Vorschau'; pv.setAttribute('aria-label', 'Vorschau');
        const hb = document.createElement('button');
        hb.type = 'button'; hb.textContent = '?'; hb.title = 'Formatierungshilfe'; hb.setAttribute('aria-label', 'Formatierungshilfe');
        right.append(pv, hb);
        bar.append(right);

        const preview = document.createElement('div');
        preview.className = 'fe-preview fmt' + (multi ? '' : ' fmt-inline');
        preview.hidden = true;
        const help = document.createElement('div');
        help.className = 'fe-help';
        help.innerHTML = HELP;
        help.hidden = true;

        ta.parentNode.insertBefore(box, ta);
        box.append(bar, ta, preview);
        box.insertBefore(help, ta);

        let timer = null;
        const render = () => {
            const body = new URLSearchParams({ text: ta.value, mode: multi ? 'text' : 'inline' });
            fetch('preview.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
                .then(r => r.ok ? r.text() : Promise.reject(r.status))
                .then(html => { preview.innerHTML = html; })
                .catch(() => { preview.textContent = 'Vorschau nicht verfügbar (bitte angemeldet bleiben).'; });
        };
        pv.addEventListener('click', () => {
            const on = preview.hidden;
            preview.hidden = !on;
            ta.hidden = on;
            pv.classList.toggle('on', on);
            if (on) render(); else ta.focus();
        });
        hb.addEventListener('click', () => { help.hidden = !help.hidden; hb.classList.toggle('on', !help.hidden); });
        ta.addEventListener('input', () => { if (!preview.hidden) { clearTimeout(timer); timer = setTimeout(render, 300); } });
        ta.addEventListener('keydown', e => {
            if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
            const k = { b: 'b', i: 'i', u: 'u', k: 'link' }[e.key.toLowerCase()];
            if (k) { e.preventDefault(); action(ta, k, multi); }
        });
        // Beim Öffnen eines Dialogs wieder in den Bearbeiten-Modus
        ta.feReset = () => { preview.hidden = true; ta.hidden = false; pv.classList.remove('on'); };
    }

    const scan = root => (root || document).querySelectorAll('textarea[data-format], input[data-format]').forEach(attach);
    window.FormatEditor = { scan, attach };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan()); else scan();
    // Spoiler per Klick/Tipp umschalten (für Touch-Geräte)
    document.addEventListener('click', e => { const s = e.target.closest('.fmt-spoiler'); if (s) { e.preventDefault(); s.classList.toggle('open'); } });
})();
