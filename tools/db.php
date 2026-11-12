<?php
/**
 * Datenbank-Werkzeug für die Kommandozeile (nicht über den Browser erreichbar).
 *
 *   php tools/db.php status                      Treiber, Server, Tabellen & Größen
 *   php tools/db.php config --host=localhost --port=3306 --name=dash --user=dash --pass=geheim [--prefix=gn_] [--socket=/pfad]
 *   php tools/db.php test                        MySQL-Verbindung prüfen
 *   php tools/db.php to-mysql                    alle SQLite-/JSON-Daten nach MySQL kopieren und umschalten
 *   php tools/db.php to-sqlite                   alles zurück nach SQLite kopieren und umschalten
 *   php tools/db.php use sqlite|mysql            nur umschalten (ohne Kopieren)
 *   php tools/db.php dump datei.sql              SQL-Sicherung (nur MySQL-Modus)
 *   php tools/db.php check | optimize            Prüfen bzw. aufräumen
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../inc/core/common.php';
db_load_modules();

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? 'status';
$opt = [];
foreach ($args as $a) if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) $opt[$m[1]] = $m[2];
$say = function (string $s) { fwrite(STDOUT, $s . "\n"); };
$fail = function (string $s) { fwrite(STDERR, "Fehler: $s\n"); exit(1); };

try {
    switch ($cmd) {
        case 'status':
            $c = db_config();
            $say('Treiber:  ' . db_driver() . ($c['driver'] === 'mysql' && db_driver() !== 'mysql' ? '  (MySQL eingestellt, aber pdo_mysql fehlt oder Datenbankname leer)' : ''));
            if ($c['name'] !== '') $say('MySQL:    ' . $c['user'] . '@' . ($c['socket'] ?: $c['host'] . ':' . $c['port']) . '/' . $c['name'] . ($c['prefix'] ? '  Präfix ' . $c['prefix'] : ''));
            foreach (array_keys(DB_MODULES) as $m) {
                $db = db_get($m);
                $say(sprintf('  %-9s %-28s %3d Tabellen  %8s KB   Schema v%d', $m, db_server_info($db), count(db_tables($db)), number_format(db_size($db) / 1024, 0, ',', '.'),
                     (int)$db->query('PRAGMA user_version')->fetchColumn()));
            }
            break;
        case 'config':
            if (!$opt) $fail('Bitte Werte angeben, z. B. --host=localhost --name=dash --user=dash --pass=…');
            db_config_save($opt);
            $say('Gespeichert in data/config.php (Treiber bleibt ' . db_config()['driver'] . ').');
            break;
        case 'test':
            $pdo = db_mysql_connect(db_config());
            $say('OK – ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
            break;
        case 'to-mysql':
        case 'to-sqlite':
            $to = $cmd === 'to-mysql' ? 'mysql' : 'sqlite';
            $from = $to === 'mysql' ? 'sqlite' : 'mysql';
            if ($to === 'mysql') db_mysql_connect(db_config());
            $sum = db_transfer($from, $to, $say);
            db_config_save(['driver' => $to]);
            $say("Fertig: {$sum['tables']} Tabellen, {$sum['rows']} Zeilen, {$sum['docs']} Dokumente. Aktiv: $to");
            break;
        case 'use':
            $to = ($args[0] ?? '') === 'mysql' ? 'mysql' : 'sqlite';
            db_config_save(['driver' => $to]);
            $say('Aktiv: ' . $to);
            break;
        case 'dump':
            if (!db_is_mysql()) $fail('Nur im MySQL-Modus – im SQLite-Modus einfach den Ordner data/db sichern.');
            $file = $args[0] ?? ('dump_' . date('Y-m-d_His') . '.sql');
            db_mysql_dump($file) ? $say('Gespeichert: ' . $file) : $fail('Konnte ' . $file . ' nicht schreiben');
            break;
        case 'check':
            foreach (array_keys(DB_MODULES) as $m) { $p = db_check(db_get($m)); $say(sprintf('  %-9s %s', $m, $p ? implode('; ', $p) : 'OK')); }
            break;
        case 'optimize':
            foreach (array_keys(DB_MODULES) as $m) { db_optimize(db_get($m)); $say('  ' . $m . ' optimiert'); }
            break;
        default:
            $fail('Unbekannter Befehl. Siehe Kopf der Datei tools/db.php');
    }
} catch (Throwable $e) {
    $fail($e->getMessage());
}
