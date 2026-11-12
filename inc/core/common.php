<?php
/**
 * Gemeinsame Hilfsfunktionen für alle Nodes.
 * Liegt bewusst in /inc, damit index.php die Datei nicht als eigene Seite auflistet.
 */

// Deutsche Zeitzone verwenden – viele Server stehen auf UTC, dann wären alle Uhrzeiten 1–2 Std. daneben
date_default_timezone_set('Europe/Berlin');

// ---------- Ordnerstruktur ----------
// /            Seiten (URLs bleiben stabil)
// /inc/core    Grundlagen (Datenbank, Login, Seiten, Widgets)   /inc/modules  Fach-Module   /inc/tpl  Seiten-Vorlagen
// /assets/css  /assets/js   /widgets   /uploads (öffentliche Galerie-Bilder)   /clients (Nexus-Desktop-Client)   /tools (CLI)
// /data        alles Private – per .htaccess gesperrt:
//              db/ (SQLite)  json/ (Daten-Dokumente)  cache/  archive/  keys/ (Schlüssel)  uploads/ (private Bilder)  config.php
if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__, 2));
if (!defined('DATA_DIR')) define('DATA_DIR', APP_ROOT . '/data');

/** Pfad im privaten Datenordner; legt Unterordner bei Bedarf an */
function data_path(string $rel): string {
    $p = DATA_DIR . '/' . ltrim($rel, '/');
    $d = substr($rel, -1) === '/' ? rtrim($p, '/') : dirname($p);
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $p;
}

/** Einmalig: Dateien der alten Ordnerstruktur an ihren neuen Platz verschieben */
function storage_upgrade(): void {
    if (is_file(DATA_DIR . '/.layout-v2')) return;
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    if (!is_file(DATA_DIR . '/.htaccess')) @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
    if (!is_file(DATA_DIR . '/index.html')) @file_put_contents(DATA_DIR . '/index.html', '');
    $move = function (string $from, string $to) {
        if (!file_exists($from) || file_exists($to)) return;
        if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true);
        @rename($from, $to);
    };
    foreach (['budget_data.json', 'fix_subs_data.json', 'budget_settings.json', 'con_data.json', 'countdown_data.json', 'feed_sources.json'] as $f) $move(APP_ROOT . '/' . $f, DATA_DIR . '/json/' . $f);
    foreach (glob(APP_ROOT . '/links_*.json') ?: [] as $f) $move($f, DATA_DIR . '/json/' . basename($f));
    foreach (['feed_cache.json', 'linkcheck_cache.json', 'linkcheck_job.json'] as $f) $move(APP_ROOT . '/' . $f, DATA_DIR . '/cache/' . $f);
    foreach (glob(APP_ROOT . '/archive/*') ?: [] as $f) $move($f, DATA_DIR . '/archive/' . basename($f));
    foreach (glob(APP_ROOT . '/archive_budget/*') ?: [] as $f) $move($f, DATA_DIR . '/archive/budget/' . basename($f));
    foreach (glob(DATA_DIR . '/*.sqlite*') ?: [] as $f) $move($f, DATA_DIR . '/db/' . basename($f));
    foreach (glob(DATA_DIR . '/.*_key_*.php') ?: [] as $f) $move($f, DATA_DIR . '/keys/' . basename($f));
    $move(APP_ROOT . '/.openbanking.php', DATA_DIR . '/keys/openbanking.php');
    $move(DATA_DIR . '/furhub_img', DATA_DIR . '/uploads/furhub');
    foreach (['archive', 'archive_budget'] as $d) if (is_dir(APP_ROOT . '/' . $d) && !(glob(APP_ROOT . '/' . $d . '/*') ?: [])) @rmdir(APP_ROOT . '/' . $d);
    @file_put_contents(DATA_DIR . '/.layout-v2', date('c'));
}
storage_upgrade();
require_once __DIR__ . '/database.php';

/** JSON-Datei laden; bei fehlender oder kaputter Datei den Standardwert liefern. */
function load_json(string $path, $default) {
    $key = doc_key($path);   // MySQL-Modus: Dokument aus der Datenbank
    if ($key !== null) {
        $raw = doc_read($key);
        if ($raw === null) return $default;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }
    if (!is_file($path)) return $default;
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $default;
}

/**
 * JSON-Datei sicher speichern: erst in eine temporäre Datei schreiben, dann umbenennen.
 * So bleibt die alte Datei intakt, falls beim Schreiben etwas schiefgeht.
 */
function save_json(string $path, $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $key = doc_key($path);
    if ($key !== null) return doc_write($key, $json);
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

/** Betrag im deutschen Format, z. B. 1.234,50 */
function eur($amount): string {
    return number_format((float)$amount, 2, ',', '.');
}

/** Kurzform für htmlspecialchars */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Deutscher Monatsname + Jahr, z. B. "November 2026" */
function german_month_year(?int $timestamp = null): string {
    $months = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
               'August', 'September', 'Oktober', 'November', 'Dezember'];
    $timestamp = $timestamp ?? time();
    return $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}

/** Nur http(s)-Links durchlassen (verhindert z. B. javascript:-Links aus fremden Feeds). */
function safe_url(string $url): string {
    return preg_match('#^https?://#i', trim($url)) ? trim($url) : '#';
}

/**
 * Betrag aus Formular oder CSV lesen. Versteht "12.50", "12,50" und "1.234,56".
 */
function parse_amount($raw): float {
    $s = preg_replace('/[^\d,.\-]/', '', trim((string)$raw));
    if ($s === '' || $s === '-') return 0.0;
    $lastComma = strrpos($s, ',');
    $lastDot = strrpos($s, '.');
    if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
        // Komma ist Dezimaltrenner -> Punkte sind Tausendertrenner
        $s = str_replace(['.', ','], ['', '.'], $s);
    } else {
        // Punkt ist Dezimaltrenner -> Kommas sind Tausendertrenner
        $s = str_replace(',', '', $s);
    }
    return round((float)$s, 2);
}

/** Deutsches Datum mit Wochentag, z. B. "Mi, 25. August 2027, 14:00 Uhr" */
function german_datetime(int $ts, bool $with_time = true): string {
    $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    $months = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
               'August', 'September', 'Oktober', 'November', 'Dezember'];
    $txt = $days[(int)date('w', $ts)] . ', ' . date('j', $ts) . '. ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    return $with_time ? $txt . ', ' . date('H:i', $ts) . ' Uhr' : $txt;
}

/** Favicon als Emoji (data-URI), damit jede Seite im Browser-Tab erkennbar ist. */
function emoji_favicon(string $emoji): string {
    return '<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>' . $emoji . '</text></svg>">';
}
