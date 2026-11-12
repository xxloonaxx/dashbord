<?php
// VRChat live über den Desktop-Client: aktuelle Welt, Spieler in der Instanz, Chatbox & Schnellknöpfe per OSC
require_once dirname(__DIR__, 2) . '/inc/modules/nexus_client.php';
return [
    'name' => 'VRChat live (Desktop-Client)',
    'icon' => '🖥️',
    'description' => 'Was gerade in VRChat los ist – Welt, Spieler, Avatar – plus Chatbox und OSC-Schnellknöpfe. Braucht den Nexus-Desktop-Client.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'permission' => 'vrcx.access',
    'refresh' => 30,
    'settings' => [
        'players' => ['label' => 'Spieler anzeigen', 'type' => 'number', 'default' => 12, 'min' => 0, 'max' => 40],
        'chat' => ['label' => 'Chatbox-Feld', 'type' => 'checkbox', 'default' => true],
        'presets' => ['label' => 'Schnellknöpfe', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        $uid = (int)(current_user()['id'] ?? 0);
        $s = nc_state($uid);
        if (!nc_tokens($uid)) return '<div class="wg-empty">Noch kein Desktop-Client verbunden. <a href="nexus.php">VRC Nexus → 🖥️ Client →</a></div>';
        $h = '<div class="vl">';
        if (!$s || !$s['fresh']) {
            $h .= '<div class="vl-off">⚪ Client offline' . ($s ? ' · zuletzt ' . e(date('d.m. H:i', $s['updated'])) : '') . '</div>';
        } elseif (empty($s['running'])) {
            $h .= '<div class="vl-off">🟢 Client verbunden · VRChat ist zu</div>';
        } else {
            $mins = $s['since'] ? (int)floor((time() - $s['since']) / 60) : 0;
            $h .= '<div class="vl-world">🌐 <b>' . e($s['world_name'] ?: 'Unterwegs …') . '</b><small>' . ($mins >= 60 ? floor($mins / 60) . ' h ' . ($mins % 60) . ' min' : $mins . ' min')
                . ' · ' . count($s['players']) . ' Spieler' . ($s['avatar'] ? ' · 🎭 ' . e($s['avatar']) : '') . '</small></div>';
            if ((int)$w->cfg['players'] > 0 && $s['players']) {
                $names = array_column($s['players'], 'name');
                natcasesort($names);
                $h .= '<div class="vl-pl">' . implode('', array_map(function ($n) { return '<span>' . e($n) . '</span>'; }, array_slice($names, 0, (int)$w->cfg['players'])))
                    . (count($names) > (int)$w->cfg['players'] ? '<span class="vl-more">+' . (count($names) - (int)$w->cfg['players']) . '</span>' : '') . '</div>';
            }
        }
        if ($w->cfg['chat']) $h .= '<form class="vl-chat" data-wg-action="chat"><input type="text" name="text" maxlength="144" placeholder="💬 An die VRChat-Chatbox …" required><button class="btn btn-sm">Senden</button></form>';
        if ($w->cfg['presets'] && ($p = nc_presets($uid))) {
            $h .= '<div class="vl-pre">' . implode('', array_map(function ($x) { return '<button type="button" class="btn btn-sm" data-wg-action="preset" data-id="' . (int)$x['id'] . '">' . ($x['kind'] === 'chatbox' ? '💬 ' : ($x['kind'] === 'param' ? '🎛️ ' : '🎮 ')) . e($x['label']) . '</button>'; }, array_slice($p, 0, 12))) . '</div>';
        }
        return $h . '<a href="nexus.php" class="wg-more">VRC Nexus →</a></div>';
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        $uid = (int)(current_user()['id'] ?? 0);
        if ($op === 'chat') { nc_osc_enqueue($uid, 'chatbox', ['text' => (string)($in['text'] ?? ''), 'sound' => true]); return 'An die Chatbox geschickt.'; }
        if ($op === 'preset') {
            foreach (nc_presets($uid) as $p) if ($p['id'] === (int)($in['id'] ?? 0)) { nc_osc_enqueue($uid, $p['kind'], $p['payload']); return '„' . $p['label'] . '“ gesendet.'; }
            throw new RuntimeException('Schnellknopf nicht gefunden.');
        }
        return null;
    },
];
