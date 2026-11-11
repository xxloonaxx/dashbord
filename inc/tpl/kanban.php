<?php
/** Vorlage „Kanban-Board“: Spalten mit Karten, per Drag & Drop verschiebbar */

function tpl_kanban_start(): array {
    $col = function ($name, $color, $cards = []) { return ['id' => tpl_id('k'), 'name' => $name, 'color' => $color, 'cards' => $cards]; };
    return ['columns' => [
        $col('💡 Ideen', 'purple'),
        $col('📋 To-do', 'cyan', [['id' => tpl_id('c'), 'title' => 'Erste Karte', 'body' => "Karten lassen sich mit der Maus **verschieben** – oder mit ◀ ▶.\n\n- [ ] Ausprobieren", 'color' => 'gold', 'due' => '', 'labels' => ['Info'], 'created' => time()]]),
        $col('⚙️ In Arbeit', 'gold'),
        $col('✅ Erledigt', 'green'),
    ]];
}

/** [Spalten-Index, Karten-Index] einer Karte */
function tpl_kanban_locate(array $cols, string $id): array {
    foreach ($cols as $ci => $col) foreach ($col['cards'] as $ki => $card) if ($card['id'] === $id) return [$ci, $ki];
    throw new InvalidArgumentException('Karte nicht gefunden – vielleicht wurde sie gerade gelöscht.');
}

function tpl_kanban_post(array &$c, string $act, array $page): array {
    $cols = &$c['columns'];
    $id = (string)($_POST['id'] ?? '');
    switch ($act) {
        case 'col_save':
            $name = tpl_in('name', 60);
            if ($name === '') throw new InvalidArgumentException('Bitte einen Spaltennamen eingeben.');
            $i = $id !== '' ? tpl_index($cols, $id) : null;
            if ($i === null) {
                if (count($cols) >= 12) throw new InvalidArgumentException('Maximal 12 Spalten.');
                $cols[] = ['id' => tpl_id('k'), 'name' => $name, 'color' => tpl_color_key($_POST['color'] ?? ''), 'cards' => []];
                return ['msg' => 'Spalte angelegt.', 'audit' => 'Kanban-Spalte „' . $name . '“ angelegt'];
            }
            $cols[$i]['name'] = $name;
            $cols[$i]['color'] = tpl_color_key($_POST['color'] ?? '', $cols[$i]['color']);
            return ['msg' => 'Spalte gespeichert.'];
        case 'col_delete':
            $i = tpl_need($cols, $id);
            $name = $cols[$i]['name'];
            array_splice($cols, $i, 1);
            return ['msg' => 'Spalte gelöscht.', 'audit' => 'Kanban-Spalte „' . $name . '“ gelöscht'];
        case 'col_move':
            tpl_move($cols, tpl_need($cols, $id), (int)($_POST['dir'] ?? 1));
            return [];
        case 'col_clear':
            $i = tpl_need($cols, $id);
            $n = count($cols[$i]['cards']);
            $cols[$i]['cards'] = [];
            return ['msg' => $n . ' Karte(n) entfernt.'];
        case 'card_save':
            $title = tpl_in('title', 160);
            if ($title === '') throw new InvalidArgumentException('Bitte einen Titel für die Karte eingeben.');
            $target = tpl_need($cols, (string)($_POST['col'] ?? ''));
            $data = ['title' => $title, 'body' => tpl_body('body', 20000), 'color' => tpl_color_key($_POST['color'] ?? ''),
                     'due' => tpl_date((string)($_POST['due'] ?? '')) ?? '', 'labels' => tpl_tags($_POST['labels'] ?? '')];
            if ($id === '') {
                $data += ['id' => tpl_id('c'), 'created' => time()];
                $cols[$target]['cards'][] = $data;
                return ['msg' => 'Karte angelegt.', 'anchor' => 'c-' . $data['id']];
            }
            [$ci, $ki] = tpl_kanban_locate($cols, $id);
            $card = $data + $cols[$ci]['cards'][$ki];
            if ($ci === $target) {
                $cols[$ci]['cards'][$ki] = $card;
            } else {
                array_splice($cols[$ci]['cards'], $ki, 1);
                $cols[$target]['cards'][] = $card;
            }
            return ['msg' => 'Karte gespeichert.', 'anchor' => 'c-' . $id];
        case 'card_delete':
            [$ci, $ki] = tpl_kanban_locate($cols, $id);
            array_splice($cols[$ci]['cards'], $ki, 1);
            return ['msg' => 'Karte gelöscht.'];
        case 'card_step':
            [$ci, $ki] = tpl_kanban_locate($cols, $id);
            $to = $ci + ((int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
            if (!isset($cols[$to])) return [];
            $card = array_splice($cols[$ci]['cards'], $ki, 1)[0];
            $cols[$to]['cards'][] = $card;
            return ['anchor' => 'c-' . $id];
        case 'card_move':   // Drag & Drop (per JavaScript)
            [$ci, $ki] = tpl_kanban_locate($cols, $id);
            $to = tpl_need($cols, (string)($_POST['col'] ?? ''));
            $card = array_splice($cols[$ci]['cards'], $ki, 1)[0];
            $pos = max(0, min(count($cols[$to]['cards']), (int)($_POST['index'] ?? 0)));
            array_splice($cols[$to]['cards'], $pos, 0, [$card]);
            return ['json' => ['ok' => true]];
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function tpl_kanban_live(array $c): string {
    $cols = $c['columns'] ?? [];
    $all = array_sum(array_map(function ($x) { return count($x['cards']); }, $cols));
    $last = $cols ? count(end($cols)['cards']) : 0;
    return $all . ' Karten' . ($cols ? ' · ' . $last . ' in „' . format_plain(end($cols)['name']) . '“' : '');
}

function tpl_kanban_render(array $c, array $page, bool $can_edit): void {
    $cols = $c['columns'] ?? [];
    $today = date('Y-m-d');
    $extra = $can_edit ? '<button type="button" class="btn" data-open="colModal" data-title="Neue Spalte" data-fill="' . tpl_fill(['id' => '', 'color' => 'cyan']) . '">＋ Spalte</button>' : '';
    tpl_filter_bar('#kb .kb-card', 'Karten durchsuchen …', [], $extra); ?>
    <div class="kb" id="kb" data-edit="<?php echo $can_edit ? 1 : 0; ?>">
        <?php foreach ($cols as $ci => $col): $cf = ['id' => $col['id']]; ?>
            <section class="kb-col" data-col="<?php echo e($col['id']); ?>" style="--c: <?php echo tpl_color($col['color']); ?>;">
                <header>
                    <span class="kb-name"><?php echo format_inline($col['name']); ?></span>
                    <span class="kb-count"><?php echo count($col['cards']); ?></span>
                    <?php if ($can_edit): ?>
                        <span class="acts">
                            <button type="button" class="ic" title="Spalte bearbeiten" aria-label="Spalte bearbeiten" data-open="colModal" data-title="Spalte bearbeiten" data-fill="<?php echo tpl_fill(['id' => $col['id'], 'name' => $col['name'], 'color' => $col['color']]); ?>">✎</button>
                            <?php if ($ci > 0) echo tpl_button('col_move', $cf + ['dir' => -1], '◀', 'Spalte nach links');
                                  if ($ci < count($cols) - 1) echo tpl_button('col_move', $cf + ['dir' => 1], '▶', 'Spalte nach rechts');
                                  if ($col['cards']) echo tpl_button('col_clear', $cf, '🧹', 'Alle Karten der Spalte entfernen', 'ic', 'Alle ' . count($col['cards']) . ' Karten in „' . format_plain($col['name']) . '“ löschen?');
                                  echo tpl_button('col_delete', $cf, '✕', 'Spalte löschen', 'ic danger', 'Spalte „' . format_plain($col['name']) . '“ samt ' . count($col['cards']) . ' Karte(n) löschen?'); ?>
                        </span>
                    <?php endif; ?>
                </header>
                <div class="kb-cards">
                    <?php foreach ($col['cards'] as $card): $due = $card['due'] ?? ''; ?>
                        <article class="kb-card" id="c-<?php echo e($card['id']); ?>" data-card="<?php echo e($card['id']); ?>" style="--cc: <?php echo tpl_color($card['color']); ?>;"
                                 <?php echo $can_edit ? 'draggable="true"' : ''; ?> data-search="<?php echo tpl_search_text($card['title'], $card['body'], implode(' ', $card['labels'])); ?>">
                            <div class="kb-title"><?php echo format_inline($card['title']); ?></div>
                            <?php if ($card['labels']): ?><div class="kb-labels"><?php echo tpl_tag_list($card['labels']); ?></div><?php endif; ?>
                            <?php if (trim($card['body']) !== ''): ?><div class="fmt kb-body clamp sm"><?php echo format_text($card['body']); ?></div><?php endif; ?>
                            <?php if ($due !== '' || $can_edit): ?>
                                <div class="kb-foot">
                                    <?php if ($due !== ''): ?><span class="kb-due <?php echo $due < $today ? 'late' : ($due === $today ? 'today' : ''); ?>" title="Fällig">📅 <?php echo e(date('d.m.Y', strtotime($due))); ?></span><?php endif; ?>
                                    <?php if ($can_edit): ?>
                                        <span class="acts">
                                            <?php if ($ci > 0) echo tpl_button('card_step', ['id' => $card['id'], 'dir' => -1], '◀', 'In vorherige Spalte'); ?>
                                            <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="cardModal" data-title="Karte bearbeiten"
                                                    data-fill="<?php echo tpl_fill(['id' => $card['id'], 'col' => $col['id'], 'title' => $card['title'], 'body' => $card['body'], 'color' => $card['color'], 'due' => $due, 'labels' => implode(', ', $card['labels'])]); ?>">✎</button>
                                            <?php echo tpl_button('card_delete', ['id' => $card['id']], '✕', 'Löschen', 'ic danger', 'Karte „' . format_plain($card['title']) . '“ löschen?');
                                                  if ($ci < count($cols) - 1) echo tpl_button('card_step', ['id' => $card['id'], 'dir' => 1], '▶', 'In nächste Spalte'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($can_edit): ?>
                    <button type="button" class="kb-add" data-open="cardModal" data-title="Neue Karte" data-fill="<?php echo tpl_fill(['id' => '', 'col' => $col['id'], 'color' => 'cyan']); ?>">＋ Karte</button>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <?php if (!$cols): ?><div class="empty-msg" style="flex: 1;">Noch keine Spalten.</div><?php endif; ?>
    </div>

    <?php if (!$can_edit) return;
    tpl_modal_open('cardModal', 'Neue Karte', 'card_save', false, '720px'); ?>
        <input type="hidden" name="id" value="">
        <label class="field">Titel *</label>
        <input type="text" name="title" maxlength="160" required data-format="inline">
        <label class="field">Beschreibung</label>
        <textarea name="body" rows="7" data-format="text"></textarea>
        <div class="pm-row">
            <div style="flex: 1;"><label class="field">Spalte</label>
                <select name="col"><?php foreach ($cols as $col): ?><option value="<?php echo e($col['id']); ?>"><?php echo e(format_plain($col['name'])); ?></option><?php endforeach; ?></select></div>
            <div style="flex: 1;"><label class="field">Fällig am</label><input type="date" name="due"></div>
        </div>
        <label class="field">Labels (mit Komma trennen)</label>
        <input type="text" name="labels" maxlength="300" placeholder="Wichtig, Con, Bug">
        <label class="field">Farbe</label>
        <?php tpl_color_picker(); ?>
    <?php tpl_modal_close();
    tpl_modal_open('colModal', 'Neue Spalte', 'col_save'); ?>
        <input type="hidden" name="id" value="">
        <label class="field">Name *</label>
        <input type="text" name="name" maxlength="60" required placeholder="z. B. 🧪 Testen">
        <label class="field">Farbe</label>
        <?php tpl_color_picker(); ?>
    <?php tpl_modal_close(); ?>
    <script>
    // Drag & Drop für Karten
    (function () {
        const board = document.getElementById('kb');
        let drag = null;
        board.addEventListener('dragstart', e => {
            const card = e.target.closest('.kb-card');
            if (!card) return;
            drag = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.card);
        });
        board.addEventListener('dragend', () => { if (drag) drag.classList.remove('dragging'); board.querySelectorAll('.kb-col.over').forEach(c => c.classList.remove('over')); drag = null; });
        board.addEventListener('dragover', e => {
            const zone = e.target.closest('.kb-col');
            if (!drag || !zone) return;
            e.preventDefault();
            board.querySelectorAll('.kb-col.over').forEach(c => c !== zone && c.classList.remove('over'));
            zone.classList.add('over');
            const list = zone.querySelector('.kb-cards');
            const after = [...list.querySelectorAll('.kb-card:not(.dragging)')].find(c => e.clientY < c.getBoundingClientRect().top + c.offsetHeight / 2);
            if (after) list.insertBefore(drag, after); else list.append(drag);
        });
        board.addEventListener('drop', e => {
            const zone = e.target.closest('.kb-col');
            if (!drag || !zone) return;
            e.preventDefault();
            zone.classList.remove('over');
            const card = drag;
            const index = [...zone.querySelectorAll('.kb-card')].indexOf(card);
            board.querySelectorAll('.kb-col').forEach(c => c.querySelector('.kb-count').textContent = c.querySelectorAll('.kb-card').length);
            PageUI.ajax({ action: 'card_move', id: card.dataset.card, col: zone.dataset.col, index })
                .then(() => { card.classList.add('moved'); setTimeout(() => location.reload(), 350); })
                .catch(err => { alert(err.message); location.reload(); });
        });
    })();
    </script>
    <?php
}
