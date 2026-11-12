<?php
// Pomodoro-/Fokus-Timer – läuft im Browser, merkt sich den Stand auch nach dem Neuladen
return [
    'name' => 'Fokus-Timer',
    'icon' => '🍅',
    'description' => 'Pomodoro-Timer mit Arbeits- und Pausenphasen, Signalton, Benachrichtigung und Tageszähler.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'work' => ['label' => 'Arbeitsphase (Minuten)', 'type' => 'number', 'default' => 25, 'min' => 1, 'max' => 180],
        'short' => ['label' => 'Kurze Pause (Minuten)', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 60],
        'long' => ['label' => 'Lange Pause (Minuten)', 'type' => 'number', 'default' => 15, 'min' => 1, 'max' => 90],
        'sound' => ['label' => 'Signalton', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        return '<div class="pd" data-work="' . (int)$w->cfg['work'] . '" data-short="' . (int)$w->cfg['short'] . '" data-long="' . (int)$w->cfg['long'] . '" data-sound="' . ($w->cfg['sound'] ? 1 : 0) . '">'
            . '<svg class="pd-ring" viewBox="0 0 100 100"><circle cx="50" cy="50" r="44" class="bg"/><circle cx="50" cy="50" r="44" class="fg"/></svg>'
            . '<div class="pd-mid"><b class="pd-time">25:00</b><span class="pd-phase">Arbeit</span></div>'
            . '<div class="pd-btns"><button type="button" class="btn btn-sm btn-gold" data-pd="start">▶ Start</button><button type="button" class="btn btn-sm" data-pd="skip">⏭</button><button type="button" class="btn btn-sm" data-pd="reset">↺</button></div>'
            . '<div class="pd-count"></div></div>';
    },
];
