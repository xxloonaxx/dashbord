<?php
// Schnellnotiz – Markdown, BBCode & HTML wie überall im Dashboard
return [
    'name' => 'Schnellnotiz',
    'icon' => '📝',
    'description' => 'Ein Notizzettel direkt auf der Startseite – mit Formatierung (Markdown, BBCode).',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'private' => ['label' => 'Nur für angemeldete Benutzer anzeigen', 'type' => 'checkbox', 'default' => true],
        'color' => ['label' => 'Farbe', 'type' => 'select', 'default' => 'gold', 'options' => ['gold' => 'Gold', 'cyan' => 'Cyan', 'purple' => 'Lila', 'green' => 'Grün', 'red' => 'Rot']],
    ],
    'render' => function (WidgetCtx $w): string {
        if ($w->cfg['private'] && !is_logged_in()) return '<div class="wg-empty">🔒 Nur für angemeldete Benutzer.</div>';
        $s = $w->state();
        $text = (string)($s['text'] ?? '');
        $h = '<div class="nt nt-' . e($w->cfg['color']) . '">';
        $h .= $text !== '' ? '<div class="fmt nt-text">' . format_text($text) . '</div>' : '<div class="wg-empty">Noch leer.</div>';
        if (!empty($s['at'])) $h .= '<div class="wg-muted nt-at">zuletzt geändert ' . e(date('d.m. H:i', (int)$s['at'])) . ($s['by'] ?? '' ? ' von ' . e($s['by']) : '') . '</div>';
        if ($w->can_edit()) {
            $h .= '<form class="nt-form" data-wg-action="save" hidden><textarea name="text" rows="6" data-format="text">' . e($text) . '</textarea>'
                . '<div class="nt-btns"><button class="btn btn-sm btn-gold">Speichern</button><button type="button" class="btn btn-sm nt-cancel">Abbrechen</button></div></form>'
                . '<button type="button" class="btn btn-sm nt-edit">✎ Bearbeiten</button>';
        }
        return $h . '</div>';
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!$w->can_edit()) throw new RuntimeException('Keine Berechtigung.');
        if ($op === 'save') $w->save_state(['text' => mb_substr((string)($in['text'] ?? ''), 0, 20000), 'at' => time(), 'by' => user_label()]);
        return 'Gespeichert.';
    },
];
