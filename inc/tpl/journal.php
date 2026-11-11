<?php
/** Vorlage „Tagebuch / Blog“: datierte Einträge mit Stimmung, Tags und Monatsarchiv */

const TPL_MOODS = ['' => '– keine –', '🤩' => 'Großartig', '😀' => 'Gut', '🙂' => 'Okay', '😐' => 'Neutral', '😕' => 'Naja', '😢' => 'Traurig',
                   '😡' => 'Wütend', '😴' => 'Müde', '🤒' => 'Krank', '🥳' => 'Party', '🐾' => 'Fursuiting'];

function tpl_journal_start(): array {
    return ['entries' => [['id' => tpl_id('j'), 'date' => date('Y-m-d'), 'title' => 'Erster Eintrag', 'mood' => '😀', 'tags' => ['Start'], 'created' => time(), 'updated' => time(),
                           'body' => "Hier kannst du Tagebuch führen, Con-Berichte schreiben oder einen kleinen Blog pflegen.\n\n**Formatierung** wie überall: Markdown, [i]BBCode[/i] oder <u>HTML</u>."]]];
}

function tpl_journal_post(array &$c, string $act, array $page): array {
    $id = (string)($_POST['id'] ?? '');
    if ($act === 'entry_save') {
        $date = tpl_date((string)($_POST['date'] ?? '')) ?? date('Y-m-d');
        $title = tpl_in('title', 160);
        $body = tpl_body();
        if ($title === '' && trim($body) === '') throw new InvalidArgumentException('Der Eintrag ist leer.');
        $data = ['date' => $date, 'title' => $title, 'body' => $body, 'mood' => isset(TPL_MOODS[$_POST['mood'] ?? '']) ? $_POST['mood'] : '',
                 'tags' => tpl_tags($_POST['tags'] ?? ''), 'updated' => time()];
        $i = $id !== '' ? tpl_index($c['entries'], $id) : null;
        if ($i === null) {
            $data += ['id' => tpl_id('j'), 'created' => time()];
            $c['entries'][] = $data;
            return ['msg' => 'Eintrag gespeichert.', 'anchor' => 'j-' . $data['id'], 'audit' => 'Tagebuch-Eintrag vom ' . date('d.m.Y', strtotime($date)) . ' angelegt'];
        }
        $c['entries'][$i] = $data + $c['entries'][$i];
        return ['msg' => 'Eintrag gespeichert.', 'anchor' => 'j-' . $id];
    }
    if ($act === 'entry_delete') {
        array_splice($c['entries'], tpl_need($c['entries'], $id), 1);
        return ['msg' => 'Eintrag gelöscht.'];
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function tpl_journal_live(array $c): string {
    $e = $c['entries'] ?? [];
    if (!$e) return 'noch leer';
    $last = max(array_column($e, 'date'));
    return count($e) . ' Einträge · zuletzt ' . date('d.m.', strtotime($last));
}

function tpl_journal_render(array $c, array $page, bool $can_edit): void {
    $entries = $c['entries'] ?? [];
    usort($entries, function ($a, $b) { return [$b['date'], $b['created']] <=> [$a['date'], $a['created']]; });
    $tags = [];
    $months = [];
    foreach ($entries as $en) {
        foreach ($en['tags'] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
        $m = substr($en['date'], 0, 7);
        $months[$m] = ($months[$m] ?? 0) + 1;
    }
    $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    $extra = $can_edit ? '<button type="button" class="btn btn-gold" data-open="entryModal" data-title="Neuer Eintrag" data-fill="' . tpl_fill(['id' => '', 'date' => date('Y-m-d'), 'mood' => '']) . '">＋ Neuer Eintrag</button>' : '';
    tpl_filter_bar('#jr .jr-entry', 'Einträge durchsuchen …', $tags, $extra); ?>
    <div class="jr-layout">
        <div class="jr" id="jr">
            <?php $cur = null; foreach ($entries as $en):
                $ts = strtotime($en['date']);
                $m = substr($en['date'], 0, 7);
                if ($m !== $cur): $cur = $m; ?>
                    <h2 class="jr-month" id="m-<?php echo e($m); ?>"><?php echo e(german_month_year($ts)); ?></h2>
                <?php endif; ?>
                <article class="jr-entry panel" id="j-<?php echo e($en['id']); ?>" data-search="<?php echo tpl_search_text($en['title'], $en['body'], implode(' ', $en['tags']), date('d.m.Y', $ts)); ?>"
                         data-tags="|<?php echo e(mb_strtolower(implode('|', $en['tags']))); ?>|">
                    <div class="jr-date"><b><?php echo date('j', $ts); ?></b><span><?php echo $days[(int)date('w', $ts)]; ?></span></div>
                    <div class="jr-main">
                        <header>
                            <h3><?php echo $en['mood'] !== '' ? '<span class="jr-mood" title="' . e(TPL_MOODS[$en['mood']] ?? '') . '">' . e($en['mood']) . '</span> ' : ''; ?><?php echo $en['title'] !== '' ? e($en['title']) : e(german_datetime($ts, false)); ?></h3>
                            <?php if ($can_edit): ?>
                                <span class="acts">
                                    <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="entryModal" data-title="Eintrag bearbeiten"
                                            data-fill="<?php echo tpl_fill(['id' => $en['id'], 'date' => $en['date'], 'title' => $en['title'], 'body' => $en['body'], 'mood' => $en['mood'], 'tags' => implode(', ', $en['tags'])]); ?>">✎</button>
                                    <?php echo tpl_button('entry_delete', ['id' => $en['id']], '🗑', 'Löschen', 'ic danger', 'Eintrag vom ' . date('d.m.Y', $ts) . ' löschen?'); ?>
                                </span>
                            <?php endif; ?>
                        </header>
                        <div class="fmt"><?php echo format_text($en['body']); ?></div>
                        <?php if ($en['tags']): ?><div class="jr-tags"><?php echo tpl_tag_list($en['tags']); ?></div><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <div class="empty-msg" data-empty-for="#jr .jr-entry" <?php echo $entries ? 'hidden' : ''; ?>><?php echo $entries ? 'Kein Eintrag passt zur Suche.' : 'Noch keine Einträge.'; ?></div>
        </div>
        <?php if ($months): ?>
            <aside class="panel jr-side">
                <div class="panel-title">Archiv</div>
                <?php foreach ($months as $m => $n): ?>
                    <a href="#m-<?php echo e($m); ?>"><?php echo e(german_month_year(strtotime($m . '-01'))); ?> <small><?php echo $n; ?></small></a>
                <?php endforeach; ?>
            </aside>
        <?php endif; ?>
    </div>

    <?php if (!$can_edit) return;
    tpl_modal_open('entryModal', 'Neuer Eintrag', 'entry_save', false, '820px'); ?>
        <input type="hidden" name="id" value="">
        <div class="pm-row">
            <div><label class="field">Datum</label><input type="date" name="date" required></div>
            <div style="flex: 1;"><label class="field">Titel</label><input type="text" name="title" maxlength="160" placeholder="optional"></div>
            <div><label class="field">Stimmung</label><select name="mood"><?php foreach (TPL_MOODS as $k => $n): ?><option value="<?php echo e($k); ?>"><?php echo e(trim($k . ' ' . $n)); ?></option><?php endforeach; ?></select></div>
        </div>
        <label class="field">Text</label>
        <textarea name="body" rows="14" data-format="text"></textarea>
        <label class="field">Tags (mit Komma trennen)</label>
        <input type="text" name="tags" maxlength="300" placeholder="Con, Urlaub, VRChat">
    <?php tpl_modal_close();
}
