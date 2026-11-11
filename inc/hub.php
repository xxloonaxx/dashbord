<?php
/**
 * Bearbeitbare Link-Hubs.
 *
 * Die Links im $categories-Array der Hub-Datei sind nur der Startbestand.
 * Sobald im Browser etwas hinzugefügt/geändert wird, landet der komplette Stand in
 * links_<hubname>.json – diese Datei hat danach Vorrang. Beim Hochladen einer neuen
 * Version der Webseite bleiben die eigenen Links dadurch erhalten.
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';

function hub_data_file(string $hub_file): string {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', pathinfo($hub_file, PATHINFO_FILENAME)));
    return dirname(__DIR__) . '/links_' . $slug . '.json';
}

/** Gespeicherten Stand laden, sonst den Startbestand aus der Hub-Datei verwenden. */
function hub_load(string $hub_file, array $defaults): array {
    $saved = load_json(hub_data_file($hub_file), null);
    return is_array($saved) ? $saved : $defaults;
}

/** Link-Eingaben aus dem Formular prüfen und säubern. Liefert [link|null, fehlermeldung]. */
function hub_link_from_post(): array {
    $title = mb_substr(trim($_POST['title'] ?? ''), 0, 100);
    $url = trim($_POST['url'] ?? '');
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . $url;
    if ($title === '') return [null, 'Bitte einen Titel angeben.'];
    if (safe_url($url) === '#' || !filter_var($url, FILTER_VALIDATE_URL)) return [null, 'Bitte eine gültige Adresse (https://…) angeben.'];
    return [[
        'title' => $title,
        'url' => $url,
        'desc' => mb_substr(trim($_POST['desc'] ?? ''), 0, 300),
        'icon' => mb_substr(trim($_POST['icon'] ?? '') ?: '🔗', 0, 4),
    ] + (!empty($_POST['nsfw']) ? ['nsfw' => true] : []), ''];
}

/** Zielkategorie: vorhandene Auswahl oder neu eingegebener Name. */
function hub_target_category(): string {
    $new = mb_substr(trim($_POST['new_category'] ?? ''), 0, 80);
    return ($_POST['category'] ?? '') === '__new__' ? $new : trim($_POST['category'] ?? '');
}

/** Sucht eine URL in allen Kategorien (außer optional einer Position). */
function hub_find_url(array $categories, string $url, ?array $except = null): ?string {
    $norm = function ($u) { return rtrim(strtolower($u), '/'); };
    foreach ($categories as $cat => $links) {
        foreach ($links as $i => $l) {
            if ($except && $except[0] === $cat && $except[1] === $i) continue;
            if ($norm($l['url']) === $norm($url)) return $cat;
        }
    }
    return null;
}

/** POST-Aktionen der Hub-Seite verarbeiten (Hinzufügen, Bearbeiten, Löschen) und zurückleiten. */
function hub_handle_post(string $hub_file, array $categories): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['hub_action'])) return;
    guard_post();   // Änderungen nur mit Admin-Login

    $msg = '';
    $error = '';
    $action = $_POST['hub_action'];

    switch ($action) {
        case 'add_link':
        case 'edit_link':
            [$link, $error] = hub_link_from_post();
            if (!$link) break;
            $target = hub_target_category();
            if ($target === '') { $error = 'Bitte eine Kategorie wählen oder neu anlegen.'; break; }

            $orig_cat = $_POST['orig_category'] ?? '';
            $orig_idx = isset($_POST['orig_index']) ? (int)$_POST['orig_index'] : -1;
            $editing = $action === 'edit_link' && isset($categories[$orig_cat][$orig_idx]);
            if ($action === 'edit_link' && !$editing) { $error = 'Der Link wurde inzwischen geändert – bitte Seite neu laden.'; break; }

            $dupe = hub_find_url($categories, $link['url'], $editing ? [$orig_cat, $orig_idx] : null);
            if ($dupe !== null) { $error = 'Diesen Link gibt es schon in „' . $dupe . '“.'; break; }

            if ($editing && $orig_cat === $target) {
                $categories[$target][$orig_idx] = $link;          // an gleicher Stelle ersetzen
            } else {
                if ($editing) {
                    array_splice($categories[$orig_cat], $orig_idx, 1);
                }
                $categories[$target] = $categories[$target] ?? [];
                $categories[$target][] = $link;
            }
            $msg = $editing ? 'Link „' . $link['title'] . '“ gespeichert.' : 'Link „' . $link['title'] . '“ hinzugefügt.';
            break;

        case 'delete_link':
            $cat = $_POST['orig_category'] ?? '';
            $idx = (int)($_POST['orig_index'] ?? -1);
            if (isset($categories[$cat][$idx])) {
                $msg = 'Link „' . $categories[$cat][$idx]['title'] . '“ gelöscht.';
                array_splice($categories[$cat], $idx, 1);
            }
            break;

        case 'add_category':
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 80);
            if ($name === '') { $error = 'Bitte einen Namen eingeben.'; break; }
            if (isset($categories[$name])) { $error = 'Die Kategorie „' . $name . '“ gibt es schon.'; break; }
            $categories[$name] = [];
            $msg = 'Kategorie „' . $name . '“ angelegt – jetzt Links hinzufügen.';
            break;

        case 'rename_category':
            $old = $_POST['old_name'] ?? '';
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 80);
            if ($name === '' || !isset($categories[$old])) { $error = 'Ungültiger Name.'; break; }
            if ($name !== $old && isset($categories[$name])) { $error = 'Die Kategorie „' . $name . '“ gibt es schon.'; break; }
            $renamed = [];
            foreach ($categories as $cat => $links) $renamed[$cat === $old ? $name : $cat] = $links;  // Reihenfolge behalten
            $categories = $renamed;
            $msg = 'Kategorie umbenannt.';
            break;

        case 'move_category':
            $name = $_POST['name'] ?? '';
            $keys = array_keys($categories);
            $pos = array_search($name, $keys, true);
            $dir = ($_POST['direction'] ?? '') === 'up' ? -1 : 1;
            if ($pos !== false && isset($keys[$pos + $dir])) {
                [$keys[$pos], $keys[$pos + $dir]] = [$keys[$pos + $dir], $keys[$pos]];
                $sorted = [];
                foreach ($keys as $k) $sorted[$k] = $categories[$k];
                $categories = $sorted;
            }
            break;

        case 'delete_category':
            $name = $_POST['name'] ?? '';
            if (isset($categories[$name])) {
                unset($categories[$name]);
                $msg = 'Kategorie „' . $name . '“ gelöscht.';
            }
            break;
    }

    if (!$error) save_json(hub_data_file($hub_file), $categories);

    $query = $error ? ['error' => $error] : ($msg ? ['msg' => $msg] : []);
    header('Location: ' . basename($hub_file) . ($query ? '?' . http_build_query($query) : ''));
    exit;
}

/** Soll die Kategorie angezeigt werden? Besucher sehen keine leeren bzw. komplett ausgeblendeten Kategorien. */
function hub_category_visible(string $name, array $links): bool {
    if (is_admin()) return true;
    foreach ($links as $l) if (nsfw_visible($l, $name)) return true;
    return false;
}

/** 18+-Markierung an einer Karte (für Admins mit Hinweis, wenn Besucher sie nicht sehen) */
function hub_nsfw_badge(array $link, string $category): string {
    if (!is_nsfw_link($link, $category)) return '';
    $hidden = !site_settings()['show_nsfw'];
    return '<span class="nsfw-badge' . ($hidden ? ' hidden-for-guests' : '') . '" title="' . ($hidden ? 'NSFW – für Besucher ausgeblendet' : 'NSFW') . '">18+</span>';
}

/** Hinweisbalken nach einer Aktion */
function hub_flash(): void {
    if (!empty($_GET['msg'])) echo '<div class="hub-flash ok" role="status">' . e($_GET['msg']) . '</div>';
    if (!empty($_GET['error'])) echo '<div class="hub-flash err" role="alert">' . e($_GET['error']) . '</div>';
}

/** Werkzeugleiste mit den Hinzufügen-Knöpfen */
function hub_toolbar(): void {
    if (!is_admin()) return; ?>
    <div class="hub-toolbar">
        <button type="button" class="hub-btn hub-btn-main" data-hub-open="link">＋ Link hinzufügen</button>
        <button type="button" class="hub-btn" data-hub-open="category">＋ Kategorie</button>
    </div>
<?php }

/** Bearbeiten-Knöpfe einer Kategorie */
function hub_category_actions(string $name, int $pos, int $total): void {
    if (!is_admin()) return; ?>
    <span class="hub-cat-actions">
        <button type="button" class="hub-icon-btn" title="Link in diese Kategorie" data-hub-open="link" data-category="<?php echo e($name); ?>">＋</button>
        <button type="button" class="hub-icon-btn" title="Umbenennen" data-hub-rename="<?php echo e($name); ?>">✎</button>
        <?php foreach ([['up', '▲', 'Nach oben', $pos > 0], ['down', '▼', 'Nach unten', $pos < $total - 1]] as [$dir, $sym, $label, $enabled]): if (!$enabled) continue; ?>
            <form method="POST" class="hub-inline"><input type="hidden" name="hub_action" value="move_category"><input type="hidden" name="name" value="<?php echo e($name); ?>"><input type="hidden" name="direction" value="<?php echo $dir; ?>"><button type="submit" class="hub-icon-btn" title="<?php echo $label; ?>"><?php echo $sym; ?></button></form>
        <?php endforeach; ?>
        <form method="POST" class="hub-inline" onsubmit="return confirm(<?php echo e(json_encode('Kategorie „' . $name . '“ samt allen Links darin löschen?', JSON_UNESCAPED_UNICODE)); ?>);"><input type="hidden" name="hub_action" value="delete_category"><input type="hidden" name="name" value="<?php echo e($name); ?>"><button type="submit" class="hub-icon-btn danger" title="Kategorie löschen">✕</button></form>
    </span>
<?php }

/** Bearbeiten-/Löschen-Knöpfe einer Link-Karte (liegen über der Karte, nicht im Link selbst) */
function hub_link_actions(string $category, int $index, array $link): void {
    if (!is_admin()) return; ?>
    <div class="hub-card-actions">
        <button type="button" class="hub-icon-btn" title="Bearbeiten" data-hub-edit="<?php echo e(json_encode($link + ['category' => $category, 'index' => $index])); ?>">✎</button>
        <form method="POST" class="hub-inline" onsubmit="return confirm(<?php echo e(json_encode('Link „' . $link['title'] . '“ löschen?', JSON_UNESCAPED_UNICODE)); ?>);"><input type="hidden" name="hub_action" value="delete_link"><input type="hidden" name="orig_category" value="<?php echo e($category); ?>"><input type="hidden" name="orig_index" value="<?php echo $index; ?>"><button type="submit" class="hub-icon-btn danger" title="Löschen">✕</button></form>
    </div>
<?php }

/** Dialoge (Link / Kategorie) + Styles + Skript */
function hub_dialogs(array $categories): void {
    auth_corner();
    echo '<link rel="stylesheet" href="assets/hub-admin.css">';
    echo '<style>.nsfw-badge{display:inline-block;font-size:.6rem;font-weight:800;letter-spacing:1px;color:#ff5555;border:1px solid rgba(255,85,85,.6);padding:0 4px;margin-left:4px;vertical-align:middle}.nsfw-badge.hidden-for-guests{border-style:dashed;opacity:.75}</style>';
    if (!is_admin()) return;
    $icons = ['🔗', '⭐', '🛠️', '📚', '🎮', '🎥', '🎵', '🛒', '🐾', '🦊', '💻', '🐧', '🌐', '📰', '🔒', '🎨', '🥽', '🛡️', '💬', '📦'];
    ?>

    <div class="hub-modal" id="hubLinkModal" role="dialog" aria-modal="true" aria-labelledby="hubLinkTitle" hidden>
        <form method="POST" class="hub-modal-box">
            <button type="button" class="hub-modal-close" data-hub-close aria-label="Schließen">&times;</button>
            <div class="hub-modal-title" id="hubLinkTitle">// Link hinzufügen</div>
            <input type="hidden" name="hub_action" value="add_link">
            <input type="hidden" name="orig_category" value="">
            <input type="hidden" name="orig_index" value="">

            <label class="hub-label">Titel *<input type="text" name="title" maxlength="100" required placeholder="z. B. ProtonDB"></label>
            <label class="hub-label">Adresse (URL) *<input type="url" name="url" required placeholder="https://…" inputmode="url"></label>
            <label class="hub-label">Beschreibung<input type="text" name="desc" maxlength="300" placeholder="Wofür ist der Link gut?"></label>

            <div class="hub-row">
                <label class="hub-label" style="flex: 2;">Kategorie *
                    <select name="category">
                        <?php foreach (array_keys($categories) as $cat): ?>
                            <option value="<?php echo e($cat); ?>"><?php echo e($cat); ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">＋ Neue Kategorie…</option>
                    </select>
                </label>
                <label class="hub-label" style="flex: 1;">Icon<input type="text" name="icon" maxlength="4" value="🔗"></label>
            </div>
            <label class="hub-label hub-new-cat" hidden>Name der neuen Kategorie<input type="text" name="new_category" maxlength="80"></label>
            <label class="hub-check"><input type="checkbox" name="nsfw" value="1"> NSFW / 18+ (wird für Besucher ausgeblendet, solange NSFW aus ist)</label>

            <div class="hub-icons">
                <?php foreach ($icons as $ic): ?><button type="button"><?php echo $ic; ?></button><?php endforeach; ?>
            </div>

            <button type="submit" class="hub-btn hub-btn-main hub-submit">Speichern</button>
        </form>
    </div>

    <div class="hub-modal" id="hubCatModal" role="dialog" aria-modal="true" aria-labelledby="hubCatTitle" hidden>
        <form method="POST" class="hub-modal-box">
            <button type="button" class="hub-modal-close" data-hub-close aria-label="Schließen">&times;</button>
            <div class="hub-modal-title" id="hubCatTitle">// Neue Kategorie</div>
            <input type="hidden" name="hub_action" value="add_category">
            <input type="hidden" name="old_name" value="">
            <label class="hub-label">Name *<input type="text" name="name" maxlength="80" required placeholder="z. B. Streaming-Tools"></label>
            <button type="submit" class="hub-btn hub-btn-main hub-submit">Speichern</button>
        </form>
    </div>

    <script src="assets/hub-admin.js"></script>
<?php }
