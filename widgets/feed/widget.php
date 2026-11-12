<?php
// Neueste Meldungen aus dem Live-Feed (feed_cache.json)
return [
    'name' => 'Neuigkeiten',
    'icon' => '📡',
    'description' => 'Die neuesten Meldungen aus dem Live-Feed – optional nach Quelle oder Stichwort gefiltert.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'refresh' => 600,
    'settings' => [
        'count' => ['label' => 'Anzahl', 'type' => 'number', 'default' => 6, 'min' => 1, 'max' => 20],
        'filter' => ['label' => 'Nur mit diesem Stichwort / dieser Quelle (optional)', 'type' => 'text', 'default' => ''],
        'desc' => ['label' => 'Kurzbeschreibung zeigen', 'type' => 'checkbox', 'default' => false],
    ],
    'render' => function (WidgetCtx $w): string {
        $file = dirname(__DIR__, 2) . '/feed_cache.json';
        $items = load_json($file, []);
        $f = mb_strtolower(trim((string)$w->cfg['filter']));
        if ($f !== '') $items = array_values(array_filter($items, function ($i) use ($f) { return mb_strpos(mb_strtolower(($i['title'] ?? '') . ' ' . ($i['source'] ?? '') . ' ' . ($i['category'] ?? '')), $f) !== false; }));
        usort($items, function ($a, $b) { return ($b['date'] ?? 0) <=> ($a['date'] ?? 0); });
        if (!$items) return '<div class="wg-empty">Keine Meldungen' . ($f !== '' ? ' zu „' . e($f) . '“' : '') . '. <a href="feed.php">Feed öffnen →</a></div>';
        $h = '';
        foreach (array_slice($items, 0, (int)$w->cfg['count']) as $i) {
            $url = preg_match('#^https?://#', (string)($i['link'] ?? '')) ? $i['link'] : 'feed.php';
            $ago = time() - (int)($i['date'] ?? time());
            $ago = $ago < 3600 ? max(1, round($ago / 60)) . ' min' : ($ago < 86400 ? round($ago / 3600) . ' Std.' : round($ago / 86400) . ' T.');
            $h .= '<a class="fw" href="' . e($url) . '" target="_blank" rel="noopener noreferrer"><b>' . e($i['title'] ?? '') . '</b>'
                . ($w->cfg['desc'] && !empty($i['description']) ? '<span>' . e(mb_strimwidth((string)$i['description'], 0, 140, '…')) . '</span>' : '')
                . '<small>' . e($i['source'] ?? '') . ' · vor ' . $ago . '</small></a>';
        }
        return $h . '<a href="feed.php" class="wg-more">Alle Meldungen →</a>';
    },
];
