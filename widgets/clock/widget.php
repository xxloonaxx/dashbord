<?php
// Uhr & Datum mit Kalenderwoche, Tag im Jahr und optionaler zweiter Zeitzone
$zones = ['' => '– keine –', 'UTC' => 'UTC', 'Europe/London' => 'London', 'America/New_York' => 'New York', 'America/Los_Angeles' => 'Los Angeles (VRChat-Abend 🇺🇸)',
          'America/Chicago' => 'Chicago', 'Asia/Tokyo' => 'Tokio', 'Australia/Sydney' => 'Sydney', 'Asia/Shanghai' => 'Shanghai', 'Europe/Moscow' => 'Moskau'];
return [
    'name' => 'Uhr & Datum',
    'icon' => '🕒',
    'description' => 'Große Uhr mit Datum, Kalenderwoche, Tag im Jahr und einer zweiten Zeitzone.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'seconds' => ['label' => 'Sekunden anzeigen', 'type' => 'checkbox', 'default' => true],
        'zone2' => ['label' => 'Zweite Zeitzone', 'type' => 'select', 'default' => 'America/Los_Angeles', 'options' => $zones],
        'zone2_label' => ['label' => 'Name der zweiten Zone (optional)', 'type' => 'text', 'default' => ''],
    ],
    'render' => function (WidgetCtx $w) use ($zones): string {
        $z = (string)$w->cfg['zone2'];
        return '<div class="ck" data-seconds="' . ($w->cfg['seconds'] ? '1' : '') . '" data-zone2="' . e($z) . '">'
            . '<div class="ck-time">--:--</div><div class="ck-date"></div><div class="ck-meta"></div>'
            . ($z !== '' ? '<div class="ck-zone"><span>' . e($w->cfg['zone2_label'] ?: preg_replace('/\s*\(.*$/', '', $zones[$z] ?? $z)) . '</span><b class="ck-z2">--:--</b></div>' : '')
            . '</div>';
    },
];
