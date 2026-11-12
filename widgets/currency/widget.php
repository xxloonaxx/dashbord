<?php
// Währungsrechner mit Tageskursen der EZB (frankfurter.app, ohne Schlüssel, 6 Std. zwischengespeichert)
const WG_CUR = ['EUR' => '🇪🇺 Euro', 'USD' => '🇺🇸 US-Dollar', 'GBP' => '🇬🇧 Pfund', 'CHF' => '🇨🇭 Franken', 'JPY' => '🇯🇵 Yen', 'CAD' => '🇨🇦 Kanad. Dollar', 'AUD' => '🇦🇺 Austr. Dollar',
                'PLN' => '🇵🇱 Złoty', 'CZK' => '🇨🇿 Krone (CZ)', 'SEK' => '🇸🇪 Krone (SE)', 'NOK' => '🇳🇴 Krone (NO)', 'DKK' => '🇩🇰 Krone (DK)', 'TRY' => '🇹🇷 Lira', 'CNY' => '🇨🇳 Yuan', 'KRW' => '🇰🇷 Won', 'HUF' => '🇭🇺 Forint'];
return [
    'name' => 'Währungsrechner',
    'icon' => '💱',
    'description' => 'Beträge umrechnen mit den Tageskursen der Europäischen Zentralbank – z. B. für Con-Reisen.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'base' => ['label' => 'Ausgangswährung', 'type' => 'select', 'default' => 'EUR', 'options' => WG_CUR],
        'show' => ['label' => 'Kurse anzeigen für (Kürzel, mit Komma)', 'type' => 'text', 'default' => 'USD, GBP, CHF, JPY'],
    ],
    'render' => function (WidgetCtx $w): string {
        $base = (string)$w->cfg['base'];
        $j = $w->cached_json('https://api.frankfurter.app/latest?from=' . rawurlencode($base), 6 * 3600);
        $rates = (array)($j['rates'] ?? []) + [$base => 1.0];
        $show = array_values(array_filter(array_map('trim', explode(',', strtoupper((string)$w->cfg['show']))), function ($c) use ($rates) { return isset($rates[$c]); }));
        $opts = function ($sel) use ($rates) { return implode('', array_map(function ($c) use ($sel) { return '<option value="' . e($c) . '"' . ($c === $sel ? ' selected' : '') . '>' . e($c . (isset(WG_CUR[$c]) ? ' ' . WG_CUR[$c] : '')) . '</option>'; }, array_keys(array_intersect_key(WG_CUR, $rates) + $rates))); };
        $h = '<div class="cx" data-rates="' . e(json_encode($rates)) . '" data-base="' . e($base) . '">'
           . '<div class="cx-in"><input type="number" step="any" value="100" class="cx-amt" aria-label="Betrag"><select class="cx-from" aria-label="Von">' . $opts($base) . '</select></div>'
           . '<button type="button" class="cx-swap" title="Tauschen">⇅</button>'
           . '<div class="cx-in"><output class="cx-out">–</output><select class="cx-to" aria-label="Nach">' . $opts($show[0] ?? 'USD') . '</select></div>';
        if ($show) $h .= '<div class="cx-list">' . implode('', array_map(function ($c) use ($rates, $base) { return '<span>1 ' . e($base) . ' = <b>' . e(number_format((float)$rates[$c], $rates[$c] >= 100 ? 0 : 4, ',', '.')) . '</b> ' . e($c) . '</span>'; }, $show)) . '</div>';
        return $h . '<div class="wg-muted">Kurs vom ' . e(date('d.m.Y', strtotime((string)($j['date'] ?? 'today')))) . ' · EZB</div></div>';
    },
];
