<?php
/**
 * Live-Feed / RSS-Reader: Quellen nach Kategorien, Artikel-Liste in mehreren Ansichten, Lesebereich,
 * Gelesen/Gemerkt pro Benutzer, Suche, Tastenkürzel, Quellenverwaltung (hinzufügen mit Autodiscovery,
 * bearbeiten, pausieren, entfernen), OPML-Import/-Export und Cron-Aktualisierung.
 */
require_once __DIR__ . '/inc/modules/feed.php';

// Cron: feed.php?cron=<schlüssel> lädt alle fälligen Quellen
if (isset($_GET['cron'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals(feed_cron_key(), (string)$_GET['cron'])) { http_response_code(403); exit("falscher Schlüssel\n"); }
    $r = feed_refresh(null, false);
    exit("ok – {$r['sources']} Quellen geladen, {$r['new']} neue Artikel, {$r['errors']} Fehler\n");
}

auth_require_setup();
$can_edit = can('feed.edit');
$uid = feed_uid();

if (isset($_GET['opml']) && $can_edit) {
    header('Content-Type: text/x-opml; charset=utf-8');
    header('Content-Disposition: attachment; filename="feeds_' . date('Y-m-d') . '.opml"');
    exit(feed_opml());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $edit_acts = ['discover', 'source_add', 'source_save', 'source_delete', 'source_toggle', 'opml_import', 'category_rename'];
    if (in_array($act, $edit_acts, true)) guard_post('feed.edit');
    elseif (is_logged_in()) guard_post(null);
    elseif (!in_array($act, ['sources', 'items', 'item', 'refresh'], true)) { http_response_code(403); exit('{"error":"Bitte anmelden."}'); }   // Besucher dürfen nur lesen
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = $_POST;
    $out = ['ok' => true];
    try {
        $db = feed_db();
        $sid = (int)($in['id'] ?? 0);
        switch ($act) {
            case 'sources':
                $out = ['sources' => feed_sources_list()];
                break;
            case 'items':
                $out = feed_items($in);
                break;
            case 'item':
                $it = feed_item((int)($in['id'] ?? 0));
                if (!$it) throw new RuntimeException('Artikel nicht gefunden.');
                if ($uid && empty($in['keep_unread'])) feed_set_state([$it['id']], true, null);
                $out = ['item' => $it];
                break;
            case 'state':
                if (!$uid) throw new RuntimeException('Zum Merken bitte anmelden.');
                $ids = array_map('intval', array_filter(explode(',', (string)($in['ids'] ?? ''))));
                feed_set_state($ids, isset($in['read']) ? (bool)$in['read'] : null, isset($in['starred']) ? (bool)$in['starred'] : null);
                break;
            case 'mark_all':
                if (!$uid) throw new RuntimeException('Bitte anmelden.');
                $out = ['msg' => feed_mark_all($in) . ' Artikel als gelesen markiert.'];
                break;
            case 'refresh':
                session_write_close();
                $force = !empty($in['force']) && $uid;
                $r = feed_refresh($sid ? [$sid] : null, (bool)$force || (bool)$sid);
                $out = $r + ['msg' => $r['sources'] ? $r['sources'] . ' Quellen geladen · ' . $r['new'] . ' neue Artikel' . ($r['errors'] ? ' · ' . $r['errors'] . ' mit Fehler' : '') : 'Alles aktuell.'];
                break;
            case 'discover':
                session_write_close();
                $out = ['feed' => feed_discover((string)($in['url'] ?? ''))];
                break;
            case 'source_add':
                $url = trim((string)($in['url'] ?? ''));
                if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('Ungültige Adresse.');
                $st = $db->prepare('SELECT id FROM sources WHERE url = ?');
                $st->execute([$url]);
                if ($st->fetchColumn()) throw new RuntimeException('Diese Quelle ist schon eingetragen.');
                $next_sort = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM sources')->fetchColumn();
                $db->prepare('INSERT INTO sources (title, url, site_url, category, icon, sort, created) VALUES (?, ?, ?, ?, ?, ?, ?)')
                   ->execute([mb_substr(trim((string)($in['title'] ?? '')) ?: (parse_url($url, PHP_URL_HOST) ?: 'Feed'), 0, 150), $url, safe_url((string)($in['site'] ?? '')) === '#' ? '' : $in['site'],
                              mb_substr(trim((string)($in['category'] ?? '')) ?: 'Allgemein', 0, 60), mb_substr(trim((string)($in['icon'] ?? '')), 0, 4), $next_sort, time()]);
                $new = (int)$db->lastInsertId();
                audit('Feed-Quelle hinzugefügt', $url);
                session_write_close();
                $r = feed_refresh([$new], true);
                $out = ['msg' => 'Quelle hinzugefügt – ' . $r['new'] . ' Artikel geladen.', 'id' => $new];
                break;
            case 'source_save':
                $url = trim((string)($in['url'] ?? ''));
                if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('Ungültige Adresse.');
                $db->prepare('UPDATE sources SET title = ?, url = ?, category = ?, icon = ?, etag = CASE WHEN url = ? THEN etag ELSE \'\' END WHERE id = ?')
                   ->execute([mb_substr(trim((string)($in['title'] ?? '')) ?: 'Feed', 0, 150), $url, mb_substr(trim((string)($in['category'] ?? '')) ?: 'Allgemein', 0, 60), mb_substr(trim((string)($in['icon'] ?? '')), 0, 4), $url, $sid]);
                $out = ['msg' => 'Gespeichert.'];
                break;
            case 'source_toggle':
                $db->prepare('UPDATE sources SET enabled = 1 - enabled WHERE id = ?')->execute([$sid]);
                feed_write_cache();
                $out = ['msg' => 'Geändert.'];
                break;
            case 'source_delete':
                $st = $db->prepare('SELECT url FROM sources WHERE id = ?');
                $st->execute([$sid]);
                $db->prepare('DELETE FROM sources WHERE id = ?')->execute([$sid]);
                audit('Feed-Quelle entfernt', (string)$st->fetchColumn(), 'warn');
                feed_write_cache();
                $out = ['msg' => 'Quelle entfernt.'];
                break;
            case 'category_rename':
                $old = (string)($in['old'] ?? '');
                $new = mb_substr(trim((string)($in['name'] ?? '')), 0, 60);
                if ($new === '') throw new RuntimeException('Name fehlt.');
                $db->prepare('UPDATE sources SET category = ? WHERE category = ?')->execute([$new, $old]);
                $out = ['msg' => 'Kategorie umbenannt.'];
                break;
            case 'opml_import':
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) throw new RuntimeException('Bitte eine OPML-Datei (max. 2 MB) wählen.');
                $n = feed_import_opml((string)file_get_contents($f['tmp_name']));
                session_write_close();
                if ($n) feed_refresh(null, true);
                $out = ['msg' => $n . ' Quelle(n) importiert.'];
                break;
            default:
                throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        http_response_code(400);
        $out = ['error' => $e->getMessage()];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sources = feed_sources_list();
$stale = !$sources || min(array_map(function ($s) { return $s['enabled'] ? $s['last_fetch'] : PHP_INT_MAX; }, $sources)) < time() - FEED_REFRESH;
$cron = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/feed.php', '?') . '?cron=' . ($can_edit ? feed_cron_key() : '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live-Feed</title>
    <?php echo emoji_favicon('📡'); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/feed.css">
</head>
<body class="fd-body">
<?php auth_corner(); ?>
<div class="fd-app" id="app">
    <aside class="fd-side" id="side">
        <div class="fd-brand"><a href="index.php" class="fd-back" title="Zur Startseite">←</a><span>📡 Live-Feed</span></div>
        <nav class="fd-views" id="views">
            <button type="button" data-view="all" class="on">🗞 Alle <b data-count="all"></b></button>
            <button type="button" data-view="unread">● Ungelesen <b data-count="unread"></b></button>
            <button type="button" data-view="today">☀ Heute</button>
            <?php if ($uid): ?><button type="button" data-view="starred">⭐ Gemerkt</button><?php endif; ?>
        </nav>
        <div class="fd-srclist" id="srcList"></div>
        <div class="fd-sidefoot">
            <?php if ($can_edit): ?><button type="button" class="btn btn-sm btn-gold" id="addBtn">＋ Quelle</button><button type="button" class="btn btn-sm" id="manageBtn">⚙ Quellen</button><?php endif; ?>
        </div>
    </aside>

    <main class="fd-main">
        <div class="fd-bar">
            <button type="button" class="fd-ic fd-menu" id="menuBtn" title="Quellen">☰</button>
            <h1 id="listTitle">Alle Artikel</h1>
            <input type="search" id="q" placeholder="Suchen … ( / )" aria-label="Artikel durchsuchen">
            <span class="fd-barbtns">
                <button type="button" class="fd-ic" id="viewBtn" title="Ansicht wechseln (V)">▤</button>
                <button type="button" class="fd-ic" id="sortBtn" title="Sortierung">⇅</button>
                <?php if ($uid): ?><button type="button" class="fd-ic" id="unreadBtn" title="Nur ungelesene (U)">◐</button>
                <button type="button" class="fd-ic" id="readAllBtn" title="Alle als gelesen markieren (Shift+A)">✓✓</button><?php endif; ?>
                <button type="button" class="fd-ic" id="refreshBtn" title="Aktualisieren (R)">⟳</button>
                <button type="button" class="fd-ic" id="helpBtn" title="Tastenkürzel (?)">⌨</button>
            </span>
        </div>
        <div class="fd-status" id="status" hidden></div>
        <div class="fd-list" id="list" data-mode="list"></div>
        <div class="fd-more"><button type="button" class="btn btn-sm" id="moreBtn" hidden>Mehr laden</button></div>
    </main>

    <article class="fd-reader" id="reader" hidden>
        <div class="fd-rbar">
            <button type="button" class="fd-ic" data-r="close" title="Schließen (Esc)">✕</button>
            <button type="button" class="fd-ic" data-r="prev" title="Vorheriger (K)">↑</button>
            <button type="button" class="fd-ic" data-r="next" title="Nächster (J)">↓</button>
            <span class="fd-rsp"></span>
            <?php if ($uid): ?><button type="button" class="fd-ic" data-r="star" title="Merken (S)">☆</button><button type="button" class="fd-ic" data-r="unread" title="Als ungelesen markieren (M)">●</button><?php endif; ?>
            <button type="button" class="fd-ic" data-r="font" title="Schriftgröße">Aa</button>
            <button type="button" class="fd-ic" data-r="copy" title="Link kopieren">⧉</button>
            <a class="fd-ic" data-r="open" href="#" target="_blank" rel="noopener noreferrer" title="Original öffnen (O)">↗</a>
        </div>
        <div class="fd-rbody" id="rbody"></div>
    </article>
</div>

<div class="modal-overlay" id="fdModal" role="dialog" aria-modal="true"><div class="modal-box fd-modal"><button type="button" class="modal-close" data-close aria-label="Schließen">&times;</button><div id="fdModalBody"></div></div></div>

<script>window.FEED = <?php echo json_encode(['sources' => $sources, 'canEdit' => $can_edit, 'user' => (bool)$uid, 'stale' => $stale, 'cron' => $can_edit ? $cron : ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<script src="assets/js/feed.js"></script>
</body>
</html>
