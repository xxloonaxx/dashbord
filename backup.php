<?php
/**
 * Backup: lädt alle persönlichen Daten (Budget, Fixkosten, Con, Countdowns + Archive) als ZIP herunter.
 * Caches (Feed, Link-Check) werden nicht mitgesichert – die entstehen automatisch neu.
 * <title>BACKUP NODE</title>
 */
require_once __DIR__ . '/inc/core/common.php';
require_once __DIR__ . '/inc/core/auth.php';
require_permission('backup.download');
audit('Backup heruntergeladen');

$skip = ['feed_cache.json', 'linkcheck_cache.json'];
$files = [];
$doc_copies = [];
foreach (array_merge(json_glob(DATA_DIR . '/json/*.json'), json_glob(DATA_DIR . '/archive/*.json'), json_glob(DATA_DIR . '/archive/budget/*.json')) as $path) {
    if (in_array(basename($path), $skip, true)) continue;
    if (($key = doc_key($path)) !== null) {   // MySQL-Modus: Dokument aus der Datenbank als Datei mitgeben
        $tmpdoc = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmpdoc, (string)doc_read($key));
        $doc_copies[] = $tmpdoc;
        $path = $tmpdoc;
        $files['data/' . $key] = $path;
        continue;
    }
    $files[substr($path, strlen(APP_ROOT) + 1)] = $path;
}

// Hochgeladene Bilder (Galerie-Vorlage)
foreach (glob(__DIR__ . '/uploads/*.{jpg,png,gif,webp}', GLOB_BRACE) ?: [] as $path) $files['uploads/' . basename($path)] = $path;
// Furry Hub: eigene Bilder (Fursona-Emotionen, Tamagotchi, Karten)
foreach (glob(DATA_DIR . '/uploads/furhub/*') ?: [] as $path) if (preg_match('/^[0-9a-f]{24}$/', basename($path))) $files['data/uploads/furhub/' . basename($path)] = $path;

$stamp = date('Y-m-d_His');

// Kalender-Datenbank (SQLite) – konsistente Kopie, auch wenn gerade geschrieben wird
// SQLite-Datenbanken (Benutzer & Seiten, Kalender) – konsistente Kopie, auch wenn gerade geschrieben wird
$db_copies = [];
if (db_is_mysql()) {
    // MySQL: vollständige SQL-Sicherung aller Tabellen
    $dump = sys_get_temp_dir() . '/db_backup_' . bin2hex(random_bytes(4)) . '.sql';
    if (db_mysql_dump($dump)) { $files['data/mysql-dump.sql'] = $dump; $db_copies[] = $dump; }
}
foreach (db_is_mysql() ? [] : (glob(DATA_DIR . '/db/*.sqlite') ?: []) as $dbf) {
    $copy = sys_get_temp_dir() . '/db_backup_' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $dbf);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
    } catch (Throwable $e) {
        @copy($dbf, $copy);
    }
    if (is_file($copy)) { $files['data/db/' . basename($dbf)] = $copy; $db_copies[] = $copy; }
}

if (class_exists('ZipArchive')) {
    $tmp = tempnam(sys_get_temp_dir(), 'bak');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $path) $zip->addFile($path, $name);
    $zip->addFromString('LIESMICH.txt', "Backup vom " . date('d.m.Y H:i') . "\nZum Wiederherstellen den Ordner data/ zurück in den Ordner der Webseite kopieren.\n"
        . (db_is_mysql() ? "MySQL: data/mysql-dump.sql einspielen (mysql -u BENUTZER -p DATENBANK < mysql-dump.sql) – oder unter Admin → Datenbank.\n" : ''));
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="dashboard-backup_' . $stamp . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    foreach (array_merge($db_copies, $doc_copies) as $c) @unlink($c);
    exit;
}

// Fallback ohne ZIP-Erweiterung: alles in einer JSON-Datei
$bundle = [];
foreach ($files as $name => $path) if (strpos($name, 'data/uploads/') !== 0) $bundle[$name] = in_array(substr($name, -7), ['.sqlite', 'ump.sql'], true) ? base64_encode((string)file_get_contents($path)) : json_decode((string)file_get_contents($path), true);
foreach (array_merge($db_copies, $doc_copies) as $c) @unlink($c);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="dashboard-backup_' . $stamp . '.json"');
echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
