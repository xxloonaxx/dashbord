<?php
/**
 * Zentrale Datenbank der Webseite (SQLite): Benutzer, Rollen & Rechte, Seiten & Kategorien,
 * Protokoll, Login-Sperren und Einstellungen.
 *
 * Datei: data/site_<zufall>.sqlite – Ordner per .htaccess gesperrt, Dateiname nicht erratbar.
 * Passwörter werden ausschließlich als Hash (password_hash / bcrypt) gespeichert.
 */
require_once __DIR__ . '/common.php';

const SITE_SCHEMA_VERSION = 1;

/** Alle Rechte, die Rollen haben können: key => [Beschreibung, Gruppe] */
const PERMISSIONS = [
    'admin.access'           => ['Admin-Zentrale öffnen', 'Verwaltung'],
    'users.manage'           => ['Benutzer, Rollen & Rechte verwalten', 'Verwaltung'],
    'pages.manage'           => ['Seiten anlegen, Index sortieren & Kategorien verwalten', 'Verwaltung'],
    'system.manage'          => ['System, Wartungsmodus & Einstellungen', 'Verwaltung'],
    'audit.view'             => ['Protokoll ansehen', 'Verwaltung'],
    'backup.download'        => ['Backup herunterladen', 'Verwaltung'],
    'settings.nsfw'          => ['NSFW-Schalter für Besucher bedienen', 'Inhalte'],
    'nsfw.view'              => ['NSFW-Inhalte immer sehen', 'Inhalte'],
    'links.edit'             => ['Links & Kategorien in Link-Hubs bearbeiten', 'Inhalte'],
    'pages.edit'             => ['Inhalte eigener Seiten bearbeiten (Notizen, Checklisten)', 'Inhalte'],
    'feed.edit'              => ['Feed-Quellen verwalten', 'Inhalte'],
    'linkcheck.run'          => ['Link-Check starten', 'Inhalte'],
    'countdown.edit'         => ['Countdowns bearbeiten', 'Kalender'],
    'calendar.edit'          => ['Kalender-Termine anlegen & bearbeiten', 'Kalender'],
    'calendar.private'       => ['Private Kalender-Termine sehen', 'Kalender'],
    'calendar.subscriptions' => ['Kalender-Abos & Handy-Abo-Links verwalten', 'Kalender'],
    'budget.access'          => ['Budget & Bank-Sync', 'Finanzen'],
    'con.access'             => ['Con-Rechner', 'Finanzen'],
];

function site_db_file(): string {
    $dir = dirname(__DIR__) . '/data';
    $found = glob($dir . '/site_*.sqlite') ?: [];
    return $found ? $found[0] : $dir . '/site_' . bin2hex(random_bytes(12)) . '.sqlite';
}

function site_db(): PDO {
    static $db = null;
    if ($db) return $db;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        http_response_code(500);
        exit('<p style="font-family:sans-serif;padding:30px">Auf dem Server fehlt die PHP-Erweiterung <b>pdo_sqlite</b> – sie wird für Benutzer und Seiten benötigt.</p>');
    }
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.html')) file_put_contents($dir . '/index.html', '');

    $db = new PDO('sqlite:' . site_db_file(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 4000');
    site_migrate($db);
    return $db;
}

function site_migrate(PDO $db): void {
    $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($version >= SITE_SCHEMA_VERSION) return;
    $db->beginTransaction();
    if ($version < 1) {
        $db->exec("
            CREATE TABLE roles (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE,
                description TEXT NOT NULL DEFAULT '',
                color       TEXT NOT NULL DEFAULT '#38d5e6',
                is_system   INTEGER NOT NULL DEFAULT 0          -- Administrator: nicht löschbar, hat alle Rechte
            );
            CREATE TABLE role_permissions (
                role_id    INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                permission TEXT NOT NULL,
                PRIMARY KEY (role_id, permission)
            );
            CREATE TABLE users (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
                display_name    TEXT NOT NULL DEFAULT '',
                password_hash   TEXT NOT NULL,
                role_id         INTEGER NOT NULL REFERENCES roles(id),
                active          INTEGER NOT NULL DEFAULT 1,
                session_version INTEGER NOT NULL DEFAULT 1,
                created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                password_changed TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                last_login      TEXT,
                last_ip         TEXT NOT NULL DEFAULT '',
                note            TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE page_categories (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT NOT NULL,
                icon  TEXT NOT NULL DEFAULT '',
                sort  INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE pages (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                slug          TEXT NOT NULL UNIQUE,              -- Dateiseite: 'budget.php', eigene Seite: 'meine-notizen'
                kind          TEXT NOT NULL CHECK (kind IN ('file', 'custom')),
                template      TEXT NOT NULL DEFAULT '',          -- eigene Seiten: links | notes | checklist | external
                title         TEXT NOT NULL,
                description   TEXT NOT NULL DEFAULT '',
                icon          TEXT NOT NULL DEFAULT '',
                category_id   INTEGER REFERENCES page_categories(id) ON DELETE SET NULL,
                sort          INTEGER NOT NULL DEFAULT 0,
                access        TEXT NOT NULL DEFAULT 'public' CHECK (access IN ('public', 'users', 'roles')),
                allowed_roles TEXT NOT NULL DEFAULT '',          -- Rollen-IDs, kommagetrennt (bei access = roles)
                hidden        INTEGER NOT NULL DEFAULT 0,        -- nicht im Index anzeigen
                show_live     INTEGER NOT NULL DEFAULT 1,        -- Live-Infos (z. B. nächster Termin) im Index
                content       TEXT NOT NULL DEFAULT '{}',        -- Inhalt eigener Seiten (JSON)
                url           TEXT NOT NULL DEFAULT '',          -- Vorlage „Externer Link“
                created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL
            );
            CREATE TABLE audit_log (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ts       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
                username TEXT NOT NULL DEFAULT '',
                action   TEXT NOT NULL,
                detail   TEXT NOT NULL DEFAULT '',
                level    TEXT NOT NULL DEFAULT 'info',          -- info | warn | security
                ip       TEXT NOT NULL DEFAULT '',
                agent    TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX idx_audit_ts ON audit_log(ts);
            CREATE TABLE login_attempts (
                ip    TEXT PRIMARY KEY,
                count INTEGER NOT NULL DEFAULT 0,
                last  INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
        ");
        // Standard-Rollen
        $db->exec("INSERT INTO roles (name, description, color, is_system) VALUES
            ('Administrator', 'Darf alles – inklusive Benutzer und Rechte.', '#f3c262', 1),
            ('Redakteur', 'Pflegt Inhalte: Links, Kalender, Countdowns, Feeds, eigene Seiten.', '#38d5e6', 0),
            ('Mitglied', 'Sieht geschützte Seiten und private Termine, darf nichts ändern.', '#50fa7b', 0)");
        $grant = $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
        foreach (['links.edit', 'pages.edit', 'feed.edit', 'linkcheck.run', 'countdown.edit', 'calendar.edit', 'calendar.private', 'nsfw.view'] as $p) $grant->execute([2, $p]);
        foreach (['calendar.private'] as $p) $grant->execute([3, $p]);
    }
    $db->exec('PRAGMA user_version = ' . SITE_SCHEMA_VERSION);
    $db->commit();

    site_import_legacy($db);
}

/**
 * Übernahme aus der Vorversion: das einzelne Admin-Konto (.admin_auth.php, inkl. Passwort-Hash),
 * die NSFW-Einstellung und das Zugriffsprotokoll.
 */
function site_import_legacy(PDO $db): void {
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) return;
    $root = dirname(__DIR__);
    $read = function (string $file) {
        if (!is_file($file)) return null;
        $raw = (string)file_get_contents($file);
        $data = json_decode(substr($raw, (int)strpos($raw, "\n") + 1), true);
        return is_array($data) ? $data : null;
    };
    $acc = $read($root . '/.admin_auth.php');
    if ($acc && !empty($acc['username']) && !empty($acc['password_hash'])) {
        $db->prepare("INSERT INTO users (username, display_name, password_hash, role_id, created_at) VALUES (?, ?, ?, 1, ?)")
           ->execute([$acc['username'], $acc['username'], $acc['password_hash'], date('Y-m-d H:i:s', (int)($acc['created'] ?? time()))]);
        $log = $read($root . '/.admin_log.php');
        $ins = $db->prepare('INSERT INTO audit_log (ts, username, action, ip, agent, level) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (array_reverse($log['entries'] ?? []) as $l) {
            $ins->execute([date('Y-m-d H:i:s', (int)$l['t']), $l['user'] ?? '', $l['event'] ?? '', $l['ip'] ?? '', $l['agent'] ?? '',
                           stripos($l['event'] ?? '', 'fehl') !== false ? 'security' : 'info']);
        }
        $db->prepare('INSERT INTO audit_log (username, action, detail) VALUES (?, ?, ?)')->execute([$acc['username'], 'Konto übernommen', 'Aus der Vorversion als Administrator übernommen']);
    }
    $settings = $read($root . '/.site_settings.php');
    if ($settings && isset($settings['show_nsfw'])) {
        $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('show_nsfw', ?)")->execute([$settings['show_nsfw'] ? '1' : '0']);
    }
}

// ---------- Einstellungen ----------

/** Gemeinsamer Zwischenspeicher der Einstellungen (Referenz auf eine statische Variable) */
function &setting_cache(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (site_db()->query('SELECT key, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache;
}

function setting(string $key, string $default = ''): string {
    $cache = &setting_cache();
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void {
    site_db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
    $cache = &setting_cache();
    $cache[$key] = $value;
}
