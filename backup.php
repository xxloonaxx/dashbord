<?php
/**
 * Backup: lädt alle persönlichen Daten (Budget, Fixkosten, Con, Countdowns + Archive) als ZIP herunter.
 * Caches (Feed, Link-Check) werden nicht mitgesichert – die entstehen automatisch neu.
 * <title>BACKUP NODE</title>
 */
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/auth.php';
require_admin_page();

$skip = ['feed_cache.json', 'linkcheck_cache.json'];
$files = [];
foreach (array_merge(glob(__DIR__ . '/*.json') ?: [], glob(__DIR__ . '/archive/*.json') ?: [], glob(__DIR__ . '/archive_budget/*.json') ?: []) as $path) {
    if (in_array(basename($path), $skip, true)) continue;
    $files[substr($path, strlen(__DIR__) + 1)] = $path;
}

$stamp = date('Y-m-d_His');

// Kalender-Datenbank (SQLite) – konsistente Kopie, auch wenn gerade geschrieben wird
$cal_copy = null;
$cal_db = (glob(__DIR__ . '/data/calendar_*.sqlite') ?: [null])[0];
if ($cal_db) {
    $cal_copy = sys_get_temp_dir() . '/cal_backup_' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $cal_db);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($cal_copy));
    } catch (Throwable $e) {
        @copy($cal_db, $cal_copy);
    }
    if (is_file($cal_copy)) $files['data/' . basename($cal_db)] = $cal_copy;
}

if (class_exists('ZipArchive')) {
    $tmp = tempnam(sys_get_temp_dir(), 'bak');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $path) $zip->addFile($path, $name);
    $zip->addFromString('LIESMICH.txt', "Backup vom " . date('d.m.Y H:i') . "\nZum Wiederherstellen die Dateien zurück in den Ordner der Webseite kopieren.\n");
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="dashboard-backup_' . $stamp . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    if ($cal_copy) @unlink($cal_copy);
    exit;
}

// Fallback ohne ZIP-Erweiterung: alles in einer JSON-Datei
$bundle = [];
foreach ($files as $name => $path) $bundle[$name] = substr($name, -7) === '.sqlite' ? base64_encode((string)file_get_contents($path)) : load_json($path, null);
if ($cal_copy) @unlink($cal_copy);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="dashboard-backup_' . $stamp . '.json"');
echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
