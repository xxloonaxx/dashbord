<?php
/** Vorlage „Tabelle“: eigene Spalten (Text, Zahl, Betrag, Datum, Haken, Link, Bewertung), sortierbar, mit Summen und CSV-Export */

const TPL_TABLE_TYPES = ['text' => 'Text', 'number' => 'Zahl', 'money' => 'Betrag (€)', 'date' => 'Datum', 'check' => 'Haken ✓', 'link' => 'Link', 'rating' => 'Bewertung ★'];

function tpl_table_start(): array {
    $cols = [['id' => 'c1', 'name' => 'Was', 'type' => 'text'], ['id' => 'c2', 'name' => 'Anzahl', 'type' => 'number'], ['id' => 'c3', 'name' => 'Preis', 'type' => 'money'],
             ['id' => 'c4', 'name' => 'Gekauft', 'type' => 'check'], ['id' => 'c5', 'name' => 'Shop', 'type' => 'link']];
    return ['cols' => $cols, 'rows' => [
        ['id' => tpl_id('r'), 'c' => ['c1' => 'Fursuit-Kühlweste', 'c2' => 1, 'c3' => 89.9, 'c4' => false, 'c5' => '']],
        ['id' => tpl_id('r'), 'c' => ['c1' => '**Badge**-Halter', 'c2' => 2, 'c3' => 4.5, 'c4' => true, 'c5' => '']],
    ]];
}

function tpl_table_value(string $type, $raw) {
    $raw = is_string($raw) ? trim($raw) : $raw;
    switch ($type) {
        case 'number': case 'money': return $raw === '' || $raw === null ? '' : parse_amount($raw);
        case 'date': return tpl_date((string)$raw) ?? '';
        case 'check': return !empty($raw);
        case 'rating': return max(0, min(5, (int)$raw));
        case 'link':
            $u = (string)$raw;
            if ($u === '') return '';
            if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
            return filter_var($u, FILTER_VALIDATE_URL) && fmt_url($u) !== '' ? mb_substr($u, 0, 2000) : '';
        default: return mb_substr((string)$raw, 0, 1000);
    }
}

/** Anzeige einer Zelle -> [HTML, Sortierwert] */
function tpl_table_cell(array $col, $v): array {
    switch ($col['type']) {
        case 'number': return [$v === '' ? '' : tpl_num($v), $v === '' ? '' : (float)$v];
        case 'money': return [$v === '' ? '' : eur($v) . ' €', $v === '' ? '' : (float)$v];
        case 'date': return [$v ? e(date('d.m.Y', strtotime($v))) : '', (string)$v];
        case 'check': return [$v ? '<span class="tc-yes">✓</span>' : '<span class="tc-no">–</span>', $v ? 1 : 0];
        case 'rating': return ['<span class="tc-stars" title="' . (int)$v . ' von 5">' . str_repeat('★', (int)$v) . '<i>' . str_repeat('★', 5 - (int)$v) . '</i></span>', (int)$v];
        case 'link':
            if (!$v) return ['', ''];
            $host = preg_replace('/^www\./', '', (string)parse_url($v, PHP_URL_HOST));
            return ['<a href="' . e($v) . '" target="_blank" rel="noopener noreferrer">' . e($host ?: $v) . ' ↗</a>', $host];
        default: return [format_inline((string)$v), mb_strtolower(format_plain((string)$v))];
    }
}

function tpl_table_post(array &$c, string $act, array $page): array {
    $cols = &$c['cols'];
    $rows = &$c['rows'];
    $id = (string)($_POST['id'] ?? '');
    switch ($act) {
        case 'col_save':
            $name = tpl_in('name', 60);
            $type = isset(TPL_TABLE_TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'text';
            if ($name === '') throw new InvalidArgumentException('Bitte einen Spaltennamen eingeben.');
            $i = $id !== '' ? tpl_index($cols, $id) : null;
            if ($i === null) {
                if (count($cols) >= 20) throw new InvalidArgumentException('Maximal 20 Spalten.');
                $cols[] = ['id' => tpl_id('c'), 'name' => $name, 'type' => $type];
                return ['msg' => 'Spalte „' . $name . '“ angelegt.', 'reopen' => 'colsModal'];
            }
            $old = $cols[$i]['type'];
            $cols[$i]['name'] = $name;
            $cols[$i]['type'] = $type;
            if ($old !== $type) foreach ($rows as &$r) { $r['c'][$id] = tpl_table_value($type, is_bool($r['c'][$id] ?? '') ? ($r['c'][$id] ? '1' : '') : (string)($r['c'][$id] ?? '')); } unset($r);
            return ['msg' => 'Spalte gespeichert.', 'reopen' => 'colsModal'];
        case 'col_delete':
            $i = tpl_need($cols, $id);
            array_splice($cols, $i, 1);
            foreach ($rows as &$r) unset($r['c'][$id]);
            unset($r);
            return ['msg' => 'Spalte gelöscht.', 'reopen' => 'colsModal'];
        case 'col_move':
            tpl_move($cols, tpl_need($cols, $id), (int)($_POST['dir'] ?? 1));
            return ['reopen' => 'colsModal'];
        case 'row_save':
            $in = (array)($_POST['c'] ?? []);
            $vals = [];
            foreach ($cols as $col) $vals[$col['id']] = tpl_table_value($col['type'], $in[$col['id']] ?? '');
            if ($id === '') {
                if (count($rows) >= 2000) throw new InvalidArgumentException('Maximal 2000 Zeilen.');
                $rows[] = ['id' => tpl_id('r'), 'c' => $vals];
                return ['msg' => 'Zeile hinzugefügt.', 'anchor' => 'r-' . end($rows)['id']];
            }
            $rows[tpl_need($rows, $id)]['c'] = $vals;
            return ['msg' => 'Zeile gespeichert.', 'anchor' => 'r-' . $id];
        case 'row_dup':
            $copy = $rows[tpl_need($rows, $id)];
            $copy['id'] = tpl_id('r');
            array_splice($rows, tpl_need($rows, $id) + 1, 0, [$copy]);
            return ['anchor' => 'r-' . $copy['id']];
        case 'row_delete':
            array_splice($rows, tpl_need($rows, $id), 1);
            return ['msg' => 'Zeile gelöscht.'];
        case 'row_move':
            tpl_move($rows, tpl_need($rows, $id), (int)($_POST['dir'] ?? 1));
            return ['anchor' => 'r-' . $id];
        case 'cell_toggle':
            $i = tpl_need($rows, $id);
            $col = (string)($_POST['col'] ?? '');
            $rows[$i]['c'][$col] = empty($rows[$i]['c'][$col]);
            return ['anchor' => 'r-' . $id, 'json' => ['value' => $rows[$i]['c'][$col]]];
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function tpl_table_export(array $c, array $page): void {
    $name = preg_replace('/[^\w\-]+/', '_', $page['slug']) . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM, damit Excel die Umlaute richtig erkennt
    fputcsv($out, array_column($c['cols'], 'name'), ';');
    foreach ($c['rows'] as $r) {
        $line = [];
        foreach ($c['cols'] as $col) {
            $v = $r['c'][$col['id']] ?? '';
            switch ($col['type']) {
                case 'number': $line[] = $v === '' ? '' : str_replace('.', ',', (string)$v); break;
                case 'money': $line[] = $v === '' ? '' : number_format((float)$v, 2, ',', ''); break;
                case 'date': $line[] = $v ? date('d.m.Y', strtotime($v)) : ''; break;
                case 'check': $line[] = $v ? 'ja' : 'nein'; break;
                case 'text': $line[] = format_plain((string)$v); break;
                default: $line[] = (string)$v;
            }
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
}

function tpl_table_live(array $c): string {
    return count($c['rows'] ?? []) . ' Einträge';
}

function tpl_table_render(array $c, array $page, bool $can_edit): void {
    $cols = $c['cols'] ?? [];
    $rows = $c['rows'] ?? [];
    $extra = '<a class="btn" href="page.php?p=' . e(rawurlencode($page['slug'])) . '&amp;export=csv" title="Für Excel / LibreOffice">⬇ CSV</a>';
    if ($can_edit) $extra .= '<button type="button" class="btn" data-open="colsModal">⚙ Spalten</button>'
                           . '<button type="button" class="btn btn-gold" data-open="rowModal" data-title="Neue Zeile" data-fill="' . tpl_fill(['id' => '']) . '">＋ Zeile</button>';
    tpl_filter_bar('#dt tbody tr', 'Tabelle durchsuchen …', [], $extra); ?>
    <div class="panel dt-wrap">
        <table class="dt" id="dt">
            <thead><tr>
                <?php foreach ($cols as $k => $col): ?>
                    <th data-sort-col="<?php echo $k; ?>" data-type="<?php echo e(in_array($col['type'], ['number', 'money', 'check', 'rating'], true) ? 'num' : 'text'); ?>" class="t-<?php echo e($col['type']); ?>" tabindex="0" title="Sortieren"><?php echo e($col['name']); ?> <span class="sort-ind"></span></th>
                <?php endforeach; ?>
                <?php if ($can_edit): ?><th class="dt-acts"></th><?php endif; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $ri => $r): $fill = ['id' => $r['id']]; $search = []; ?>
                    <tr id="r-<?php echo e($r['id']); ?>">
                        <?php foreach ($cols as $col):
                            $v = $r['c'][$col['id']] ?? ($col['type'] === 'check' ? false : '');
                            [$html, $sort] = tpl_table_cell($col, $v);
                            $fill['c[' . $col['id'] . ']'] = $col['type'] === 'check' ? (bool)$v : (string)$v;
                            $search[] = is_bool($v) ? '' : (string)$v; ?>
                            <td class="t-<?php echo e($col['type']); ?>" data-v="<?php echo e((string)$sort); ?>">
                                <?php if ($col['type'] === 'check' && $can_edit): ?>
                                    <form method="POST" class="inl"><input type="hidden" name="action" value="cell_toggle"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><input type="hidden" name="col" value="<?php echo e($col['id']); ?>"><button class="tc-toggle" title="Umschalten"><?php echo $html; ?></button></form>
                                <?php else: echo $html; endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <?php if ($can_edit): ?>
                            <td class="dt-acts"><span class="acts">
                                <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="rowModal" data-title="Zeile bearbeiten" data-fill="<?php echo tpl_fill($fill); ?>">✎</button>
                                <?php if ($ri > 0) echo tpl_button('row_move', ['id' => $r['id'], 'dir' => -1], '↑', 'Nach oben');
                                      if ($ri < count($rows) - 1) echo tpl_button('row_move', ['id' => $r['id'], 'dir' => 1], '↓', 'Nach unten');
                                      echo tpl_button('row_dup', ['id' => $r['id']], '⧉', 'Kopieren');
                                      echo tpl_button('row_delete', ['id' => $r['id']], '✕', 'Löschen', 'ic danger', 'Diese Zeile löschen?'); ?>
                            </span></td>
                        <?php endif; ?>
                        <?php echo '<td hidden data-search-src>' . e(implode(' ', $search)) . '</td>'; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
                <tfoot><tr>
                    <?php foreach ($cols as $col):
                        $vals = array_map(function ($r) use ($col) { return $r['c'][$col['id']] ?? ''; }, $rows);
                        $nums = array_filter($vals, function ($v) { return $v !== '' && !is_bool($v); });
                        switch ($col['type']) {
                            case 'number': $f = $nums ? 'Σ ' . tpl_num(array_sum($nums)) : ''; break;
                            case 'money': $f = $nums ? 'Σ ' . eur(array_sum($nums)) . ' €' : ''; break;
                            case 'check': $f = count(array_filter($vals)) . ' / ' . count($vals); break;
                            case 'rating': $f = $nums ? 'Ø ' . number_format(array_sum($nums) / count($nums), 1, ',', '') : ''; break;
                            default: $f = '';
                        } ?>
                        <td class="t-<?php echo e($col['type']); ?>"><?php echo e($f); ?></td>
                    <?php endforeach; ?>
                    <?php if ($can_edit): ?><td></td><?php endif; ?>
                </tr></tfoot>
            <?php endif; ?>
        </table>
        <?php if (!$rows): ?><div class="empty-msg">Noch keine Zeilen.</div><?php endif; ?>
    </div>
    <script>
    // Sortieren per Klick auf die Spaltenüberschrift; Suche berücksichtigt auch versteckten Rohtext
    (function () {
        const t = document.getElementById('dt'), body = t.tBodies[0];
        body.querySelectorAll('tr').forEach(tr => { tr.dataset.search = (tr.textContent + ' ' + (tr.querySelector('[data-search-src]') || {}).textContent).toLowerCase(); });
        t.querySelectorAll('th[data-sort-col]').forEach(th => {
            const go = () => {
                const k = +th.dataset.sortCol, num = th.dataset.type === 'num', dir = th.classList.contains('asc') ? -1 : 1;
                t.querySelectorAll('th').forEach(x => x.classList.remove('asc', 'desc'));
                th.classList.add(dir === 1 ? 'asc' : 'desc');
                [...body.rows].sort((a, b) => {
                    let x = a.cells[k].dataset.v, y = b.cells[k].dataset.v;
                    if (num) { x = x === '' ? -Infinity : +x; y = y === '' ? -Infinity : +y; return (x - y) * dir; }
                    return x.localeCompare(y, 'de', { numeric: true }) * dir;
                }).forEach(r => body.append(r));
            };
            th.addEventListener('click', go);
            th.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
        });
    })();
    </script>

    <?php if (!$can_edit) return;
    tpl_modal_open('rowModal', 'Neue Zeile', 'row_save', false, '620px'); ?>
        <input type="hidden" name="id" value="">
        <div class="row-fields">
            <?php foreach ($cols as $col): $n = 'c[' . $col['id'] . ']'; ?>
                <div class="rf rf-<?php echo e($col['type']); ?>">
                    <?php if ($col['type'] === 'check'): ?>
                        <label class="chk"><input type="checkbox" name="<?php echo e($n); ?>" value="1"> <?php echo e($col['name']); ?></label>
                    <?php else: ?>
                        <label class="field"><?php echo e($col['name']); ?> <small>(<?php echo e(TPL_TABLE_TYPES[$col['type']]); ?>)</small></label>
                        <?php switch ($col['type']) {
                            case 'number': case 'money': echo '<input type="text" name="' . e($n) . '" inputmode="decimal" placeholder="' . ($col['type'] === 'money' ? '0,00' : '0') . '">'; break;
                            case 'date': echo '<input type="date" name="' . e($n) . '">'; break;
                            case 'link': echo '<input type="text" name="' . e($n) . '" inputmode="url" placeholder="https://…">'; break;
                            case 'rating': echo '<select name="' . e($n) . '">' . implode('', array_map(function ($s) { return '<option value="' . $s . '">' . ($s ? str_repeat('★', $s) : '– keine –') . '</option>'; }, range(0, 5))) . '</select>'; break;
                            default: echo '<input type="text" name="' . e($n) . '" maxlength="1000" data-format="inline">';
                        } ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$cols): ?><p class="muted">Bitte zuerst unter „⚙ Spalten“ Spalten anlegen.</p><?php endif; ?>
    <?php tpl_modal_close(); ?>

    <div class="pm" id="colsModal" role="dialog" aria-modal="true" aria-labelledby="colsModalT" hidden>
        <div class="pm-box" style="max-width: 640px;">
            <button type="button" class="pm-x" data-close aria-label="Schließen">&times;</button>
            <div class="pm-title" id="colsModalT">Spalten verwalten</div>
            <?php foreach ($cols as $k => $col): ?>
                <div class="col-row">
                    <form method="POST" class="col-form">
                        <input type="hidden" name="action" value="col_save"><input type="hidden" name="id" value="<?php echo e($col['id']); ?>">
                        <input type="text" name="name" value="<?php echo e($col['name']); ?>" maxlength="60" required aria-label="Spaltenname">
                        <select name="type" aria-label="Typ"><?php foreach (TPL_TABLE_TYPES as $tk => $tn): ?><option value="<?php echo $tk; ?>" <?php echo $tk === $col['type'] ? 'selected' : ''; ?>><?php echo e($tn); ?></option><?php endforeach; ?></select>
                        <button class="ic" title="Speichern" aria-label="Speichern">💾</button>
                    </form>
                    <?php if ($k > 0) echo tpl_button('col_move', ['id' => $col['id'], 'dir' => -1], '←', 'Nach links');
                          if ($k < count($cols) - 1) echo tpl_button('col_move', ['id' => $col['id'], 'dir' => 1], '→', 'Nach rechts');
                          echo tpl_button('col_delete', ['id' => $col['id']], '✕', 'Spalte löschen', 'ic danger', 'Spalte „' . $col['name'] . '“ samt allen Werten löschen?'); ?>
                </div>
            <?php endforeach; ?>
            <form method="POST" class="col-form col-new">
                <input type="hidden" name="action" value="col_save"><input type="hidden" name="id" value="">
                <input type="text" name="name" maxlength="60" required placeholder="Neue Spalte …" aria-label="Neue Spalte">
                <select name="type" aria-label="Typ"><?php foreach (TPL_TABLE_TYPES as $tk => $tn): ?><option value="<?php echo $tk; ?>"><?php echo e($tn); ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-gold">＋ Hinzufügen</button>
            </form>
        </div>
    </div>
    <?php
}
