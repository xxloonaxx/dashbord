<?php
// Bilderrahmen: eigene Bilder-Adressen (z. B. Fursuit-Fotos, Ref-Sheets) als Diashow
return [
    'name' => 'Bilderrahmen',
    'icon' => '🖼️',
    'description' => 'Zeigt eigene Bilder (Adressen oder hochgeladene Bilder aus den Seiten) als Diashow mit Überblendung.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 'm',
    'settings' => [
        'images' => ['label' => 'Bild-Adressen (eine pro Zeile, https://… oder uploads/…)', 'type' => 'textarea', 'default' => ''],
        'uploads' => ['label' => 'Auch hochgeladene Bilder (Ordner uploads/) verwenden', 'type' => 'checkbox', 'default' => true],
        'seconds' => ['label' => 'Sekunden pro Bild', 'type' => 'number', 'default' => 8, 'min' => 3, 'max' => 120],
        'fit' => ['label' => 'Darstellung', 'type' => 'select', 'default' => 'cover', 'options' => ['cover' => 'füllend (beschnitten)', 'contain' => 'ganz sichtbar']],
        'height' => ['label' => 'Höhe in Pixeln', 'type' => 'number', 'default' => 220, 'min' => 100, 'max' => 600],
    ],
    'render' => function (WidgetCtx $w): string {
        $list = [];
        foreach (preg_split('/\R/u', (string)$w->cfg['images']) as $l) { $l = trim($l); if (preg_match('#^(https://\S+|uploads/[\w.\-]+)$#i', $l)) $list[] = $l; }
        if ($w->cfg['uploads']) foreach (glob(dirname(__DIR__, 2) . '/uploads/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [] as $f) $list[] = 'uploads/' . basename($f);
        $list = array_values(array_unique($list));
        if (!$list) return '<div class="wg-empty">Noch keine Bilder – im ⚙ Bild-Adressen eintragen oder in einer Galerie-Seite Bilder hochladen.</div>';
        shuffle($list);
        return '<div class="ph" style="height:' . (int)$w->cfg['height'] . 'px" data-sec="' . (int)$w->cfg['seconds'] . '" data-fit="' . e($w->cfg['fit']) . '" data-imgs="' . e(json_encode(array_slice($list, 0, 100), JSON_UNESCAPED_SLASHES)) . '">'
             . '<img alt="" referrerpolicy="no-referrer"><img alt="" referrerpolicy="no-referrer"><span class="ph-n"></span><button type="button" class="ph-prev" aria-label="Zurück">‹</button><button type="button" class="ph-next" aria-label="Weiter">›</button></div>';
    },
];
