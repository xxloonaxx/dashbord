<?php
// Boop! – Mini-Ausgabe des Furry Hubs: gemeinsamer Boop-Zähler + Tierfakt des Tages
require_once dirname(__DIR__, 2) . '/inc/furhub.php';
return [
    'name' => 'Boop!',
    'icon' => '👃',
    'description' => 'Eine Nase zum Boopen direkt auf der Startseite – zählt für den gemeinsamen Zähler im Furry Hub. Dazu der Tierfakt des Tages.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'fact' => ['label' => 'Tierfakt des Tages anzeigen', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        $total = 0;
        try { $total = fh_stats()['total']['boop'] ?? 0; } catch (Throwable $e) { /* Hub-Datenbank nicht verfügbar */ }
        $critters = ['🦊', '🐺', '🐱', '🦝', '🦦', '🐰', '🐉', '🐶', '🐯', '🦁'];
        return '<div class="bp" data-total="' . (int)$total . '">'
            . '<button type="button" class="bp-nose" title="Boop!">' . $critters[(int)date('z') % count($critters)] . '</button>'
            . '<div class="bp-n"><b>' . number_format($total, 0, ',', '.') . '</b><small>Boops insgesamt</small></div>'
            . ($w->cfg['fact'] ? '<p class="bp-fact">🐾 ' . e(fh_daily(FH_FACTS, 'fact')) . '</p>' : '')
            . '<a class="wg-more" href="furhub.php">Furry Hub öffnen →</a></div>';
    },
];
