<?php
/**
 * Budget: Einstellungen (Kategorie-Limits, Sparziele, Regeln), Fälligkeit von Fixkosten/Abos mit Rhythmus,
 * Kategorien mit Icons, Monatsstatistik aus dem Archiv und Erkennung wiederkehrender Zahlungen.
 */
require_once __DIR__ . '/../core/common.php';

const BUDGET_DATA_FILE = DATA_DIR . '/json/budget_data.json';
const BUDGET_FIX_FILE = DATA_DIR . '/json/fix_subs_data.json';
const BUDGET_SETTINGS_FILE = DATA_DIR . '/json/budget_settings.json';
const BUDGET_ARCHIVE_DIR = DATA_DIR . '/archive/budget/';
const BUDGET_SECTIONS = ['income' => ['Einnahmen', '💼', '#50fa7b'], 'fixed' => ['Fixkosten', '🏠', '#38d5e6'], 'subs' => ['Abos', '🔁', '#bd93f9'], 'other' => ['Sonstige Ausgaben', '🛍️', '#f3c262']];
const BUDGET_INTERVALS = ['monthly' => ['monatlich', 1], 'quarterly' => ['vierteljährlich', 3], 'halfyear' => ['halbjährlich', 6], 'yearly' => ['jährlich', 12]];

/** Kategorie => Icon (für Anzeige und Vorschläge) */
const BUDGET_CATEGORY_ICONS = [
    'Lohn / Abschlag' => '💼', 'Einnahme' => '💰', 'Rückzahlung' => '↩️', 'Wohnen' => '🏠', 'Energie' => '⚡', 'KFZ' => '🚗', 'Versicherung' => '🛡️',
    'Fixkosten' => '📌', 'Abo' => '🔁', 'Media' => '📺', 'Streaming' => '📺', 'Gaming' => '🎮', 'Software' => '💻', 'Telefon / Internet' => '📱',
    'Lebensmittel' => '🛒', 'Drogerie' => '🧴', 'Tanken' => '⛽', 'Mobilität' => '🚆', 'Essen gehen' => '🍔', 'Online-Shopping' => '📦',
    'Kleidung' => '👕', 'Gesundheit' => '💊', 'Freizeit' => '🎟️', 'Cons & Events' => '🎪', 'Geschenke' => '🎁', 'Haustier' => '🐾',
    'Sparen' => '🐷', 'Sport' => '🏋️', 'Bildung' => '📚', 'Shopping / Sonstiges' => '🛍️', 'Sonstiges' => '💸',
];

function budget_cat_icon(string $cat): string {
    if (isset(BUDGET_CATEGORY_ICONS[$cat])) return BUDGET_CATEGORY_ICONS[$cat];
    foreach (BUDGET_CATEGORY_ICONS as $k => $i) if (mb_stripos($cat, explode(' ', $k)[0]) !== false) return $i;
    return '💸';
}

/** Gleichbleibende Farbe je Kategorie (für Diagramme) */
function budget_cat_color(string $cat): string {
    $palette = ['#38d5e6', '#f3c262', '#bd93f9', '#50fa7b', '#ff79c6', '#ff9f43', '#7aa2f7', '#2dd4bf', '#f87171', '#a3e635', '#e879f9', '#facc15', '#60a5fa', '#fb7185'];
    return $palette[abs(crc32(mb_strtolower($cat))) % count($palette)];
}

// ---------- Einstellungen ----------

function budget_settings(): array {
    $s = load_json(BUDGET_SETTINGS_FILE, []);
    return $s + ['limits' => [], 'goals' => [], 'rules' => [], 'dismissed' => [], 'savings_target' => 0];
}

function budget_settings_save(array $s): void {
    save_json(BUDGET_SETTINGS_FILE, $s);
}

function budget_rules(): array {
    static $rules = null;
    if ($rules === null) $rules = budget_settings()['rules'];
    return $rules;
}

/** Eigene Regel passend zum Buchungstext? → ['section' => …|'', 'category' => …, 'title' => …|''] */
function budget_rule_match(string $text): ?array {
    $t = mb_strtolower($text);
    foreach (budget_rules() as $r) {
        $m = mb_strtolower(trim((string)($r['match'] ?? '')));
        if ($m !== '' && mb_strpos($t, $m) !== false) return $r;
    }
    return null;
}

// ---------- Fälligkeit der Vorlagen ----------

/** Monatsnummer (1–12) und Jahr zu einem Budget-Monatsnamen wie „November 2026“ */
function budget_month_of(string $month_name): array {
    $months = ['januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7,
               'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12];
    if (preg_match('/(\p{L}+)\s+(\d{4})/u', mb_strtolower($month_name), $m) && isset($months[$m[1]])) return [$months[$m[1]], (int)$m[2]];
    return [(int)date('n'), (int)date('Y')];
}

function budget_tpl_due(array $tpl, int $month): bool {
    $step = BUDGET_INTERVALS[$tpl['interval'] ?? 'monthly'][1] ?? 1;
    if ($step === 1) return true;
    $start = (int)($tpl['start_month'] ?? $month);
    return ((($month - $start) % $step) + $step) % $step === 0;
}

/** Monatlicher Anteil einer Vorlage (z. B. Jahresbeitrag / 12) */
function budget_tpl_monthly(array $tpl): float {
    return (float)$tpl['amount'] / (BUDGET_INTERVALS[$tpl['interval'] ?? 'monthly'][1] ?? 1);
}

/** Nächste Fälligkeit (Zeitstempel) ab heute */
function budget_tpl_next(array $tpl): int {
    $day = max(1, min(28, (int)($tpl['day'] ?? 1)));
    $t = mktime(0, 0, 0, (int)date('n'), $day, (int)date('Y'));
    for ($i = 0; $i < 24; $i++) {
        $c = strtotime("+$i month", $t);
        if ($c >= strtotime('today') && budget_tpl_due($tpl, (int)date('n', $c))) return $c;
    }
    return $t;
}

// ---------- Auswertung ----------

function budget_entry_ts(array $e): int {
    $d = (string)($e['date'] ?? '');
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}))?/', $d, $m)) return mktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), 0, (int)$m[2], (int)$m[1], (int)$m[3]);
    return strtotime($d) ?: 0;
}

/** Kennzahlen eines Monats */
function budget_summary(array $entries): array {
    $s = ['income' => 0.0, 'fixed' => 0.0, 'subs' => 0.0, 'other' => 0.0, 'open' => 0.0, 'open_income' => 0.0, 'cats' => [], 'count' => count($entries)];
    foreach ($entries as $e) {
        $sec = isset(BUDGET_SECTIONS[$e['section'] ?? '']) ? $e['section'] : 'other';
        $a = (float)$e['amount'];
        $s[$sec] += $a;
        if (($e['status'] ?? '') === 'Offen') { if ($sec === 'income') $s['open_income'] += $a; else $s['open'] += $a; }
        if ($sec !== 'income') {
            $c = trim((string)($e['category'] ?? '')) ?: 'Sonstiges';
            $s['cats'][$c] = ($s['cats'][$c] ?? 0) + $a;
        }
    }
    arsort($s['cats']);
    $s['expense'] = $s['fixed'] + $s['subs'] + $s['other'];
    $s['net'] = $s['income'] - $s['expense'];
    $s['rate'] = $s['income'] > 0 ? round($s['net'] / $s['income'] * 100) : null;
    return $s;
}

/** Alle archivierten Monate + aktueller Monat, chronologisch */
function budget_history(array $current): array {
    $list = [];
    foreach (glob(BUDGET_ARCHIVE_DIR . '*.json') ?: [] as $f) {
        $d = load_json($f, null);
        if (!$d || !isset($d['entries'])) continue;
        [$m, $y] = budget_month_of((string)($d['month_name'] ?? ''));
        $list[] = ['name' => (string)($d['month_name'] ?? '?'), 'key' => sprintf('%04d-%02d', $y, $m), 'file' => basename($f), 'entries' => $d['entries'], 'current' => false];
    }
    [$m, $y] = budget_month_of((string)($current['month_name'] ?? ''));
    $list[] = ['name' => (string)($current['month_name'] ?? ''), 'key' => sprintf('%04d-%02d', $y, $m), 'file' => '', 'entries' => $current['entries'], 'current' => true];
    usort($list, function ($a, $b) { return [$a['key'], $a['current'] ? 1 : 0] <=> [$b['key'], $b['current'] ? 1 : 0]; });
    return $list;
}

/** Normalisierter Händlername zum Gruppieren („HEM Tankstelle 1234“ → „hem tankstelle“) */
function budget_merchant(string $title): string {
    $t = mb_strtolower($title);
    $t = preg_replace('/^(zahlung (von|an)|kartenzahlung|lastschrift|überweisung)\s+/u', '', $t);
    $t = preg_replace('/(www\.|\.com|\.de|\.net|\*.*$|\/.*$|\d{3,}|[#,;:]+)/u', ' ', $t);
    $words = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($t)) ?: [], function ($w) { return mb_strlen($w) > 1; })), 0, 3);
    return implode(' ', $words);
}

/**
 * Wiederkehrende Zahlungen unter „Sonstige“ finden, die (noch) keine Vorlage sind:
 * gleicher Händler in mindestens zwei verschiedenen Monaten mit ähnlichem Betrag.
 */
function budget_recurring(array $history, array $fix_subs, array $dismissed): array {
    $tpl_names = [];
    foreach (['fixed', 'subs'] as $sec) foreach ($fix_subs[$sec] ?? [] as $t) $tpl_names[] = budget_merchant($t['title']);
    $seen = [];
    foreach (array_slice($history, -6) as $h) {
        foreach ($h['entries'] as $e) {
            if (($e['section'] ?? '') !== 'other' || !empty($e['template_id'])) continue;
            $k = budget_merchant((string)$e['title']);
            if ($k === '' || in_array($k, $tpl_names, true) || in_array($k, $dismissed, true)) continue;
            $seen[$k][$h['key']][] = (float)$e['amount'];
            $seen[$k]['_title'] = $e['title'];
            $seen[$k]['_cat'] = $e['category'] ?? '';
        }
    }
    $out = [];
    foreach ($seen as $k => $months) {
        $title = $months['_title'];
        $cat = $months['_cat'];
        unset($months['_title'], $months['_cat']);
        if (count($months) < 2) continue;
        $amounts = array_map(function ($l) { return min($l); }, $months);
        $avg = array_sum($amounts) / count($amounts);
        if ($avg <= 0 || max($amounts) - min($amounts) > max(1.0, $avg * 0.15)) continue;
        $out[] = ['key' => $k, 'title' => $title, 'amount' => round($avg, 2), 'months' => count($months), 'category' => $cat];
    }
    usort($out, function ($a, $b) { return $b['months'] <=> $a['months'] ?: $b['amount'] <=> $a['amount']; });
    return array_slice($out, 0, 8);
}
