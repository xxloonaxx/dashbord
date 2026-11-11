<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/countdown.php';
require_once __DIR__ . '/inc/linkcheck.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/calendar.php';

// Beim allerersten Aufruf: Admin-Konto anlegen
auth_require_setup();
$admin = is_admin();
$show_nsfw = site_settings()['show_nsfw'];

// Seiten, die nur angemeldet erreichbar sind
$protected_pages = ['budget.php', 'con.php', 'backup.php'];

// Der aktuelle Ordner, in dem das Skript liegt
$dir = __DIR__;
$allowed_extensions = ['php', 'html', 'htm'];

/** Live-Vorschau für die Con-Karte */
function con_preview(): string {
    $con = load_json(__DIR__ . '/con_data.json', null);
    if (!$con) return 'Keine aktiven Con-Daten gefunden';
    $budget = (float)($con['total_budget'] ?? 0);
    $spent = 0;
    foreach ($con['expenses'] ?? [] as $ex) $spent += (float)($ex['amount'] ?? 0);
    return 'AKTIVE CON: ' . ($con['con_name'] ?? 'Unbenannt')
        . ' // BUDGET: ' . eur($budget) . ' € // AUSGABEN: ' . eur($spent) . ' € // REST: ' . eur($budget - $spent) . ' €';
}

/** Live-Vorschau für die Budget-Karte */
function budget_preview(): string {
    $b = load_json(__DIR__ . '/budget_data.json', null);
    if (!$b) return 'Noch keine Monatsdaten vorhanden';
    $inc = 0; $exp = 0; $open = 0;
    foreach ($b['entries'] ?? [] as $e) {
        $amt = (float)($e['amount'] ?? 0);
        if (($e['section'] ?? 'other') === 'income') $inc += $amt; else $exp += $amt;
        if (($e['status'] ?? '') === 'Offen' && ($e['section'] ?? '') !== 'income') $open += $amt;
    }
    $txt = 'MONAT: ' . ($b['month_name'] ?? '?') . ' // NETTO: ' . eur($inc - $exp) . ' €';
    if ($open > 0) $txt .= ' // OFFEN: ' . eur($open) . ' €';
    return $txt;
}

/** Live-Vorschau für den Feed */
function feed_preview(): string {
    $cache = __DIR__ . '/feed_cache.json';
    $items = load_json($cache, []);
    if (!$items) return 'Noch kein Feed-Cache vorhanden';
    return count($items) . ' MELDUNGEN IM CACHE // STAND: ' . date('d.m. H:i', filemtime($cache)) . ' UHR';
}

/** Live-Vorschau für die Countdown-Karte */
function countdown_preview(): string {
    [$upcoming] = sorted_countdowns(load_countdowns()['events']);
    if (!$upcoming) return 'Keine anstehenden Countdowns';
    $parts = [];
    foreach (array_slice($upcoming, 0, 3) as $ev) {
        $parts[] = mb_strtoupper($ev['title'], 'UTF-8') . ' ' . mb_strtoupper(relative_days($ev['ts']), 'UTF-8');
    }
    return implode(' // ', $parts);
}

/** Live-Vorschau für die Link-Check-Karte */
function linkcheck_preview(): string {
    $report = load_linkcheck();
    if (!$report['checked_at']) return 'Noch nicht geprüft – Karte öffnen und "Jetzt prüfen" klicken';
    $bad = 0;
    foreach ($report['results'] as $r) if (in_array($r['status'], ['dead', 'broken'], true)) $bad++;
    return ($bad ? "⚠ $bad KAPUTTE LINKS" : 'ALLE LINKS ERREICHBAR') . ' // GEPRÜFT: ' . date('d.m. H:i', (int)$report['checked_at']) . ' UHR';
}

/** Linkdaten eines Hubs laden (die Hub-Dateien liefern bei LINKS_ONLY nur ihr Array zurück). */
function load_hub_links(string $path): ?array {
    if (strpos((string)file_get_contents($path), "if (defined('LINKS_ONLY')) return \$categories;") === false) return null;
    if (!defined('LINKS_ONLY')) define('LINKS_ONLY', true);
    $result = (function () use ($path) { return include $path; })();
    return is_array($result) ? $result : null;
}

$clean_links = [];
$global_sub_links = [];
$seen_urls = [];

foreach (scandir($dir) as $file) {
    if ($file[0] === '.' || $file === basename(__FILE__) || !is_file($dir . '/' . $file)) {
        continue;
    }
    $file_info = pathinfo($file);
    if (!isset($file_info['extension']) || !in_array(strtolower($file_info['extension']), $allowed_extensions)) {
        continue;
    }

    $filename = $file_info['filename'];
    $sort_order = 999;
    $clean_filename = $filename;

    if (preg_match('/^(\d+)[\s_\-]*(.*)$/', $filename, $matches)) {
        $sort_order = (int)$matches[1];
        $clean_filename = $matches[2];
    }
    if (trim($clean_filename) === '') {
        $clean_filename = $filename;
    }
    $clean_name = mb_strtoupper(str_replace(['_', '-'], ' ', $clean_filename), 'UTF-8');

    // Seitentitel aus dem <title>-Tag als Untertitel verwenden
    $page_title = '';
    if (preg_match('#<title>(.*?)</title>#is', (string)file_get_contents($dir . '/' . $file), $m)) {
        $page_title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }

    // Beschreibung: Live-Daten für bekannte Module, Linkanzahl für Hubs
    $desc = '';
    $lower = strtolower($file);
    if (in_array($lower, $protected_pages, true) && !$admin) {
        $desc = '🔒 Nur für Admins – Login erforderlich';
    } elseif ($lower === 'admin.php') {
        $desc = $admin ? 'Angemeldet als ' . auth_user() . ' // Einstellungen, Sicherheit & Protokoll' : 'Anmelden, um Budget, Con-Rechner & Änderungen freizuschalten';
    } elseif ($lower === 'con.php') {
        $desc = con_preview();
    } elseif ($lower === 'budget.php') {
        $desc = budget_preview();
    } elseif ($lower === 'feed.php') {
        $desc = feed_preview();
    } elseif ($lower === 'calendar.php') {
        $desc = cal_preview($admin);
    } elseif ($lower === 'countdown.php') {
        $desc = countdown_preview();
    } elseif ($lower === 'linkcheck.php') {
        $desc = linkcheck_preview();
    } elseif ($lower === 'backup.php') {
        $desc = 'Alle Daten (Budget, Con, Countdowns …) als ZIP herunterladen';
    } elseif ($hub = load_hub_links($dir . '/' . $file)) {
        $count = 0;
        foreach ($hub as $category => $links) {
            foreach ($links as $l) {
                if (!nsfw_visible($l, $category)) continue;   // NSFW ausgeblendet
                $count++;
                $key = rtrim(strtolower($l['url']), '/');
                if (isset($seen_urls[$key])) continue;
                $seen_urls[$key] = true;
                $global_sub_links[] = [
                    'title' => $l['title'],
                    'url' => $l['url'],
                    'desc' => $l['desc'] ?? '',
                    'icon' => $l['icon'] ?? '🔗',
                    'category' => $category,
                    'hub' => $page_title ?: $clean_name,
                    'nsfw' => is_nsfw_link($l, $category),
                ];
            }
        }
        $desc = $count . ' LINKS';
    }

    $clean_links[] = [
        'url' => $file,
        'name' => $clean_name,
        'title' => $page_title,
        'sort' => $sort_order,
        'desc' => $desc,
        'locked' => in_array($lower, $protected_pages, true) && !$admin,
    ];
}

usort($clean_links, function ($a, $b) {
    return [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']];
});

$total_nodes = count($clean_links) + count($global_sub_links);
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHOST NODE // TERMINAL</title>
    <?php echo emoji_favicon('🛰️'); ?>
    <style>
        :root {
            --bg-color: #040609;
            --panel-bg: rgba(10, 16, 24, 0.85);
            --panel-hover: rgba(18, 28, 42, 0.95);
            --accent-gold: #f3c262;
            --accent-cyan: #38d5e6;
            --accent-glow: rgba(56, 213, 230, 0.3);
            --text-main: #e2e8f0;
            --text-muted: #64748b;
            --border-color: rgba(56, 213, 230, 0.3);
        }

        * { box-sizing: border-box; }

        body {
            font-family: "Segoe UI", Roboto, -apple-system, sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 50% 10%, rgba(56, 213, 230, 0.08), transparent 70%),
                linear-gradient(rgba(4, 6, 9, 0.9), rgba(4, 6, 9, 0.98));
            background-attachment: fixed;
            color: var(--text-main);
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.3) 50%);
            background-size: 100% 4px;
            z-index: 1;
            pointer-events: none;
            opacity: 0.5;
        }

        .wrapper {
            width: 100%;
            max-width: 750px;
            z-index: 2;
        }

        .telemetry-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(56, 213, 230, 0.05);
            border: 1px solid var(--border-color);
            padding: 8px 14px;
            margin-bottom: 20px;
            font-size: 0.75rem;
            letter-spacing: 2px;
            color: var(--accent-cyan);
            text-transform: uppercase;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
        }

        .admin-bar { display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin: -12px 0 18px; font-size: 0.7rem; letter-spacing: 1.5px; text-transform: uppercase; flex-wrap: wrap; }
        .admin-bar.is-admin { justify-content: space-between; padding: 7px 12px; border: 1px solid rgba(243, 194, 98, 0.35); background: rgba(243, 194, 98, 0.05); }
        .admin-user { color: var(--accent-gold); text-decoration: none; font-weight: 700; }
        .admin-bar:not(.is-admin) .admin-user { color: var(--text-muted); font-weight: 400; }
        .admin-user:hover { color: #fff; }
        .nsfw-form { display: flex; align-items: center; gap: 8px; margin: 0; }
        .nsfw-label { color: var(--text-muted); }
        .nsfw-state { color: var(--text-muted); min-width: 2.4em; }
        .mini-switch { position: relative; width: 42px; height: 22px; padding: 0; cursor: pointer; border: 1px solid var(--border-color); background: rgba(4, 6, 9, 0.9); transition: all 0.2s; }
        .mini-switch::after { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: var(--text-muted); transition: all 0.2s; }
        .mini-switch.on { border-color: #ff5555; box-shadow: 0 0 10px rgba(255, 85, 85, 0.3); }
        .mini-switch.on::after { left: 22px; background: #ff5555; }
        .mini-switch.on + .nsfw-state { color: #ff5555; }
        .admin-logout { background: none; border: 1px solid rgba(255, 85, 85, 0.4); color: #ff5555; cursor: pointer; width: 26px; height: 24px; }
        .admin-logout:hover { background: #ff5555; color: #040609; }
        .nsfw-tag { font-size: 0.6rem; color: #ff5555; border: 1px solid rgba(255, 85, 85, 0.6); padding: 0 4px; letter-spacing: 1px; vertical-align: middle; }
        .file-link.locked { border-left-color: var(--text-muted); opacity: 0.75; }
        .file-link.locked:hover { opacity: 1; }

        .telemetry-item span {
            color: #ffffff;
            font-weight: 700;
        }

        header {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 15px;
        }

        .hud-tag {
            font-size: 0.7rem;
            letter-spacing: 3px;
            color: var(--accent-cyan);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .hud-tag::before {
            content: "";
            display: inline-block;
            width: 6px;
            height: 6px;
            background-color: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-cyan);
        }

        h1 {
            font-size: 2.4rem;
            font-weight: 800;
            letter-spacing: 5px;
            margin: 0 0 6px 0;
            text-transform: uppercase;
            color: var(--accent-gold);
            text-shadow: 0 0 15px rgba(243, 194, 98, 0.4);
        }

        .subtitle {
            color: var(--text-muted);
            margin: 0;
            font-size: 0.85rem;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .search-box {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            padding: 20px;
            margin-bottom: 30px;
            backdrop-filter: blur(12px);
            clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 0 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }

        .search-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .search-controls {
            display: flex;
            gap: 10px;
        }

        select#search-engine {
            background-color: rgba(4, 6, 9, 0.95);
            color: var(--accent-gold);
            border: 1px solid var(--border-color);
            padding: 12px 14px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 1px;
            outline: none;
            cursor: pointer;
            text-transform: uppercase;
        }

        select#search-engine:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-glow);
        }

        input[type="search"].search-input {
            flex: 1;
            background-color: rgba(4, 6, 9, 0.95);
            border: 1px solid var(--border-color);
            color: #ffffff;
            padding: 12px 16px;
            font-size: 1rem;
            outline: none;
            letter-spacing: 1px;
            transition: all 0.2s ease;
        }

        input[type="search"].search-input:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 15px var(--accent-glow);
        }

        button.search-btn {
            background-color: rgba(243, 194, 98, 0.15);
            border: 1px solid var(--accent-gold);
            color: var(--accent-gold);
            padding: 0 22px;
            font-weight: 700;
            letter-spacing: 2px;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.2s ease;
        }

        button.search-btn:hover {
            background-color: var(--accent-cyan);
            border-color: var(--accent-cyan);
            color: #040609;
            box-shadow: 0 0 15px var(--accent-cyan);
        }

        .section-title {
            font-size: 0.8rem;
            letter-spacing: 2.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 15px;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
        }

        .list-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .file-link {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent-gold);
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: var(--text-main);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(10px);
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
        }

        .file-link:hover {
            background-color: var(--panel-hover);
            border-color: var(--accent-cyan);
            border-left-color: var(--accent-cyan);
            transform: translateX(8px);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .file-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .file-name {
            font-weight: 800;
            font-size: 1.05rem;
            letter-spacing: 2px;
            color: #ffffff;
        }

        .file-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .file-desc {
            font-size: 0.78rem;
            color: var(--accent-cyan);
            margin-top: 2px;
            letter-spacing: 0.5px;
        }

        .action-zone {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-muted);
            font-size: 0.75rem;
            letter-spacing: 2px;
            transition: color 0.2s ease;
            white-space: nowrap;
        }

        .file-link:hover .action-zone {
            color: var(--accent-cyan);
        }

        .arrow {
            font-size: 1.2rem;
            transition: transform 0.2s ease;
        }

        .file-link:hover .arrow {
            transform: translateX(6px);
        }

        .empty-msg {
            text-align: center;
            color: var(--text-muted);
            padding: 30px;
            border: 1px dashed var(--border-color);
            background: var(--panel-bg);
            letter-spacing: 2px;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .file-link[hidden], .empty-msg[hidden] { display: none; }
        .file-link.sub-item { border-left-color: var(--accent-cyan); }

        .file-link:focus-visible {
            outline: 2px solid var(--accent-cyan);
            outline-offset: 2px;
            border-left-color: var(--accent-cyan);
        }

        .search-hint {
            font-size: 0.7rem;
            letter-spacing: 1px;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        kbd {
            display: inline-block;
            padding: 1px 6px;
            border: 1px solid var(--border-color);
            background: rgba(56, 213, 230, 0.06);
            color: var(--accent-cyan);
            font-family: inherit;
            font-size: 0.7rem;
        }

        .file-details { min-width: 0; }
        .file-desc { overflow-wrap: anywhere; }

        @media (max-width: 640px) {
            body { padding: 20px 14px; }
            h1 { font-size: 1.8rem; letter-spacing: 3px; }
            .telemetry-bar { flex-wrap: wrap; gap: 4px 14px; font-size: 0.65rem; letter-spacing: 1px; }
            .search-box { padding: 14px; }
            .search-controls { flex-wrap: wrap; }
            select#search-engine { flex: 1 1 100%; }
            input[type="search"].search-input { min-width: 0; }
            button.search-btn { padding: 12px 16px; }
            .file-link { padding: 14px 16px; }
            .file-link:hover { transform: none; }
            .action-zone span:first-child { display: none; }
            .search-hint { display: none; }
            #modeIndicator { display: none; }
        }
    </style>
</head>
<body>

    <div class="wrapper">
        <div class="telemetry-bar">
            <div class="telemetry-item">STATUS: <span>ONLINE // SECURE</span></div>
            <div class="telemetry-item">INDEXED NODES: <span id="nodeCount"><?php echo $total_nodes; ?></span></div>
            <div class="telemetry-item">TIME: <span id="liveClock">--:--:--</span></div>
        </div>

        <!-- Admin-Leiste: NSFW-Schalter nur für angemeldete Admins -->
        <div class="admin-bar <?php echo $admin ? 'is-admin' : ''; ?>">
            <?php if ($admin): ?>
                <a href="admin.php" class="admin-user">⚙ <?php echo e(auth_user()); ?></a>
                <form method="POST" action="admin.php" class="nsfw-form">
                    <input type="hidden" name="action" value="toggle_nsfw">
                    <input type="hidden" name="back" value="index.php">
                    <span class="nsfw-label">NSFW für Besucher</span>
                    <button type="submit" class="mini-switch <?php echo $show_nsfw ? 'on' : ''; ?>" role="switch" aria-checked="<?php echo $show_nsfw ? 'true' : 'false'; ?>" title="NSFW-Links für Besucher <?php echo $show_nsfw ? 'ausblenden' : 'anzeigen'; ?>"></button>
                    <span class="nsfw-state"><?php echo $show_nsfw ? 'AN' : 'AUS'; ?></span>
                </form>
                <form method="POST" action="admin.php" style="margin: 0;"><input type="hidden" name="action" value="logout"><button type="submit" class="admin-logout" title="Abmelden">⏻</button></form>
            <?php else: ?>
                <a href="admin.php?next=index.php" class="admin-user">🔒 Admin-Login</a>
            <?php endif; ?>
        </div>

        <header>
            <div class="hud-tag">System // Central Terminal</div>
            <h1>Startseite</h1>
            <p class="subtitle" id="greeting">Zentrale Schnittstelle &amp; Datenknoten</p>
        </header>

        <div class="search-box">
            <form id="searchForm" action="https://www.google.com/search" method="GET" class="search-form" role="search">
                <div class="search-controls">
                    <select id="search-engine" aria-label="Suchmaschine">
                        <option value="google" selected>Google</option>
                        <option value="duckduckgo">DuckDuckGo</option>
                        <option value="ecosia">Ecosia</option>
                        <option value="bing">Bing</option>
                        <option value="internal">// LOKAL (ALLE NODES &amp; LINKS)</option>
                    </select>
                    <input type="search" id="searchInput" name="q" class="search-input" placeholder="Suchbegriff eingeben..." autofocus autocomplete="off" aria-label="Suchbegriff">
                    <button type="submit" id="searchBtn" class="search-btn">Suchen</button>
                </div>
                <div class="search-hint" id="searchHint">
                    Tippen zeigt sofort passende Links aus allen Hubs &bull; <kbd>/</kbd> Suche &bull; <kbd>Esc</kbd> leeren &bull; <kbd>Enter</kbd> Websuche
                </div>
            </form>
        </div>

        <div class="section-title">
            <span id="sectionHeader">Aktive Verzeichnisse &amp; Hubs</span>
            <span id="modeIndicator">[EXECUTE MODE]</span>
        </div>

        <!-- Kombinierte Liste aller lokalen Seiten und Unter-Links -->
        <div class="list-container" id="linkList">
            <?php if (empty($clean_links)): ?>
                <div class="empty-msg">Keine weiteren Seiten im Verzeichnis gefunden.</div>
            <?php endif; ?>

            <!-- Lokale PHP/HTML Hauptseiten -->
            <?php foreach ($clean_links as $link): ?>
                <a href="<?php echo e($link['locked'] ? 'admin.php?next=' . urlencode($link['url']) : $link['url']); ?>" class="file-link search-item node-item <?php echo $link['locked'] ? 'locked' : ''; ?>" data-search-text="<?php echo e(mb_strtolower($link['name'] . ' ' . $link['title'] . ' ' . $link['url'] . ' ' . $link['desc'], 'UTF-8')); ?>">
                    <div class="file-details">
                        <span class="file-name"><?php echo $link['locked'] ? '🔒 ' : ''; ?><?php echo e($link['name']); ?></span>
                        <span class="file-meta">TARGET: <?php echo e($link['url']); ?><?php if ($link['title']): ?> // <?php echo e($link['title']); ?><?php endif; ?></span>
                        <?php if ($link['desc'] !== ''): ?>
                            <span class="file-desc"><?php echo e($link['desc']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="action-zone">
                        <span><?php echo $link['locked'] ? 'LOGIN' : 'EXECUTE'; ?></span>
                        <span class="arrow">&rarr;</span>
                    </div>
                </a>
            <?php endforeach; ?>

            <!-- Alle Unter-Links aus den Hubs (werden automatisch aus den Hub-Seiten gelesen) -->
            <?php foreach ($global_sub_links as $sub): ?>
                <a href="<?php echo e($sub['url']); ?>" target="_blank" rel="noopener noreferrer" class="file-link search-item sub-item" hidden data-search-text="<?php echo e(mb_strtolower($sub['title'] . ' ' . $sub['desc'] . ' ' . $sub['category'] . ' ' . $sub['hub'] . ' ' . $sub['url'], 'UTF-8')); ?>">
                    <div class="file-details">
                        <span class="file-name"><?php echo e($sub['icon']); ?> <?php echo e($sub['title']); ?><?php if ($sub['nsfw']): ?> <span class="nsfw-tag">18+</span><?php endif; ?></span>
                        <span class="file-meta">HUB LINK // <?php echo e($sub['hub']); ?> // <?php echo e($sub['category']); ?></span>
                        <?php if ($sub['desc'] !== ''): ?>
                            <span class="file-desc"><?php echo e($sub['desc']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="action-zone">
                        <span>OPEN</span>
                        <span class="arrow">&nearr;</span>
                    </div>
                </a>
            <?php endforeach; ?>

            <div class="empty-msg" id="noResults" hidden>Keine lokalen Treffer – <kbd>Enter</kbd> sucht im Web.</div>
        </div>
    </div>

    <script>
        // ---------- Uhr & Begrüßung ----------
        function updateClock() {
            const now = new Date();
            document.getElementById('liveClock').textContent =
                [now.getHours(), now.getMinutes(), now.getSeconds()].map(n => String(n).padStart(2, '0')).join(':');
        }
        setInterval(updateClock, 1000);
        updateClock();

        (function greet() {
            const h = new Date().getHours();
            const text = h < 5 ? 'Gute Nacht' : h < 11 ? 'Guten Morgen' : h < 17 ? 'Guten Tag' : h < 22 ? 'Guten Abend' : 'Gute Nacht';
            const date = new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });
            document.getElementById('greeting').textContent = text + ' // ' + date;
        })();

        // ---------- Suche ----------
        const ENGINES = {
            google:     'https://www.google.com/search',
            duckduckgo: 'https://duckduckgo.com/',
            ecosia:     'https://www.ecosia.org/search',
            bing:       'https://www.bing.com/search'
        };
        const ENGINE_NAMES = { google: 'Google', duckduckgo: 'DuckDuckGo', ecosia: 'Ecosia', bing: 'Bing' };

        const form = document.getElementById('searchForm');
        const input = document.getElementById('searchInput');
        const select = document.getElementById('search-engine');
        const btn = document.getElementById('searchBtn');
        const modeInd = document.getElementById('modeIndicator');
        const header = document.getElementById('sectionHeader');
        const hint = document.getElementById('searchHint');
        const noResults = document.getElementById('noResults');
        const items = Array.from(document.querySelectorAll('.search-item'));

        function storage(key, value) {
            try {
                if (value === undefined) return localStorage.getItem(key);
                localStorage.setItem(key, value);
            } catch (e) { return null; }
        }

        function updateSearchEngine(engine) {
            const internal = engine === 'internal';
            btn.textContent = internal ? 'Öffnen' : 'Suchen';
            modeInd.textContent = internal ? '[SEARCH MODE]' : '[EXECUTE MODE]';
            input.placeholder = internal ? 'Nodes & Links durchsuchen...' : 'Suchbegriff eingeben...';
            hint.innerHTML = internal
                ? 'Lokale Suche über alle Hubs &bull; <kbd>Enter</kbd> öffnet den ersten Treffer &bull; <kbd>Esc</kbd> leeren'
                : 'Tippen zeigt sofort passende Links aus allen Hubs &bull; <kbd>/</kbd> Suche &bull; <kbd>Esc</kbd> leeren &bull; <kbd>Enter</kbd> Websuche (' + ENGINE_NAMES[engine] + ')';
            if (!internal) form.action = ENGINES[engine];
            storage('searchEngine', engine);
            filterLocalLinks();
        }

        function filterLocalLinks() {
            const terms = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            let visible = 0;

            items.forEach(item => {
                let show;
                if (!terms.length) {
                    show = item.classList.contains('node-item');
                } else {
                    const text = item.dataset.searchText;
                    show = terms.every(t => text.includes(t));
                }
                item.hidden = !show;
                if (show) visible++;
            });

            header.textContent = terms.length ? visible + ' lokale Treffer' : 'Aktive Verzeichnisse & Hubs';
            noResults.hidden = !(terms.length && visible === 0);
        }

        function firstVisible() {
            return items.find(i => !i.hidden);
        }

        form.addEventListener('submit', e => {
            const query = input.value.trim();
            if (select.value === 'internal') {
                e.preventDefault();
                const hit = firstVisible();
                if (hit && query) {
                    if (hit.target === '_blank') window.open(hit.href, '_blank', 'noopener');
                    else window.location.href = hit.href;
                }
            } else if (!query) {
                e.preventDefault();
            }
        });

        select.addEventListener('change', () => { updateSearchEngine(select.value); input.focus(); });
        input.addEventListener('input', filterLocalLinks);
        input.addEventListener('keydown', e => {
            if (e.key === 'Escape') { input.value = ''; filterLocalLinks(); }
            if (e.key === 'ArrowDown') {
                const hit = firstVisible();
                if (hit) { e.preventDefault(); hit.focus(); }
            }
        });
        document.addEventListener('keydown', e => {
            if (e.key === '/' && document.activeElement !== input && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
                e.preventDefault();
                input.focus();
            }
        });

        // Zuletzt genutzte Suchmaschine merken
        const savedEngine = storage('searchEngine');
        if (savedEngine && select.querySelector('option[value="' + savedEngine + '"]')) select.value = savedEngine;
        updateSearchEngine(select.value);
    </script>
</body>
</html>
