<?php
require_once __DIR__ . '/inc/linkcheck.php';
require_once __DIR__ . '/inc/auth.php';

auth_require_setup();
guard_post('linkcheck.run');   // Prüfung starten nur mit Recht

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_check'])) {
    @set_time_limit(180);
    if (!function_exists('curl_multi_init')) {
        header('Location: linkcheck.php?error=curl');
        exit;
    }
    run_linkcheck();
    header('Location: linkcheck.php?done=1');
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
    <link rel="stylesheet" href="assets/hud.css">
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
        #running { display: none; color: var(--accent-gold); font-size: 0.8rem; letter-spacing: 1px; }
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
    <?php elseif (isset($_GET['error'])): ?>
        <div class="notice" style="border-color: var(--accent-red);">Auf diesem Server ist die PHP-Erweiterung cURL nicht aktiv – die Prüfung ist so nicht möglich.</div>
    <?php endif; ?>

    <div class="panel">
        <div class="run-box">
            <p>
                Letzte Prüfung:
                <strong style="color: #fff;"><?php echo $report['checked_at'] ? e(german_datetime((int)$report['checked_at'])) : 'noch nie'; ?></strong><br>
                Dauert ca. 15–30 Sekunden, alle Links werden parallel abgefragt.
            </p>
            <?php if (can('linkcheck.run')): ?>
            <form method="POST" onsubmit="document.getElementById('running').style.display='block'; this.querySelector('button').disabled=true;">
                <button type="submit" name="run_check" class="btn btn-gold">Jetzt prüfen</button>
                <div id="running">⟳ Prüfe Links… bitte warten</div>
            </form>
            <?php else: ?>
            <a href="admin.php?next=linkcheck.php" class="btn">🔒 Zum Prüfen anmelden</a>
            <?php endif; ?>
        </div>
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
