<?php
/**
 * Eigene Seiten aus Vorlagen: page.php?p=<kennung>
 *   links     – Link-Hub (gleicher Editor wie die mitgelieferten Hubs)
 *   notes     – Notizen mit einfacher Formatierung (Markdown)
 *   checklist – Checkliste mit Gruppen und Fortschritt
 *   external  – leitet auf eine andere Webseite weiter
 */
require_once __DIR__ . '/inc/pages.php';
require_once __DIR__ . '/inc/hub.php';
require_once __DIR__ . '/inc/linkcheck.php';

auth_require_setup();

$page = page_get((string)($_GET['p'] ?? ''));
if (!$page || $page['kind'] !== 'custom') render_notice_page(404, '🔍', 'Seite nicht gefunden', 'Diese Seite gibt es nicht (mehr).');
if (!page_access_ok($page)) {
    if (!is_logged_in()) { header('Location: admin.php?next=' . urlencode('page.php?p=' . $page['slug'])); exit; }
    render_notice_page(403, '⛔', 'Kein Zugriff', 'Diese Seite ist für deine Rolle nicht freigegeben.');
}
if ($page['template'] === 'external') { header('Location: ' . safe_url($page['url'])); exit; }

$self = 'page.php?p=' . rawurlencode($page['slug']);
$content = page_content($page);
$can_edit = can('pages.edit');

// ---------- Änderungen ----------
if ($page['template'] === 'links') {
    $categories = hub_load('page_' . $page['slug'], []);
    hub_handle_post('page_' . $page['slug'], $categories, $self);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    guard_post('pages.edit');
    $act = $_POST['action'] ?? '';
    $msg = '';
    if ($page['template'] === 'notes' && $act === 'save_notes') {
        $content['markdown'] = mb_substr((string)($_POST['markdown'] ?? ''), 0, 100000);
        $msg = 'Gespeichert.';
    }
    if ($page['template'] === 'checklist') {
        $groups = $content['groups'] ?? [];
        $gid = (string)($_POST['group'] ?? '');
        $iid = (string)($_POST['item'] ?? '');
        foreach ($groups as &$g) {
            if ($act === 'add_item' && $g['id'] === $gid) {
                foreach (preg_split('/\r?\n/', trim((string)($_POST['text'] ?? ''))) as $line) {   // mehrere Zeilen = mehrere Punkte
                    $line = mb_substr(trim($line), 0, 200);
                    if ($line !== '') $g['items'][] = ['id' => uniqid('i'), 'text' => $line, 'done' => false];
                }
            }
            if ($act === 'rename_group' && $g['id'] === $gid) $g['name'] = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80) ?: $g['name'];
            foreach ($g['items'] as $k => &$it) {
                if ($it['id'] !== $iid) continue;
                if ($act === 'toggle') $it['done'] = !$it['done'];
                if ($act === 'delete_item') unset($g['items'][$k]);
            }
            unset($it);
            if ($act === 'clear_done' && $g['id'] === $gid) $g['items'] = array_filter($g['items'], function ($x) { return !$x['done']; });
            if ($act === 'reset' && $g['id'] === $gid) { foreach ($g['items'] as &$x) $x['done'] = false; unset($x); }
            $g['items'] = array_values($g['items']);
        }
        unset($g);
        if ($act === 'add_group') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
            if ($name !== '') $groups[] = ['id' => uniqid('g'), 'name' => $name, 'items' => []];
        }
        if ($act === 'delete_group') $groups = array_values(array_filter($groups, function ($g) use ($gid) { return $g['id'] !== $gid; }));
        $content['groups'] = $groups;
    }
    page_save_content((int)$page['id'], $content);
    if (in_array($act, ['save_notes', 'add_group', 'delete_group', 'clear_done', 'reset'], true)) audit('Seite bearbeitet', $page['title'] . ' (' . $act . ')');
    header('Location: ' . $self . ($msg ? '&msg=' . urlencode($msg) : '') . (isset($_POST['anchor']) ? '#' . preg_replace('/[^a-z0-9]/i', '', $_POST['anchor']) : ''));
    exit;
}

$icon = $page['icon'] ?: (PAGE_TEMPLATES[$page['template']][0] ?? '📄');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page['title']); ?></title>
    <?php echo emoji_favicon($icon); ?>
    <link rel="stylesheet" href="assets/hud.css">
    <style>
        .page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
        .page-icon { font-size: 2.2rem; margin-right: 10px; vertical-align: middle; }
        .page-meta { font-size: 0.7rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; margin-top: 8px; }
        .flash { padding: 10px 14px; margin-bottom: 16px; border: 1px solid var(--accent-green); color: var(--accent-green); background: rgba(80, 250, 123, 0.07); font-size: 0.85rem; }

        /* Link-Hub */
        .search-box { width: 100%; max-width: 380px; margin-top: 14px; }
        .category-section { margin-bottom: 26px; }
        .category-title { font-size: 1rem; text-transform: uppercase; letter-spacing: 2px; color: var(--accent-gold); margin-bottom: 12px; border-left: 4px solid var(--accent-gold); padding-left: 12px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(290px, 100%), 1fr)); gap: 12px; }
        .card { background: var(--panel-bg); border: 1px solid var(--border-color); border-left: 3px solid var(--accent-gold); padding: 14px; display: flex; gap: 12px; align-items: flex-start;
                text-decoration: none; color: var(--text-main); transition: all 0.2s ease; clip-path: var(--cut-sm); }
        .card:hover { border-color: var(--accent-cyan); border-left-color: var(--accent-cyan); transform: translateY(-2px); box-shadow: 0 0 14px rgba(56, 213, 230, 0.15); }
        .icon-wrapper { width: 40px; height: 40px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; background: rgba(243, 194, 98, 0.05); border: 1px solid rgba(243, 194, 98, 0.2); }
        .title { font-weight: 700; color: #fff; margin-bottom: 3px; }
        .desc { font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; }
        .search-counter { margin-top: 8px; font-size: 0.72rem; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text-muted); }
        .no-results { text-align: center; padding: 30px; border: 1px dashed var(--border-color); color: var(--text-muted); letter-spacing: 2px; text-transform: uppercase; font-size: 0.85rem; }

        /* Notizen */
        .md { line-height: 1.7; font-size: 0.95rem; }
        .md h2 { color: var(--accent-gold); letter-spacing: 1px; margin: 22px 0 8px; font-size: 1.35rem; }
        .md h3 { color: var(--accent-cyan); margin: 18px 0 6px; font-size: 1.1rem; }
        .md h4, .md h5 { color: #fff; margin: 14px 0 4px; }
        .md a { color: var(--accent-cyan); }
        .md code { background: rgba(56, 213, 230, 0.08); padding: 1px 5px; color: var(--accent-gold); font-size: 0.88em; }
        .md pre { background: rgba(4, 6, 9, 0.9); border: 1px solid var(--border-color); padding: 12px; overflow-x: auto; }
        .md pre code { background: none; padding: 0; color: var(--text-main); }
        .md blockquote { margin: 10px 0; padding: 6px 14px; border-left: 3px solid var(--accent-purple); color: var(--text-muted); background: rgba(189, 147, 249, 0.05); }
        .md hr { border: none; border-top: 1px solid var(--border-color); margin: 20px 0; }
        .md ul.md-tasks { list-style: none; padding-left: 4px; }
        .md li.done { color: var(--text-muted); text-decoration: line-through; }
        .md > :first-child { margin-top: 0; }
        .md-editor { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .md-editor textarea { min-height: 420px; font-family: ui-monospace, Consolas, monospace; font-size: 0.85rem; line-height: 1.5; letter-spacing: 0; resize: vertical; }
        .md-help { font-size: 0.72rem; color: var(--text-muted); line-height: 1.8; }
        .md-help code { color: var(--accent-gold); }

        /* Checkliste */
        .cl-total { margin-bottom: 22px; }
        .cl-bar { height: 8px; background: rgba(56, 213, 230, 0.08); border: 1px solid var(--border-color); margin-top: 6px; }
        .cl-bar div { height: 100%; background: var(--accent-green); box-shadow: 0 0 10px rgba(80, 250, 123, 0.4); transition: width 0.3s; }
        .cl-group .panel-title small { color: var(--text-muted); font-size: 0.7rem; }
        .cl-item { display: flex; align-items: center; gap: 10px; padding: 7px 4px; border-bottom: 1px solid rgba(56, 213, 230, 0.08); }
        .cl-item form { margin: 0; }
        .cl-check { width: 26px; height: 26px; border: 1px solid var(--accent-cyan); background: transparent; color: #040609; cursor: pointer; font-weight: 800; padding: 0; }
        .cl-check.on { background: var(--accent-green); border-color: var(--accent-green); }
        .cl-check:disabled { cursor: default; opacity: 0.8; }
        .cl-text { flex: 1; }
        .cl-item.done .cl-text { text-decoration: line-through; color: var(--text-muted); }
        .cl-del { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem; opacity: 0; }
        .cl-item:hover .cl-del { opacity: 1; }
        .cl-add { display: flex; gap: 8px; margin-top: 10px; }
        .cl-add textarea { min-height: 40px; height: 40px; resize: vertical; }
        .cl-tools { display: flex; gap: 6px; flex-wrap: wrap; }
        .cl-tools form { margin: 0; }
        @media (max-width: 800px) { .md-editor { grid-template-columns: 1fr; } .cl-del { opacity: 1; } }
    </style>
</head>
<body>
<?php auth_corner(); ?>
<div class="wrapper" style="max-width: 1050px;">
    <div class="nav-bar">
        <a href="index.php" class="nav-back">&larr; ZURÜCK ZUM INDEX</a>
        <?php if (can('pages.manage')): ?><a href="index.php?edit=1#page-<?php echo (int)$page['id']; ?>" class="nav-tab" title="Titel, Beschreibung, Zugriff & Kategorie">⚙ Seiten-Einstellungen</a><?php endif; ?>
    </div>

    <header class="page-head">
        <div>
            <div class="hud-tag">Seite // <?php echo e(PAGE_TEMPLATES[$page['template']][1] ?? 'Eigene Seite'); ?></div>
            <h1><span class="page-icon"><?php echo e($icon); ?></span><?php echo e($page['title']); ?></h1>
            <?php if ($page['description'] !== ''): ?><p class="subtitle"><?php echo e($page['description']); ?></p><?php endif; ?>
            <div class="page-meta"><?php echo e(PAGE_ACCESS[$page['access']]); ?> &bull; zuletzt geändert <?php echo e(date('d.m.Y H:i', strtotime($page['updated_at']))); ?></div>
            <?php if ($page['template'] === 'links'): ?>
                <input type="search" id="searchInput" class="search-box" placeholder="Links durchsuchen..." autocomplete="off" aria-label="Links durchsuchen">
                <?php hub_toolbar(); ?>
            <?php endif; ?>
        </div>
        <?php if ($page['template'] === 'notes' && $can_edit): ?>
            <button type="button" class="btn btn-gold" id="editNotesBtn">✎ Bearbeiten</button>
        <?php endif; ?>
    </header>

    <?php if (!empty($_GET['msg'])): ?><div class="flash"><?php echo e($_GET['msg']); ?></div><?php endif; ?>

<?php if ($page['template'] === 'links'): ?>
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
                                    <?php if (($tool['desc'] ?? '') !== ''): ?><div class="desc"><?php echo e($tool['desc']); ?></div><?php endif; ?>
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
    <script src="assets/hub-search.js"></script>

<?php elseif ($page['template'] === 'notes'): ?>
    <div class="panel md" id="notesView"><?php echo markdown_to_html($content['markdown'] ?? '') ?: '<p class="side-note">Noch leer.</p>'; ?></div>
    <?php if ($can_edit): ?>
        <form method="POST" class="panel" id="notesEdit" hidden>
            <input type="hidden" name="action" value="save_notes">
            <div class="md-editor">
                <div>
                    <label class="field">Text</label>
                    <textarea name="markdown" id="mdText" spellcheck="true"><?php echo e($content['markdown'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label class="field">So wird formatiert</label>
                    <div class="md-help">
                        <code># Überschrift</code> &nbsp; <code>## Unterüberschrift</code><br>
                        <code>**fett**</code> &nbsp; <code>*kursiv*</code> &nbsp; <code>~~durchgestrichen~~</code> &nbsp; <code>`Code`</code><br>
                        <code>- Punkt</code> &nbsp; <code>1. Nummeriert</code> &nbsp; <code>- [ ] offen</code> &nbsp; <code>- [x] erledigt</code><br>
                        <code>[Linktext](https://…)</code> &nbsp; <code>&gt; Zitat</code> &nbsp; <code>---</code> Trennlinie<br>
                        <code>```</code> Codeblock <code>```</code>
                    </div>
                    <p class="md-help" id="mdStats" style="margin-top: 14px;"></p>
                </div>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 12px;">
                <button type="submit" class="btn btn-gold">Speichern</button>
                <button type="button" class="btn" id="cancelNotes">Abbrechen</button>
            </div>
        </form>
        <script>
            const view = document.getElementById('notesView'), edit = document.getElementById('notesEdit'), ta = document.getElementById('mdText');
            const stats = () => { const t = ta.value; document.getElementById('mdStats').textContent = (t.trim() ? t.trim().split(/\s+/).length : 0) + ' Wörter · ' + t.length + ' Zeichen'; };
            document.getElementById('editNotesBtn').onclick = () => { view.hidden = true; edit.hidden = false; ta.focus(); stats(); };
            document.getElementById('cancelNotes').onclick = () => { edit.hidden = true; view.hidden = false; };
            ta.addEventListener('input', stats);
            ta.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); edit.submit(); } });
        </script>
    <?php endif; ?>

<?php elseif ($page['template'] === 'checklist'):
    $groups = $content['groups'] ?? [];
    $all = 0; $done = 0;
    foreach ($groups as $g) foreach ($g['items'] as $it) { $all++; if ($it['done']) $done++; } ?>
    <div class="cl-total">
        <div class="progress-label" style="display: flex; justify-content: space-between; font-size: 0.72rem; letter-spacing: 2px; text-transform: uppercase; color: var(--text-muted);">
            <span>Fortschritt</span><span><?php echo $done; ?> / <?php echo $all; ?> erledigt<?php echo $all ? ' · ' . round($done / $all * 100) . ' %' : ''; ?></span>
        </div>
        <div class="cl-bar"><div style="width: <?php echo $all ? round($done / $all * 100) : 0; ?>%;"></div></div>
    </div>
    <?php foreach ($groups as $g): $gd = count(array_filter($g['items'], function ($x) { return $x['done']; })); ?>
        <div class="panel cl-group" id="g<?php echo e($g['id']); ?>">
            <div class="panel-title">
                <span><?php echo e($g['name']); ?> <small><?php echo $gd; ?>/<?php echo count($g['items']); ?></small></span>
                <?php if ($can_edit): ?>
                    <span class="cl-tools">
                        <form method="POST"><input type="hidden" name="action" value="reset"><input type="hidden" name="group" value="<?php echo e($g['id']); ?>"><input type="hidden" name="anchor" value="g<?php echo e($g['id']); ?>"><button class="btn btn-sm" title="Alle Haken entfernen">↺</button></form>
                        <form method="POST"><input type="hidden" name="action" value="clear_done"><input type="hidden" name="group" value="<?php echo e($g['id']); ?>"><input type="hidden" name="anchor" value="g<?php echo e($g['id']); ?>"><button class="btn btn-sm" title="Erledigte entfernen">🧹</button></form>
                        <form method="POST" onsubmit="const n = prompt('Neuer Name:', <?php echo e(json_encode($g['name'], JSON_UNESCAPED_UNICODE)); ?>); if (!n) return false; this.elements.name.value = n;"><input type="hidden" name="action" value="rename_group"><input type="hidden" name="group" value="<?php echo e($g['id']); ?>"><input type="hidden" name="name"><button class="btn btn-sm" title="Umbenennen">✎</button></form>
                        <form method="POST" onsubmit="return confirm('Gruppe samt Einträgen löschen?');"><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group" value="<?php echo e($g['id']); ?>"><button class="btn btn-sm btn-danger" title="Gruppe löschen">✕</button></form>
                    </span>
                <?php endif; ?>
            </div>
            <?php if (!$g['items']): ?><div class="side-note" style="color: var(--text-muted); font-size: 0.8rem;">Noch keine Einträge.</div><?php endif; ?>
            <?php foreach ($g['items'] as $it): ?>
                <div class="cl-item <?php echo $it['done'] ? 'done' : ''; ?>">
                    <form method="POST"><input type="hidden" name="action" value="toggle"><input type="hidden" name="item" value="<?php echo e($it['id']); ?>"><input type="hidden" name="anchor" value="g<?php echo e($g['id']); ?>">
                        <button class="cl-check <?php echo $it['done'] ? 'on' : ''; ?>" <?php echo $can_edit ? '' : 'disabled'; ?> aria-label="Erledigt umschalten"><?php echo $it['done'] ? '✓' : ''; ?></button></form>
                    <span class="cl-text"><?php echo md_inline($it['text']); ?></span>
                    <?php if ($can_edit): ?>
                        <form method="POST"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="item" value="<?php echo e($it['id']); ?>"><input type="hidden" name="anchor" value="g<?php echo e($g['id']); ?>"><button class="cl-del" title="Löschen">✕</button></form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($can_edit): ?>
                <form method="POST" class="cl-add">
                    <input type="hidden" name="action" value="add_item"><input type="hidden" name="group" value="<?php echo e($g['id']); ?>"><input type="hidden" name="anchor" value="g<?php echo e($g['id']); ?>">
                    <textarea name="text" placeholder="Neuer Eintrag … (mehrere Zeilen = mehrere Einträge)" required></textarea>
                    <button class="btn btn-sm btn-gold">＋</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($can_edit): ?>
        <form method="POST" class="cl-add" style="max-width: 420px;">
            <input type="hidden" name="action" value="add_group">
            <input type="text" name="name" placeholder="Neue Gruppe, z. B. „Kleidung“" maxlength="80" required>
            <button class="btn btn-sm">＋ Gruppe</button>
        </form>
        <script>
            // Enter speichert, Umschalt+Enter = neue Zeile
            document.querySelectorAll('.cl-add textarea').forEach(t => t.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); t.form.submit(); } }));
        </script>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
