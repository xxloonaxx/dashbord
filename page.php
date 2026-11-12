<?php
/**
 * Eigene Seiten aus Vorlagen: page.php?p=<kennung>
 * Die Vorlagen selbst liegen in inc/tpl/*.php (Notizen, Checkliste, Kanban, Tabelle, Tagebuch, Galerie, Zähler, Einbettungen).
 * „links“ nutzt den Editor der mitgelieferten Link-Hubs, „external“ leitet weiter.
 */
require_once __DIR__ . '/inc/core/pages.php';
require_once __DIR__ . '/inc/modules/hub.php';
require_once __DIR__ . '/inc/modules/linkcheck.php';
require_once __DIR__ . '/inc/core/templates.php';

auth_require_setup();

$page = page_get((string)($_GET['p'] ?? ''));
if (!$page || $page['kind'] !== 'custom') render_notice_page(404, '🔍', 'Seite nicht gefunden', 'Diese Seite gibt es nicht (mehr).');
if (!page_access_ok($page)) {
    if (!is_logged_in()) { header('Location: admin.php?next=' . urlencode('page.php?p=' . $page['slug'])); exit; }
    render_notice_page(403, '⛔', 'Kein Zugriff', 'Diese Seite ist für deine Rolle nicht freigegeben.');
}
if ($page['template'] === 'external') { header('Location: ' . safe_url($page['url'])); exit; }

$tpl = $page['template'];
$self = 'page.php?p=' . rawurlencode($page['slug']);
$content = page_content_normalized($page);
$can_edit = can('pages.edit');

if ($tpl === 'table' && isset($_GET['export'])) { tpl_table_export($content, $page); exit; }

// ---------- Änderungen ----------
if ($tpl === 'links') {
    $categories = hub_load('page_' . $page['slug'], []);
    hub_handle_post('page_' . $page['slug'], $categories, $self);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('pages.edit');
    $ajax = !empty($_POST['ajax']);
    $act = (string)($_POST['action'] ?? '');
    $res = [];
    $err = '';
    try {
        if (!function_exists('tpl_' . $tpl . '_post')) throw new InvalidArgumentException('Diese Seite kann man nicht bearbeiten.');
        $fn = 'tpl_' . $tpl . '_post';
        $res = $fn($content, $act, $page) ?: [];
        page_save_content((int)$page['id'], $content);
        if (!empty($res['audit'])) audit('Seite bearbeitet', $page['title'] . ': ' . $res['audit']);
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($err !== '') http_response_code(400);
        echo json_encode($err !== '' ? ['error' => $err] : ($res['json'] ?? ['ok' => true]), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $q = $err !== '' ? '&error=' . urlencode($err) : (!empty($res['msg']) ? '&msg=' . urlencode($res['msg']) : '');
    if (!empty($res['reopen'])) $q .= '&open=' . urlencode($res['reopen']);
    header('Location: ' . $self . $q . (!empty($res['anchor']) ? '#' . preg_replace('/[^a-z0-9\-]/i', '', $res['anchor']) : ''));
    exit;
}

$icon = $page['icon'] ?: (PAGE_TEMPLATES[$tpl][0] ?? '📄');
$wide = in_array($tpl, ['kanban', 'table', 'gallery', 'notes'], true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page['title']); ?></title>
    <?php echo emoji_favicon($icon); ?>
    <link rel="stylesheet" href="assets/css/hud.css">
    <link rel="stylesheet" href="assets/css/page.css">
</head>
<body class="tpl-<?php echo e($tpl); ?>">
<?php if ($tpl !== 'links') auth_corner(); ?>
<div class="wrapper" style="max-width: <?php echo $wide ? '1280px' : '1050px'; ?>;">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <?php if (can('pages.manage')): ?><a href="index.php?edit=1#page-<?php echo (int)$page['id']; ?>" class="nav-tab" title="Titel, Beschreibung, Zugriff & Kategorie">⚙ Seiten-Einstellungen</a><?php endif; ?>
    </div>

    <header class="page-head">
        <div>
            <div class="hud-tag">Seite // <?php echo e(PAGE_TEMPLATES[$tpl][1] ?? 'Eigene Seite'); ?></div>
            <h1><span class="page-icon"><?php echo e($icon); ?></span><?php echo e($page['title']); ?></h1>
            <?php if ($page['description'] !== ''): ?><p class="subtitle fmt-inline"><?php echo format_inline($page['description']); ?></p><?php endif; ?>
            <div class="page-meta"><?php echo e(PAGE_ACCESS[$page['access']]); ?> &bull; zuletzt geändert <?php echo e(date('d.m.Y H:i', strtotime($page['updated_at']))); ?></div>
            <?php if ($tpl === 'links'): ?>
                <input type="search" id="searchInput" class="search-box" placeholder="Links durchsuchen..." autocomplete="off" aria-label="Links durchsuchen">
                <?php hub_toolbar(); ?>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($_GET['msg'])): ?><div class="flash"><?php echo e($_GET['msg']); ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="flash err">⚠ <?php echo e($_GET['error']); ?></div><?php endif; ?>

<?php if ($tpl === 'links'): ?>
    <?php hub_flash(); ?>
    <div id="contentWrapper">
        <?php if (!$categories): ?>
            <div class="empty-msg"><?php echo can('links.edit') ? 'Noch keine Links – oben auf „＋ Kategorie“ und „＋ Link hinzufügen“ klicken.' : 'Hier gibt es noch keine Links.'; ?></div>
        <?php endif; ?>
        <?php $cat_pos = 0; foreach ($categories as $catName => $tools): $cat_index = $cat_pos++; if (!hub_category_visible($catName, $tools)) continue; ?>
            <div class="category-section">
                <div class="category-title hub-editable"><span><?php echo e($catName); ?></span><?php hub_category_actions($catName, $cat_index, count($categories)); ?></div>
                <div class="grid">
                    <?php if (!$tools && can('links.edit')): ?>
                        <div class="hub-empty">Noch keine Links – <button type="button" data-hub-open="link" data-category="<?php echo e($catName); ?>">jetzt hinzufügen</button></div>
                    <?php endif; ?>
                    <?php foreach ($tools as $i => $tool): if (!nsfw_visible($tool, $catName)) continue; ?>
                        <div class="card-wrap">
                            <a href="<?php echo e($tool['url']); ?>" class="card" target="_blank" rel="noopener noreferrer">
                                <div class="icon-wrapper"><?php echo e($tool['icon']); ?></div>
                                <div class="info">
                                    <div class="title"><?php echo e($tool['title']); ?> <?php echo hub_nsfw_badge($tool, $catName); ?> <?php echo link_badge($tool['url']); ?></div>
                                    <?php if (($tool['desc'] ?? '') !== ''): ?><div class="desc"><?php echo format_inline($tool['desc'], ['links' => false]); ?></div><?php endif; ?>
                                </div>
                            </a>
                            <?php hub_link_actions($catName, $i, $tool); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php hub_dialogs($categories); ?>
    <script src="assets/js/hub-search.js"></script>
<?php else:
    tpl_call($tpl, 'render', $content, $page, $can_edit);
endif; ?>
</div>
<?php if ($tpl !== 'links'): ?>
    <script src="assets/js/page.js"></script>
    <?php if ($can_edit): ?><script src="assets/js/editor.js"></script><?php endif; ?>
<?php endif; ?>
</body>
</html>
