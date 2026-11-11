<?php
/** Vorlage „Zähler & Tracker“: Zähler mit + / −, Ziel, Fortschritt und Verlauf */

function tpl_counter_start(): array {
    $mk = function ($name, $icon, $value, $goal, $unit, $color) {
        return ['id' => tpl_id('z'), 'name' => $name, 'icon' => $icon, 'value' => $value, 'step' => 1, 'goal' => $goal, 'unit' => $unit, 'color' => $color, 'log' => []];
    };
    return ['counters' => [$mk('Mate getrunken', '🧉', 0, 0, 'Flaschen', 'gold'), $mk('Con-Tage dieses Jahr', '🎪', 0, 10, 'Tage', 'purple'), $mk('Fursuit-Stunden', '🐾', 0, 50, 'Std.', 'cyan')]];
}

function tpl_counter_post(array &$c, string $act, array $page): array {
    $list = &$c['counters'];
    $id = (string)($_POST['id'] ?? '');
    switch ($act) {
        case 'cnt_save':
            $name = tpl_in('name', 80);
            if ($name === '') throw new InvalidArgumentException('Bitte einen Namen eingeben.');
            $data = ['name' => $name, 'icon' => mb_substr(tpl_in('icon', 8), 0, 4) ?: '🔢', 'unit' => tpl_in('unit', 20), 'color' => tpl_color_key($_POST['color'] ?? ''),
                     'step' => max(0.01, min(1000000, parse_amount($_POST['step'] ?? '1') ?: 1)), 'goal' => max(0, parse_amount($_POST['goal'] ?? '0'))];
            $value = parse_amount($_POST['value'] ?? '0');
            if ($id === '') {
                $list[] = $data + ['id' => tpl_id('z'), 'value' => $value, 'log' => []];
                return ['msg' => 'Zähler angelegt.', 'anchor' => 'z-' . end($list)['id']];
            }
            $i = tpl_need($list, $id);
            if (abs($value - (float)$list[$i]['value']) > 0.0001) array_unshift($list[$i]['log'], ['t' => time(), 'd' => $value - (float)$list[$i]['value'], 'v' => $value]);
            $list[$i] = $data + ['value' => $value] + $list[$i];
            $list[$i]['log'] = array_slice($list[$i]['log'], 0, 50);
            return ['msg' => 'Zähler gespeichert.', 'anchor' => 'z-' . $id];
        case 'cnt_add':
            $i = tpl_need($list, $id);
            $d = isset($_POST['amount']) && $_POST['amount'] !== '' ? parse_amount($_POST['amount']) : (float)$list[$i]['step'] * ((int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
            if ($d == 0) return [];
            $list[$i]['value'] = round((float)$list[$i]['value'] + $d, 2);
            array_unshift($list[$i]['log'], ['t' => time(), 'd' => $d, 'v' => $list[$i]['value']]);
            $list[$i]['log'] = array_slice($list[$i]['log'], 0, 50);
            return ['anchor' => 'z-' . $id, 'json' => tpl_counter_state($list[$i])];
        case 'cnt_reset':
            $i = tpl_need($list, $id);
            array_unshift($list[$i]['log'], ['t' => time(), 'd' => -(float)$list[$i]['value'], 'v' => 0]);
            $list[$i]['value'] = 0;
            return ['msg' => 'Zähler zurückgesetzt.', 'anchor' => 'z-' . $id];
        case 'cnt_delete':
            array_splice($list, tpl_need($list, $id), 1);
            return ['msg' => 'Zähler gelöscht.'];
        case 'cnt_move':
            tpl_move($list, tpl_need($list, $id), (int)($_POST['dir'] ?? 1));
            return ['anchor' => 'z-' . $id];
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

/** Werte für die Anzeige (auch für die Live-Aktualisierung per JavaScript) */
function tpl_counter_state(array $z): array {
    $today = 0;
    foreach ($z['log'] as $l) if (date('Y-m-d', $l['t']) === date('Y-m-d')) $today += $l['d'];
    $pct = $z['goal'] > 0 ? max(0, min(100, round($z['value'] / $z['goal'] * 100))) : null;
    return ['value' => tpl_num($z['value']), 'pct' => $pct, 'today' => $today ? 'heute ' . ($today > 0 ? '+' : '') . tpl_num($today) : '',
            'log' => array_map(function ($l) { return ['t' => tpl_when($l['t']), 'd' => ($l['d'] > 0 ? '+' : '') . tpl_num($l['d']), 'v' => tpl_num($l['v'])]; }, array_slice($z['log'], 0, 8))];
}

function tpl_counter_live(array $c): string {
    $z = $c['counters'][0] ?? null;
    return $z ? $z['icon'] . ' ' . tpl_num($z['value']) . ' ' . $z['unit'] : '';
}

function tpl_counter_render(array $c, array $page, bool $can_edit): void {
    $list = $c['counters'] ?? [];
    if ($can_edit): ?>
        <div class="tb"><span class="muted small" style="flex: 1;">Tipp: + und − speichern sofort. Eigene Menge über ✎ → Wert.</span>
            <button type="button" class="btn btn-gold" data-open="cntModal" data-title="Neuer Zähler" data-fill="<?php echo tpl_fill(['id' => '', 'icon' => '🔢', 'step' => '1', 'goal' => '0', 'value' => '0', 'color' => 'cyan']); ?>">＋ Neuer Zähler</button></div>
    <?php endif; ?>
    <div class="cnt-grid">
        <?php foreach ($list as $k => $z): $s = tpl_counter_state($z); ?>
            <article class="cnt panel" id="z-<?php echo e($z['id']); ?>" style="--c: <?php echo tpl_color($z['color']); ?>;">
                <header><span class="cnt-icon"><?php echo e($z['icon']); ?></span><h3><?php echo e($z['name']); ?></h3>
                    <?php if ($can_edit): ?>
                        <span class="acts">
                            <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="cntModal" data-title="Zähler bearbeiten"
                                    data-fill="<?php echo tpl_fill(['id' => $z['id'], 'name' => $z['name'], 'icon' => $z['icon'], 'unit' => $z['unit'], 'color' => $z['color'], 'step' => tpl_num($z['step']), 'goal' => tpl_num($z['goal']), 'value' => tpl_num($z['value'])]); ?>">✎</button>
                            <?php if ($k > 0) echo tpl_button('cnt_move', ['id' => $z['id'], 'dir' => -1], '◀', 'Nach vorne');
                                  echo tpl_button('cnt_reset', ['id' => $z['id']], '↺', 'Auf 0 setzen', 'ic', 'Zähler „' . $z['name'] . '“ auf 0 setzen?');
                                  echo tpl_button('cnt_delete', ['id' => $z['id']], '✕', 'Löschen', 'ic danger', 'Zähler „' . $z['name'] . '“ löschen?');
                                  if ($k < count($list) - 1) echo tpl_button('cnt_move', ['id' => $z['id'], 'dir' => 1], '▶', 'Nach hinten'); ?>
                        </span>
                    <?php endif; ?>
                </header>
                <div class="cnt-value"><b data-v><?php echo e($s['value']); ?></b> <span><?php echo e($z['unit']); ?></span></div>
                <div class="cnt-sub"><span data-today><?php echo e($s['today']); ?></span><?php if ($z['goal'] > 0): ?><span>Ziel: <?php echo e(tpl_num($z['goal']) . ' ' . $z['unit']); ?></span><?php endif; ?></div>
                <?php if ($z['goal'] > 0): ?><div class="bar"><div data-pct style="width: <?php echo (int)$s['pct']; ?>%;"></div></div><?php endif; ?>
                <?php if ($can_edit): ?>
                    <div class="cnt-btns">
                        <?php echo tpl_button('cnt_add', ['id' => $z['id'], 'dir' => -1], '−&nbsp;' . e(tpl_num($z['step'])), 'Weniger', 'btn cnt-step');
                              echo tpl_button('cnt_add', ['id' => $z['id'], 'dir' => 1], '＋&nbsp;' . e(tpl_num($z['step'])), 'Mehr', 'btn btn-gold cnt-step'); ?>
                    </div>
                <?php endif; ?>
                <details class="cnt-log" <?php echo $s['log'] ? '' : 'hidden'; ?>><summary>Verlauf</summary>
                    <ul data-log><?php foreach ($s['log'] as $l): ?><li><span><?php echo e($l['t']); ?></span><b><?php echo e($l['d']); ?></b><span>= <?php echo e($l['v']); ?></span></li><?php endforeach; ?></ul>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (!$list): ?><div class="empty-msg">Noch keine Zähler.</div><?php endif; ?>
    <?php if (!$can_edit) return; ?>
    <script>
    // + / − ohne Neuladen der Seite
    document.querySelectorAll('.cnt form').forEach(f => {
        if (!f.querySelector('.cnt-step')) return;
        f.addEventListener('submit', e => {
            e.preventDefault();
            const card = f.closest('.cnt');
            PageUI.ajax(Object.fromEntries(new FormData(f))).then(s => {
                if (!s.value) return;
                card.querySelector('[data-v]').textContent = s.value;
                card.querySelector('[data-today]').textContent = s.today;
                const p = card.querySelector('[data-pct]'); if (p) p.style.width = s.pct + '%';
                const log = card.querySelector('.cnt-log'); log.hidden = false;
                log.querySelector('[data-log]').innerHTML = s.log.map(l => '<li><span>' + l.t + '</span><b>' + l.d + '</b><span>= ' + l.v + '</span></li>').join('');
                card.classList.remove('bump'); void card.offsetWidth; card.classList.add('bump');
            }).catch(err => alert(err.message));
        });
    });
    </script>
    <?php
    tpl_modal_open('cntModal', 'Neuer Zähler', 'cnt_save'); ?>
        <input type="hidden" name="id" value="">
        <div class="pm-row">
            <div style="width: 90px;"><label class="field">Icon</label><input type="text" name="icon" maxlength="8"></div>
            <div style="flex: 1;"><label class="field">Name *</label><input type="text" name="name" maxlength="80" required placeholder="z. B. Gelesene Bücher"></div>
        </div>
        <div class="pm-row">
            <div style="flex: 1;"><label class="field">Aktueller Wert</label><input type="text" name="value" inputmode="decimal"></div>
            <div style="flex: 1;"><label class="field">Schrittweite</label><input type="text" name="step" inputmode="decimal"></div>
            <div style="flex: 1;"><label class="field">Ziel (0 = keins)</label><input type="text" name="goal" inputmode="decimal"></div>
            <div style="flex: 1;"><label class="field">Einheit</label><input type="text" name="unit" maxlength="20" placeholder="Stück"></div>
        </div>
        <label class="field">Farbe</label>
        <?php tpl_color_picker(); ?>
    <?php tpl_modal_close();
}
