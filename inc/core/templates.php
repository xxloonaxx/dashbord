<?php
/**
 * Vorlagen für eigene Seiten (page.php). Jede Vorlage liegt in inc/tpl/<name>.php und stellt bereit:
 *   tpl_<name>_start(): array                       – Startinhalt einer neuen Seite
 *   tpl_<name>_normalize(array $c, array $page)     – (optional) alte Daten auf das aktuelle Format bringen
 *   tpl_<name>_post(array &$c, string $act, array $page): array  – Änderung ausführen
 *        Rückgabe: ['msg' => …, 'anchor' => …, 'audit' => …, 'json' => […]]; Fehler als InvalidArgumentException
 *   tpl_<name>_render(array $c, array $page, bool $can_edit): void
 *   tpl_<name>_live(array $c): string               – (optional) Kurzinfo für die Kachel im Index
 */
require_once __DIR__ . '/format.php';

const TPL_COLORS = ['cyan' => '#38d5e6', 'gold' => '#f3c262', 'green' => '#50fa7b', 'purple' => '#bd93f9', 'red' => '#ff5555',
                    'pink' => '#ff79c6', 'orange' => '#ffb86c', 'grey' => '#8b98ad'];
const TPL_COLOR_NAMES = ['cyan' => 'Cyan', 'gold' => 'Gold', 'green' => 'Grün', 'purple' => 'Lila', 'red' => 'Rot', 'pink' => 'Pink', 'orange' => 'Orange', 'grey' => 'Grau'];

foreach (glob(__DIR__ . '/../tpl/*.php') ?: [] as $tpl_file) require_once $tpl_file;

function tpl_call(string $tpl, string $fn, ...$args) {
    $f = 'tpl_' . $tpl . '_' . $fn;
    return function_exists($f) ? $f(...$args) : null;
}

function tpl_color(?string $k): string { return TPL_COLORS[$k] ?? TPL_COLORS['cyan']; }
function tpl_color_key($v, string $default = 'cyan'): string { return isset(TPL_COLORS[$v]) ? $v : $default; }
function tpl_id(string $prefix): string { return $prefix . bin2hex(random_bytes(5)); }
function tpl_in(string $key, int $max): string { return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max); }
function tpl_body(string $key = 'body', int $max = 100000): string { return mb_substr(str_replace("\r\n", "\n", (string)($_POST[$key] ?? '')), 0, $max); }

/** "a, #b c" -> ['a', 'b c'] */
function tpl_tags($raw): array {
    $out = [];
    foreach (preg_split('/[,;]+/', (string)$raw) as $t) {
        $t = mb_substr(trim(ltrim(trim($t), '#')), 0, 30);
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) $out[] = $t;
    }
    return array_slice($out, 0, 12);
}

function tpl_index(array $list, string $id): ?int {
    foreach ($list as $i => $x) if (($x['id'] ?? null) === $id) return $i;
    return null;
}

function tpl_need(array $list, string $id): int {
    $i = tpl_index($list, $id);
    if ($i === null) throw new InvalidArgumentException('Eintrag nicht gefunden – vielleicht wurde er gerade gelöscht.');
    return $i;
}

function tpl_move(array &$list, int $i, int $dir): void {
    $j = $i + ($dir < 0 ? -1 : 1);
    if (!isset($list[$i], $list[$j])) return;
    [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
}

function tpl_date(string $ymd): ?string {
    $d = DateTime::createFromFormat('!Y-m-d', $ymd);
    return $d && $d->format('Y-m-d') === $ymd ? $ymd : null;
}

function tpl_when(int $ts): string {
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'heute, ' . date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'gestern, ' . date('H:i', $ts);
    return date('d.m.Y', $ts);
}

function tpl_num($v): string {
    $f = (float)$v;
    return number_format($f, abs($f - round($f)) > 0.0001 ? 2 : 0, ',', '.');
}

/** data-fill-Attribut für Dialoge */
function tpl_fill(array $data): string {
    return e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Kleines Formular mit nur einem Knopf (Löschen, Anheften, Verschieben …) */
function tpl_button(string $action, array $fields, string $label, string $title, string $class = 'ic', string $confirm = ''): string {
    $h = '<form method="POST" class="inl"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>';
    $h .= '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<button type="submit" class="' . e($class) . '" title="' . e($title) . '" aria-label="' . e($title) . '">' . $label . '</button></form>';
}

function tpl_color_picker(string $name = 'color'): void {
    echo '<div class="swatches">';
    foreach (TPL_COLORS as $k => $hex) {
        echo '<label title="' . e(TPL_COLOR_NAMES[$k]) . '"><input type="radio" name="' . e($name) . '" value="' . $k . '"' . ($k === 'cyan' ? ' checked' : '') . '><span style="--c:' . $hex . '"></span></label>';
    }
    echo '</div>';
}

/** Dialog öffnen/schließen (Inhalt dazwischen frei) */
function tpl_modal_open(string $id, string $title, string $action, bool $upload = false, string $width = ''): void {
    echo '<div class="pm" id="' . e($id) . '" role="dialog" aria-modal="true" aria-labelledby="' . e($id) . 'T" hidden>';
    echo '<form method="POST" class="pm-box"' . ($upload ? ' enctype="multipart/form-data"' : '') . ($width ? ' style="max-width:' . e($width) . '"' : '') . '>';
    echo '<button type="button" class="pm-x" data-close aria-label="Schließen">&times;</button>';
    echo '<div class="pm-title" id="' . e($id) . 'T">' . e($title) . '</div>';
    echo '<input type="hidden" name="action" value="' . e($action) . '">';
}

function tpl_modal_close(string $submit = 'Speichern'): void {
    echo '<div class="pm-actions"><button type="submit" class="btn btn-gold">' . e($submit) . '</button><button type="button" class="btn" data-close>Abbrechen</button>'
       . '<span class="pm-hint">Strg+Enter speichert</span></div></form></div>';
}

/** Suchfeld + optionale Tag-Filter für eine Liste von Elementen mit data-search / data-tags */
function tpl_filter_bar(string $target, string $placeholder, array $tags = [], string $extra = ''): void {
    echo '<div class="tb"><input type="search" class="tb-search" placeholder="' . e($placeholder) . '" data-filter="' . e($target) . '" aria-label="' . e($placeholder) . '">' . $extra . '</div>';
    if ($tags) {
        arsort($tags);
        echo '<div class="chips" data-chips="' . e($target) . '"><button type="button" class="chip on" data-tag="">Alle</button>';
        foreach ($tags as $t => $n) echo '<button type="button" class="chip" data-tag="' . e(mb_strtolower($t)) . '">#' . e($t) . ' <small>' . $n . '</small></button>';
        echo '</div>';
    }
}

function tpl_tag_list(array $tags): string {
    return implode(' ', array_map(function ($t) { return '<span class="tag">#' . e($t) . '</span>'; }, $tags));
}

function tpl_search_text(string ...$parts): string {
    return e(mb_strtolower(format_plain(implode(' ', $parts)), 'UTF-8'));
}
