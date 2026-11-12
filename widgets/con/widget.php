<?php
// Aktuelle Con aus dem Con-Rechner: Countdown, Budget, offene Zahlungen
require_once dirname(__DIR__, 2) . '/inc/con.php';
return [
    'name' => 'Nächste Con',
    'icon' => '🎪',
    'description' => 'Die aktive Con aus dem Con-Rechner: Tage bis zur Con, Budget-Rest, offene Zahlungen und Packliste.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'permission' => 'con.access',
    'render' => function (WidgetCtx $w): string {
        $data = con_load();
        $c = null;
        foreach ($data['cons'] as $x) if ($x['id'] === $data['active']) $c = con_fill($x);
        if (!$c) return '<div class="wg-empty">Noch keine Con geplant. <a href="con.php">Con-Rechner →</a></div>';
        $r = con_calc($c);
        $d = $r['days'];
        $big = $d['phase'] === 'before' ? $d['until'] . ' <small>Tage</small>' : ($d['phase'] === 'running' ? 'Tag ' . $d['day_no'] : '✓');
        $sub = $d['phase'] === 'before' ? 'bis ' . e($c['name']) : ($d['phase'] === 'running' ? e($c['name']) . ' läuft! 🎉' : e($c['name']) . ' ist vorbei');
        $pack = (array)($c['packing'] ?? []);
        $packed = count(array_filter($pack, function ($p) { return !empty($p['done']); }));
        $h = '<a href="con.php" class="cw"><div class="cw-top"><span class="cw-ic">' . e($c['icon']) . '</span><div><b class="cw-big">' . $big . '</b><small>' . $sub . '</small></div></div>'
           . ($c['start'] ? '<div class="cw-row">📅 ' . e(date('d.m.', strtotime($c['start']))) . ($c['end'] && $c['end'] !== $c['start'] ? ' – ' . e(date('d.m.Y', strtotime($c['end']))) : '') . ($c['city'] ? ' · 📍 ' . e($c['city']) : '') . '</div>' : '');
        if ($r['budget'] > 0) $h .= '<div class="cw-row"><span>💶 Budget</span><b>' . e(con_money($r['my'])) . ' / ' . e(con_money($r['budget'])) . '</b></div><div class="cw-bar"><i style="width:' . min(100, round($r['ratio'])) . '%" class="' . ($r['ratio'] > 100 ? 'over' : ($r['ratio'] > 85 ? 'warn' : '')) . '"></i></div>';
        if ($r['dues']) $h .= '<div class="cw-row warn">⏳ ' . count($r['dues']) . ' offene Zahlung(en)' . ($r['dues'][0]['due'] ? ' · nächste ' . e(date('d.m.', strtotime($r['dues'][0]['due']))) : '') . '</div>';
        if ($pack) $h .= '<div class="cw-row"><span>🎒 Packliste</span><b>' . $packed . ' / ' . count($pack) . '</b></div>';
        return $h . '</a>';
    },
];
