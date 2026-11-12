<?php
require_once __DIR__ . '/inc/modules/linkcheck.php';
require_once __DIR__ . '/inc/core/auth.php';

auth_require_setup();
guard_post('linkcheck.run');   // Prüfung starten nur mit Recht

// Schrittweise Prüfung per JavaScript (jede Anfrage dauert nur wenige Sekunden -> keine Zeitlimit-Abbrüche beim Hoster)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lc_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if ($_POST['lc_action'] === 'start') {
            audit('Link-Check gestartet');
            echo json_encode(linkcheck_start());
        } else {
            echo json_encode(linkcheck_step());
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Ausweichweg ohne JavaScript: alles in einer Anfrage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_check'])) {
    @set_time_limit(180);
    $report = run_linkcheck();
    $all_dead = $report['results'] && !array_filter($report['results'], function ($r) { return $r['code'] !== 0; });
    header('Location: linkcheck.php?' . ($all_dead ? 'offline=1' : 'done=1'));
    exit;
}

$links = collect_hub_links();
$report = load_linkcheck();
$results = $report['results'];

$labels = [
    'dead' => ['Nicht erreichbar', 'var(--accent-red)', 'Domain existiert nicht mehr oder Server antwortet nicht'],
    'broken' => ['Fehler', 'var(--accent-red)', 'Seite nicht gefunden (404) oder Serverfehler'],
    'moved' => ['Umgezogen', 'var(--accent-gold)', 'Leitet auf eine andere Domain weiter – Link evtl. aktualisieren'],
    'blocked' => ['Bot-Schutz', 'var(--accent-purple)', 'Seite existiert, blockt aber automatische Abrufe (im Browser meist ok)'],
    'ok' => ['OK', 'var(--accent-green)', 'Erreichbar'],
    'unchecked' => ['Ungeprüft', 'var(--text-muted)', 'Noch nicht geprüft'],
];

$rows = [];
$counts = array_fill_keys(array_keys($labels), 0);
// NSFW-Links ausblenden, wenn sie für Besucher versteckt sind
$links = array_filter($links, function ($info) { return nsfw_visible(['nsfw' => $info['nsfw'] ?? false], $info['category']); });

foreach ($links as $url => $info) {
    $r = $results[$url] ?? null;
    $state = $r ? $r['status'] : 'unchecked';
    if ($state === 'ok' && moved_to_other_host($url, $r['final_url'])) $state = 'moved';
    $counts[$state]++;
    $rows[] = ['url' => $url, 'info' => $info, 'r' => $r, 'state' => $state];
}
$order = array_flip(array_keys($labels));
usort($rows, function ($a, $b) use ($order) {
    return [$order[$a['state']], $a['info']['title']] <=> [$order[$b['state']], $b['info']['title']];
});
$problems = $counts['dead'] + $counts['broken'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LINK-CHECK NODE</title>
    <?php echo emoji_favicon('🩺'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <style>
        .stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 20px; }
        .stat { background: var(--panel-bg); border: 1px solid var(--border-color); padding: 12px; clip-path: var(--cut-sm); cursor: pointer; text-align: left; font-family: inherit; color: inherit; }
        .stat.active { border-color: var(--accent-cyan); box-shadow: inset 0 0 18px rgba(56, 213, 230, 0.12); }
        .stat b { display: block; font-size: 1.5rem; }
        .stat span { font-size: 0.62rem; letter-spacing: 2px; text-transform: uppercase; color: var(--text-muted); }
        .lc-list { display: flex; flex-direction: column; gap: 6px; }
        .lc-row { display: grid; grid-template-columns: 130px 1fr auto; gap: 12px; align-items: center; padding: 10px 14px; background: rgba(14, 20, 27, 0.7); border: 1px solid var(--border-color); border-left: 3px solid var(--c); }
        .lc-title { font-weight: 700; color: #fff; }
        .lc-url { font-size: 0.75rem; color: var(--accent-cyan); text-decoration: none; word-break: break-all; }
        .lc-url:hover { text-decoration: underline; }
        .lc-meta { font-size: 0.68rem; color: var(--text-muted); letter-spacing: 0.5px; margin-top: 2px; }
        .lc-code { font-size: 0.75rem; color: var(--text-muted); text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .legend { font-size: 0.75rem; color: var(--text-muted); line-height: 1.7; }
        .legend b { letter-spacing: 1px; }
        .run-box { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; }
        .run-box p { margin: 0; font-size: 0.8rem; color: var(--text-muted); }
        .lc-progress { border-color: var(--accent-gold); }
        .lc-prog-head, .lc-prog-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; font-size: 0.8rem; }
        .lc-prog-head { color: var(--accent-gold); letter-spacing: 1px; text-transform: uppercase; font-size: 0.72rem; margin-bottom: 8px; }
        .lc-prog-foot { color: var(--text-muted); margin-top: 8px; min-height: 26px; }
        .lc-bar { height: 10px; background: rgba(56, 213, 230, 0.08); border: 1px solid var(--border-color); overflow: hidden; }
        .lc-bar div { height: 100%; width: 0; background: linear-gradient(90deg, var(--accent-cyan), var(--accent-gold)); box-shadow: 0 0 12px var(--accent-glow); transition: width 0.4s ease; }
        .lc-error { margin-top: 12px; padding: 10px 12px; border: 1px solid var(--accent-red); color: var(--accent-red); background: rgba(255, 85, 85, 0.07); font-size: 0.82rem; line-height: 1.5; }
        .lc-error button { margin-top: 8px; }
        @media (max-width: 700px) {
            .stats { grid-template-columns: repeat(3, 1fr); }
            .lc-row { grid-template-columns: 1fr; gap: 4px; }
            .lc-code { text-align: left; }
        }
    </style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
    </div>

    <header>
        <div class="hud-tag">System // Link Integrity Scanner</div>
        <h1>Link-Check</h1>
        <p class="subtitle"><?php echo count($links); ?> Links aus <?php echo count(find_hub_files()); ?> Hubs</p>
    </header>

    <?php if (isset($_GET['done'])): ?>
        <div class="notice">Prüfung abgeschlossen. <?php echo $problems ? "<strong>$problems</strong> Link(s) sind kaputt – sie werden in den Hubs jetzt mit ⚠ markiert." : 'Alle Links sind erreichbar. 🎉'; ?></div>
    <?php elseif (isset($_GET['offline'])): ?>
        <div class="notice" style="border-color: var(--accent-red);">⚠ Kein einziger Link war erreichbar. Sehr wahrscheinlich blockiert dein Hoster ausgehende Verbindungen
            (bei manchen Gratis-Hostern üblich) – dann funktionieren auch Feed, Kalender-Abos und Bank-Sync nicht. Bitte beim Hoster nachfragen, ob „ausgehende HTTP-Verbindungen / cURL“ erlaubt sind.</div>
    <?php endif; ?>
    <?php if (!function_exists('curl_init') && !ini_get('allow_url_fopen')): ?>
        <div class="notice" style="border-color: var(--accent-red);">⚠ Auf diesem Server sind weder cURL noch <code>allow_url_fopen</code> aktiv – Links können so nicht geprüft werden. Bitte beim Hoster die PHP-Erweiterung cURL aktivieren lassen.</div>
    <?php endif; ?>

    <div class="panel">
        <div class="run-box">
            <p>
                Letzte Prüfung:
                <strong style="color: #fff;"><?php echo $report['checked_at'] ? e(german_datetime((int)$report['checked_at'])) : 'noch nie'; ?></strong><br>
                Die Links werden in kleinen Paketen geprüft – der Fortschritt erscheint live.
            </p>
            <?php if (can('linkcheck.run')): ?>
            <form method="POST" id="lcForm">
                <button type="submit" name="run_check" value="1" class="btn btn-gold" id="lcBtn">Jetzt prüfen</button>
            </form>
            <?php else: ?>
            <a href="admin.php?next=linkcheck.php" class="btn">🔒 Zum Prüfen anmelden</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel lc-progress" id="lcProgress" hidden>
        <div class="lc-prog-head"><span id="lcPhase">Prüfe Links…</span><span id="lcCount">0 / 0</span></div>
        <div class="lc-bar"><div id="lcBar"></div></div>
        <div class="lc-prog-foot"><span id="lcCurrent"></span><button type="button" class="btn btn-sm btn-danger" id="lcCancel">Abbrechen</button></div>
        <div class="lc-error" id="lcError" hidden></div>
    </div>

    <div class="stats">
        <button type="button" class="stat active" data-filter="all"><b><?php echo count($links); ?></b><span>Alle</span></button>
        <button type="button" class="stat" data-filter="problem"><b style="color: var(--accent-red);"><?php echo $problems; ?></b><span>Kaputt</span></button>
        <button type="button" class="stat" data-filter="moved"><b style="color: var(--accent-gold);"><?php echo $counts['moved']; ?></b><span>Umgezogen</span></button>
        <button type="button" class="stat" data-filter="blocked"><b style="color: var(--accent-purple);"><?php echo $counts['blocked']; ?></b><span>Bot-Schutz</span></button>
        <button type="button" class="stat" data-filter="ok"><b style="color: var(--accent-green);"><?php echo $counts['ok']; ?></b><span>OK</span></button>
    </div>

    <div class="panel">
        <div class="panel-title"><span>// Ergebnisse</span><span id="shown"></span></div>
        <div class="lc-list">
            <?php foreach ($rows as $row):
                [$label, $color, $hint] = $labels[$row['state']];
                $r = $row['r'];
            ?>
                <div class="lc-row" style="--c: <?php echo $color; ?>;" data-state="<?php echo $row['state']; ?>">
                    <span class="badge" style="color: <?php echo $color; ?>;" title="<?php echo e($hint); ?>"><?php echo e($label); ?></span>
                    <div>
                        <div class="lc-title"><?php echo e($row['info']['title']); ?></div>
                        <a class="lc-url" href="<?php echo e($row['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($row['url']); ?></a>
                        <div class="lc-meta">
                            <?php echo e(implode(', ', array_unique($row['info']['hubs']))); ?> &bull; <?php echo e($row['info']['category']); ?>
                            <?php if ($row['state'] === 'moved'): ?><br>&rarr; jetzt: <?php echo e($r['final_url']); ?><?php endif; ?>
                            <?php if ($r && $r['error']): ?><br><?php echo e($r['error']); ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="lc-code"><?php echo $r ? ($r['code'] ? 'HTTP ' . (int)$r['code'] : '—') . '<br>' . (int)$r['ms'] . ' ms' : ''; ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel legend">
        <div class="panel-title"><span>// Legende</span></div>
        <?php foreach ($labels as $key => [$label, $color, $hint]): if ($key === 'unchecked') continue; ?>
            <b style="color: <?php echo $color; ?>;"><?php echo e($label); ?>:</b> <?php echo e($hint); ?><br>
        <?php endforeach; ?>
        Links landen in einem Hub, indem du sie im <code>$categories</code>-Array der jeweiligen Hub-Datei einträgst bzw. entfernst.
    </div>
</div>

<script>
    // ---------- Schrittweise Prüfung mit Live-Fortschritt ----------
    (function () {
        const form = document.getElementById('lcForm');
        if (!form) return;
        const $ = id => document.getElementById(id);
        let cancelled = false;
        const post = action => fetch('linkcheck.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: 'lc_action=' + action
        }).then(async r => {
            const text = await r.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                if (r.redirected || /name="login"|id="u"/.test(text)) throw new Error('Deine Anmeldung ist abgelaufen – bitte neu anmelden und erneut prüfen.');
                throw new Error('Der Server hat keine gültige Antwort geliefert (HTTP ' + r.status + '). Häufig ist das ein Zeitlimit oder ein PHP-Fehler beim Hoster.');
            }
            if (!r.ok || data.error) throw new Error(data.error || ('HTTP ' + r.status));
            return data;
        });
        function show(d) {
            const total = d.total + (d.retry_total || 0);
            const done = d.done + (d.retry_done || 0);
            $('lcBar').style.width = (total ? Math.round(done / total * 100) : 0) + '%';
            $('lcCount').textContent = d.phase === 2 ? (d.retry_done + ' / ' + d.retry_total + ' erneut') : (d.done + ' / ' + d.total);
            $('lcPhase').textContent = d.phase === 2 ? 'Zweiter Versuch für nicht erreichbare Links…' : 'Prüfe Links…';
            $('lcCurrent').textContent = d.current ? 'zuletzt: ' + d.current : '';
        }
        function fail(err) {
            $('lcError').hidden = false;
            $('lcError').innerHTML = '';
            $('lcError').append('⚠ ' + err.message);
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = 'Ohne Live-Anzeige prüfen (eine lange Anfrage)';
            b.onclick = () => { const f = document.createElement('form'); f.method = 'POST'; f.innerHTML = '<input type="hidden" name="run_check" value="1">'; document.body.append(f); f.submit(); };
            $('lcError').append(document.createElement('br'), b);
            $('lcBtn').disabled = false;
            $('lcCancel').hidden = true;
        }
        async function run() {
            cancelled = false;
            $('lcProgress').hidden = false; $('lcError').hidden = true; $('lcCancel').hidden = false;
            $('lcBtn').disabled = true;
            try {
                const s = await post('start');
                show({ done: 0, total: s.total, phase: 1 });
                for (let guard = 0; guard < 500 && !cancelled; guard++) {
                    const d = await post('step');
                    if (d.finished) {
                        $('lcBar').style.width = '100%';
                        location.href = 'linkcheck.php?' + (d.all_dead ? 'offline=1' : 'done=1');
                        return;
                    }
                    show(d);
                }
                if (cancelled) { $('lcPhase').textContent = 'Abgebrochen – bisherige Ergebnisse wurden nicht gespeichert.'; $('lcBtn').disabled = false; }
            } catch (err) { fail(err); }
        }
        form.addEventListener('submit', e => { if (!window.fetch) return; e.preventDefault(); run(); });
        $('lcCancel').addEventListener('click', () => { cancelled = true; $('lcCancel').hidden = true; });
    })();

    const rows = document.querySelectorAll('.lc-row');
    const shown = document.getElementById('shown');
    function applyFilter(f) {
        let n = 0;
        rows.forEach(r => {
            const s = r.dataset.state;
            const show = f === 'all' || s === f || (f === 'problem' && (s === 'dead' || s === 'broken'));
            r.style.display = show ? '' : 'none';
            if (show) n++;
        });
        shown.textContent = n + ' angezeigt';
    }
    document.querySelectorAll('.stat').forEach(btn => btn.addEventListener('click', () => {
        document.querySelectorAll('.stat').forEach(b => b.classList.toggle('active', b === btn));
        applyFilter(btn.dataset.filter);
    }));
    applyFilter('all');
</script>
</body>
</html>
