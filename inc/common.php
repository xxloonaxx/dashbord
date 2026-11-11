<?php
/**
 * Gemeinsame Hilfsfunktionen für alle Nodes.
 * Liegt bewusst in /inc, damit index.php die Datei nicht als eigene Seite auflistet.
 */

/** JSON-Datei laden; bei fehlender oder kaputter Datei den Standardwert liefern. */
function load_json(string $path, $default) {
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
