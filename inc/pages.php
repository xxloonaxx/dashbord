<?php
/**
 * Seitenregister für den Index: vorhandene PHP-Seiten + selbst erstellte Seiten aus Vorlagen,
 * mit Kategorien, Sortierung, Beschreibung, Icon und Zugriffsstufe.
 */
require_once __DIR__ . '/auth.php';

/** Vorlagen für neue Seiten: Schlüssel => [Icon, Name, Beschreibung] (Logik in inc/tpl/<schlüssel>.php) */
const PAGE_TEMPLATES = [
    'links'     => ['🔗', 'Link-Hub', 'Linksammlung mit Kategorien, Suche, Icons und Link-Check.'],
    'notes'     => ['📝', 'Notizen', 'Beliebig viele Notizen als Karten – Farben, Tags, Anheften, Suche.'],
    'checklist' => ['✅', 'Checkliste', 'Abhakbare Gruppen mit Fortschritt – z. B. Packliste.'],
    'kanban'    => ['🗂️', 'Kanban-Board', 'Spalten & Karten per Drag & Drop – Aufgaben, Projekte.'],
    'table'     => ['📊', 'Tabelle', 'Eigene Spalten (Zahl, €, Datum, ✓, ★), Summen, CSV-Export.'],
    'journal'   => ['📔', 'Tagebuch / Blog', 'Datierte Einträge mit Stimmung, Tags und Archiv.'],
    'gallery'   => ['🖼️', 'Galerie', 'Bilder hochladen oder verlinken, mit Großansicht.'],
    'counter'   => ['🔢', 'Zähler & Tracker', 'Zähler mit + / −, Ziel, Fortschritt und Verlauf.'],
    'embed'     => ['📺', 'Einbettungen', 'YouTube, Twitch, Spotify, Karten oder Webseiten einbetten.'],
    'external'  => ['🌐', 'Externer Link', 'Kachel im Index, die direkt zu einer anderen Webseite führt.'],
];

const PAGE_ACCESS = ['public' => '🌍 Öffentlich', 'users' => '🔑 Nur angemeldet', 'roles' => '🛡️ Nur bestimmte Rollen'];

/** Standardwerte der mitgelieferten Seiten: Datei => [Titel, Icon, Beschreibung, Kategorie, Zugriff] */
const PAGE_DEFAULTS = [
    '1linkseite.php'       => ['Linksammlung', '🔗', 'Tools, News & Ressourcen für Linux, Code, Gaming und mehr', 'Link-Hubs', 'public'],
    '2VRchat.php'          => ['VRChat Creator Hub', '🥽', 'Assets, Prefabs, Shader & Tutorials für VRChat-Creator', 'Link-Hubs', 'public'],
    '3hueter-Zentrale.php' => ['Hüter-Zentrale', '🛡️', 'Destiny 2: Builds, Datenbanken, God-Rolls & Guides', 'Link-Hubs', 'public'],
    'furry.php'            => ['Furry Community Hub', '🐾', 'Cons, Stammtische, Shops & Kunst aus der Community', 'Link-Hubs', 'public'],
    'calendar.php'         => ['Kalender', '📅', 'Termine, Kalender-Abos, Feiertage & Erinnerungen', 'Planung', 'public'],
    'countdown.php'        => ['Countdowns', '⏳', 'Countdowns bis Cons, Urlaub & Geburtstagen', 'Planung', 'public'],
    'feed.php'             => ['Live-Feed', '📡', 'Neuigkeiten aus Tech, Gaming & Furry-Fandom', 'Planung', 'public'],
    'budget.php'           => ['Budget', '💶', 'Monatsbudget, Fixkosten, Abos & Bank-Sync', 'Finanzen', 'users'],
    'con.php'              => ['Con-Rechner', '🎪', 'Budget & Planung für Conventions', 'Finanzen', 'users'],
    'nexus.php'            => ['VRC Nexus', '🥽', 'VRChat-Zentrale: Freunde, Verlauf, Statistik, Welten, Avatare & Gruppen – Direkt-Zugriff per Zwischenablage', 'Community', 'users'],
    'discord.php'          => ['Discord-Zentrale', '🤖', 'Discord-Server über deinen Bot: Slash-Befehle, Rollen-Knöpfe, Umfragen, Automatik, Mitglieder & Moderation', 'Community', 'users'],
    'nsfw.php'             => ['NSFW-Galerie', '🔞', 'Bilder-APIs (e621, Rule34, Danbooru, Gelbooru …) durchsuchen, Favoriten & Discord – 18+', 'Community', 'users'],
    'linkcheck.php'        => ['Link-Check', '🩺', 'Prüft alle Links der Hubs auf Erreichbarkeit', 'System', 'public'],
    'backup.php'           => ['Backup', '💾', 'Alle Daten als ZIP herunterladen', 'System', 'users'],
    'admin.php'            => ['Admin-Zentrale', '⚙️', 'Benutzer, Rechte, Seiten, Protokoll & System', 'System', 'public'],
];

/** Rechte, ohne die eine Dateiseite für angemeldete Benutzer ausgeblendet wird */
const PAGE_REQUIRED_PERMISSION = ['budget.php' => 'budget.access', 'con.php' => 'con.access', 'backup.php' => 'backup.download', 'nexus.php' => 'vrcx.access', 'discord.php' => 'discord.manage', 'nsfw.php' => 'nsfw.browse'];

/** Seiten, die nie im Register auftauchen (Hilfsdateien) */
const PAGE_IGNORE = ['index.php', 'page.php', 'preview.php', 'vrcx.php'];

/** Umbenannte Dateien: alter Name => neuer Name (Einstellungen der Kachel bleiben erhalten) */
const PAGE_RENAMES = ['vrcx.php' => 'nexus.php'];

function page_category_id(string $name): int {
    $db = site_db();
    $st = $db->prepare('SELECT id FROM page_categories WHERE name = ?');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $icons = ['Link-Hubs' => '🔗', 'Planung' => '🗓️', 'Finanzen' => '💶', 'System' => '⚙️', 'Eigene Seiten' => '✨', 'Weitere Seiten' => '📁', 'Community' => '🥽'];
    $db->prepare('INSERT INTO page_categories (name, icon, sort) VALUES (?, ?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM page_categories))')->execute([$name, $icons[$name] ?? '📁']);
    return (int)$db->lastInsertId();
}

/**
 * Register mit den Dateien im Ordner abgleichen: neue Dateien eintragen, gelöschte entfernen.
 * Läuft bei jedem Index- und Admin-Aufruf (schnell, nur ein Verzeichnis-Scan).
 */
function pages_sync(): void {
    $db = site_db();
    $root = dirname(__DIR__);
    $files = [];
    foreach (scandir($root) as $f) {
        if ($f[0] === '.' || in_array($f, PAGE_IGNORE, true) || !is_file("$root/$f")) continue;
        if (!preg_match('/\.(php|html?)$/i', $f)) continue;
        $files[] = $f;
    }
    foreach (PAGE_RENAMES as $old => $new) {
        if (!is_file("$root/$new")) continue;
        $db->prepare("UPDATE pages SET slug = ? WHERE slug = ? AND kind = 'file' AND NOT EXISTS (SELECT 1 FROM pages WHERE slug = ?)")->execute([$new, $old, $new]);
        if (isset(PAGE_DEFAULTS[$new])) {
            $db->prepare("UPDATE pages SET title = ?, description = ? WHERE slug = ? AND title IN ('VRCX', 'VRCX Lite')")->execute([PAGE_DEFAULTS[$new][0], PAGE_DEFAULTS[$new][2], $new]);
        }
    }
    $known = $db->query("SELECT slug FROM pages WHERE kind = 'file'")->fetchAll(PDO::FETCH_COLUMN);
    $first_run = !$known;
    $ins = $db->prepare("INSERT INTO pages (slug, kind, title, description, icon, category_id, sort, access) VALUES (?, 'file', ?, ?, ?, ?, ?, ?)");
    foreach ($files as $f) {
        if (in_array($f, $known, true)) continue;
        $name = pathinfo($f, PATHINFO_FILENAME);
        $sort = preg_match('/^(\d+)/', $name, $m) ? (int)$m[1] : 500;
        if (isset(PAGE_DEFAULTS[$f])) {
            [$title, $icon, $desc, $cat, $access] = PAGE_DEFAULTS[$f];
            $sort = (array_search($f, array_keys(PAGE_DEFAULTS), true) + 1) * 10;
        } else {
            $title = ucwords(str_replace(['_', '-'], ' ', preg_replace('/^\d+[\s_\-]*/', '', $name)));
            if (preg_match('#<title>(.*?)</title>#is', (string)file_get_contents("$root/$f"), $m)) $title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) ?: $title;
            [$icon, $desc, $cat, $access] = ['📄', '', 'Weitere Seiten', 'public'];
        }
        $ins->execute([$f, $title, $desc, $icon, page_category_id($cat), $sort, $access]);
    }
    $gone = array_diff($known, $files);
    if ($gone) {
        $del = $db->prepare("DELETE FROM pages WHERE kind = 'file' AND slug = ?");
        foreach ($gone as $g) $del->execute([$g]);
    }
    if ($first_run) {
        // Kategorien in sinnvolle Reihenfolge bringen
        foreach (['Link-Hubs', 'Planung', 'Community', 'Finanzen', 'Eigene Seiten', 'Weitere Seiten', 'System'] as $i => $n) {
            $db->prepare('UPDATE page_categories SET sort = ? WHERE name = ?')->execute([$i, $n]);
        }
    }
}

function page_categories(): array {
    return site_db()->query('SELECT * FROM page_categories ORDER BY sort, name')->fetchAll();
}

function page_get(string $slug): ?array {
    $st = site_db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function page_get_by_id(int $id): ?array {
    $st = site_db()->prepare('SELECT * FROM pages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Ziel-Adresse einer Seite */
function page_href(array $p): string {
    if ($p['kind'] === 'file') return $p['slug'];
    if ($p['template'] === 'external') return $p['url'];
    return 'page.php?p=' . rawurlencode($p['slug']);
}

/** Darf der aktuelle Benutzer die Seite im Index sehen? (Gäste sehen geschützte Seiten mit Schloss) */
function page_visible_in_index(array $p, bool $edit_mode): bool {
    if ($edit_mode) return true;
    if ($p['hidden']) return false;
    if (!is_logged_in()) return $p['access'] !== 'roles';   // Gäste: öffentliche + "nur angemeldet" (mit Schloss)
    if (!page_access_ok($p)) return false;
    $perm = PAGE_REQUIRED_PERMISSION[$p['slug']] ?? null;
    return !$perm || can($perm);
}

/** Aus einem Titel eine URL-taugliche Kennung machen */
function page_slugify(string $title): string {
    $s = mb_strtolower(trim($title));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = $s !== '' ? mb_substr($s, 0, 48) : 'seite';
    $base = $s;
    $i = 2;
    while (page_get($s) || is_file(dirname(__DIR__) . '/' . $s)) $s = $base . '-' . $i++;
    return $s;
}

/** Formularfelder -> Seiten-Datensatz (für Anlegen und Bearbeiten) */
function page_fields_from_post(array $p, ?array $existing = null): array {
    $title = mb_substr(trim($p['title'] ?? ''), 0, 80);
    if ($title === '') throw new InvalidArgumentException('Bitte einen Titel eingeben.');
    $access = array_key_exists($p['access'] ?? '', PAGE_ACCESS) ? $p['access'] : 'public';
    $roles = array_map('intval', (array)($p['roles'] ?? []));
    if ($access === 'roles' && !$roles) throw new InvalidArgumentException('Bitte mindestens eine Rolle auswählen (Administratoren haben immer Zugriff).');
    $cat = (int)($p['category_id'] ?? 0);
    $f = [
        'title' => $title,
        'description' => mb_substr(trim($p['description'] ?? ''), 0, 240),
        'icon' => mb_substr(trim($p['icon'] ?? ''), 0, 4),
        'category_id' => $cat > 0 ? $cat : null,
        'access' => $access,
        'allowed_roles' => $access === 'roles' ? implode(',', array_unique($roles)) : '',
        'hidden' => !empty($p['hidden']) ? 1 : 0,
        'show_live' => !empty($p['show_live']) ? 1 : 0,
    ];
    $template = $existing['template'] ?? ($p['template'] ?? '');
    if (($existing['kind'] ?? 'custom') === 'custom' && $template === 'external') {
        $url = trim($p['url'] ?? '');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if (safe_url($url) === '#' || !filter_var($url, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Bitte eine gültige Adresse (https://…) angeben.');
        $f['url'] = $url;
    }
    return $f;
}

function page_content(array $p): array {
    $c = json_decode($p['content'] ?: '{}', true);
    return is_array($c) ? $c : [];
}

function page_save_content(int $id, array $content): void {
    site_db()->prepare("UPDATE pages SET content = ?, updated_at = datetime('now','localtime') WHERE id = ?")
             ->execute([json_encode($content, JSON_UNESCAPED_UNICODE), $id]);
}

/** Startinhalt neuer Seiten je Vorlage */
function page_template_start(string $template): array {
    require_once __DIR__ . '/templates.php';
    return tpl_call($template, 'start') ?? [];
}

/** Inhalt einer eigenen Seite inkl. Umstellung alter Datenformate */
function page_content_normalized(array $p): array {
    require_once __DIR__ . '/templates.php';
    $c = page_content($p);
    return tpl_call($p['template'], 'normalize', $c, $p) ?? ($c ?: (tpl_call($p['template'], 'start') ?? []));
}

/** Kompatibilität: alte Aufrufe nutzen jetzt den gemeinsamen Formatierer */
function md_inline(string $s): string {
    require_once __DIR__ . '/format.php';
    return format_inline($s);
}

function markdown_to_html(string $md): string {
    require_once __DIR__ . '/format.php';
    return format_text($md);
}
