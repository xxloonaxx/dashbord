<?php
// Aufgabenliste mit Fälligkeitsdatum, Priorität und „Erledigte ausblenden“
return [
    'name' => 'Aufgaben',
    'icon' => '✅',
    'description' => 'To-do-Liste mit Abhaken, Priorität und Fälligkeitsdatum.',
    'version' => '1.0',
    'author' => 'GHOST NODE',
    'size' => 's',
    'login' => true,
    'settings' => [
        'hide_done' => ['label' => 'Erledigte ausblenden', 'type' => 'checkbox', 'default' => false],
        'limit' => ['label' => 'Maximal angezeigt', 'type' => 'number', 'default' => 12, 'min' => 3, 'max' => 50],
    ],
    'render' => function (WidgetCtx $w): string {
        $items = (array)($w->state()['items'] ?? []);
        usort($items, function ($a, $b) { return [$a['done'] ? 1 : 0, $a['prio'] ? 0 : 1, $a['due'] ?: '9999'] <=> [$b['done'] ? 1 : 0, $b['prio'] ? 0 : 1, $b['due'] ?: '9999']; });
        $open = count(array_filter($items, function ($i) { return !$i['done']; }));
        $edit = $w->can_edit();
        $h = '<div class="td-head"><span>' . $open . ' offen · ' . (count($items) - $open) . ' erledigt</span>'
            . ($edit && count($items) > $open ? '<button type="button" class="wg-link" data-wg-action="clear">Erledigte löschen</button>' : '') . '</div><ul class="td">';
        $shown = 0;
        foreach ($items as $i) {
            if ($i['done'] && $w->cfg['hide_done']) continue;
            if (++$shown > (int)$w->cfg['limit']) break;
            $late = !$i['done'] && $i['due'] && $i['due'] < date('Y-m-d');
            $h .= '<li class="' . ($i['done'] ? 'done' : '') . '">'
                . ($edit ? '<button type="button" class="td-chk" data-wg-action="toggle" data-id="' . e($i['id']) . '" aria-label="Abhaken">' . ($i['done'] ? '✓' : '') . '</button>' : '<span class="td-chk">' . ($i['done'] ? '✓' : '') . '</span>')
                . '<span class="td-t">' . ($i['prio'] ? '<b class="td-prio">!</b> ' : '') . e($i['text'])
                . ($i['due'] ? ' <small class="' . ($late ? 'late' : '') . '">📅 ' . e(date('d.m.', strtotime($i['due']))) . '</small>' : '') . '</span>'
                . ($edit ? '<button type="button" class="td-x" data-wg-action="delete" data-id="' . e($i['id']) . '" aria-label="Löschen">×</button>' : '') . '</li>';
        }
        $h .= '</ul>';
        if (!$items) $h .= '<div class="wg-empty">Nichts zu tun. 🎉</div>';
        if ($edit) $h .= '<form class="td-add" data-wg-action="add"><input type="text" name="text" placeholder="Neue Aufgabe … (! am Anfang = wichtig)" maxlength="200" required><input type="date" name="due" title="Fällig am"><button class="btn btn-sm">＋</button></form>';
        return $h;
    },
    'action' => function (WidgetCtx $w, string $op, array $in): ?string {
        if (!$w->can_edit()) throw new RuntimeException('Keine Berechtigung.');
        $s = $w->state();
        $items = (array)($s['items'] ?? []);
        $id = (string)($in['id'] ?? '');
        if ($op === 'add') {
            $t = trim((string)($in['text'] ?? ''));
            $prio = strpos($t, '!') === 0;
            $t = ltrim($t, '! ');
            if ($t !== '') $items[] = ['id' => bin2hex(random_bytes(4)), 'text' => mb_substr($t, 0, 200), 'done' => false, 'prio' => $prio,
                                       'due' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['due'] ?? '')) ? $in['due'] : ''];
        }
        if ($op === 'toggle') foreach ($items as &$i) if ($i['id'] === $id) $i['done'] = !$i['done'];
        unset($i);
        if ($op === 'delete') $items = array_values(array_filter($items, function ($i) use ($id) { return $i['id'] !== $id; }));
        if ($op === 'clear') $items = array_values(array_filter($items, function ($i) { return !$i['done']; }));
        $s['items'] = array_slice($items, 0, 300);
        $w->save_state($s);
        return null;
    },
];
