<?php
// VRChat-Freunde, die gerade online sind (aus dem Zwischenspeicher von VRC Nexus – keine eigene Abfrage bei VRChat)
require_once dirname(__DIR__, 2) . '/inc/modules/vrchat.php';
return [
    'name' => 'VRChat-Freunde',
    'icon' => '🥽',
    'description' => 'Wer ist gerade in VRChat online und wo? Daten aus VRC Nexus (aktualisiert, wenn VRC Nexus offen ist).',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'permission' => 'vrcx.access',
    'refresh' => 120,
    'settings' => [
        'count' => ['label' => 'Anzahl', 'type' => 'number', 'default' => 8, 'min' => 1, 'max' => 30],
        'favorites' => ['label' => 'Favoriten zuerst', 'type' => 'checkbox', 'default' => true],
        'web' => ['label' => 'Auch „Web/App online“ zeigen', 'type' => 'checkbox', 'default' => false],
    ],
    'render' => function (WidgetCtx $w): string {
        $uid = (int)(current_user()['id'] ?? 0);
        if (!vrc_account($uid)) return '<div class="wg-empty">VRChat ist nicht verbunden. <a href="nexus.php">VRC Nexus öffnen →</a></div>';
        $list = array_values(array_filter(vrc_friend_list($uid), function ($f) use ($w) { return $f['state'] === 'online' || ($w->cfg['web'] && $f['state'] === 'active'); }));
        usort($list, function ($a, $b) use ($w) { return [$w->cfg['favorites'] && !$a['favorite'], $a['state'] !== 'online', mb_strtolower($a['name'])] <=> [$w->cfg['favorites'] && !$b['favorite'], $b['state'] !== 'online', mb_strtolower($b['name'])]; });
        $h = '<div class="vw-sum">' . e(vrc_live_info($uid)) . '</div>';
        $dot = ['join me' => '#42caff', 'active' => '#51e57e', 'ask me' => '#e88134', 'busy' => '#5b0b0b'];
        foreach (array_slice($list, 0, (int)$w->cfg['count']) as $f) {
            $where = $f['loc']['world_name'] ?? '';
            $where = $f['state'] !== 'online' ? 'Web/App' : ($where !== '' ? $where : (($f['loc']['type'] ?? '') === 'private' ? 'Privat' : '–'));
            $h .= '<a class="vw-f" href="nexus.php#user=' . e($f['id']) . '"><span class="vw-dot" style="background:' . e($dot[$f['status']] ?? '#888') . '"></span>'
                . '<span class="vw-n">' . ($f['favorite'] ? '⭐ ' : '') . e($f['name']) . '<small>' . e($where) . '</small></span></a>';
        }
        if (!$list) $h .= '<div class="wg-empty">Gerade niemand online.</div>';
        return $h;
    },
];
