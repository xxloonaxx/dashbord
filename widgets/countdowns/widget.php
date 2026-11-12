<?php
// Die nächsten Countdowns mit laufender Restzeit
require_once dirname(__DIR__, 2) . '/inc/modules/countdown.php';
return [
    'name' => 'Countdowns',
    'icon' => '⏳',
    'description' => 'Die nächsten Countdowns (Cons, Urlaub, Geburtstage) mit live laufender Restzeit.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'count' => ['label' => 'Anzahl', 'type' => 'number', 'default' => 4, 'min' => 1, 'max' => 12],
        'big' => ['label' => 'Ersten Countdown groß anzeigen', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        [$up] = sorted_countdowns(load_countdowns()['events']);
        $up = array_slice($up, 0, (int)$w->cfg['count']);
        if (!$up) return '<div class="wg-empty">Keine anstehenden Countdowns. <a href="countdown.php">Anlegen →</a></div>';
        $h = '';
        foreach ($up as $i => $c) {
            $big = $i === 0 && $w->cfg['big'];
            $h .= '<a class="cd' . ($big ? ' big' : '') . '" href="countdown.php"><span class="cd-ic">' . e($c['icon']) . '</span><span class="cd-b"><b>' . e($c['title']) . '</b>'
                . '<small>' . e(date('d.m.Y', $c['ts'])) . '</small></span><span class="cd-left" data-ts="' . (int)$c['ts'] . '"></span></a>';
        }
        return $h;
    },
];
