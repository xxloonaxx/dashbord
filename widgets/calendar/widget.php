<?php
// Mini-Monatskalender mit Termin-Punkten und Liste der nächsten Termine
require_once dirname(__DIR__, 2) . '/inc/modules/calendar.php';
return [
    'name' => 'Kalender',
    'icon' => '📅',
    'description' => 'Mini-Monatsansicht mit markierten Tagen und den nächsten Terminen (inkl. Feiertage & Abos).',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'refresh' => 900,
    'settings' => [
        'count' => ['label' => 'Nächste Termine', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 15],
        'days' => ['label' => 'Wie weit voraus (Tage)', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 365],
        'holidays' => ['label' => 'Feiertage zeigen', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        $priv = can('calendar.private') || can('calendar.edit');
        $today = new DateTimeImmutable('today');
        $first = $today->modify('first day of this month');
        $last = $today->modify('last day of this month');
        $layers = $w->cfg['holidays'] ? ['events', 'holidays', 'countdowns'] : ['events', 'countdowns'];
        $month = cal_items($first, $last, $priv, $layers);
        $marks = [];
        foreach ($month as $it) {
            for ($d = $it['start']->setTime(0, 0); $d <= $it['end'] && $d <= $last; $d = $d->modify('+1 day')) {
                if ($d >= $first) $marks[$d->format('j')][] = $it['color'] ?? '#38d5e6';
            }
        }
        $wd = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $mn = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $h = '<div class="mc"><div class="mc-title"><a href="calendar.php">' . $mn[(int)$today->format('n')] . ' ' . $today->format('Y') . '</a></div><div class="mc-grid">';
        foreach ($wd as $x) $h .= '<span class="mc-wd">' . $x . '</span>';
        for ($i = 1; $i < (int)$first->format('N'); $i++) $h .= '<span></span>';
        for ($d = 1; $d <= (int)$last->format('j'); $d++) {
            $dots = array_slice(array_unique($marks[$d] ?? []), 0, 3);
            $h .= '<a href="calendar.php?view=day&amp;date=' . $today->format('Y-m-') . sprintf('%02d', $d) . '" class="mc-d' . ($d === (int)$today->format('j') ? ' today' : '') . ($dots ? ' has' : '') . '">' . $d
                . ($dots ? '<i>' . implode('', array_map(function ($c) { return '<b style="background:' . e($c) . '"></b>'; }, $dots)) . '</i>' : '') . '</a>';
        }
        $h .= '</div></div>';
        if ((int)$w->cfg['count'] > 0) {
            $now = new DateTimeImmutable();
            $next = array_values(array_filter(cal_items($today, $today->modify('+' . (int)$w->cfg['days'] . ' days'), $priv, $layers), function ($it) use ($now) { return $it['end'] >= $now && empty($it['cancelled']); }));
            $h .= '<div class="mc-list">';
            foreach (array_slice($next, 0, (int)$w->cfg['count']) as $it) {
                $s = $it['start'];
                $when = $s->format('Y-m-d') === $today->format('Y-m-d') ? 'Heute' : ($s->format('Y-m-d') === $today->modify('+1 day')->format('Y-m-d') ? 'Morgen' : $s->format('d.m.'));
                $h .= '<div class="mc-ev" style="--c:' . e($it['color'] ?? '#38d5e6') . '"><span>' . $when . (!empty($it['all_day']) ? '' : '<small>' . $s->format('H:i') . '</small>') . '</span><b>' . e(trim(($it['icon'] ?? '') . ' ' . $it['title'])) . '</b></div>';
            }
            if (!$next) $h .= '<div class="wg-empty">Keine Termine.</div>';
            $h .= '</div>';
        }
        return '<div class="mc-wrap">' . $h . '</div>';
    },
];
