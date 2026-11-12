<?php
// Zitat des Tages – wechselt täglich (oder bei jedem Laden), eigene Zitate möglich
const WG_QUOTES = [
    ['Die beste Zeit, einen Baum zu pflanzen, war vor 20 Jahren. Die zweitbeste ist jetzt.', 'Chinesisches Sprichwort'],
    ['Es ist nicht wenig Zeit, die wir haben, sondern es ist viel Zeit, die wir nicht nutzen.', 'Seneca'],
    ['Phantasie ist wichtiger als Wissen, denn Wissen ist begrenzt.', 'Albert Einstein'],
    ['Wer immer tut, was er schon kann, bleibt immer das, was er schon ist.', 'Henry Ford'],
    ['Der Weg ist das Ziel.', 'Konfuzius'],
    ['Man muss das Unmögliche versuchen, um das Mögliche zu erreichen.', 'Hermann Hesse'],
    ['Auch aus Steinen, die einem in den Weg gelegt werden, kann man Schönes bauen.', 'Johann Wolfgang von Goethe'],
    ['Glück ist das Einzige, was sich verdoppelt, wenn man es teilt.', 'Albert Schweitzer'],
    ['Talk is cheap. Show me the code.', 'Linus Torvalds'],
    ['Erst wenn man in Pfoten läuft, versteht man den Weg.', 'Furry-Weisheit'],
    ['Eine Reise von tausend Meilen beginnt mit dem ersten Schritt.', 'Laozi'],
    ['Mut steht am Anfang des Handelns, Glück am Ende.', 'Demokrit'],
    ['Das Leben ist wie Fahrradfahren. Um die Balance zu halten, musst du in Bewegung bleiben.', 'Albert Einstein'],
    ['Sei du selbst die Veränderung, die du dir wünschst für diese Welt.', 'Mahatma Gandhi'],
    ['Wer kämpft, kann verlieren. Wer nicht kämpft, hat schon verloren.', 'Bertolt Brecht'],
    ['Kreativität ist Intelligenz, die Spaß hat.', 'Albert Einstein'],
    ['Programme müssen für Menschen geschrieben werden und nur nebenbei für Maschinen.', 'Harold Abelson'],
    ['Nicht weil es schwer ist, wagen wir es nicht, sondern weil wir es nicht wagen, ist es schwer.', 'Seneca'],
    ['In der Ruhe liegt die Kraft.', 'Sprichwort'],
    ['Jeder Tag ist eine neue Chance, das zu tun, was du möchtest.', 'Friedrich Schiller'],
];
return [
    'name' => 'Zitat des Tages',
    'icon' => '💬',
    'description' => 'Ein inspirierendes Zitat – täglich wechselnd. Eigene Zitate lassen sich in den Einstellungen ergänzen.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'settings' => [
        'mode' => ['label' => 'Wechsel', 'type' => 'select', 'default' => 'day', 'options' => ['day' => 'einmal am Tag', 'load' => 'bei jedem Laden']],
        'own' => ['label' => 'Eigene Zitate (eins pro Zeile, Autor nach „ – “)', 'type' => 'textarea', 'default' => ''],
        'only_own' => ['label' => 'Nur eigene Zitate verwenden', 'type' => 'checkbox', 'default' => false],
    ],
    'render' => function (WidgetCtx $w): string {
        $own = [];
        foreach (preg_split('/\R/u', (string)$w->cfg['own']) as $l) {
            $l = trim($l);
            if ($l === '') continue;
            $p = preg_split('/\s+[–-]\s+(?=[^–-]+$)/u', $l);
            $own[] = [$p[0], $p[1] ?? ''];
        }
        $list = $w->cfg['only_own'] && $own ? $own : array_merge(WG_QUOTES, $own);
        $i = $w->cfg['mode'] === 'load' ? random_int(0, count($list) - 1) : (int)date('z') % count($list);
        [$q, $a] = $list[$i];
        return '<figure class="qt"><blockquote>' . e($q) . '</blockquote>' . ($a !== '' ? '<figcaption>— ' . e($a) . '</figcaption>' : '') . '</figure>';
    },
];
