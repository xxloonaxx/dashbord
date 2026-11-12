<?php
/**
 * Datenbank-Schicht: SQLite (Standard, ohne Einrichtung) oder MySQL / MariaDB.
 *
 * Alle Module sprechen über db_get('<modul>') mit ihrer Datenbank. Im SQLite-Modus hat jedes Modul
 * eine eigene Datei in data/db/, im MySQL-Modus liegen alle Tabellen in EINER Datenbank – mit
 * Präfix je Modul (z. B. cal_events, nx_friends), damit sich gleichnamige Tabellen nicht stören.
 *
 * Die Module schreiben weiter SQLite-SQL. Die Klasse Db übersetzt das bei MySQL automatisch:
 *  INSERT OR IGNORE/REPLACE, ON CONFLICT … DO UPDATE (excluded.x), datetime('now', …), strftime('%s', …),
 *  PRAGMA user_version (→ Tabelle schema_versions), CREATE TABLE (Typen, Schlüssel, Fremdschlüssel),
 *  COLLATE NOCASE, MAX(a, b), group_concat(x, sep), reservierte Spaltennamen (key, read) …
 *
 * Einstellungen stehen in data/config.php (wird über Admin → Datenbank gepflegt).
 */

const DB_MODULES = [
    // Modul       SQLite-Datei     MySQL-Präfix   Migrationsfunktion  Fremdschlüssel prüfen
    'site'     => ['file' => 'site',     'prefix' => '',      'migrate' => 'site_migrate', 'fk' => true,  'label' => 'Benutzer, Seiten, Rechte, Protokoll'],
    'calendar' => ['file' => 'calendar', 'prefix' => 'cal_',  'migrate' => 'cal_migrate',  'fk' => true,  'label' => 'Kalender'],
    'feed'     => ['file' => 'feed',     'prefix' => 'feed_', 'migrate' => 'feed_migrate', 'fk' => true,  'label' => 'Live-Feed / RSS'],
    'vrchat'   => ['file' => 'vrcx',     'prefix' => 'nx_',   'migrate' => 'vrc_migrate',  'fk' => false, 'label' => 'VRC Nexus'],
    'furhub'   => ['file' => 'furhub',   'prefix' => 'fh_',   'migrate' => 'fh_migrate',   'fk' => false, 'label' => 'Furry Hub'],
];
/** Dateien, in denen die Migrationsfunktionen stehen (für Umzug & Werkzeuge) */
const DB_MODULE_FILES = ['site' => '/core/site.php', 'calendar' => '/modules/calendar.php', 'feed' => '/modules/feed.php',
                         'vrchat' => '/modules/vrchat.php', 'furhub' => '/modules/furhub.php'];

const DB_CONFIG_FILE = DATA_DIR . '/config.php';

// ---------- Konfiguration ----------

function db_config(): array {
    if (isset($GLOBALS['__db_cfg'])) return $GLOBALS['__db_cfg'];
    $cfg = ['driver' => 'sqlite', 'host' => '127.0.0.1', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'prefix' => '', 'socket' => ''];
    if (is_file(DB_CONFIG_FILE)) {
        $raw = (string)@file_get_contents(DB_CONFIG_FILE);
        $data = json_decode(substr($raw, (int)strpos($raw, "\n") + 1), true);
        if (is_array($data)) $cfg = array_merge($cfg, array_intersect_key($data, $cfg));
    }
    return $GLOBALS['__db_cfg'] = $cfg;
}

function db_config_save(array $new): bool {
    $cfg = array_merge(db_config(), array_intersect_key($new, db_config()));
    $cfg['port'] = (int)$cfg['port'] ?: 3306;
    $cfg['prefix'] = preg_replace('/[^a-z0-9_]/i', '', (string)$cfg['prefix']);
    $cfg['driver'] = $cfg['driver'] === 'mysql' ? 'mysql' : 'sqlite';
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    $content = "<?php http_response_code(404); exit; ?>\n" . json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = DB_CONFIG_FILE . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!rename($tmp, DB_CONFIG_FILE)) return false;
    $GLOBALS['__db_cfg'] = $cfg;
    return true;
}

function db_cfg(): array { return db_config(); }

/** Aktiver Treiber: 'sqlite' oder 'mysql' (fällt auf SQLite zurück, wenn pdo_mysql fehlt) */
function db_driver(): string {
    if (!empty($GLOBALS['__db_driver_override'])) return $GLOBALS['__db_driver_override'];
    $c = db_cfg();
    return ($c['driver'] === 'mysql' && $c['name'] !== '' && in_array('mysql', PDO::getAvailableDrivers(), true)) ? 'mysql' : 'sqlite';
}

function db_is_mysql(): bool { return db_driver() === 'mysql'; }

// ---------- Verbindungen ----------

/** Datenbank eines Moduls (legt Tabellen bei Bedarf an) */
function db_get(string $module, ?string $driver = null): Db {
    static $pool = [];
    $driver = $driver ?: db_driver();
    if (isset($pool[$driver][$module])) return $pool[$driver][$module];
    if (!isset(DB_MODULES[$module])) throw new InvalidArgumentException('Unbekanntes Datenbank-Modul: ' . $module);
    $m = DB_MODULES[$module];
    $db = $driver === 'mysql' ? db_open_mysql(db_cfg(), $module) : db_open_sqlite($module);
    $pool[$driver][$module] = $db;
    if (function_exists($m['migrate'])) ($m['migrate'])($db);
    return $db;
}

function db_sqlite_dir(): string {
    $dir = DATA_DIR . '/db';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}

/** Datei der SQLite-Datenbank (zufälliger Namensteil, damit sie auch ohne .htaccess nicht abrufbar ist) */
function db_sqlite_file(string $module): string {
    $name = DB_MODULES[$module]['file'];
    $dir = db_sqlite_dir();
    $found = glob($dir . '/' . $name . '_*.sqlite') ?: [];
    if ($found) return $found[0];
    $new = $dir . '/' . $name . '_' . bin2hex(random_bytes(12)) . '.sqlite';
    if (is_file($dir . '/' . $name . '.sqlite')) rename($dir . '/' . $name . '.sqlite', $new);   // ältere Installation
    return $new;
}

function db_open_sqlite(string $module): Db {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        http_response_code(500);
        exit('<p style="font-family:sans-serif;padding:30px">Auf dem Server fehlt die PHP-Erweiterung <b>pdo_sqlite</b>. '
           . 'Alternativ kann unter Admin → Datenbank MySQL eingerichtet werden (dafür data/config.php anlegen).</p>');
    }
    $db = new Db('sqlite:' . db_sqlite_file($module), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->init('sqlite', $module, '');
    if (DB_MODULES[$module]['fk']) $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    return $db;
}

/** Verbindung zu MySQL/MariaDB (wirft PDOException mit verständlicher Meldung) */
function db_mysql_connect(array $c): PDO {
    $dsn = $c['socket'] !== '' ? 'mysql:unix_socket=' . $c['socket'] : 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'];
    $dsn .= ($c['name'] !== '' ? ';dbname=' . $c['name'] : '') . ';charset=utf8mb4';
    return new Db($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                                 PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_TIMEOUT => 5]);
}

function db_open_mysql(array $c, string $module): Db {
    try {
        $db = db_mysql_connect($c);
    } catch (PDOException $e) {
        http_response_code(503);
        exit('<p style="font-family:sans-serif;padding:30px">Die MySQL-Datenbank ist gerade nicht erreichbar.<br><small>' . htmlspecialchars($e->getMessage()) . '</small></p>');
    }
    $db->init('mysql', $module, $c['prefix'] . DB_MODULES[$module]['prefix'], $c['prefix']);
    return $db;
}

// ---------- Hilfen ----------

/** Liste der Tabellen eines Moduls (ohne Präfix) */
function db_tables(Db $db): array {
    if (!$db->mysql) return $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    return $db->tableNames();
}

/** Spalten einer Tabelle (ohne Präfix) */
function db_columns(Db $db, string $table): array {
    if (!$db->mysql) return array_column($db->query('PRAGMA table_info(' . $db->quote($table) . ')')->fetchAll(), 'name');
    $st = $db->rawPrepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $st->execute([$db->prefix . $table]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Größe der Daten eines Moduls in Byte */
function db_size(Db $db): int {
    if (!$db->mysql) { $f = db_sqlite_file($db->module); return (int)@filesize($f) + (int)@filesize($f . '-wal'); }
    $names = array_map(function ($t) use ($db) { return $db->prefix . $t; }, db_tables($db));
    if (!$names) return 0;
    $st = $db->rawPrepare('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
    $st->execute($names);
    return (int)$st->fetchColumn();
}

/** Größe aller Datenbanken zusammen (ohne Module zu öffnen) */
function db_size_all(): int {
    if (!db_is_mysql()) { $n = 0; foreach (glob(DATA_DIR . '/db/*.sqlite*') ?: [] as $f) $n += (int)filesize($f); return $n; }
    $db = db_get('site');
    $st = $db->rawPrepare('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?');
    $st->execute([$db->globalPrefix . '%']);
    return (int)$st->fetchColumn();
}

/** Server-Version als Text */
function db_server_info(Db $db): string {
    if (!$db->mysql) return 'SQLite ' . $db->query('SELECT sqlite_version()')->fetchColumn();
    $v = (string)$db->getAttribute(PDO::ATTR_SERVER_VERSION);
    return (stripos($v, 'mariadb') !== false ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*$/', '', preg_replace('/^5\.5\.5-/', '', $v));
}

/** Datenbank aufräumen/optimieren */
function db_optimize(Db $db): void {
    if (!$db->mysql) { $db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $db->exec('VACUUM'); $db->exec('PRAGMA optimize'); return; }
    foreach (db_tables($db) as $t) $db->rawQuery('OPTIMIZE TABLE `' . $db->prefix . $t . '`')->fetchAll();
}

/** Integritätsprüfung – liefert Liste der Probleme (leer = alles gut) */
function db_check(Db $db): array {
    $out = [];
    if (!$db->mysql) {
        foreach ($db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) as $r) if ($r !== 'ok') $out[] = $r;
        if (DB_MODULES[$db->module]['fk']) foreach ($db->query('PRAGMA foreign_key_check')->fetchAll() as $r) $out[] = 'Verwaister Eintrag in ' . $r['table'] . ' (rowid ' . $r['rowid'] . ')';
        return $out;
    }
    foreach (db_tables($db) as $t) foreach ($db->rawQuery('CHECK TABLE `' . $db->prefix . $t . '`')->fetchAll() as $r) {
        if (!in_array(strtolower($r['Msg_text'] ?? ''), ['ok', 'table is already up to date'], true) && ($r['Msg_type'] ?? '') !== 'note') $out[] = $t . ': ' . $r['Msg_text'];
    }
    return $out;
}

// ---------- JSON-Dokumente (im MySQL-Modus in der Tabelle documents statt als Datei) ----------

/** Liegt diese Datei im MySQL-Modus in der Datenbank? (data/json/… und data/archive/…) */
function doc_key(string $path): ?string {
    if (!db_is_mysql()) return null;
    $root = DATA_DIR . '/';
    if (strpos($path, $root) !== 0) return null;
    $rel = substr($path, strlen($root));
    return (strpos($rel, 'json/') === 0 || strpos($rel, 'archive/') === 0) ? $rel : null;
}

function doc_db(): Db {
    static $ready = false;
    $db = db_get('site');
    if (!$ready) {
        $db->rawExec('CREATE TABLE IF NOT EXISTS `' . $db->globalPrefix . 'documents` (name VARCHAR(191) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY, body LONGTEXT NOT NULL, updated BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $ready = true;
    }
    return $db;
}
function doc_table(): string { return '`' . doc_db()->globalPrefix . 'documents`'; }

function doc_read(string $key): ?string {
    $st = doc_db()->rawPrepare('SELECT body FROM ' . doc_table() . ' WHERE name = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function doc_write(string $key, string $body, ?int $time = null): bool {
    return doc_db()->rawPrepare('REPLACE INTO ' . doc_table() . ' (name, body, updated) VALUES (?, ?, ?)')->execute([$key, $body, $time ?? time()]);
}
function doc_meta(string $key): ?array {
    $st = doc_db()->rawPrepare('SELECT updated, LENGTH(body) AS size FROM ' . doc_table() . ' WHERE name = ?');
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

/** Wie is_file() – auch für Dokumente in der Datenbank */
function json_exists(string $path): bool { $k = doc_key($path); return $k === null ? is_file($path) : doc_meta($k) !== null; }
/** Wie filemtime() */
function json_mtime(string $path): int { $k = doc_key($path); return $k === null ? (int)@filemtime($path) : (int)(doc_meta($k)['updated'] ?? 0); }
/** Wie filesize() */
function json_size(string $path): int { $k = doc_key($path); return $k === null ? (int)@filesize($path) : (int)(doc_meta($k)['size'] ?? 0); }
/** Wie unlink() */
function json_delete(string $path): bool {
    $k = doc_key($path);
    if ($k === null) return @unlink($path);
    return doc_db()->rawPrepare('DELETE FROM ' . doc_table() . ' WHERE name = ?')->execute([$k]);
}
/** Wie rename() */
function json_rename(string $from, string $to): bool {
    $kf = doc_key($from); $kt = doc_key($to);
    if ($kf === null && $kt === null) { if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true); return @rename($from, $to); }
    $body = $kf === null ? @file_get_contents($from) : doc_read($kf);
    if ($body === null || $body === false) return false;
    if ($kt === null) { if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true); $ok = file_put_contents($to, $body) !== false; }
    else $ok = doc_write($kt, $body);
    return $ok && json_delete($from);
}
/** Wie glob() für JSON-Dateien, z. B. json_glob(DATA_DIR . '/archive/con_*.json') */
function json_glob(string $pattern): array {
    $k = doc_key($pattern);
    if ($k === null) return glob($pattern) ?: [];
    $dir = dirname($k) . '/';
    $like = $dir . '%';   // _ als Platzhalter ist egal – der Regex unten prüft genau
    $st = doc_db()->rawPrepare('SELECT name FROM ' . doc_table() . ' WHERE name LIKE ? ORDER BY name');
    $st->execute([$like]);
    $re = '/^' . str_replace(['\\*', '\\?'], ['[^\/]*', '[^\/]'], preg_quote($k, '/')) . '$/';
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) if (preg_match($re, $n)) $out[] = DATA_DIR . '/' . $n;
    return $out;
}

// ---------- Umzug zwischen SQLite und MySQL ----------

/** Alle Modul-Dateien laden (Migrationsfunktionen) */
function db_load_modules(): void {
    foreach (DB_MODULE_FILES as $f) require_once dirname(__DIR__) . $f;
}

/**
 * Alle Daten von $from nach $to kopieren ('sqlite' → 'mysql' oder umgekehrt).
 * Die Quelle bleibt unverändert. Tabellen im Ziel werden vorher geleert.
 * $log erhält Fortschrittszeilen. Gibt eine Zusammenfassung zurück.
 */
function db_transfer(string $from, string $to, ?callable $log = null): array {
    @set_time_limit(0);
    db_load_modules();
    $log = $log ?: function ($s) {};
    $sum = ['tables' => 0, 'rows' => 0, 'docs' => 0];
    foreach (array_keys(DB_MODULES) as $m) {
        $src = db_get($m, $from);
        $dst = db_get($m, $to);
        $dst->foreignKeys(false);
        foreach (db_tables($src) as $t) {
            if (in_array($t, ['schema_versions', 'documents'], true)) continue;
            if (!in_array($t, db_tables($dst), true)) { $log("⚠ $m/$t: Tabelle fehlt im Ziel – übersprungen"); continue; }
            $cols = array_values(array_intersect(db_columns($src, $t), db_columns($dst, $t)));
            if (!$cols) continue;
            $dst->beginTransaction();
            $dst->exec('DELETE FROM ' . $t);
            $q = function ($c) use ($src) { return $src->mysql ? '`' . $c . '`' : '"' . $c . '"'; };
            $sel = $src->mysql ? $src->rawQuery('SELECT ' . implode(', ', array_map($q, $cols)) . ' FROM `' . $src->prefix . $t . '`')
                               : $src->query('SELECT ' . implode(', ', array_map($q, $cols)) . ' FROM "' . $t . '"');
            $qd = function ($c) use ($dst) { return $dst->mysql ? '`' . $c . '`' : '"' . $c . '"'; };
            $ins = $dst->rawPrepare('INSERT INTO ' . ($dst->mysql ? '`' . $dst->prefix . $t . '`' : '"' . $t . '"') . ' (' . implode(', ', array_map($qd, $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
            $n = 0;
            while ($row = $sel->fetch(PDO::FETCH_NUM)) { $ins->execute($row); $n++; }
            $dst->commit();
            $sum['tables']++; $sum['rows'] += $n;
            $log("✔ $m/$t: $n Zeilen");
        }
        $dst->foreignKeys(true);
    }
    // JSON-Dokumente
    $files = [];
    foreach (['json', 'archive'] as $d) {
        $it = is_dir(DATA_DIR . '/' . $d) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR . '/' . $d, FilesystemIterator::SKIP_DOTS)) : [];
        foreach ($it as $f) if ($f->isFile() && substr($f->getFilename(), -5) === '.json') $files[] = substr($f->getPathname(), strlen(DATA_DIR) + 1);
    }
    if ($to === 'mysql') {
        $GLOBALS['__db_driver_override'] = 'mysql';
        foreach ($files as $rel) { doc_write($rel, (string)file_get_contents(DATA_DIR . '/' . $rel), (int)filemtime(DATA_DIR . '/' . $rel)); $sum['docs']++; }
        unset($GLOBALS['__db_driver_override']);
    } else {
        $GLOBALS['__db_driver_override'] = 'mysql';
        foreach (doc_db()->rawQuery('SELECT name, body, updated FROM ' . doc_table()) as $r) {
            $p = DATA_DIR . '/' . $r['name'];
            if (!is_dir(dirname($p))) @mkdir(dirname($p), 0755, true);
            file_put_contents($p, $r['body']); @touch($p, (int)$r['updated']);
            $sum['docs']++;
        }
        unset($GLOBALS['__db_driver_override']);
    }
    $log('✔ ' . $sum['docs'] . ' JSON-Dokumente übertragen');
    return $sum;
}

/** SQL-Dump aller MySQL-Tabellen (für das Backup) in eine Datei schreiben */
function db_mysql_dump(string $file): bool {
    $GLOBALS['__db_driver_override'] = 'mysql';
    $db = doc_db();
    $fh = fopen($file, 'w');
    if (!$fh) return false;
    fwrite($fh, "-- GHOST NODE Datenbank-Sicherung vom " . date('d.m.Y H:i') . "\n-- Einspielen: mysql -u BENUTZER -p DATENBANK < datei.sql\nSET NAMES utf8mb4;\nSET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES,NO_ENGINE_SUBSTITUTION';\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    $st = $db->rawPrepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME");
    $st->execute([$db->globalPrefix . '%']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (strpos($t, $db->globalPrefix) !== 0) continue;
        $create = $db->rawQuery('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
        $rows = $db->rawQuery('SELECT * FROM `' . $t . '`');
        while ($r = $rows->fetch(PDO::FETCH_NUM)) {
            fwrite($fh, 'INSERT INTO `' . $t . '` VALUES (' . implode(', ', array_map(function ($v) use ($db) { return $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $db->quote((string)$v)); }, $r)) . ");\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);
    unset($GLOBALS['__db_driver_override']);
    return true;
}

// =====================================================================================
// PDO mit SQLite→MySQL-Übersetzung
// =====================================================================================

class DbStatement extends PDOStatement {
    protected function __construct() {}
    /** Ganze Zahlen als Zahl binden (sonst schreibt MySQL bei LIMIT ? z. B. LIMIT '10') */
    #[\ReturnTypeWillChange]
    public function execute($params = null) {
        if (!$params) return parent::execute();
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (strpos((string)$k, ':') === 0 ? $k : ':' . $k);
            $type = is_int($v) ? PDO::PARAM_INT : (is_bool($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            $this->bindValue($key, is_bool($v) ? (int)$v : $v, $type);
        }
        return parent::execute();
    }
}

class Db extends PDO {
    /** @var bool */ public $mysql = false;
    /** @var string */ public $module = '';
    /** @var string Präfix der Tabellen dieses Moduls */ public $prefix = '';
    /** @var string Präfix der gesamten Installation */ public $globalPrefix = '';
    /** @var bool */ public $mariadb = false;
    /** @var string */ public $serverVersion = '';
    private $known = null;
    private $cache = [];
    private $colTypes = [];

    public function init(string $driver, string $module, string $prefix, string $globalPrefix = ''): void {
        $this->mysql = $driver === 'mysql';
        $this->module = $module;
        $this->prefix = $prefix;
        $this->globalPrefix = $globalPrefix;
        if (!$this->mysql) return;
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DbStatement::class]);
        $v = (string)$this->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->mariadb = stripos($v, 'mariadb') !== false;
        $this->serverVersion = preg_replace('/^5\.5\.5-/', '', $v);
        $tz = date('P');
        parent::exec("SET SESSION sql_mode = 'PIPES_AS_CONCAT,NO_BACKSLASH_ESCAPES,NO_ENGINE_SUBSTITUTION', time_zone = '" . $tz . "'");
        parent::exec('CREATE TABLE IF NOT EXISTS `' . $globalPrefix . 'schema_versions` (module VARCHAR(64) NOT NULL PRIMARY KEY, version INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    // ---- unübersetzter Zugriff ----
    public function rawQuery(string $sql) { return parent::query($sql); }
    public function rawPrepare(string $sql) { return parent::prepare($sql); }
    public function rawExec(string $sql) { return parent::exec($sql); }

    public function foreignKeys(bool $on): void {
        if ($this->mysql) parent::exec('SET FOREIGN_KEY_CHECKS = ' . ($on ? 1 : 0));
        elseif (DB_MODULES[$this->module]['fk'] ?? false) parent::exec('PRAGMA foreign_keys = ' . ($on ? 'ON' : 'OFF'));
    }

    public function tableNames(): array {
        $st = parent::prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME');
        $st->execute([$this->prefix . '%']);
        $out = [];
        $all = array_map(function ($m) { return $this->globalPrefix . $m['prefix']; }, DB_MODULES);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (strpos($t, $this->prefix) !== 0) continue;
            // Tabellen anderer Module mit längerem Präfix ausschließen (Site hat das kürzeste Präfix)
            $foreign = false;
            foreach ($all as $p) if (strlen($p) > strlen($this->prefix) && strpos($t, $p) === 0) $foreign = true;
            if ($foreign) continue;
            $name = substr($t, strlen($this->prefix));
            if (in_array($name, ['schema_versions', 'documents'], true) && $this->prefix === $this->globalPrefix) continue;
            $out[] = $name;
        }
        return $out;
    }

    // ---- Transaktionen: MySQL beendet sie bei CREATE/ALTER selbst ----
    #[\ReturnTypeWillChange]
    public function commit() { if ($this->mysql && !$this->inTransaction()) return true; return parent::commit(); }
    #[\ReturnTypeWillChange]
    public function rollBack() { if ($this->mysql && !$this->inTransaction()) return true; return parent::rollBack(); }

    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = []) {
        return parent::prepare($this->mysql ? $this->translate($sql) : $sql, $options);
    }

    #[\ReturnTypeWillChange]
    public function query($sql, $mode = null, ...$args) {
        if ($this->mysql) {
            if (preg_match('/^\s*PRAGMA\s+user_version\s*;?\s*$/i', $sql)) $sql = 'SELECT ' . $this->schemaVersion() . ' AS user_version';
            elseif (preg_match('/^\s*PRAGMA\b/i', $sql)) $sql = 'SELECT 0 WHERE 1 = 0';
            else $sql = $this->translate($sql);
        }
        return $mode === null ? parent::query($sql) : parent::query($sql, $mode, ...$args);
    }

    #[\ReturnTypeWillChange]
    public function exec($sql) {
        if (!$this->mysql) return parent::exec($sql);
        $n = 0;
        foreach (self::split($sql) as $part) {
            if (preg_match('/^\s*PRAGMA\s+user_version\s*=\s*(\d+)\s*$/i', $part, $m)) {
                parent::prepare('REPLACE INTO `' . $this->globalPrefix . 'schema_versions` (module, version) VALUES (?, ?)')->execute([$this->module, (int)$m[1]]);
                continue;
            }
            if (preg_match('/^\s*(PRAGMA|VACUUM|ANALYZE)\b/i', $part)) continue;
            foreach ($this->translateDdl($part) as $sqlPart) {
                try {
                    $r = parent::exec($sqlPart);
                    $n += (int)$r;
                } catch (PDOException $e) {
                    // bereits vorhandene Spalte/Index/Tabelle (MySQL kann kein ADD COLUMN IF NOT EXISTS) → ignorieren
                    if (in_array((int)($e->errorInfo[1] ?? 0), [1050, 1060, 1061, 1091], true)) continue;
                    throw new PDOException($e->getMessage() . ' [SQL: ' . mb_substr($sqlPart, 0, 300) . ']', 0, $e);
                }
            }
        }
        return $n;
    }

    public function schemaVersion(): int {
        $st = parent::prepare('SELECT version FROM `' . $this->globalPrefix . 'schema_versions` WHERE module = ?');
        $st->execute([$this->module]);
        return (int)$st->fetchColumn();
    }

    /** Mehrere Anweisungen an ; trennen (nicht in Zeichenketten) */
    public static function split(string $sql): array {
        $lits = [];
        $masked = self::mask($sql, $lits);
        $out = [];
        foreach (explode(';', $masked) as $p) if (trim($p) !== '') $out[] = self::unmask($p, $lits);
        return $out;
    }

    /** Zeichenketten durch Platzhalter ersetzen und Kommentare entfernen */
    public static function mask(string $sql, array &$lits): string {
        $out = ''; $n = strlen($sql); $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === $c) { if ($c !== '`' && $j + 1 < $n && $sql[$j + 1] === $c) { $j += 2; continue; } break; }
                    $j++;
                }
                $lits[] = substr($sql, $i, $j - $i + 1);
                $out .= "\x01" . (count($lits) - 1) . "\x02";
                $i = $j + 1;
                continue;
            }
            if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-') { $j = strpos($sql, "\n", $i); $i = $j === false ? $n : $j; continue; }
            if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') { $j = strpos($sql, '*/', $i + 2); $i = $j === false ? $n : $j + 2; $out .= ' '; continue; }
            $out .= $c; $i++;
        }
        return $out;
    }

    public static function unmask(string $s, array $lits): string {
        return preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($lits) { return $lits[(int)$m[1]]; }, $s);
    }

    /** Wert einer maskierten Zeichenkette ('…') oder null */
    private static function litValue(string $arg, array $lits): ?string {
        if (!preg_match('/^\s*\x01(\d+)\x02\s*$/', $arg, $m)) return null;
        $l = $lits[(int)$m[1]];
        if ($l[0] === '`') return null;
        return str_replace($l[0] . $l[0], $l[0], substr($l, 1, -1));
    }

    /** Argumente einer Funktion ab der öffnenden Klammer $open → [args, Position der schließenden Klammer] */
    private static function args(string $s, int $open): array {
        $depth = 0; $args = []; $cur = ''; $n = strlen($s);
        for ($i = $open; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '(') { if ($depth++ > 0) $cur .= $c; continue; }
            if ($c === ')') { if (--$depth === 0) { $args[] = $cur; return [$args, $i]; } $cur .= $c; continue; }
            if ($c === ',' && $depth === 1) { $args[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        return [$args, $n - 1];
    }

    /** Bekannte Tabellen dieses Moduls (für tabelle.spalte) */
    private function known(): array {
        if ($this->known === null) $this->known = array_flip($this->tableNames());
        return $this->known;
    }

    /** SQLite-SQL → MySQL-SQL */
    public function translate(string $sql): string {
        if (isset($this->cache[$sql])) return $this->cache[$sql];
        $lits = [];
        $s = self::mask($sql, $lits);
        $s = $this->prefixTables($s);
        $s = $this->rewriteFunctions($s, $lits);
        // INSERT OR …
        $conflictNothing = preg_match('/\bON\s+CONFLICT\b[^;]*?\bDO\s+NOTHING\b/i', $s);
        $s = preg_replace('/\bON\s+CONFLICT\b[^;]*?\bDO\s+NOTHING\b/i', '', $s);
        if ($conflictNothing) $s = preg_replace('/^\s*INSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $s);
        $s = preg_replace('/\bINSERT\s+OR\s+IGNORE\s+INTO\b/i', 'INSERT IGNORE INTO', $s);
        $s = preg_replace('/\bINSERT\s+OR\s+REPLACE\s+INTO\b/i', 'REPLACE INTO', $s);
        $s = preg_replace('/\bUPDATE\s+OR\s+(IGNORE|REPLACE)\s+/i', 'UPDATE IGNORE ', $s);
        $s = preg_replace('/\bON\s+CONFLICT\s*(\([^)]*\))?\s*DO\s+UPDATE\s+SET\b/i', 'ON DUPLICATE KEY UPDATE', $s);
        $s = preg_replace('/\bexcluded\.(\w+)/i', 'VALUES($1)', $s);
        $s = preg_replace('/\bCOLLATE\s+NOCASE\b/i', 'COLLATE utf8mb4_general_ci', $s);
        $s = preg_replace('/\bCOLLATE\s+BINARY\b/i', 'COLLATE utf8mb4_bin', $s);
        $s = preg_replace('/\bCAST\s*\((.+?)\s+AS\s+INTEGER\s*\)/i', 'CAST($1 AS SIGNED)', $s);
        $s = preg_replace('/\bCAST\s*\((.+?)\s+AS\s+REAL\s*\)/i', 'CAST($1 AS DOUBLE)', $s);
        $s = preg_replace('/\bCAST\s*\((.+?)\s+AS\s+TEXT\s*\)/i', 'CAST($1 AS CHAR)', $s);
        $s = self::quoteReserved($s);
        return $this->cache[$sql] = self::unmask($s, $lits);
    }

    /** Spaltennamen, die in MySQL reserviert sind (klein geschrieben = Spalte, groß = Schlüsselwort) */
    private static function quoteReserved(string $s): string {
        return preg_replace('/(?<![\w`])(key|read|rank|groups|row|rows|range|usage|condition|lead|lag|window|system|function|interval|match|change)(?![\w`])/', '`$1`', $s);
    }

    private const NOT_TABLES = ['select', 'set', 'if', 'values', 'dual', 'information_schema', 'where', 'lateral'];

    /** Tabellennamen mit dem Modul-Präfix versehen */
    private function prefixTables(string $s): string {
        if ($this->prefix === '') return $s;
        $p = $this->prefix;
        $s = preg_replace_callback('/\b(FROM|JOIN|INTO|REFERENCES|UPDATE(?:\s+OR\s+\w+|\s+IGNORE)?)(\s+)([A-Za-z_]\w*)\b/i', function ($m) use ($p) {
            if (in_array(strtolower($m[3]), self::NOT_TABLES, true)) return $m[0];
            return $m[1] . $m[2] . $p . $m[3];
        }, $s);
        $s = preg_replace_callback('/\bTABLE(\s+)(IF\s+(?:NOT\s+)?EXISTS\s+)?([A-Za-z_]\w*)\b/i', function ($m) use ($p) {
            $this->known = null;
            return 'TABLE' . $m[1] . $m[2] . $p . $m[3];
        }, $s);
        $s = preg_replace_callback('/(\bINDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?\w+\s+ON\s+)([A-Za-z_]\w*)/i', function ($m) use ($p) { return $m[1] . $p . $m[2]; }, $s);
        // tabelle.spalte
        $known = $this->known();
        if ($known) $s = preg_replace_callback('/(?<![\w.`])([A-Za-z_]\w*)\.(?=[A-Za-z_`*])/', function ($m) use ($known, $p) {
            return isset($known[$m[1]]) ? $p . $m[1] . '.' : $m[0];
        }, $s);
        return $s;
    }

    /** datetime()/date()/strftime()/MAX(a,b)/group_concat(x, sep)/… übersetzen */
    private function rewriteFunctions(string $s, array $lits): string {
        if (!preg_match_all('/\b(datetime|date|time|strftime|max|min|group_concat|iif|length|random)\s*\(/i', $s, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) return $s;
        for ($k = count($mm) - 1; $k >= 0; $k--) {
            $fn = strtolower($mm[$k][1][0]);
            $start = $mm[$k][0][1];
            $open = $start + strlen($mm[$k][0][0]) - 1;
            [$args, $close] = self::args($s, $open);
            $rep = null;
            switch ($fn) {
                case 'datetime': case 'date': case 'time': case 'strftime':
                    $rep = $this->dateExpr($fn, $args, $lits);
                    break;
                case 'max': case 'min':
                    if (count($args) > 1) $rep = ($fn === 'max' ? 'GREATEST(' : 'LEAST(') . implode(',', $args) . ')';
                    break;
                case 'group_concat':
                    if (count($args) === 2) $rep = 'GROUP_CONCAT(' . $args[0] . ' SEPARATOR ' . trim($args[1]) . ')';
                    break;
                case 'iif':
                    $rep = 'IF(' . implode(',', $args) . ')';
                    break;
                case 'length':
                    $rep = 'CHAR_LENGTH(' . implode(',', $args) . ')';
                    break;
                case 'random':
                    $rep = 'RAND()';
                    break;
            }
            if ($rep !== null) $s = substr($s, 0, $start) . $rep . substr($s, $close + 1);
        }
        return $s;
    }

    private function dateExpr(string $fn, array $args, array $lits): ?string {
        $fmt = null;
        if ($fn === 'strftime') { $fmt = self::litValue((string)array_shift($args), $lits); if ($fmt === null) return null; }
        if (!$args) return null;
        $first = self::litValue($args[0], $lits);
        $mods = [];
        for ($i = 1; $i < count($args); $i++) {
            $v = self::litValue($args[$i], $lits);
            if ($v === null) throw new RuntimeException('Datumsfunktion mit nicht-konstantem Modifikator lässt sich nicht nach MySQL übersetzen.');
            $mods[] = strtolower(trim($v));
        }
        $local = in_array('localtime', $mods, true);
        if ($first !== null && strtolower($first) === 'now') $e = $local || $fmt === '%s' ? 'NOW()' : 'UTC_TIMESTAMP()';
        elseif (in_array('unixepoch', $mods, true)) $e = $local ? 'FROM_UNIXTIME(' . $args[0] . ')' : "CONVERT_TZ(FROM_UNIXTIME(" . $args[0] . "), @@session.time_zone, '+00:00')";
        else $e = 'CAST(' . $args[0] . ' AS DATETIME)';
        foreach ($mods as $m) {
            if (preg_match('/^([+-]?)\s*(\d+(?:\.\d+)?)\s+(second|minute|hour|day|month|year)s?$/', $m, $x)) {
                $e = '(' . $e . ($x[1] === '-' ? ' - ' : ' + ') . 'INTERVAL ' . (int)$x[2] . ' ' . strtoupper($x[3]) . ')';
            } elseif ($m === 'start of day') $e = 'CAST(DATE(' . $e . ') AS DATETIME)';
            elseif ($m === 'start of month') $e = "CAST(DATE_FORMAT($e, '%Y-%m-01') AS DATETIME)";
            elseif ($m === 'start of year') $e = "CAST(DATE_FORMAT($e, '%Y-01-01') AS DATETIME)";
            elseif (!in_array($m, ['localtime', 'utc', 'unixepoch'], true)) throw new RuntimeException('Unbekannter Datums-Modifikator: ' . $m);
        }
        if ($fn === 'datetime') return "DATE_FORMAT($e, '%Y-%m-%d %H:%i:%s')";
        if ($fn === 'date') return "DATE_FORMAT($e, '%Y-%m-%d')";
        if ($fn === 'time') return "DATE_FORMAT($e, '%H:%i:%s')";
        if ($fmt === '%s') return 'UNIX_TIMESTAMP(' . $e . ')';
        if ($fmt === '%w') return '(DAYOFWEEK(' . $e . ') - 1)';
        $map = ['%Y' => '%Y', '%m' => '%m', '%d' => '%d', '%H' => '%H', '%M' => '%i', '%S' => '%s', '%j' => '%j', '%W' => '%u', '%%' => '%%'];
        return "DATE_FORMAT($e, '" . strtr($fmt, $map) . "')";
    }

    // ---- DDL ----

    /** CREATE TABLE / CREATE INDEX / ALTER TABLE übersetzen (kann mehrere Anweisungen ergeben) */
    private function translateDdl(string $sql): array {
        $lits = [];
        $m0 = self::mask($sql, $lits);
        if (preg_match('/^\s*CREATE\s+TABLE\b/i', $m0)) return [$this->createTable($sql)];
        if (preg_match('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\b/i', $m0)) return [$this->createIndex($sql)];
        if (preg_match('/^\s*ALTER\s+TABLE\s+(\w+)\s+ADD\s+(?:COLUMN\s+)?(.+)$/is', $m0, $m)) {
            $table = $this->prefix . $m[1];
            $fk = [];
            $col = $this->columnDef(self::unmask($m[2], $lits), [], $fk);
            $out = ['ALTER TABLE `' . $table . '` ADD COLUMN ' . $col];
            foreach ($fk as $f) $out[] = 'ALTER TABLE `' . $table . '` ADD ' . $f;
            $this->colTypes = [];
            return $out;
        }
        if (preg_match('/^\s*DROP\s+INDEX\s+(?:IF\s+EXISTS\s+)?(\w+)\s*$/i', $m0, $m)) {
            // SQLite kennt den Tabellennamen nicht – Index in allen Tabellen des Moduls suchen
            $st = parent::prepare('SELECT DISTINCT TABLE_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ? AND TABLE_NAME LIKE ?');
            $st->execute([$m[1], $this->prefix . '%']);
            return array_map(function ($t) use ($m) { return 'DROP INDEX `' . $m[1] . '` ON `' . $t . '`'; }, $st->fetchAll(PDO::FETCH_COLUMN));
        }
        return [$this->translate($sql)];
    }

    private function createTable(string $sql): string {
        $lits = [];
        $s = self::mask($sql, $lits);
        if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\(/i', $s, $m)) return $this->translate($sql);
        $name = $m[1];
        $this->known = null;
        $this->colTypes = [];
        [$items] = self::args($s, strlen($m[0]) - 1);
        // Schlüssel-Spalten sammeln (die brauchen VARCHAR statt TEXT)
        $keyCols = [];
        $tablePk = false;
        $constraints = [];
        $columns = [];
        foreach ($items as $it) {
            $t = trim($it);
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE|FOREIGN\s+KEY|CHECK|CONSTRAINT)\b/i', $t)) {
                if (preg_match('/^PRIMARY\s+KEY/i', $t)) $tablePk = true;
                if (preg_match('/^(?:PRIMARY\s+KEY|UNIQUE)\s*\(([^)]*)\)/i', $t, $k)) {
                    $cs = explode(',', $k[1]);
                    // Länge so wählen, dass der ganze Schlüssel unter 3072 Byte bleibt (utf8mb4 = 4 Byte je Zeichen)
                    foreach ($cs as $c) { $c = strtolower(trim(preg_replace('/\s+(ASC|DESC|COLLATE\s+\w+)\b.*/i', '', $c), ' `"')); $keyCols[$c] = min($keyCols[$c] ?? 700, intdiv(740, count($cs))); }
                }
                $constraints[] = $t;
            } else $columns[] = $t;
        }
        $fk = [];
        $defs = [];
        foreach ($columns as $c) {
            $defs[] = $this->columnDef(self::unmask($c, $lits), $keyCols, $fk, $tablePk);
        }
        foreach ($constraints as $c) {
            $c = self::unmask($c, $lits);
            $l3 = [];
            $cm = self::mask($c, $l3);
            if (preg_match('/^FOREIGN\s+KEY/i', $cm)) { $cm = preg_replace('/\bREFERENCES\s+(\w+)/i', 'REFERENCES `' . $this->prefix . '$1`', $cm); }
            if (preg_match('/^UNIQUE\s*\(/i', $cm)) $cm = 'UNIQUE KEY ' . substr($cm, 6);
            $cm = preg_replace('/\bON\s+CONFLICT\s+\w+/i', '', $cm);
            $defs[] = self::unmask(self::quoteReserved($cm), $l3);
        }
        foreach ($fk as $f) $defs[] = $f;
        return 'CREATE TABLE IF NOT EXISTS `' . $this->prefix . $name . "` (\n  " . implode(",\n  ", $defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /** Eine Spaltendefinition übersetzen; Fremdschlüssel landen in $fk */
    private function columnDef(string $def, array $keyCols, array &$fk, bool $tablePk = false): string {
        $lits = [];
        $s = trim(self::mask($def, $lits));
        if (!preg_match('/^[`"]?(\w+)[`"]?\s*(.*)$/s', $s, $m)) return $def;
        $col = $m[1];
        $rest = $m[2];
        $type = '';
        if (preg_match('/^(INTEGER|INT|BIGINT|TEXT|REAL|BLOB|NUMERIC|BOOLEAN|FLOAT|DOUBLE|VARCHAR\s*\(\s*\d+\s*\)|DATETIME)\b/i', $rest, $t)) {
            $type = strtoupper(preg_replace('/\s+/', '', $t[1]));
            $rest = substr($rest, strlen($t[0]));
        }
        // Fremdschlüssel
        if (preg_match('/\bREFERENCES\s+(\w+)\s*\(([^)]*)\)((?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:SET\s+NULL|SET\s+DEFAULT|CASCADE|RESTRICT|NO\s+ACTION))*)/i', $rest, $r)) {
            $rest = str_replace($r[0], '', $rest);
            $ref = $this->prefix . $r[1];
            $fk[] = 'FOREIGN KEY (`' . $col . '`) REFERENCES `' . $ref . '`(' . trim($r[2]) . ')' . $r[3];
        }
        $isPk = (bool)preg_match('/\bPRIMARY\s+KEY\b/i', $rest);
        $isUnique = (bool)preg_match('/\bUNIQUE\b/i', $rest);
        $nocase = (bool)preg_match('/\bCOLLATE\s+NOCASE\b/i', $rest);
        $rest = preg_replace('/\bCOLLATE\s+\w+/i', '', $rest);
        $rest = preg_replace('/\bAUTOINCREMENT\b/i', '', $rest);
        $rest = preg_replace('/\bON\s+CONFLICT\s+\w+/i', '', $rest);
        $isKey = $isPk || $isUnique || isset($keyCols[strtolower($col)]);
        $keyLen = $keyCols[strtolower($col)] ?? 700;
        $nowDefault = preg_match('/\bDEFAULT\s*\(\s*(datetime|date)\s*\(\s*\x01\d+\x02[^)]*\)\s*\)/i', $rest, $dm);
        if (in_array($type, ['INTEGER', 'INT', 'BIGINT', 'BOOLEAN'], true) || ($type === '' && $isPk)) {
            $mt = 'BIGINT';
            if ($isPk && in_array($type, ['INTEGER', 'INT', ''], true) && !$tablePk) $mt = 'BIGINT NOT NULL AUTO_INCREMENT';
        } elseif (in_array($type, ['REAL', 'FLOAT', 'DOUBLE', 'NUMERIC'], true)) $mt = 'DOUBLE';
        elseif ($type === 'BLOB') $mt = 'LONGBLOB';
        elseif ($nowDefault) {
            $mt = 'DATETIME';
            $rest = str_replace($dm[0], 'DEFAULT CURRENT_TIMESTAMP', $rest);
        } elseif ($isKey) {
            $mt = 'VARCHAR(' . $keyLen . ') COLLATE ' . ($nocase ? 'utf8mb4_general_ci' : 'utf8mb4_bin');
        } else {
            $mt = 'MEDIUMTEXT';
            // TEXT mit Standardwert: MariaDB kann das direkt, MySQL ab 8.0.13 nur als Ausdruck, MySQL 5.7 gar nicht
            if (preg_match('/\bDEFAULT\s+(\x01\d+\x02|-?\d+(?:\.\d+)?)/i', $rest, $d)) {
                if ($this->mariadb) { /* bleibt */ }
                elseif (version_compare($this->serverVersion, '8.0.13', '>=')) $rest = str_replace($d[0], 'DEFAULT (' . $d[1] . ')', $rest);
                else $rest = str_replace($d[0], '', $rest);
            }
            if ($nocase) $mt .= ' COLLATE utf8mb4_general_ci';
        }
        $rest = $this->rewriteFunctions($rest, $lits);
        $rest = preg_replace('/\s+/', ' ', trim($rest));
        if (strpos($mt, 'AUTO_INCREMENT') !== false) $rest = trim(preg_replace('/\bNOT\s+NULL\b/i', '', $rest));
        return self::unmask('`' . $col . '` ' . $mt . ($rest !== '' ? ' ' . $rest : ''), $lits);
    }

    private function createIndex(string $sql): string {
        $lits = [];
        $s = self::mask($sql, $lits);
        if (!preg_match('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)\s+ON\s+(\w+)\s*\(/i', $s, $m)) return $this->translate($sql);
        [$cols] = self::args($s, strlen($m[0]) - 1);
        $table = $this->prefix . $m[3];
        if (!isset($this->colTypes[$table])) {
            $st = parent::prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            $this->colTypes[$table] = array_change_key_case(array_column($st->fetchAll(), 'DATA_TYPE', 'COLUMN_NAME'));
        }
        $types = $this->colTypes[$table];
        $out = [];
        foreach ($cols as $c) {
            $c = trim($c);
            if (!preg_match('/^[`"]?(\w+)[`"]?\s*(ASC|DESC)?/i', $c, $x)) continue;
            $len = in_array($types[strtolower($x[1])] ?? '', ['text', 'mediumtext', 'longtext', 'blob', 'longblob'], true) ? '(191)' : '';
            $out[] = '`' . $x[1] . '`' . $len . (isset($x[2]) ? ' ' . strtoupper($x[2]) : '');
        }
        return 'CREATE ' . ($m[1] ? 'UNIQUE ' : '') . 'INDEX `' . $m[2] . '` ON `' . $table . '` (' . implode(', ', $out) . ')';
    }
}
