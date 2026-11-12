<?php
/**
 * Backups: alle persönlichen Daten als ZIP (Download über backup.php, automatisch über den Aufgabenplaner).
 *
 * Enthalten: Datenbanken (SQLite-Kopien bzw. MySQL-SQL-Dump), JSON-Daten & Archive, hochgeladene Bilder.
 * Nicht enthalten: Zwischenspeicher (entsteht neu) und die Schlüssel in data/keys (bewusst getrennt aufbewahren).
 * Automatische Backups liegen in data/backups/ (per .htaccess gesperrt) – es werden nur die neuesten N behalten.
 */
require_once __DIR__ . '/common.php';

const BACKUP_DIR = DATA_DIR . '/backups';

/**
 * Backup-ZIP schreiben. $with_uploads = false lässt Bilder weg (kleiner, für automatische Backups).
 * Gibt ['ok' => bool, 'files' => n, 'size' => bytes] zurück.
 */
function backup_create(string $zip_path, bool $with_uploads = true): array {
    $files = [];   // Name im ZIP => Pfad
    $temp = [];    // danach löschen
    foreach (array_merge(json_glob(DATA_DIR . '/json/*.json'), json_glob(DATA_DIR . '/archive/*.json'), json_glob(DATA_DIR . '/archive/budget/*.json')) as $path) {
        if (($key = doc_key($path)) !== null) {   // MySQL-Modus: Dokument aus der Datenbank als Datei
            $t = tempnam(sys_get_temp_dir(), 'doc');
            file_put_contents($t, (string)doc_read($key));
            $temp[] = $t;
            $files['data/' . $key] = $t;
            continue;
        }
        $files[substr($path, strlen(APP_ROOT) + 1)] = $path;
    }
    if ($with_uploads) {
        foreach (glob(APP_ROOT . '/uploads/*.{jpg,png,gif,webp}', GLOB_BRACE) ?: [] as $path) $files['uploads/' . basename($path)] = $path;
        foreach (glob(DATA_DIR . '/uploads/furhub/*') ?: [] as $path) if (preg_match('/^[0-9a-f]{24}$/', basename($path))) $files['data/uploads/furhub/' . basename($path)] = $path;
    }
    if (db_is_mysql()) {
        $dump = sys_get_temp_dir() . '/db_backup_' . bin2hex(random_bytes(4)) . '.sql';
        if (db_mysql_dump($dump)) { $files['data/mysql-dump.sql'] = $dump; $temp[] = $dump; }
    } else {
        // SQLite: konsistente Kopie, auch wenn gerade geschrieben wird
        foreach (glob(DATA_DIR . '/db/*.sqlite') ?: [] as $dbf) {
            $copy = sys_get_temp_dir() . '/db_backup_' . bin2hex(random_bytes(4)) . '.sqlite';
            try {
                $pdo = new PDO('sqlite:' . $dbf);
                $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
            } catch (Throwable $e) {
                @copy($dbf, $copy);
            }
            if (is_file($copy)) { $files['data/db/' . basename($dbf)] = $copy; $temp[] = $copy; }
        }
    }
    $ok = false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $path) $zip->addFile($path, $name);
            $zip->addFromString('LIESMICH.txt', "Backup vom " . date('d.m.Y H:i') . "\nZum Wiederherstellen den Ordner data/ zurück in den Ordner der Webseite kopieren.\n"
                . (db_is_mysql() ? "MySQL: data/mysql-dump.sql einspielen (mysql -u BENUTZER -p DATENBANK < mysql-dump.sql).\n" : '')
                . "Hinweis: Schlüssel (data/keys) sind absichtlich nicht enthalten – bitte getrennt sichern, sonst müssen VRChat/Discord neu verbunden werden.\n");
            $ok = $zip->close();
        }
    }
    foreach ($temp as $t) @unlink($t);
    return ['ok' => $ok, 'files' => count($files), 'size' => $ok ? (int)filesize($zip_path) : 0];
}

/** Automatisches Backup anlegen und alte entfernen */
function backup_auto(int $keep): array {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    if (!is_file(BACKUP_DIR . '/.htaccess')) @file_put_contents(BACKUP_DIR . '/.htaccess', "Require all denied\n");
    $name = 'auto_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
    $r = backup_create(BACKUP_DIR . '/' . $name, false);
    $removed = 0;
    $list = backup_list();
    foreach (array_slice($list, max(1, $keep)) as $old) { if (@unlink(BACKUP_DIR . '/' . $old['name'])) $removed++; }
    return $r + ['name' => $name, 'removed' => $removed];
}

/** Vorhandene automatische Backups, neueste zuerst */
function backup_list(): array {
    $out = [];
    foreach (glob(BACKUP_DIR . '/auto_*.zip') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
    usort($out, function ($a, $b) { return $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']); });
    return $out;
}

function backup_file(string $name): ?string {
    if (!preg_match('/^auto_[0-9_\-]+_[0-9a-f]{6}\.zip$/', $name)) return null;
    $p = BACKUP_DIR . '/' . $name;
    return is_file($p) ? $p : null;
}
