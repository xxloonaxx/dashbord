<?php
/**
 * Aufgabenplaner: regelmäßige Arbeiten im Hintergrund (Feeds, Kalender-Abos, Aufräumen, Backup, Link-Check).
 *
 * Auslöser (Admin → System → Aufgaben):
 *  - „Beim Seitenaufruf“ (Standard): nach dem Ausliefern einer Seite wird höchstens einmal pro Minute geprüft, ob etwas fällig ist.
 *  - „Echter Cron“: der Server ruft  php cron.php  (oder  cron.php?key=…) z. B. alle 5 Minuten auf.
 *  - „Aus“.
 * Einstellungen liegen in der Tabelle settings (task_<id>_on, task_<id>_every, task_<id>_last, task_<id>_result).
 */
require_once __DIR__ . '/auth.php';

/** id => [Icon, Name, Beschreibung, Standard-Abstand in Minuten, standardmäßig an] */
const TASKS = [
    'feed'      => ['📡', 'Feeds abrufen', 'Alle RSS-/Atom-Quellen neu laden.', 30, true],
    'calendar'  => ['📅', 'Kalender-Abos', 'Abonnierte Kalender (ICS) abgleichen.', 60, true],
    'cleanup'   => ['🧹', 'Aufräumen', 'Alte Sitzungen, Login-Sperren, OSC-Befehle, Zähler & Protokolleinträge entfernen.', 1440, true],
    'backup'    => ['💾', 'Automatisches Backup', 'Datenbank & JSON-Daten nach data/backups sichern (ohne Bilder).', 1440, false],
    'linkcheck' => ['🔗', 'Link-Check', 'Alle Links der Link-Hubs prüfen.', 10080, false],
];
const TASK_INTERVALS = [15 => '15 min', 30 => '30 min', 60 => '1 Std.', 180 => '3 Std.', 360 => '6 Std.', 720 => '12 Std.', 1440 => 'täglich', 10080 => 'wöchentlich'];

function tasks_mode(): string { $m = setting('tasks_mode', 'pseudo'); return in_array($m, ['pseudo', 'cron', 'off'], true) ? $m : 'pseudo'; }

function task_conf(string $id): array {
    $d = TASKS[$id];
    return ['on' => setting("task_{$id}_on", $d[4] ? '1' : '0') === '1', 'every' => max(5, (int)setting("task_{$id}_every", (string)$d[3])),
            'last' => (int)setting("task_{$id}_last", '0'), 'result' => setting("task_{$id}_result", ''), 'ok' => setting("task_{$id}_ok", '1') === '1'];
}

function tasks_due(): array {
    $due = [];
    foreach (array_keys(TASKS) as $id) { $c = task_conf($id); if ($c['on'] && $c['last'] + $c['every'] * 60 <= time()) $due[] = $id; }
    return $due;
}

/** Schlüssel für cron.php?key=… (wird bei Bedarf erzeugt) */
function tasks_key(): string {
    $k = setting('tasks_key', '');
    if (strlen($k) !== 32) { $k = bin2hex(random_bytes(16)); set_setting('tasks_key', $k); }
    return $k;
}

/** Eine Aufgabe ausführen → Ergebnistext (wirft bei Fehlern) */
function task_run(string $id): string {
    @set_time_limit(300);
    switch ($id) {
        case 'feed':
            require_once APP_ROOT . '/inc/modules/feed.php';
            $r = feed_refresh(null, false);
            return (int)($r['new'] ?? 0) . ' neue Einträge';
        case 'calendar':
            require_once APP_ROOT . '/inc/modules/calendar.php';
            require_once APP_ROOT . '/inc/modules/calendar_ics.php';
            return cal_sync_due(20) . ' Abos abgeglichen';
        case 'cleanup':
            return tasks_cleanup();
        case 'backup':
            require_once __DIR__ . '/backup.php';
            $r = backup_auto(max(1, (int)setting('backup_keep', '7')));
            if (!$r['ok']) throw new RuntimeException('Backup fehlgeschlagen (ZIP-Erweiterung vorhanden?)');
            return $r['name'] . ' · ' . round($r['size'] / 1024) . ' KB' . ($r['removed'] ? ' · ' . $r['removed'] . ' alte entfernt' : '');
        case 'linkcheck':
            require_once APP_ROOT . '/inc/modules/linkcheck.php';
            $s = linkcheck_start();
            $until = time() + 240;
            while (empty($s['finished']) && time() < $until) $s = linkcheck_step();
            return empty($s['finished']) ? 'Zeit abgelaufen – läuft beim nächsten Mal weiter' : (int)$s['total'] . ' Links geprüft, ' . (int)$s['bad'] . ' kaputt';
    }
    throw new RuntimeException('Unbekannte Aufgabe');
}

/** Aufräumen in allen Modulen */
function tasks_cleanup(): string {
    $n = [];
    $db = site_db();
    $st = $db->prepare("DELETE FROM user_sessions WHERE last_seen < datetime('now','localtime','-31 days')"); $st->execute(); $n['Sitzungen'] = $st->rowCount();
    $st = $db->prepare('DELETE FROM login_attempts WHERE last < ?'); $st->execute([time() - 86400]); $n['Sperren'] = $st->rowCount();
    $keep = max(30, (int)setting('audit_keep_days', '365'));
    $st = $db->prepare("DELETE FROM audit_log WHERE ts < datetime('now','localtime','-" . $keep . " days')"); $st->execute(); $n['Protokoll'] = $st->rowCount();
    try {
        require_once APP_ROOT . '/inc/modules/vrchat.php';
        $st = vrc_db()->prepare('DELETE FROM osc_queue WHERE created < ?'); $st->execute([time() - 7 * 86400]); $n['OSC'] = $st->rowCount();
    } catch (Throwable $e) { /* Nexus nie benutzt */ }
    try {
        require_once APP_ROOT . '/inc/modules/furhub.php';
        if (fh_db_present()) { $st = fh_db()->prepare('DELETE FROM rate WHERE minute < ?'); $st->execute([(int)floor(time() / 60) - 60]); $n['Zähler'] = $st->rowCount(); }
    } catch (Throwable $e) { /* egal */ }
    foreach (glob(DATA_DIR . '/cache/*.tmp') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
    $parts = [];
    foreach ($n as $k => $v) if ($v) $parts[] = $v . ' ' . $k;
    return $parts ? implode(', ', $parts) . ' entfernt' : 'nichts zu tun';
}

/**
 * Fällige (oder bestimmte) Aufgaben ausführen. Nur ein Lauf gleichzeitig (Dateisperre).
 * Gibt [id => [ok, text, sekunden]] zurück.
 */
function tasks_run(?array $only = null, bool $force = false): array {
    $lock = fopen(data_path('cache/tasks.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return [];
    $out = [];
    try {
        $ids = $only ?? tasks_due();
        foreach ($ids as $id) {
            if (!isset(TASKS[$id])) continue;
            if (!$force && !task_conf($id)['on']) continue;
            $t0 = microtime(true);
            try { $text = task_run($id); $ok = true; }
            catch (Throwable $e) { $text = mb_substr($e->getMessage(), 0, 200); $ok = false; }
            set_setting("task_{$id}_last", (string)time());
            set_setting("task_{$id}_result", $text);
            set_setting("task_{$id}_ok", $ok ? '1' : '0');
            $out[$id] = ['ok' => $ok, 'text' => $text, 'seconds' => round(microtime(true) - $t0, 1)];
            if (!$ok) audit('Aufgabe fehlgeschlagen', TASKS[$id][1] . ': ' . $text, 'warn', ['id' => null, 'username' => 'Aufgabenplaner']);
        }
        set_setting('tasks_last_run', (string)time());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $out;
}

/** „Beim Seitenaufruf“: nach dem Ausliefern prüfen (höchstens einmal pro Minute) */
function tasks_pseudo_cron(): void {
    if (PHP_SAPI === 'cli' || tasks_mode() !== 'pseudo') return;
    $tick = DATA_DIR . '/cache/tasks.tick';
    if (is_file($tick) && filemtime($tick) > time() - 60) return;
    @touch(data_path('cache/tasks.tick'));
    register_shutdown_function(function () {
        try {
            if (!tasks_due()) return;
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true);
            tasks_run();
        } catch (Throwable $e) { /* nie die Seite stören */ }
    });
}
