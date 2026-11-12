<?php
// Eine einzelne Quelle (oder Kategorie) aus dem RSS-Reader – mit Vorschaubildern
require_once dirname(__DIR__, 2) . '/inc/modules/feed.php';
$opts = ['' => '– alle Quellen –'];
try {
    foreach (feed_db()->query('SELECT id, title, category FROM sources ORDER BY category, title')->fetchAll() as $s) $opts['s' . $s['id']] = $s['category'] . ' › ' . $s['title'];
    foreach (feed_db()->query('SELECT DISTINCT category FROM sources ORDER BY category')->fetchAll(PDO::FETCH_COLUMN) as $c) $opts['c' . $c] = '📁 Kategorie: ' . $c;
} catch (Throwable $e) { /* Reader noch nicht eingerichtet */ }
return [
    'name' => 'RSS-Quelle',
    'icon' => '📰',
    'description' => 'Zeigt die neuesten Artikel einer bestimmten Quelle oder Kategorie aus dem RSS-Reader – mit Bildern.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'refresh' => 900,
    'settings' => [
        'src' => ['label' => 'Quelle oder Kategorie', 'type' => 'select', 'default' => '', 'options' => $opts],
        'count' => ['label' => 'Anzahl', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 20],
        'images' => ['label' => 'Vorschaubilder', 'type' => 'checkbox', 'default' => true],
        'unread' => ['label' => 'Nur ungelesene (für angemeldete)', 'type' => 'checkbox', 'default' => false],
    ],
    'render' => function (WidgetCtx $w): string {
        $src = (string)$w->cfg['src'];
        $f = ['view' => 'all', 'limit' => (int)$w->cfg['count']];
        if ($src !== '' && $src[0] === 's') $f['source'] = (int)substr($src, 1);
        if ($src !== '' && $src[0] === 'c') $f['category'] = substr($src, 1);
        if ($w->cfg['unread'] && is_logged_in()) $f['unread_only'] = 1;
        $r = feed_items($f);
        if (!$r['items']) return '<div class="wg-empty">Keine Artikel. <a href="feed.php">Zum Reader →</a></div>';
        $h = '';
        foreach (array_slice($r['items'], 0, (int)$w->cfg['count']) as $i => $it) {
            $img = $w->cfg['images'] && $it['image'] ? '<img src="' . e($it['image']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">' : '';
            $h .= '<a class="rs' . ($i === 0 && $img ? ' first' : '') . ($it['read'] ? ' read' : '') . '" href="' . e($it['link']) . '" target="_blank" rel="noopener noreferrer">' . $img
                . '<span><b>' . e($it['title']) . '</b><small>' . e(($it['icon'] ? $it['icon'] . ' ' : '') . $it['source']) . ' · ' . e(date('d.m. H:i', $it['ts'])) . '</small></span></a>';
        }
        return $h . '<a href="feed.php" class="wg-more">Im Reader öffnen →</a>';
    },
];
