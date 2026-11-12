<?php
// Zufallsgenerator: Würfel, Münze, Zahl, Auswahl aus einer Liste – läuft komplett im Browser
return [
    'name' => 'Zufall',
    'icon' => '🎲',
    'description' => 'Würfeln (W4 bis W100), Münze werfen, Zufallszahl oder zufällige Auswahl aus deiner Liste.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'choices' => ['label' => 'Auswahl-Liste (eine Option pro Zeile)', 'type' => 'textarea', 'default' => "Pizza\nSushi\nDöner\nBurger"],
    ],
    'render' => function (WidgetCtx $w): string {
        $choices = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$w->cfg['choices']))));
        return '<div class="rn" data-choices="' . e(json_encode($choices, JSON_UNESCAPED_UNICODE)) . '"><div class="rn-out">🎲</div><div class="rn-sub">Klick auf einen Knopf</div>'
            . '<div class="rn-btns">' . implode('', array_map(function ($d) { return '<button type="button" class="btn btn-sm" data-dice="' . $d . '">W' . $d . '</button>'; }, [4, 6, 8, 10, 12, 20, 100]))
            . '<button type="button" class="btn btn-sm" data-coin>🪙 Münze</button>' . ($choices ? '<button type="button" class="btn btn-sm btn-gold" data-pick>🤔 Auswahl</button>' : '') . '</div>'
            . '<form class="rn-range"><input type="number" name="a" value="1"><span>bis</span><input type="number" name="b" value="100"><button class="btn btn-sm">Zahl</button></form></div>';
    },
];
