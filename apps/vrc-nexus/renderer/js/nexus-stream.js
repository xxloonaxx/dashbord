/* VRC Nexus – 📺 Stream-Bot (Twitch + YouTube): Live-Chat, Punkte, Befehle, Belohnungen (lösen VRChat-Makros aus), Verbindung. Nur Windows-App. */
(function () {
    const UI = window.NexusUI;
    const box = document.getElementById('streamBox');
    if (!UI || !box) return;
    const esc = UI.esc;
    const api = (a, d) => UI.api(a, d);
    const toast = (m, bad) => UI.toast(m, bad);
    const fail = e => toast('⚠ ' + e.message, true);
    let view = UI.store.get('streamView', 'chat'), st = null, seq = 0, feed = [], timer = null, plQ = '';
    const int0 = v => Number(v) || 0;
    const CMD_TPL = [
        { name: 'ohren', perm: 'all', cost: 25, cooldown: 10, user_cooldown: 30, script: 'toggle Ears', response: '🐾 {user} hat die Ohren umgeschaltet!' },
        { name: 'farbe', perm: 'all', cost: 20, cooldown: 5, user_cooldown: 30, script: 'param Color = {1}', response: '🎨 {user} hat die Farbe auf {1} gestellt!', usage: '<0–7>', aliases: 'color' },
        { name: 'outfit', perm: 'sub', cost: 0, cooldown: 30, script: 'param Outfit = {1}', response: '👗 Outfit {1} – gewählt von {user}', usage: '<Nummer>' },
        { name: 'tanz', perm: 'all', cost: 100, cooldown: 60, script: 'param Dance = 1\nwait 15\nparam Dance = 0', response: '💃 {user} lässt tanzen!' },
        { name: 'foto', perm: 'vip', cost: 0, cooldown: 30, script: 'cam Capture', response: '📸 Klick! (von {user})' },
        { name: 'springen', perm: 'all', cost: 10, cooldown: 10, script: 'input Jump', response: '' },
        { name: 'sag', perm: 'sub', cost: 30, cooldown: 20, script: 'chatbox 💬 {user}: {args}', response: '', usage: '<Text>' },
        { name: 'lurk', perm: 'all', cost: 0, cooldown: 0, script: '', response: '👀 {user} lurkt jetzt – viel Spaß!' },
        { name: 'hydrate', perm: 'all', cost: 0, cooldown: 600, script: 'chatbox 💧 Trinkpause! (von {user})', response: '💧 Danke {user}, wird gemacht!' },
    ];
    const ICON = { twitch: '🟣', youtube: '🔴' };
    const STATE = s => /verbunden/.test(s) && !/getrennt/.test(s) ? 'ok' : /fehler/.test(s) ? 'bad' : '';

    function shell() {
        box.innerHTML = '<div class="nx-row rc-bar"><span class="chips">' + [['chat', '💬 Live-Chat'], ['points', '🏆 Punkte'], ['cmds', '⌨️ Befehle'], ['rewards', '🎁 Belohnungen'], ['events', '⭐ Ereignisse'], ['timers', '⏰ Timer'], ['extras', '🎲 Spiele & Extras'], ['setup', '🔌 Verbindung']]
            .map(v => '<button type="button" class="chip' + (view === v[0] ? ' on' : '') + '" data-sv="' + v[0] + '">' + v[1] + '</button>').join('') + '</span><span id="stState" class="small"></span></div><div id="stBody"></div>';
    }
    function stateHtml() {
        if (!st) return '';
        const c = st.cfg;
        return (c.twitch.on ? '🟣 Twitch: <span class="' + STATE(st.twitch.state) + '">' + esc(st.twitch.state) + '</span>' + (st.twitch.error ? ' (' + esc(st.twitch.error) + ')' : '') : '🟣 Twitch aus')
            + ' · ' + (c.youtube.on ? '🔴 YouTube: <span class="' + STATE(st.youtube.state) + '">' + esc(st.youtube.state) + '</span>' + (st.youtube.error ? ' (' + esc(st.youtube.error) + ')' : '') : '🔴 YouTube aus');
    }
    const line = e => '<div class="sb-m k-' + e.kind + ' p-' + e.platform + '"><span class="muted small">' + new Date(e.t).toLocaleTimeString(nxLoc(), { hour: '2-digit', minute: '2-digit' }) + '</span> '
        + (e.kind === 'reward' ? '🎁 ' : e.kind === 'alert' ? '⭐ ' : e.kind === 'system' ? 'ℹ️ ' : ICON[e.platform] + ' ') + '<b' + (e.color ? ' style="color:' + esc(e.color) + '"' : '') + '>' + esc(e.user) + (e.mod ? ' 🛡' : '') + '</b> ' + esc(e.text) + '</div>';

    function render() {
        $('#stState').innerHTML = stateHtml();
        const b = $('#stBody'), c = st.cfg;
        if (view === 'chat') {
            if (!b.querySelector('#sbFeed')) {
                b.innerHTML = '<div class="nc-grid sb-grid"><div class="nc-card"><div id="sbFeed" class="sb-feed"></div>'
                    + '<form id="sbSend" class="nx-row"><select name="platform"><option value="twitch">🟣 Twitch</option><option value="youtube">🔴 YouTube (nur Overlay)</option></select><input type="text" name="text" maxlength="480" placeholder="Nachricht in den Chat" style="flex:1"><button class="btn btn-sm btn-gold">Senden</button></form>'
                    + '<details class="small"><summary>🧪 Testnachricht (als Zuschauer)</summary><form id="sbTest" class="nx-row"><input type="text" name="user" value="Testzuschauer" style="width:140px"><input type="text" name="text" placeholder="z. B. !punkte oder !winken" style="flex:1"><label class="check small"><input type="checkbox" name="mod"> Mod</label><button class="btn btn-sm">Senden</button></form></details></div>'
                    + '<div class="nc-card"><h4>🏆 Top ' + esc(c.points.name) + '</h4><div id="sbTop"></div><p class="muted small" style="margin-top:10px">' + st.users + ' Zuschauer bekannt</p>'
                    + '<h4 style="margin-top:12px">Befehle für Zuschauer</h4><p class="small">' + ['punkte', 'top', 'geben', 'welt', 'spieler', 'song', 'befehle', 'belohnungen'].concat(c.commands.map(x => x.name)).map(x => '<code>' + esc(c.prefix + x) + '</code>').join(' ') + '</p>'
                    + (c.rewards.length ? '<h4>Belohnungen</h4><p class="small">' + c.rewards.map(r => '<code>' + esc(c.prefix + r.name) + '</code> ' + r.cost).join(' · ') + '</p>' : '') + '</div></div>';
            }
            const f = $('#sbFeed'), atEnd = f.scrollTop + f.clientHeight >= f.scrollHeight - 30;
            f.innerHTML = feed.length ? feed.map(line).join('') : '<div class="muted small">Noch keine Nachrichten. Verbinde Twitch/YouTube unter „Verbindung“ – oder schick unten eine Testnachricht.</div>';
            if (atEnd) f.scrollTop = f.scrollHeight;
            $('#sbTop').innerHTML = (st.top || []).map((u, i) => '<div class="rc-row">' + (i + 1) + '. ' + ICON[u.platform] + ' ' + esc(u.name) + '<span>' + u.p.toLocaleString(nxLoc()) + '</span></div>').join('') || '<span class="muted small">–</span>';
        }
        if (view === 'points') {
            b.innerHTML = '<div class="nx-row"><input type="search" id="sbPQ" placeholder="🔎 Zuschauer suchen" value="' + esc(plQ) + '"></div><div id="sbPL"><div class="vx-loading">…</div></div>';
            loadPoints();
        }
        if (view === 'cmds') {
            const PERM = { all: 'alle', sub: 'Abonnenten/Mitglieder', vip: 'VIPs', mod: 'Mods', owner: 'nur ich' };
            b.innerHTML = '<div class="nc-grid"><div class="nc-card"><h4>⌨️ Eigene Befehle</h4>' + (c.commands.length ? c.commands.map(x => '<div class="nc-mrow' + (x.on === false ? ' off' : '') + '"><code>' + esc(c.prefix + x.name) + '</code>'
                + '<span class="small" style="flex:1">' + (x.script ? '⚡ ' : '') + esc(x.response || (x.script || '').split('\n')[0]) + '</span><span class="muted small">' + esc(PERM[x.perm || (x.mod ? 'mod' : 'all')]) + (x.cost ? ' · ' + x.cost + ' ' + esc(c.points.name) : '') + ' · ' + int0(x.uses) + '×</span>'
                + '<button type="button" class="nc-link" data-cedit="' + esc(x.name) + '">bearbeiten</button><button type="button" class="nc-x" data-cdel="' + esc(x.name) + '">✕</button></div>').join('') : '<p class="muted small">Noch keine.</p>')
                + '<h4 style="margin-top:12px">✨ Vorlagen</h4><div class="hm-btns">' + CMD_TPL.map((t, k) => '<button type="button" class="chip" data-ctpl="' + k + '">' + esc(c.prefix + t.name) + '</button>').join('') + '</div>'
                + '<p class="muted small" style="margin-top:8px">Eingebaut: ' + ['punkte', 'top', 'geben', 'zuschauzeit', 'uptime', 'welt', 'spieler', 'song', 'befehle', 'belohnungen', 'zitat', 'gamble', 'münze', 'slots', 'duell', 'tts', 'so (Mods)', 'umfrage (Mods)', 'verlosung (Mods)', 'addpunkte (Mods)'].map(x => '<code>' + esc(c.prefix + x) + '</code>').join(' ') + '</p></div>'
                + '<form class="nc-card" id="sbCmd"><h4>🧱 Befehls-Baukasten</h4><input type="hidden" name="old">'
                + '<div class="nx-row"><span>' + esc(c.prefix) + '</span><input type="text" name="name" placeholder="ohren" required style="width:130px"><input type="text" name="aliases" placeholder="Aliase, z. B. ears, öhrchen" style="flex:1"><label class="check small"><input type="checkbox" name="on" checked> aktiv</label></div>'
                + '<div class="nx-row"><label class="small">Wer darf <select name="perm">' + Object.entries(PERM).map(([k, v]) => '<option value="' + k + '">' + v + '</option>').join('') + '</select></label>'
                + '<label class="small">Kosten <input type="number" name="cost" value="0" min="0" style="width:70px"></label><label class="small">Pause <input type="number" name="cooldown" value="10" min="0" style="width:60px"> s</label>'
                + '<label class="small">pro Person <input type="number" name="user_cooldown" value="0" min="0" style="width:60px"> s</label></div>'
                + '<label class="small">⚡ Aktion in VRChat (Makro-Schritte, optional):</label><textarea name="script" rows="5" class="mono" placeholder="param Ears = 1&#10;wait 3&#10;param Ears = 0" style="width:100%"></textarea>'
                + '<div class="hm-btns"><span class="small">einfügen:</span>' + [['🔘 Parameter an/aus', 'toggle Ears'], ['🎚 Parameter = Wert', 'param Color = {1}'], ['⏱ warten', 'wait 3'], ['💬 Chatbox', 'chatbox {user}: {args}'], ['📸 Foto', 'cam Capture'], ['🎮 Springen', 'input Jump'], ['🔊 Vorlesen', 'speak {user} sagt {args}'], ['🌐 Webhook', 'webhook https://…']]
                    .map(([l, v]) => '<button type="button" class="chip" data-cins="' + esc(v) + '">' + l + '</button>').join('') + '</div>'
                + '<input type="text" name="response" placeholder="Antwort im Chat, z. B. 🎨 {user} hat die Farbe auf {1} gestellt!" style="width:100%;margin-top:6px">'
                + '<input type="text" name="usage" placeholder="Hilfe, wenn ein Wert fehlt, z. B. <0–7>" style="width:100%;margin-top:6px">'
                + '<p class="muted small">Platzhalter: {user} {1} {2} … (Wörter nach dem Befehl) {args} (alles danach) {punkte} {punkte_name} {welt} {spieler} {avatar} {musik} {zeit} {plattform}. Übergaben werden bereinigt (keine Zeilenumbrüche). Kostet der Befehl Punkte und die Aktion schlägt fehl, gibt es die Punkte zurück.</p>'
                + '<div class="nx-row"><button class="btn btn-sm btn-gold">Speichern</button><button type="button" class="btn btn-sm" id="sbCmdTry">🧪 als Testzuschauer ausprobieren</button></div></form></div>';
        }
        if (view === 'events') {
            const EV = [['sub', '🟣 Neues Abo (Twitch)'], ['resub', '🟣 Abo-Verlängerung ({monate})'], ['subgift', '🎁 Geschenk-Abos ({menge})'], ['raid', '🚀 Raid ({menge} Leute)'], ['bits', '💎 Bits ({menge})'],
                ['superchat', '🔴 Super Chat (YouTube, {betrag})'], ['member', '🔴 Neues Mitglied (YouTube)'], ['first', '🥇 Erster im Chat (Bonus)']];
            b.innerHTML = '<p class="muted small">Jedes Ereignis kann im Chat antworten und eine <b>VRChat-Aktion</b> auslösen (z. B. bei einem Raid den „Party“-Parameter an, bei Bits Konfetti …).</p><div class="sb-evgrid">' + EV.map(([k, l]) => { const e = c.events[k]; return '<form class="nc-card sb-ev" data-ev="' + k + '"><h4>' + l + '</h4>'
                + '<label class="check small"><input type="checkbox" name="on"' + (e.on ? ' checked' : '') + '> aktiv</label>'
                + (e.min !== undefined ? ' <label class="small">ab <input type="number" name="min" value="' + e.min + '" min="0" style="width:70px"></label>' : '') + (e.bonus !== undefined ? ' <label class="small">Bonus <input type="number" name="bonus" value="' + e.bonus + '" min="0" style="width:70px"></label>' : '')
                + '<input type="text" name="response" value="' + esc(e.response) + '" placeholder="Antwort im Chat" style="width:100%">'
                + '<textarea name="script" rows="3" class="mono" placeholder="Aktion, z. B. param Party = 1&#10;wait 10&#10;param Party = 0" style="width:100%">' + esc(e.script) + '</textarea>'
                + '<div class="nx-row"><button class="btn btn-sm btn-gold">Speichern</button><button type="button" class="btn btn-sm" data-evtest="' + k + '">🧪 Test</button></div></form>'; }).join('') + '</div>';
        }
        if (view === 'timers') {
            b.innerHTML = '<div class="nc-grid"><div class="nc-card"><h4>⏰ Timer-Nachrichten</h4><p class="muted small">Werden regelmäßig in den Chat geschrieben – aber nur, wenn seitdem genug geschrieben wurde (kein Spam in leeren Chats).</p>'
                + (c.timers.length ? c.timers.map(t => '<div class="nc-mrow' + (t.on ? '' : ' off') + '"><span class="small" style="flex:1">' + esc(t.text) + '</span><span class="muted small">alle ' + t.every + ' min · ab ' + t.min_msgs + ' Nachr.</span><button type="button" class="nc-link" data-tedit="' + esc(t.id) + '">bearbeiten</button><button type="button" class="nc-x" data-tdel="' + esc(t.id) + '">✕</button></div>').join('') : '<p class="muted small">Noch keine.</p>') + '</div>'
                + '<form class="nc-card" id="sbTimer"><h4>✏️ Timer</h4><input type="hidden" name="id"><textarea name="text" rows="3" placeholder="z. B. 💜 Folge gern dem Kanal! · Befehle: !befehle" style="width:100%" required></textarea>'
                + '<div class="nx-row"><label class="small">alle <input type="number" name="every" value="20" min="1" style="width:60px"> min</label><label class="small">ab <input type="number" name="min_msgs" value="5" min="0" style="width:60px"> Nachrichten</label><label class="check small"><input type="checkbox" name="on" checked> aktiv</label></div>'
                + '<button class="btn btn-sm btn-gold">Speichern</button></form></div>';
        }
        if (view === 'extras') {
            const g = c.games, t = c.tts, num = (n, v, w) => '<input type="number" name="' + n + '" value="' + v + '" min="0" style="width:' + (w || 70) + 'px">';
            const chk = (n, v) => '<input type="checkbox" name="' + n + '"' + (v ? ' checked' : '') + '>';
            const poll = st.poll, ga = st.giveaway;
            b.innerHTML = '<div class="nc-grid">'
                + '<form class="nc-card" id="sbGames"><h4>🎲 Spiele mit ' + esc(c.points.name) + '</h4>'
                + '<div class="nx-row small"><label class="check">' + chk('gamble_on', g.gamble.on) + ' <code>' + esc(c.prefix) + 'gamble</code></label> Einsatz ' + num('gamble_min', g.gamble.min, 60) + '–' + num('gamble_max', g.gamble.max) + ' Pause ' + num('gamble_cooldown', g.gamble.cooldown, 60) + ' s</div>'
                + '<div class="nx-row small"><label class="check">' + chk('coinflip_on', g.coinflip.on) + ' <code>' + esc(c.prefix) + 'münze</code></label> Einsatz ' + num('coinflip_min', g.coinflip.min, 60) + '–' + num('coinflip_max', g.coinflip.max) + ' Pause ' + num('coinflip_cooldown', g.coinflip.cooldown, 60) + ' s</div>'
                + '<div class="nx-row small"><label class="check">' + chk('slots_on', g.slots.on) + ' <code>' + esc(c.prefix) + 'slots</code></label> kostet ' + num('slots_cost', g.slots.cost, 60) + ' Pause ' + num('slots_cooldown', g.slots.cooldown, 60) + ' s</div>'
                + '<div class="nx-row small"><label class="check">' + chk('duel_on', g.duel.on) + ' <code>' + esc(c.prefix) + 'duell</code></label> max. ' + num('duel_max', g.duel.max) + ' Pause ' + num('duel_cooldown', g.duel.cooldown, 60) + ' s</div>'
                + '<div class="nx-row small"><label class="check">' + chk('tts_on', t.on) + ' <code>' + esc(c.prefix) + 'tts</code> Vorlesen am PC</label> kostet ' + num('tts_cost', t.cost, 60) + ' max. ' + num('tts_max', t.max, 60) + ' Zeichen</div>'
                + '<button class="btn btn-sm btn-gold">Speichern</button></form>'
                + '<div class="nc-card"><h4>🔢 Zähler</h4>' + c.counters.map(x => '<div class="nc-mrow"><code>' + esc(c.prefix + x.name) + '</code><b style="font-size:1.2rem">' + int0(x.value) + '</b><span class="small muted" style="flex:1">' + esc(x.text) + '</span>'
                    + '<button type="button" class="chip" data-cstep="' + esc(x.name) + '" data-n="1">+1</button><button type="button" class="chip" data-cstep="' + esc(x.name) + '" data-n="-1">−1</button><button type="button" class="nc-x" data-ctdel="' + esc(x.name) + '">✕</button></div>').join('')
                + '<form class="nx-row" id="sbCounter"><input type="text" name="name" placeholder="z. B. tode" style="width:100px" required><input type="text" name="text" placeholder="💀 Bisher {wert} Tode" style="flex:1"><button class="btn btn-sm">＋</button></form>'
                + '<p class="muted small">Im Chat: ' + esc(c.prefix) + 'name zeigt den Stand · Mods: ' + esc(c.prefix) + 'name + / - / Zahl / reset</p></div>'
                + '<div class="nc-card"><h4>📊 Umfrage</h4>' + (poll ? '<b>' + esc(poll.q) + '</b>' + poll.options.map(o => '<div class="nx-row small"><span style="flex:1">' + esc(o.text) + '</span><div class="sp-meter" style="flex:2"><i style="width:' + (poll.total ? Math.round(o.votes / poll.total * 100) : 0) + '%"></i></div><b>' + o.votes + '</b></div>').join('')
                    + '<button type="button" class="btn btn-sm btn-gold" data-poll="end">Beenden &amp; Ergebnis posten</button>'
                    : '<form id="sbPoll"><input type="text" name="q" placeholder="Frage" style="width:100%" required><textarea name="options" rows="3" placeholder="Antworten – eine pro Zeile" style="width:100%" required></textarea><button class="btn btn-sm btn-gold">Starten</button></form>')
                + '<p class="muted small">Auch im OBS-Chat-Overlay sichtbar. Im Chat: ' + esc(c.prefix) + 'stimme 1</p></div>'
                + '<div class="nc-card"><h4>🎁 Verlosung</h4>' + (ga ? '<p>Stichwort: <code>' + esc(ga.keyword) + '</code>' + (ga.cost ? ' · kostet ' + ga.cost : '') + ' · <b>' + ga.entries.length + '</b> Teilnehmer</p><p class="small">' + ga.entries.map(esc).join(', ') + '</p>'
                    + '<button type="button" class="btn btn-sm btn-gold" data-ga="draw">🎉 Gewinner ziehen</button> <button type="button" class="btn btn-sm" data-ga="stop">Beenden</button>'
                    : '<form class="nx-row" id="sbGa"><input type="text" name="keyword" value="' + esc(c.prefix) + 'mitmachen" style="width:140px"><label class="small">kostet <input type="number" name="cost" value="0" min="0" style="width:70px"></label><button class="btn btn-sm btn-gold">Starten</button></form>') + '</div>'
                + '<div class="nc-card"><h4>📝 Zitate</h4>' + (c.quotes.length ? c.quotes.map((q, k) => '<div class="nc-mrow small"><b>#' + (k + 1) + '</b><span style="flex:1">„' + esc(q.text) + '“</span><button type="button" class="nc-x" data-qdel="' + k + '">✕</button></div>').join('') : '<p class="muted small">Noch keine. Mods im Chat: ' + esc(c.prefix) + 'zitat add Text</p>')
                + '<form class="nx-row" id="sbQuote"><input type="text" name="text" placeholder="Neues Zitat" style="flex:1" required><button class="btn btn-sm">＋</button></form></div>'
                + '</div>';
        }
        if (view === 'rewards') {
            b.innerHTML = '<div class="nc-grid"><div class="nc-card"><h4>🎁 Belohnungen</h4><p class="muted small">Zuschauer lösen sie mit ' + esc(c.prefix) + 'Name im Chat ein – die Punkte werden abgezogen und das Makro läuft in VRChat (Avatar-Parameter, Chatbox, Kamera, Webhook …).</p>'
                + (c.rewards.length ? c.rewards.map(r => '<div class="nc-mrow"><code>' + esc(c.prefix + r.name) + '</code><b>' + r.cost + '</b><span class="small mono" style="flex:1">' + esc(r.script.split('\n')[0]) + (r.script.includes('\n') ? ' …' : '') + '</span><span class="muted small">' + r.cooldown + ' s</span>'
                    + '<button type="button" class="nc-link" data-redit="' + esc(r.name) + '">bearbeiten</button><button type="button" class="nc-x" data-rdel="' + esc(r.name) + '">✕</button></div>').join('') : '<p class="muted small">Noch keine.</p>') + '</div>'
                + '<form class="nc-card" id="sbRw"><h4>✏️ Belohnung</h4><input type="hidden" name="old"><div class="nx-row"><span>' + esc(c.prefix) + '</span><input type="text" name="name" placeholder="ohren" required style="width:140px"><label class="small">Kosten <input type="number" name="cost" value="100" min="1" style="width:80px"></label><label class="small">Pause <input type="number" name="cooldown" value="30" min="0" style="width:64px"> s</label></div>'
                + '<textarea name="script" rows="5" class="mono" placeholder="param Ears = 1&#10;wait 5&#10;param Ears = 0&#10;chatbox 🐾 {user} hat meine Ohren bewegt!" required style="width:100%"></textarea>'
                + '<input type="text" name="response" placeholder="Antwort im Chat, z. B. {user} hat „Ohren“ eingelöst! 🐾" style="width:100%">'
                + '<p class="muted small">Makro-Schritte wie unter Spiel &amp; OSC → Makros. Platzhalter im Makro: {user} {args} {kosten}.</p>'
                + '<div class="hm-btns"><span class="small">Vorlagen:</span>' + [['ohren', 50, 'param Ears = 1\nwait 5\nparam Ears = 0'], ['tanz', 200, 'param Dance = 1\nwait 10\nparam Dance = 0'], ['foto', 100, 'cam Capture'], ['gruss', 25, 'chatbox 👋 Grüße an {user}!'], ['hupe', 150, 'input Jump']]
                    .map(t => '<button type="button" class="chip" data-rtpl="' + esc(JSON.stringify(t)) + '">' + esc(t[0]) + '</button>').join('') + '</div><button class="btn btn-sm btn-gold">Speichern</button></form></div>';
        }
        if (view === 'setup') {
            b.innerHTML = '<form class="nc-grid" id="sbCfg">'
                + '<div class="nc-card"><h4>🟣 Twitch</h4><label class="check"><input type="checkbox" name="tw_on"' + (c.twitch.on ? ' checked' : '') + '> verbinden</label>'
                + '<div class="nx-row"><span class="small">Kanal</span><input type="text" name="tw_channel" value="' + esc(c.twitch.channel) + '" placeholder="deinkanal"><span class="small">Bot-Konto</span><input type="text" name="tw_bot" value="' + esc(c.twitch.bot) + '" placeholder="leer = Kanal"></div>'
                + '<div class="nx-row"><input type="password" id="sbTwTok" placeholder="' + (st.has.twitch ? '•••• Token gespeichert' : 'OAuth-Token (oauth:…)') + '" style="flex:1" autocomplete="off"><button type="button" class="btn btn-sm" data-key="twitch">Token speichern</button></div>'
                + '<p class="muted small">Token mit Chat-Rechten (chat:read, chat:edit) für das Bot-Konto, z. B. über die Twitch-Entwicklerkonsole oder einen Token-Generator. Wird verschlüsselt gespeichert.</p></div>'
                + '<div class="nc-card"><h4>🔴 YouTube</h4><label class="check"><input type="checkbox" name="yt_on"' + (c.youtube.on ? ' checked' : '') + '> verbinden</label>'
                + '<div class="nx-row"><span class="small">Live-Video</span><input type="text" name="yt_video" value="' + esc(c.youtube.video) + '" placeholder="Link oder Video-ID des Livestreams" style="flex:1"></div>'
                + '<div class="nx-row"><span class="small">Abfrage alle</span><input type="number" name="yt_interval" value="' + c.youtube.interval + '" min="5" max="120" style="width:64px"><span class="small">s</span></div>'
                + '<div class="nx-row"><input type="password" id="sbYtKey" placeholder="' + (st.has.youtube ? '•••• Schlüssel gespeichert' : 'Google-API-Schlüssel (YouTube Data API v3)') + '" style="flex:1" autocomplete="off"><button type="button" class="btn btn-sm" data-key="youtube">Schlüssel speichern</button></div>'
                + '<p class="muted small">YouTube: Chat lesen, Punkte, Befehle und Belohnungen gehen. Antworten des Bots erscheinen im OBS-Chat-Overlay und hier – in den YouTube-Chat schreiben bräuchte eine Google-Anmeldung (OAuth). Kostenloses Kontingent: alle 10 s reicht für ~6 h Stream.</p></div>'
                + '<div class="nc-card"><h4>🏆 Punkte</h4><div class="nx-row"><span class="small">Name</span><input type="text" name="p_name" value="' + esc(c.points.name) + '" style="width:120px"><span class="small">Start</span><input type="number" name="p_start" value="' + c.points.start + '" min="0" style="width:80px"></div>'
                + '<div class="nx-row"><span class="small">pro Nachricht</span><input type="number" name="p_per_msg" value="' + c.points.per_msg + '" min="0" style="width:64px"><span class="small">höchstens alle</span><input type="number" name="p_msg_cooldown" value="' + c.points.msg_cooldown + '" min="0" style="width:70px"><span class="small">s</span></div>'
                + '<div class="nx-row"><span class="small">fürs Zuschauen</span><input type="number" name="p_per_watch" value="' + c.points.per_watch + '" min="0" style="width:64px"><span class="small">alle</span><input type="number" name="p_watch_every" value="' + c.points.watch_every + '" min="1" style="width:64px"><span class="small">min (wer in den letzten 15 min geschrieben hat)</span></div></div>'
                + '<div class="nc-card"><h4>⚙ Bot</h4><div class="nx-row"><span class="small">Befehls-Zeichen</span><input type="text" name="prefix" value="' + esc(c.prefix) + '" maxlength="2" style="width:50px"></div>'
                + '<label class="check small"><input type="checkbox" name="chat_to_vrc"' + (c.chat_to_vrc ? ' checked' : '') + '> Stream-Chat in die VRChat-Chatbox</label> <input type="text" name="vrc_prefix" value="' + esc(c.vrc_prefix) + '" style="width:50px" title="Vorsatz">'
                + '<label class="check small"><input type="checkbox" name="vrc_only_game"' + (c.vrc_only_game ? ' checked' : '') + '> nur wenn VRChat läuft</label>'
                + '<label class="check small"><input type="checkbox" name="greet"' + (c.greet ? ' checked' : '') + '> neue Zuschauer begrüßen</label><input type="text" name="greet_text" value="' + esc(c.greet_text) + '" style="width:100%">'
                + '<p class="muted small">OBS-Chat-Overlay: Einstellungen → Integrationen → „Stream-Chat“-Adresse.</p></div>'
                + '<div><button class="btn btn-gold">💾 Speichern &amp; verbinden</button> <button type="button" class="btn btn-sm" id="sbRe">↻ Neu verbinden</button></div></form>';
        }
    }
    function $(s) { return box.querySelector(s); }
    function loadPoints() {
        api('stream_points', { q: plQ }).then(d => {
            const l = $('#sbPL');
            if (!l) return;
            l.innerHTML = d.list.length ? '<table class="ol-tab"><tbody>' + d.list.map((u, i) => '<tr><td>' + (i + 1) + '.</td><td>' + ICON[u.platform] + ' ' + esc(u.name) + '</td><td><b>' + u.p.toLocaleString(nxLoc()) + '</b> ' + esc(d.name) + '</td><td class="muted small">' + u.msgs + ' Nachrichten · ' + (u.last ? UI.ago(u.last) : '–') + '</td>'
                + '<td><button type="button" class="chip" data-padd="' + esc(u.key) + '" data-n="100">+100</button><button type="button" class="chip" data-padd="' + esc(u.key) + '" data-n="-100">−100</button><button type="button" class="chip" data-pset="' + esc(u.key) + '">setzen …</button></td></tr>').join('') + '</tbody></table>'
                : '<div class="nc-empty">Noch keine Zuschauer.</div>';
        }).catch(fail);
    }
    function poll() {
        clearTimeout(timer);
        api('stream_status', { since: seq }).then(d => {
            st = d;
            if (d.feed.length) { feed = feed.concat(d.feed).slice(-300); seq = d.feed[d.feed.length - 1].i; }
            if (!box.firstChild) shell();
            const a = document.activeElement;
            const busy = a && box.contains(a) && /INPUT|TEXTAREA|SELECT/.test(a.tagName);
            if (view === 'chat' || !$('#stBody').firstChild || (view === 'extras' && !busy && (d.poll || d.giveaway))) render(); else $('#stState').innerHTML = stateHtml();
            if (a && box.contains(a) && a.focus) a.focus();
        }).catch(e => { box.innerHTML = '<div class="vx-error">⚠ ' + esc(e.message) + '</div>'; }).finally(() => {
            const pane = document.querySelector('[data-pane=stream]');
            if (pane && !pane.hidden) timer = setTimeout(poll, view === 'chat' || view === 'extras' ? 1500 : 5000);
        });
    }
    box.addEventListener('click', e => {
        const v = e.target.closest('[data-sv]');
        if (v) { view = v.dataset.sv; UI.store.set('streamView', view); box.querySelectorAll('[data-sv]').forEach(x => x.classList.toggle('on', x === v)); $('#stBody').innerHTML = ''; render(); return; }
        const k = e.target.closest('[data-key]');
        if (k) { const inp = $(k.dataset.key === 'twitch' ? '#sbTwTok' : '#sbYtKey'); return api('stream_key', { kind: k.dataset.key, value: inp.value }).then(d => { st = d; toast(d.msg); inp.value = ''; inp.placeholder = d.has[k.dataset.key] ? '•••• gespeichert' : inp.placeholder; $('#stState').innerHTML = stateHtml(); }).catch(fail); }   // Formular nicht neu zeichnen – sonst gehen ungespeicherte Eingaben verloren
        if (e.target.closest('#sbRe')) return api('stream_reconnect').then(d => { st = d; toast('Verbinde neu …'); render(); }).catch(fail);
        const pa = e.target.closest('[data-padd]');
        if (pa) return api('stream_points_set', { key: pa.dataset.padd, add: pa.dataset.n, q: plQ }).then(() => loadPoints()).catch(fail);
        const ps = e.target.closest('[data-pset]');
        if (ps) { const n = prompt('Neue Punktzahl:'); if (n !== null) api('stream_points_set', { key: ps.dataset.pset, p: n, q: plQ }).then(() => loadPoints()).catch(fail); return; }
        const fillForm = (f, o) => { for (const [kk, vv] of Object.entries(o)) if (f[kk]) { if (f[kk].type === 'checkbox') f[kk].checked = kk === 'on' ? vv !== false : !!vv; else f[kk].value = vv ?? ''; } };
        const ce = e.target.closest('[data-cedit]');
        if (ce) { const x = st.cfg.commands.find(y => y.name === ce.dataset.cedit); return fillForm($('#sbCmd'), Object.assign({ old: x.name, perm: x.mod ? 'mod' : 'all', cost: 0, user_cooldown: 0, aliases: '', usage: '', script: '', on: true }, x)); }
        const re = e.target.closest('[data-redit]');
        if (re) { const x = st.cfg.rewards.find(y => y.name === re.dataset.redit); return fillForm($('#sbRw'), Object.assign({ old: x.name }, x)); }
        const rt = e.target.closest('[data-rtpl]');
        if (rt) { const t = JSON.parse(rt.dataset.rtpl); return fillForm($('#sbRw'), { old: '', name: t[0], cost: t[1], script: t[2], response: '{user} hat „' + t[0] + '“ eingelöst!' }); }
        const ct = e.target.closest('[data-ctpl]');
        if (ct) { const t = CMD_TPL[+ct.dataset.ctpl]; const f = $('#sbCmd'); f.reset(); return fillForm(f, Object.assign({ old: '', on: true, aliases: '', usage: '', user_cooldown: 0 }, t)); }
        const ci = e.target.closest('[data-cins]');
        if (ci) { const ta = $('#sbCmd [name=script]'); ta.value = (ta.value.trim() ? ta.value.replace(/\s*$/, '\n') : '') + ci.dataset.cins; ta.focus(); return; }
        if (e.target.closest('#sbCmdTry')) { const f = $('#sbCmd'); const name = f.name.value.trim(); if (!name) return; return api('stream_cmd_save', formData(f)).then(d => { st = d; return api('stream_test', { user: 'Testzuschauer', text: st.cfg.prefix + name + ' 3', mod: '1', since: seq }); }).then(() => { toast('🧪 ' + st.cfg.prefix + name + ' 3 ausgelöst – siehe Live-Chat'); }).catch(fail); }
        const et = e.target.closest('[data-evtest]');
        if (et) return api('stream_event_test', { kind: et.dataset.evtest }).then(d => toast(d.msg)).catch(fail);
        const te = e.target.closest('[data-tedit]');
        if (te) { const x = st.cfg.timers.find(y => y.id === te.dataset.tedit); return fillForm($('#sbTimer'), x); }
        const td = e.target.closest('[data-tdel]');
        if (td) return api('stream_timer_delete', { id: td.dataset.tdel }).then(d => { st = d; render(); }).catch(fail);
        const cs = e.target.closest('[data-cstep]');
        if (cs) return api('stream_counter_step', { name: cs.dataset.cstep, step: cs.dataset.n }).then(d => { st = d; render(); }).catch(fail);
        const ctd = e.target.closest('[data-ctdel]');
        if (ctd && confirm('Zähler löschen?')) return api('stream_counter_delete', { name: ctd.dataset.ctdel }).then(d => { st = d; render(); }).catch(fail);
        const qd = e.target.closest('[data-qdel]');
        if (qd) return api('stream_quote_delete', { index: qd.dataset.qdel }).then(d => { st = d; render(); }).catch(fail);
        const pe = e.target.closest('[data-poll]');
        if (pe) return api('stream_poll', { op: pe.dataset.poll }).then(d => { st = d; if (d.msg) toast(d.msg); render(); }).catch(fail);
        const gaB = e.target.closest('[data-ga]');
        if (gaB) return api('stream_giveaway', { op: gaB.dataset.ga }).then(d => { st = d; if (d.msg) { toast(d.msg); if (gaB.dataset.ga === 'draw') alert(d.msg); } render(); }).catch(fail);
        const cd = e.target.closest('[data-cdel]');
        if (cd && confirm('Befehl löschen?')) return api('stream_cmd_delete', { name: cd.dataset.cdel }).then(d => { st = d; render(); }).catch(fail);
        const rd = e.target.closest('[data-rdel]');
        if (rd && confirm('Belohnung löschen?')) return api('stream_reward_delete', { name: rd.dataset.rdel }).then(d => { st = d; render(); }).catch(fail);
    });
    function formData(f) { const data = {}; for (const el of f.elements) if (el.name) data[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value; return data; }
    box.addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target, data = formData(f);
        if (f.dataset.ev) return api('stream_event_save', Object.assign(data, { kind: f.dataset.ev })).then(d => { st = d; toast(d.msg); }).catch(fail);
        if (f.id === 'sbTimer') return api('stream_timer_save', data).then(d => { st = d; toast(d.msg); render(); }).catch(fail);
        if (f.id === 'sbGames') return api('stream_games_save', data).then(d => { st = d; toast(d.msg); }).catch(fail);
        if (f.id === 'sbCounter') return api('stream_counter_save', data).then(d => { st = d; render(); }).catch(fail);
        if (f.id === 'sbQuote') return api('stream_quote_add', data).then(d => { st = d; render(); }).catch(fail);
        if (f.id === 'sbPoll') return api('stream_poll', Object.assign(data, { op: 'start' })).then(d => { st = d; toast('📊 Umfrage gestartet'); render(); }).catch(fail);
        if (f.id === 'sbGa') return api('stream_giveaway', Object.assign(data, { op: 'start' })).then(d => { st = d; toast('🎁 Verlosung gestartet'); render(); }).catch(fail);
        if (f.id === 'sbSend') return api('stream_send', data).then(() => { f.text.value = ''; poll(); }).catch(fail);
        if (f.id === 'sbTest') return api('stream_test', Object.assign(data, { since: seq })).then(() => { f.text.value = ''; poll(); }).catch(fail);
        if (f.id === 'sbCmd') return api('stream_cmd_save', data).then(d => { st = d; toast(d.msg); render(); }).catch(fail);
        if (f.id === 'sbRw') return api('stream_reward_save', data).then(d => { st = d; toast(d.msg); render(); }).catch(fail);
        if (f.id === 'sbCfg') return api('stream_cfg', data).then(d => { st = d; toast(d.msg); render(); }).catch(fail);
    });
    let qt = null;
    box.addEventListener('input', e => { if (e.target.id === 'sbPQ') { plQ = e.target.value; clearTimeout(qt); qt = setTimeout(loadPoints, 300); } });
    window.NexusStream = { open() { if (!box.firstChild) shell(); poll(); } };
    const pane = document.querySelector('[data-pane=stream]');
    if (pane && !pane.hidden) window.NexusStream.open();
})();
