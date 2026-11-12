<?php
/**
 * Backup: lädt alle persönlichen Daten (Datenbanken, Budget, Con, Countdowns, Link-Hubs, Archive, Bilder) als ZIP herunter.
 * Caches (Feed, Link-Check) werden nicht mitgesichert – die entstehen automatisch neu.
 * Mit ?auto=<name> wird ein automatisches Backup aus data/backups heruntergeladen.
 * <title>BACKUP NODE</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/auth.php';
require_once __DIR__ . '/inc/core/backup.php';
require_permission('backup.download');

if (isset($_GET['auto'])) {
    $f = backup_file((string)$_GET['auto']);
    if (!$f) { http_response_code(404); exit('Backup nicht gefunden.'); }
    audit('Automatisches Backup heruntergeladen', basename($f));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

audit('Backup heruntergeladen');
$stamp = date('Y-m-d_His');
if (!class_exists('ZipArchive')) {
    // Ohne ZIP-Erweiterung: JSON-Daten als eine Datei (Datenbanken bitte direkt sichern)
    $bundle = [];
    foreach (array_merge(json_glob(DATA_DIR . '/json/*.json'), json_glob(DATA_DIR . '/archive/*.json'), json_glob(DATA_DIR . '/archive/budget/*.json')) as $p) {
        $bundle[substr($p, strlen(APP_ROOT) + 1)] = load_json($p, null);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="dashboard-backup_' . $stamp . '.json"');
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
$tmp = tempnam(sys_get_temp_dir(), 'bak');
$r = backup_create($tmp, true);
if (!$r['ok']) { @unlink($tmp); http_response_code(500); exit('Backup konnte nicht erstellt werden.'); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="dashboard-backup_' . $stamp . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
