<?php
// Budget auf einen Blick (nur mit Recht „Budget & Bank-Sync“)
require_once dirname(__DIR__, 2) . '/inc/modules/budget.php';
return [
    'name' => 'Budget',
    'icon' => '💶',
    'description' => 'Netto, offene Posten, Ausgaben-Quote, Limits und die größten Kategorien des aktuellen Monats.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'permission' => 'budget.access',
    'settings' => [
        'blur' => ['label' => 'Beträge verwischt (Maus darüber zeigt sie)', 'type' => 'checkbox', 'default' => false],
        'cats' => ['label' => 'Größte Kategorien', 'type' => 'number', 'default' => 3, 'min' => 0, 'max' => 8],
    ],
    'render' => function (WidgetCtx $w): string {
        $b = load_json(DATA_DIR . '/json/budget_data.json', null);
        if (!$b) return '<div class="wg-empty">Noch kein Budget angelegt. <a href="budget.php">Öffnen →</a></div>';
        $s = budget_summary($b['entries'] ?? []);
        $bs = budget_settings();
        $ratio = $s['income'] > 0 ? min(100, $s['expense'] / $s['income'] * 100) : 100;
        $m = $w->cfg['blur'] ? ' bw-blur' : '';
        $h = '<a href="budget.php" class="bw' . $m . '"><div class="bw-month">' . e($b['month_name'] ?? '') . '</div>'
            . '<div class="bw-net ' . ($s['net'] >= 0 ? 'pos' : 'neg') . '"><span class="bw-m">' . ($s['net'] >= 0 ? '+' : '−') . eur(abs($s['net'])) . ' €</span><small>Netto' . ($s['rate'] !== null ? ' · ' . $s['rate'] . ' % gespart' : '') . '</small></div>'
            . '<div class="bw-bar"><i style="width:' . round($ratio) . '%" class="' . ($ratio >= 100 ? 'over' : ($ratio >= 85 ? 'warn' : '')) . '"></i></div>'
            . '<div class="bw-row"><span>💼 <span class="bw-m">' . eur($s['income']) . ' €</span></span><span>💸 <span class="bw-m">' . eur($s['expense']) . ' €</span></span></div>';
        if ($s['open'] > 0) $h .= '<div class="bw-open">⏳ <span class="bw-m">' . eur($s['open']) . ' €</span> noch offen</div>';
        foreach ($bs['limits'] as $c => $l) if (($s['cats'][$c] ?? 0) > $l) $h .= '<div class="bw-warn">⚠ ' . e($c) . ': Limit überschritten</div>';
        foreach (array_slice($s['cats'], 0, (int)$w->cfg['cats'], true) as $c => $v)
            $h .= '<div class="bw-cat"><span>' . budget_cat_icon($c) . ' ' . e($c) . '</span><span class="bw-m">' . eur($v) . ' €</span></div>';
        return $h . '</a>';
    },
];
