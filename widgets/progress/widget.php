<?php
// Fortschrittsbalken: Tag, Woche, Monat, Jahr – plus eigene Zeitspanne (z. B. Ausbildung, Projekt)
return [
    'name' => 'Zeit-Fortschritt',
    'icon' => '📊',
    'description' => 'Wie viel vom Tag, der Woche, dem Monat und dem Jahr schon vorbei ist – plus eine eigene Zeitspanne.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'own_name' => ['label' => 'Eigene Zeitspanne: Name (optional)', 'type' => 'text', 'default' => ''],
        'own_start' => ['label' => 'Beginn (JJJJ-MM-TT)', 'type' => 'text', 'default' => ''],
        'own_end' => ['label' => 'Ende (JJJJ-MM-TT)', 'type' => 'text', 'default' => ''],
        'workday' => ['label' => 'Arbeitstag statt ganzem Tag (z. B. 08:00-16:30, leer = 0–24 Uhr)', 'type' => 'text', 'default' => ''],
    ],
    'render' => function (WidgetCtx $w): string {
        $own = '';
        $s = strtotime((string)$w->cfg['own_start']); $en = strtotime((string)$w->cfg['own_end']);
        if ($w->cfg['own_name'] !== '' && $s && $en && $en > $s) $own = ' data-own="' . e(json_encode([$w->cfg['own_name'], $s, $en + 86399], JSON_UNESCAPED_UNICODE)) . '"';
        $wd = preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim((string)$w->cfg['workday']), $m) ? ' data-work="' . ((int)$m[1] * 60 + (int)$m[2]) . '-' . ((int)$m[3] * 60 + (int)$m[4]) . '"' : '';
        return '<div class="pg"' . $own . $wd . '></div>';
    },
];
