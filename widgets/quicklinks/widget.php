<?php
// Schnellzugriff: eigene Lesezeichen als Icon-Raster
return [
    'name' => 'Schnellzugriff',
    'icon' => '⚡',
    'description' => 'Eigene Lesezeichen als Icon-Kacheln – z. B. Mail, Discord, VRChat-Webseite, Bank.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'settings' => [
        'newtab' => ['label' => 'In neuem Tab öffnen', 'type' => 'checkbox', 'default' => true],
    ],
    'render' => function (WidgetCtx $w): string {
        $links = (array)($w->state()['links'] ?? [['t' => 'Discord', 'u' => 'https://discord.com/app', 'i' => '💬'], ['t' => 'VRChat', 'u' => 'https://vrchat.com/home', 'i' => '🥽'],
                                                 ['t' => 'YouTube', 'u' => 'https://www.youtube.com', 'i' => '▶️'], ['t' => 'e621', 'u' => 'https://e621.net', 'i' => '🦊']]);
        $edit = $w->can_edit();
        $h = '<div class="ql">';
        foreach ($links as $i => $l) {
            $host = parse_url($l['u'], PHP_URL_HOST) ?: '';
            $h .= '<span class="ql-i"><a href="' . e($l['u']) . '"' . ($w->cfg['newtab'] ? ' target="_blank" rel="noopener noreferrer"' : '') . ' title="' . e($host) . '"><i>' . e($l['i'] ?: '🔗') . '</i><span>' . e($l['t']) . '</span></a>'
                . ($edit ? '<button type="button" class="ql-x" data-wg-action="delete" data-i="' . $i . '" title="Entfernen">×</button>' : '') . '</span>';
        }
        $h .= '</div>';
        if ($edit) $h .= '<details class="ql-add"><summary>＋ Link hinzufügen</summary><form data-wg-action="add"><input type="text" name="i" placeholder="🔗" maxlength="4"><input type="text" name="t" placeholder="Name" maxlength="30" required><input type="url" name="u" placeholder="https://…" required><button class="btn btn-sm">Speichern</button></form></details>';
        return $h;
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!$w->can_edit()) throw new RuntimeException('Keine Berechtigung.');
        $s = $w->state();
        $links = (array)($s['links'] ?? [['t' => 'Discord', 'u' => 'https://discord.com/app', 'i' => '💬'], ['t' => 'VRChat', 'u' => 'https://vrchat.com/home', 'i' => '🥽'],
                                       ['t' => 'YouTube', 'u' => 'https://www.youtube.com', 'i' => '▶️'], ['t' => 'e621', 'u' => 'https://e621.net', 'i' => '🦊']]);
        if ($op === 'add') {
            $u = trim((string)($in['u'] ?? ''));
            if (!preg_match('#^https?://#i', $u)) throw new RuntimeException('Bitte eine Adresse mit https:// angeben.');
            $links[] = ['t' => mb_substr(trim((string)($in['t'] ?? '')), 0, 30) ?: (parse_url($u, PHP_URL_HOST) ?: 'Link'), 'u' => mb_substr($u, 0, 500), 'i' => mb_substr(trim((string)($in['i'] ?? '')), 0, 4)];
        }
        if ($op === 'delete') { array_splice($links, (int)($in['i'] ?? -1), 1); }
        $s['links'] = array_slice(array_values($links), 0, 40);
        $w->save_state($s);
        return null;
    },
];
