<?php
/** Vorlage „Checkliste“: Gruppen mit abhakbaren Punkten und Fortschritt */

function tpl_checklist_start(): array {
    return ['groups' => [['id' => tpl_id('g'), 'name' => 'Allgemein', 'items' => []]]];
}

function tpl_checklist_post(array &$c, string $act, array $page): array {
    $groups = $c['groups'] ?? [];
    $gid = (string)($_POST['group'] ?? '');
    $iid = (string)($_POST['item'] ?? '');
    $anchor = $gid !== '' ? 'g' . $gid : '';
    $audit = null;
    foreach ($groups as &$g) {
        if ($act === 'add_item' && $g['id'] === $gid) {
            foreach (preg_split('/\r?\n/', trim((string)($_POST['text'] ?? ''))) as $line) {   // mehrere Zeilen = mehrere Punkte
                $line = mb_substr(trim($line), 0, 300);
                if ($line !== '') $g['items'][] = ['id' => tpl_id('i'), 'text' => $line, 'done' => false];
            }
        }
        if ($act === 'rename_group' && $g['id'] === $gid) $g['name'] = tpl_in('name', 80) ?: $g['name'];
        foreach ($g['items'] as $k => &$it) {
            if ($it['id'] !== $iid) continue;
            $anchor = 'g' . $g['id'];
            if ($act === 'toggle') $it['done'] = !$it['done'];
            if ($act === 'edit_item') $it['text'] = tpl_in('text', 300) ?: $it['text'];
            if ($act === 'delete_item') unset($g['items'][$k]);
        }
        unset($it);
        if ($act === 'clear_done' && $g['id'] === $gid) $g['items'] = array_filter($g['items'], function ($x) { return !$x['done']; });
        if ($act === 'reset' && $g['id'] === $gid) { foreach ($g['items'] as &$x) $x['done'] = false; unset($x); }
        $g['items'] = array_values($g['items']);
    }
    unset($g);
    if ($act === 'add_group') {
        $name = tpl_in('name', 80);
        if ($name === '') throw new InvalidArgumentException('Bitte einen Namen für die Gruppe eingeben.');
        $groups[] = ['id' => tpl_id('g'), 'name' => $name, 'items' => []];
        $anchor = 'g' . end($groups)['id'];
        $audit = 'Gruppe „' . $name . '“ angelegt';
    }
    if ($act === 'move_group' && ($i = tpl_index($groups, $gid)) !== null) tpl_move($groups, $i, (int)($_POST['dir'] ?? 1));
    if ($act === 'delete_group') {
        $groups = array_values(array_filter($groups, function ($g) use ($gid) { return $g['id'] !== $gid; }));
        $anchor = '';
        $audit = 'Gruppe gelöscht';
    }
    $c['groups'] = $groups;
    return ['anchor' => $anchor, 'audit' => $audit];
}

function tpl_checklist_live(array $c): string {
    $all = 0; $done = 0;
    foreach ($c['groups'] ?? [] as $g) foreach ($g['items'] as $it) { $all++; if ($it['done']) $done++; }
    return $all ? "$done / $all erledigt" : 'leer';
}

function tpl_checklist_render(array $c, array $page, bool $can_edit): void {
    $groups = $c['groups'] ?? [];
    $all = 0; $done = 0;
    foreach ($groups as $g) foreach ($g['items'] as $it) { $all++; if ($it['done']) $done++; }
    $pct = $all ? round($done / $all * 100) : 0; ?>
    <div class="cl-total">
        <div class="cl-label"><span>Fortschritt</span><span><?php echo $done; ?> / <?php echo $all; ?> erledigt<?php echo $all ? ' · ' . $pct . ' %' : ''; ?></span></div>
        <div class="bar"><div style="width: <?php echo $pct; ?>%;"></div></div>
    </div>
    <?php foreach ($groups as $gi => $g): $gd = count(array_filter($g['items'], function ($x) { return $x['done']; })); $gf = ['group' => $g['id']]; ?>
        <div class="panel cl-group" id="g<?php echo e($g['id']); ?>">
            <div class="panel-title">
                <span><?php echo e($g['name']); ?> <small><?php echo $gd; ?>/<?php echo count($g['items']); ?></small></span>
                <?php if ($can_edit): ?>
                    <span class="acts always">
                        <?php if ($gi > 0) echo tpl_button('move_group', $gf + ['dir' => -1], '↑', 'Nach oben');
                              if ($gi < count($groups) - 1) echo tpl_button('move_group', $gf + ['dir' => 1], '↓', 'Nach unten');
                              echo tpl_button('reset', $gf, '↺', 'Alle Haken entfernen');
                              echo tpl_button('clear_done', $gf, '🧹', 'Erledigte entfernen', 'ic', 'Alle erledigten Punkte dieser Gruppe entfernen?'); ?>
                        <form method="POST" class="inl" data-prompt="Neuer Name der Gruppe:" data-prompt-field="name" data-prompt-value="<?php echo e($g['name']); ?>">
                            <input type="hidden" name="action" value="rename_group"><input type="hidden" name="group" value="<?php echo e($g['id']); ?>"><input type="hidden" name="name">
                            <button class="ic" title="Umbenennen" aria-label="Umbenennen">✎</button></form>
                        <?php echo tpl_button('delete_group', $gf, '✕', 'Gruppe löschen', 'ic danger', 'Gruppe „' . $g['name'] . '“ samt Einträgen löschen?'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if (!$g['items']): ?><div class="muted small">Noch keine Einträge.</div><?php endif; ?>
            <?php foreach ($g['items'] as $it): ?>
                <div class="cl-item <?php echo $it['done'] ? 'done' : ''; ?>">
                    <form method="POST" class="inl"><input type="hidden" name="action" value="toggle"><input type="hidden" name="item" value="<?php echo e($it['id']); ?>">
                        <button class="cl-check <?php echo $it['done'] ? 'on' : ''; ?>" <?php echo $can_edit ? '' : 'disabled'; ?> aria-label="Erledigt umschalten"><?php echo $it['done'] ? '✓' : ''; ?></button></form>
                    <span class="cl-text"><?php echo format_inline($it['text']); ?></span>
                    <?php if ($can_edit): ?>
                        <span class="acts">
                            <form method="POST" class="inl" data-prompt="Eintrag ändern:" data-prompt-field="text" data-prompt-value="<?php echo e($it['text']); ?>">
                                <input type="hidden" name="action" value="edit_item"><input type="hidden" name="item" value="<?php echo e($it['id']); ?>"><input type="hidden" name="text">
                                <button class="ic" title="Bearbeiten" aria-label="Bearbeiten">✎</button></form>
                            <?php echo tpl_button('delete_item', ['item' => $it['id']], '✕', 'Löschen', 'ic danger'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($can_edit): ?>
                <form method="POST" class="cl-add">
                    <input type="hidden" name="action" value="add_item"><input type="hidden" name="group" value="<?php echo e($g['id']); ?>">
                    <textarea name="text" placeholder="Neuer Eintrag … (mehrere Zeilen = mehrere Einträge, **Formatierung** erlaubt)" required></textarea>
                    <button class="btn btn-sm btn-gold">＋</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($can_edit): ?>
        <form method="POST" class="cl-add" style="max-width: 420px;">
            <input type="hidden" name="action" value="add_group">
            <input type="text" name="name" placeholder="Neue Gruppe, z. B. „Kleidung“" maxlength="80" required>
            <button class="btn btn-sm">＋ Gruppe</button>
        </form>
        <script>
            // Enter speichert, Umschalt+Enter = neue Zeile
            document.querySelectorAll('.cl-add textarea').forEach(t => t.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); t.form.requestSubmit(); } }));
        </script>
    <?php endif;
}
