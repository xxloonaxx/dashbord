<?php
/** Vorlage „Notizen“: beliebig viele Notizen als Karten – mit Farben, Tags, Anheften, Suche und Formatierung */

function tpl_notes_start(): array {
    return ['notes' => [[
        'id' => tpl_id('n'), 'title' => 'Willkommen 👋', 'color' => 'gold', 'pinned' => true, 'tags' => ['Info'], 'created' => time(), 'updated' => time(),
        'body' => "Das ist deine erste Notiz. Du kannst beliebig viele anlegen – oben auf **＋ Neue Notiz**.\n\n"
                . "- **Markdown**: `**fett**`, `*kursiv*`, `# Überschrift`, `- Liste`\n"
                . "- [b]BBCode[/b]: `[b]`, `[color=gold]`, `[quote]`, `[spoiler]`\n"
                . "- <mark>HTML</mark>: `<b>`, `<span style=\"…\">`, `<table>`\n\n"
                . "> Tipp: Mit 📌 bleibt eine Notiz immer oben.\n\n||Spoiler: Klick mich!|| :paw:",
    ]]];
}

function tpl_notes_normalize(array $c, array $page): array {
    if (!isset($c['notes'])) {
        // Früher gab es pro Seite nur einen einzigen Text – der wird zur ersten Notiz
        $c['notes'] = [];
        if (trim($c['markdown'] ?? '') !== '') {
            $ts = strtotime($page['updated_at'] ?? 'now') ?: time();
            $c['notes'][] = ['id' => tpl_id('n'), 'title' => $page['title'], 'body' => $c['markdown'], 'color' => 'cyan', 'pinned' => false,
                             'tags' => [], 'created' => $ts, 'updated' => $ts];
        }
        unset($c['markdown']);
    }
    return $c;
}

function tpl_notes_post(array &$c, string $act, array $page): array {
    $id = (string)($_POST['id'] ?? '');
    if ($act === 'note_save') {
        $title = tpl_in('title', 120);
        $body = tpl_body();
        if ($title === '' && trim($body) === '') throw new InvalidArgumentException('Die Notiz ist leer – bitte Titel oder Text eingeben.');
        $data = ['title' => $title, 'body' => $body, 'color' => tpl_color_key($_POST['color'] ?? ''), 'pinned' => !empty($_POST['pinned']),
                 'tags' => tpl_tags($_POST['tags'] ?? ''), 'updated' => time()];
        $i = $id !== '' ? tpl_index($c['notes'], $id) : null;
        if ($i === null) {
            $data += ['id' => tpl_id('n'), 'created' => time()];
            $c['notes'][] = $data;
            return ['msg' => 'Notiz angelegt.', 'anchor' => 'n-' . $data['id'], 'audit' => 'Notiz „' . ($title ?: 'ohne Titel') . '“ angelegt'];
        }
        $c['notes'][$i] = $data + $c['notes'][$i];
        return ['msg' => 'Notiz gespeichert.', 'anchor' => 'n-' . $id];
    }
    $i = tpl_need($c['notes'], $id);
    switch ($act) {
        case 'note_pin':
            $c['notes'][$i]['pinned'] = empty($c['notes'][$i]['pinned']);
            return ['anchor' => 'n-' . $id];
        case 'note_dup':
            $copy = $c['notes'][$i];
            $copy['id'] = tpl_id('n');
            $copy['title'] = trim($copy['title'] . ' (Kopie)');
            $copy['created'] = $copy['updated'] = time();
            $copy['pinned'] = false;
            $c['notes'][] = $copy;
            return ['msg' => 'Notiz kopiert.', 'anchor' => 'n-' . $copy['id']];
        case 'note_delete':
            $title = $c['notes'][$i]['title'];
            array_splice($c['notes'], $i, 1);
            return ['msg' => 'Notiz gelöscht.', 'audit' => 'Notiz „' . ($title ?: 'ohne Titel') . '“ gelöscht'];
    }
    throw new InvalidArgumentException('Unbekannte Aktion.');
}

function tpl_notes_live(array $c): string {
    $n = count($c['notes'] ?? []);
    return $n === 1 ? '1 Notiz' : $n . ' Notizen';
}

function tpl_notes_render(array $c, array $page, bool $can_edit): void {
    $notes = $c['notes'];
    usort($notes, function ($a, $b) { return [!empty($b['pinned']), $b['updated']] <=> [!empty($a['pinned']), $a['updated']]; });
    $tags = [];
    foreach ($notes as $n) foreach ($n['tags'] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
    $extra = '<select class="tb-sort" data-sort="#notesGrid" aria-label="Sortierung"><option value="updated">Zuletzt geändert</option><option value="created">Neueste zuerst</option>'
           . '<option value="title">Titel A–Z</option><option value="color">Nach Farbe</option></select>';
    if ($can_edit) $extra .= '<button type="button" class="btn btn-gold" data-open="noteModal" data-title="Neue Notiz" data-fill="' . tpl_fill(['id' => '', 'color' => 'cyan']) . '">＋ Neue Notiz</button>';
    tpl_filter_bar('#notesGrid > .note', 'Notizen durchsuchen …', $tags, $extra);
    ?>
    <div class="notes-grid" id="notesGrid">
        <?php foreach ($notes as $n): $col = tpl_color($n['color']); ?>
            <article class="note<?php echo !empty($n['pinned']) ? ' pinned' : ''; ?>" id="n-<?php echo e($n['id']); ?>" style="--c: <?php echo $col; ?>;"
                     data-search="<?php echo tpl_search_text($n['title'], $n['body'], implode(' ', $n['tags'])); ?>"
                     data-tags="|<?php echo e(mb_strtolower(implode('|', $n['tags']))); ?>|" data-pinned="<?php echo !empty($n['pinned']) ? 1 : 0; ?>"
                     data-updated="<?php echo (int)$n['updated']; ?>" data-created="<?php echo (int)$n['created']; ?>" data-title="<?php echo e(mb_strtolower($n['title'])); ?>" data-color="<?php echo e($n['color']); ?>">
                <header>
                    <h3><?php echo $n['title'] !== '' ? e($n['title']) : '<span class="muted">Ohne Titel</span>'; ?></h3>
                    <?php if (!empty($n['pinned'])): ?><span class="note-pin" title="Angeheftet">📌</span><?php endif; ?>
                </header>
                <div class="fmt note-body clamp"><?php echo format_text($n['body']); ?></div>
                <footer>
                    <span class="note-tags"><?php echo tpl_tag_list($n['tags']); ?></span>
                    <span class="note-date" title="Erstellt <?php echo date('d.m.Y H:i', $n['created']); ?>"><?php echo tpl_when((int)$n['updated']); ?></span>
                    <?php if ($can_edit): ?>
                        <span class="acts">
                            <button type="button" class="ic" title="Bearbeiten" aria-label="Bearbeiten" data-open="noteModal" data-title="Notiz bearbeiten"
                                    data-fill="<?php echo tpl_fill(['id' => $n['id'], 'title' => $n['title'], 'body' => $n['body'], 'color' => $n['color'], 'pinned' => !empty($n['pinned']), 'tags' => implode(', ', $n['tags'])]); ?>">✎</button>
                            <?php echo tpl_button('note_pin', ['id' => $n['id']], '📌', !empty($n['pinned']) ? 'Lösen' : 'Anheften');
                                  echo tpl_button('note_dup', ['id' => $n['id']], '⧉', 'Kopieren');
                                  echo tpl_button('note_delete', ['id' => $n['id']], '🗑', 'Löschen', 'ic danger', 'Notiz „' . ($n['title'] ?: 'ohne Titel') . '“ wirklich löschen?'); ?>
                        </span>
                    <?php endif; ?>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="empty-msg" data-empty-for="#notesGrid > .note" <?php echo $notes ? 'hidden' : ''; ?>><?php echo $notes ? 'Keine Notiz passt zur Suche.' : 'Noch keine Notizen.'; ?></div>

    <?php if ($can_edit):
        tpl_modal_open('noteModal', 'Neue Notiz', 'note_save', false, '820px'); ?>
        <input type="hidden" name="id" value="">
        <label class="field">Titel</label>
        <input type="text" name="title" maxlength="120" placeholder="z. B. Einkaufsliste, Con-Infos, Ideen …">
        <label class="field">Text</label>
        <textarea name="body" rows="14" data-format="text" placeholder="Markdown, BBCode oder HTML – die Hilfe gibt's unter ?"></textarea>
        <div class="pm-row">
            <div><label class="field">Farbe</label><?php tpl_color_picker(); ?></div>
            <div style="flex: 1;"><label class="field">Tags (mit Komma trennen)</label><input type="text" name="tags" maxlength="300" placeholder="Arbeit, Con, Wichtig"></div>
        </div>
        <label class="chk"><input type="checkbox" name="pinned" value="1"> 📌 Oben anheften</label>
        <?php tpl_modal_close();
    endif;
}
