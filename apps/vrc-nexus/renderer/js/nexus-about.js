/* VRC Nexus – ℹ️ Über VRC Nexus (Mehr → Über): Version, System, Changelog der letzten Versionen, Danksagungen */
(function () {
    const UI = window.NexusUI;
    if (!UI) return;
    const esc = UI.esc;
    let n = 2;
    // kleines Markdown: **fett**, `code`, „- “-Listen, Absätze
    function md(src) {
        const lines = String(src || '').split('\n');
        let html = '', list = false;
        const inline = t => esc(t).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`([^`]+)`/g, '<code>$1</code>');
        for (const raw of lines) {
            const l = raw.replace(/\s+$/, '');
            const li = /^\s*[-*] (.*)$/.exec(l);
            if (li) { if (!list) { html += '<ul>'; list = true; } html += '<li>' + inline(li[1]) + '</li>'; continue; }
            if (list && /^\s{2,}\S/.test(l)) { html = html.replace(/<\/li>$/, ' ' + inline(l.trim()) + '</li>'); continue; }
            if (list) { html += '</ul>'; list = false; }
            if (l.trim()) html += '<p>' + inline(l) + '</p>';
        }
        return html + (list ? '</ul>' : '');
    }
    function render(d) {
        const box = document.getElementById('aboutBox');
        if (!box) return;
        const up = d.update;
        box.innerHTML = '<div class="ab-head"><img src="icon.png" alt="" class="ab-logo"><div><h2>VRC Nexus</h2>'
            + '<p class="ab-ver">Version <b>' + esc(d.version) + '</b> · ' + esc(d.platform) + (d.runtime.electron ? ' · Electron ' + esc(d.runtime.electron) + ' · Chromium ' + esc(d.runtime.chrome.split('.')[0]) : '') + '</p>'
            + '<p class="small">' + (up ? (up.available ? '⬆ Version <b>' + esc(up.version) + '</b> ist verfügbar – <button type="button" class="nc-link" data-ab="update">jetzt aktualisieren</button>' : '✔ Du hast die neueste Version.') : '') + ' <button type="button" class="nc-link" data-ab="check">Nach Updates suchen</button></p></div></div>'
            + '<div class="nc-grid ab-grid">'
            + '<div class="nc-card ab-log"><h4>📝 Was ist neu</h4>' + d.changelog.map((c, i) => '<section class="ab-v"><h3><span class="ab-tag' + (i === 0 ? ' now' : '') + '">' + esc(c.version) + (c.version === d.version ? ' · installiert' : '') + '</span> ' + esc(c.title) + '</h3><div class="ab-body fmt" data-no-i18n>' + md(c.body) + '</div></section>').join('')
            + (d.total > d.changelog.length ? '<button type="button" class="btn btn-sm" data-ab="more">Ältere Versionen anzeigen (' + (d.total - d.changelog.length) + ')</button>' : '')
            + ' <a class="nc-link" href="' + esc(d.repo) + '" target="_blank" rel="noopener">Alle Releases auf GitHub ↗</a></div>'
            + '<div class="nc-card"><h4>ℹ️ Über die App</h4><p class="small">VRC Nexus ist ein Begleit-Programm für VRChat: Freunde & Orte, Spiel-Log, OSC-Steuerung, Chatbox, Spracheingabe & -ausgabe, Stream-Bot, Stream-Deck und mehr – Teil des GHOST-NODE-Dashboards.</p>'
            + '<p class="small muted">Nicht offiziell mit VRChat Inc. verbunden. Die App nutzt die inoffizielle VRChat-API sparsam und speichert dein Passwort nie – nur das Sitzungs-Cookie, verschlüsselt.</p>'
            + (d.data ? '<p class="small">📁 Datenordner: <code>' + esc(d.data) + '</code></p>' : '')
            + '<h4 style="margin-top:12px">🙏 Danke an</h4><ul class="small ab-credits">'
            + '<li><b>Electron</b>, <b>sql.js</b> – App-Grundlage & Datenbank</li><li><b>whisper.cpp</b> (ggml-org) – Spracherkennung</li><li><b>Piper</b> (rhasspy) – Sprachausgabe offline</li>'
            + '<li><b>Open-Meteo</b> – Wetterdaten</li><li><b>OpenVR</b> (Valve) – SteamVR-Overlay</li><li><b>DeepL</b>, <b>Google</b>, <b>Azure</b>, <b>ElevenLabs</b>, <b>OpenAI</b>, <b>Pulsoid</b>, <b>decapi.me</b> – optionale Dienste</li>'
            + '<li>Ideen aus der Community: VRCX, MagicChatbox, TTS Voice Wizard – jeweils eigene Umsetzung</li></ul></div>'
            + '</div>';
    }
    function load() { return UI.api('about', { n }).then(render).catch(e => { const b = document.getElementById('aboutBox'); if (b) b.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; }); }
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-ab]');
        if (!b || !b.closest('#aboutBox')) return;
        if (b.dataset.ab === 'more') { n = 50; return load(); }
        if (b.dataset.ab === 'check') return UI.api('update_check').then(d => { UI.toast(d.msg || 'Geprüft.'); load(); }).catch(er => UI.toast('⚠ ' + er.message, true));
        if (b.dataset.ab === 'update') return UI.tab('settings');
    });
    window.NexusAbout = { open() { n = 2; load(); } };
})();
