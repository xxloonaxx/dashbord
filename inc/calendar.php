<?php
/**
 * Kalender – Datenbank (SQLite) und Termin-Logik.
 *
 * Datenbank: data/calendar_<zufall>.sqlite (Ordner per .htaccess gesperrt, Name nicht erratbar)
 *   categories        Kategorien mit Farbe
 *   events            Termine (auch mehrtägig, ganztägig, wiederkehrend)
 *   event_exceptions  einzelne ausgelassene Termine einer Serie
 *   settings          Einstellungen (z. B. Bundesland für Feiertage)
 *
 * Countdowns aus countdown.php werden nur GELESEN und als eigene Ebene angezeigt –
 * der Kalender verändert die Countdowns nie (Verbindung nur in Richtung Kalender).
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/countdown.php';

/**
 * Pfad der Datenbank. Der Dateiname enthält einen zufälligen Teil (z. B. calendar_3f9a….sqlite),
 * damit sie selbst dann nicht abrufbar ist, wenn der Server .htaccess ignoriert (nginx).
 */
function cal_db_file(): string {
    $dir = dirname(__DIR__) . '/data';
    $found = glob($dir . '/calendar_*.sqlite') ?: [];
    if ($found) return $found[0];
    if (is_file($dir . '/calendar.sqlite')) {   // ältere Installation umbenennen
        $new = $dir . '/calendar_' . bin2hex(random_bytes(12)) . '.sqlite';
        rename($dir . '/calendar.sqlite', $new);
        return $new;
    }
    return $dir . '/calendar_' . bin2hex(random_bytes(12)) . '.sqlite';
}
const CAL_SCHEMA_VERSION = 1;

const CAL_STATES = [
    '' => 'Nur bundesweite Feiertage', 'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg',
    'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen',
    'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz', 'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt',
    'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
];

const CAL_RECURRENCE = ['none' => 'Einmalig', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich', 'yearly' => 'Jährlich'];

function cal_db(): PDO {
    static $db = null;
    if ($db) return $db;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Auf dem Server fehlt die PHP-Erweiterung pdo_sqlite.');
    }
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.html')) file_put_contents($dir . '/index.html', '');

    $db = new PDO('sqlite:' . cal_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 4000');
    cal_migrate($db);
    return $db;
}

/** Tabellen anlegen bzw. aktualisieren (Version in PRAGMA user_version). */
function cal_migrate(PDO $db): void {
    $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($version >= CAL_SCHEMA_VERSION) return;

    $db->beginTransaction();
    if ($version < 1) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT NOT NULL UNIQUE,
                color TEXT NOT NULL DEFAULT '#38d5e6',
                sort  INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS events (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                title          TEXT NOT NULL,
                description    TEXT NOT NULL DEFAULT '',
                location       TEXT NOT NULL DEFAULT '',
                url            TEXT NOT NULL DEFAULT '',
                start_at       TEXT NOT NULL,              -- 'YYYY-MM-DD HH:MM' (Ortszeit)
                end_at         TEXT NOT NULL,
                all_day        INTEGER NOT NULL DEFAULT 0,
                category_id    INTEGER REFERENCES categories(id) ON DELETE SET NULL,
                recurrence     TEXT NOT NULL DEFAULT 'none' CHECK (recurrence IN ('none','daily','weekly','monthly','yearly')),
                recur_interval INTEGER NOT NULL DEFAULT 1 CHECK (recur_interval BETWEEN 1 AND 99),
                recur_until    TEXT,                       -- 'YYYY-MM-DD' oder NULL
                private        INTEGER NOT NULL DEFAULT 1,
                created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                updated_at     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE INDEX IF NOT EXISTS idx_events_start ON events(start_at);
            CREATE INDEX IF NOT EXISTS idx_events_recurrence ON events(recurrence);
            CREATE TABLE IF NOT EXISTS event_exceptions (
                event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
                day      TEXT NOT NULL,                    -- ausgelassener Termin 'YYYY-MM-DD'
                PRIMARY KEY (event_id, day)
            );
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
        ");
        $ins = $db->prepare('INSERT OR IGNORE INTO categories (name, color, sort) VALUES (?, ?, ?)');
        foreach ([['Privat', '#38d5e6'], ['Arbeit', '#f3c262'], ['Con & Events', '#ff79c6'], ['Geburtstag', '#50fa7b'], ['Gaming', '#bd93f9'], ['Termin', '#ff9f43']] as $i => [$n, $c]) {
            $ins->execute([$n, $c, $i]);
        }
        $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('state', 'BW')");
    }
    $db->exec('PRAGMA user_version = ' . CAL_SCHEMA_VERSION);
    $db->commit();
}

function cal_setting(string $key, string $default = ''): string {
    $st = cal_db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function cal_set_setting(string $key, string $value): void {
    cal_db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

function cal_categories(): array {
    $cats = [];
    foreach (cal_db()->query('SELECT * FROM categories ORDER BY sort, name') as $c) $cats[(int)$c['id']] = $c;
    return $cats;
}

// ---------- Feiertage ----------

/** Ostersonntag (Gaußsche Osterformel, ohne Abhängigkeit von ext-calendar) */
function cal_easter(int $year): DateTimeImmutable {
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/** Gesetzliche Feiertage in Deutschland: ['YYYY-MM-DD' => 'Name'] */
function cal_holidays(int $year, string $state): array {
    $e = cal_easter($year);
    $o = function (int $days) use ($e) { return $e->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d'); };
    $h = [
        "$year-01-01" => 'Neujahr',
        $o(-2) => 'Karfreitag',
        $o(1) => 'Ostermontag',
        "$year-05-01" => 'Tag der Arbeit',
        $o(39) => 'Christi Himmelfahrt',
        $o(50) => 'Pfingstmontag',
        "$year-10-03" => 'Tag der Deutschen Einheit',
        "$year-12-25" => '1. Weihnachtstag',
        "$year-12-26" => '2. Weihnachtstag',
    ];
    $in = function (array $states) use ($state) { return in_array($state, $states, true); };
    if ($in(['BW', 'BY', 'ST'])) $h["$year-01-06"] = 'Heilige Drei Könige';
    if ($in(['BE', 'MV']) && $year >= 2019) $h["$year-03-08"] = 'Internationaler Frauentag';
    if ($in(['BB'])) { $h[$o(0)] = 'Ostersonntag'; $h[$o(49)] = 'Pfingstsonntag'; }
    if ($in(['BW', 'BY', 'HE', 'NW', 'RP', 'SL'])) $h[$o(60)] = 'Fronleichnam';
    if ($in(['SL'])) $h["$year-08-15"] = 'Mariä Himmelfahrt';
    if ($in(['TH']) && $year >= 2019) $h["$year-09-20"] = 'Weltkindertag';
    if ($in(['BB', 'MV', 'SN', 'ST', 'TH', 'HB', 'HH', 'NI', 'SH']) || $year === 2017) $h["$year-10-31"] = 'Reformationstag';
    if ($in(['BW', 'BY', 'NW', 'RP', 'SL'])) $h["$year-11-01"] = 'Allerheiligen';
    if ($in(['SN'])) {
        // Buß- und Bettag: Mittwoch vor dem 23. November
        $d = new DateTimeImmutable("$year-11-22");
        while ($d->format('N') !== '3') $d = $d->modify('-1 day');
        $h[$d->format('Y-m-d')] = 'Buß- und Bettag';
    }
    ksort($h);
    return $h;
}

// ---------- Termine aufklappen ----------

function cal_dt(string $s): DateTimeImmutable {
    return new DateTimeImmutable($s);
}

/**
 * Alle Vorkommen im Zeitraum [from, to] (inklusive) – Termine, Feiertage, Countdowns.
 * Jeder Eintrag: type, key, title, start, end (DateTimeImmutable), all_day, color, …
 */
function cal_items(DateTimeImmutable $from, DateTimeImmutable $to, bool $is_admin, array $layers = ['events', 'holidays', 'countdowns']): array {
    $items = [];
    $from = $from->setTime(0, 0);
    $to = $to->setTime(23, 59, 59);

    if (in_array('events', $layers, true)) {
        $cats = cal_categories();
        $sql = 'SELECT * FROM events WHERE start_at <= :to AND (recurrence <> \'none\' OR end_at >= :from)' . ($is_admin ? '' : ' AND private = 0');
        $st = cal_db()->prepare($sql);
        $st->execute([':to' => $to->format('Y-m-d H:i'), ':from' => $from->format('Y-m-d H:i')]);
        $events = $st->fetchAll();

        $exc = [];
        if ($events) {
            foreach (cal_db()->query('SELECT event_id, day FROM event_exceptions') as $x) $exc[(int)$x['event_id']][$x['day']] = true;
        }

        foreach ($events as $ev) {
            $cat = $cats[(int)$ev['category_id']] ?? null;
            foreach (cal_expand($ev, $from, $to) as [$s, $e]) {
                if (isset($exc[(int)$ev['id']][$s->format('Y-m-d')])) continue;
                $items[] = [
                    'type' => 'event', 'key' => 'e' . $ev['id'] . '-' . $s->format('Ymd'), 'id' => (int)$ev['id'],
                    'title' => $ev['title'], 'start' => $s, 'end' => $e, 'all_day' => (bool)$ev['all_day'],
                    'color' => $cat['color'] ?? '#8b949e', 'category' => $cat['name'] ?? '', 'category_id' => (int)$ev['category_id'],
                    'location' => $ev['location'], 'description' => $ev['description'], 'url' => $ev['url'],
                    'recurrence' => $ev['recurrence'], 'recur_interval' => (int)$ev['recur_interval'], 'recur_until' => $ev['recur_until'],
                    'private' => (bool)$ev['private'], 'raw_start' => $ev['start_at'], 'raw_end' => $ev['end_at'],
                ];
            }
        }
    }

    if (in_array('holidays', $layers, true) && cal_setting('show_holidays', '1') === '1') {
        $state = cal_setting('state', '');
        for ($y = (int)$from->format('Y'); $y <= (int)$to->format('Y'); $y++) {
            foreach (cal_holidays($y, $state) as $day => $name) {
                if ($day < $from->format('Y-m-d') || $day > $to->format('Y-m-d')) continue;
                $d = cal_dt($day);
                $items[] = ['type' => 'holiday', 'key' => 'h' . $day, 'title' => $name, 'start' => $d, 'end' => $d->setTime(23, 59),
                            'all_day' => true, 'color' => '#ff5555', 'category' => 'Feiertag'];
            }
        }
    }

    if (in_array('countdowns', $layers, true)) {
        // Nur lesen! Jährliche Countdowns erscheinen in jedem Jahr des Zeitraums.
        foreach (load_countdowns()['events'] as $cd) {
            $base = strtotime($cd['date']);
            if (!$base) continue;
            $candidates = [];
            if (($cd['repeat'] ?? 'none') === 'yearly') {
                for ($y = (int)$from->format('Y'); $y <= (int)$to->format('Y'); $y++) {
                    $t = mktime((int)date('H', $base), (int)date('i', $base), 0, (int)date('n', $base), (int)date('j', $base), $y);
                    if ($y >= (int)date('Y', $base)) $candidates[] = $t;
                }
            } else {
                $candidates[] = $base;
            }
            foreach ($candidates as $t) {
                $d = (new DateTimeImmutable())->setTimestamp($t);
                if ($d < $from || $d > $to) continue;
                $items[] = ['type' => 'countdown', 'key' => 'c' . $cd['id'] . '-' . $d->format('Y'), 'title' => $cd['title'], 'icon' => $cd['icon'] ?? '⏳',
                            'start' => $d, 'end' => $d, 'all_day' => $d->format('H:i') === '00:00', 'color' => '#f3c262', 'category' => 'Countdown',
                            'description' => $cd['note'] ?? '', 'url' => $cd['link'] ?? '', 'featured' => !empty($cd['featured']),
                            'recurrence' => ($cd['repeat'] ?? 'none') === 'yearly' ? 'yearly' : 'none'];
            }
        }
    }

    usort($items, function ($a, $b) {
        return [$a['start']->format('Y-m-d'), $a['all_day'] ? 0 : 1, $a['start'], $a['title']]
           <=> [$b['start']->format('Y-m-d'), $b['all_day'] ? 0 : 1, $b['start'], $b['title']];
    });
    return $items;
}

/** Wiederholungen eines Termins im Zeitraum berechnen: Liste von [start, end]. */
function cal_expand(array $ev, DateTimeImmutable $from, DateTimeImmutable $to): array {
    $start = cal_dt($ev['start_at']);
    $end = cal_dt($ev['end_at']);
    $duration = $end->getTimestamp() - $start->getTimestamp();
    $rec = $ev['recurrence'];
    $n = max(1, (int)$ev['recur_interval']);
    $until = $ev['recur_until'] ? cal_dt($ev['recur_until'] . ' 23:59:59') : null;
    $out = [];

    if ($rec === 'none') {
        return [[$start, $end]];
    }

    $day = (int)$start->format('j');
    $month = (int)$start->format('n');
    $time = $start->format('H:i');
    for ($k = 0; $k < 5000; $k++) {
        switch ($rec) {
            case 'daily':  $s = $start->modify('+' . ($k * $n) . ' days'); break;
            case 'weekly': $s = $start->modify('+' . ($k * $n) . ' weeks'); break;
            case 'monthly':
                // 31. eines Monats: Monate ohne diesen Tag werden übersprungen
                $base = $start->modify('first day of this month')->modify('+' . ($k * $n) . ' months');
                if ($day > (int)$base->format('t')) { $s = null; break; }
                $s = $base->setDate((int)$base->format('Y'), (int)$base->format('n'), $day);
                break;
            case 'yearly':
                $y = (int)$start->format('Y') + $k * $n;
                if (!checkdate($month, $day, $y)) { $s = null; break; }   // 29. Februar
                $s = $start->setDate($y, $month, $day);
                break;
            default: $s = null;
        }
        if ($s === null) continue;
        if ($s > $to || ($until && $s > $until)) break;
        $e = $s->setTimestamp($s->getTimestamp() + $duration);
        if ($e >= $from) $out[] = [$s, $e];
    }
    return $out;
}

/** Einträge eines Tages (berücksichtigt mehrtägige Termine) */
function cal_items_on(array $items, string $day): array {
    return array_values(array_filter($items, function ($it) use ($day) {
        return $it['start']->format('Y-m-d') <= $day && $it['end']->format('Y-m-d') >= $day;
    }));
}

/** Zeitangabe für einen Eintrag an einem bestimmten Tag */
function cal_time_label(array $it, ?string $day = null): string {
    if ($it['all_day']) return 'ganztägig';
    $sd = $it['start']->format('Y-m-d');
    $ed = $it['end']->format('Y-m-d');
    if ($day && $sd !== $ed) {
        if ($day === $sd) return 'ab ' . $it['start']->format('H:i');
        if ($day === $ed) return 'bis ' . $it['end']->format('H:i');
        return 'ganztägig';
    }
    return $it['start']->format('H:i') . ($it['end'] > $it['start'] ? '–' . $it['end']->format('H:i') : '');
}

// ---------- Speichern ----------

/** Formular -> Datensatz. Wirft eine Exception mit verständlicher Meldung. */
function cal_event_from_post(array $p): array {
    $title = trim($p['title'] ?? '');
    if ($title === '') throw new InvalidArgumentException('Bitte einen Titel eingeben.');
    $all_day = !empty($p['all_day']);
    $sd = $p['start_date'] ?? '';
    $ed = ($p['end_date'] ?? '') ?: $sd;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) throw new InvalidArgumentException('Bitte ein gültiges Datum wählen.');
    $st = $all_day ? '00:00' : (preg_match('/^\d{2}:\d{2}$/', $p['start_time'] ?? '') ? $p['start_time'] : '09:00');
    $et = $all_day ? '23:59' : (preg_match('/^\d{2}:\d{2}$/', $p['end_time'] ?? '') ? $p['end_time'] : $st);
    $start = "$sd $st";
    $end = "$ed $et";
    if ($end < $start) throw new InvalidArgumentException('Das Ende liegt vor dem Beginn.');

    $rec = (string)($p['recurrence'] ?? 'none');
    if (!array_key_exists($rec, CAL_RECURRENCE)) $rec = 'none';
    $until = ($rec !== 'none' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['recur_until'] ?? '')) ? $p['recur_until'] : null;
    if ($until && $until < $sd) throw new InvalidArgumentException('„Wiederholen bis“ liegt vor dem ersten Termin.');
    $url = trim($p['url'] ?? '');
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if ($url !== '' && safe_url($url) === '#') $url = '';

    $cat = (int)($p['category_id'] ?? 0);
    return [
        'title' => mb_substr($title, 0, 150),
        'description' => mb_substr(trim($p['description'] ?? ''), 0, 4000),
        'location' => mb_substr(trim($p['location'] ?? ''), 0, 200),
        'url' => mb_substr($url, 0, 500),
        'start_at' => $start,
        'end_at' => $end,
        'all_day' => $all_day ? 1 : 0,
        'category_id' => $cat > 0 ? $cat : null,
        'recurrence' => $rec,
        'recur_interval' => max(1, min(99, (int)($p['recur_interval'] ?? 1))),
        'recur_until' => $until,
        'private' => !empty($p['private']) ? 1 : 0,
    ];
}

function cal_insert(array $ev): int {
    $cols = array_keys($ev);
    $st = cal_db()->prepare('INSERT INTO events (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')');
    $st->execute($ev);
    return (int)cal_db()->lastInsertId();
}

function cal_update(int $id, array $ev): void {
    $set = implode(', ', array_map(function ($c) { return "$c = :$c"; }, array_keys($ev)));
    $st = cal_db()->prepare("UPDATE events SET $set, updated_at = datetime('now','localtime') WHERE id = :id");
    $st->execute($ev + ['id' => $id]);
}

function cal_get(int $id): ?array {
    $st = cal_db()->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ---------- iCalendar (.ics) ----------

function ics_escape(string $s): string {
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
}

/** Lange Zeilen nach RFC 5545 umbrechen */
function ics_fold(string $line): string {
    $out = '';
    while (strlen($line) > 74) {
        $cut = 74;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;   // nicht mitten im UTF-8-Zeichen
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

function cal_export_ics(bool $is_admin): string {
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GHOST NODE//Kalender//DE', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:GHOST NODE Kalender', 'X-WR-TIMEZONE:Europe/Berlin'];
    $cats = cal_categories();
    $exc = [];
    foreach (cal_db()->query('SELECT event_id, day FROM event_exceptions') as $x) $exc[(int)$x['event_id']][] = $x['day'];
    $sql = 'SELECT * FROM events' . ($is_admin ? '' : ' WHERE private = 0') . ' ORDER BY start_at';
    foreach (cal_db()->query($sql) as $ev) {
        $s = cal_dt($ev['start_at']);
        $e = cal_dt($ev['end_at']);
        $l = ['BEGIN:VEVENT', 'UID:event-' . $ev['id'] . '@ghost-node', 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'SUMMARY:' . ics_escape($ev['title'])];
        if ($ev['all_day']) {
            $l[] = 'DTSTART;VALUE=DATE:' . $s->format('Ymd');
            $l[] = 'DTEND;VALUE=DATE:' . $e->modify('+1 day')->format('Ymd');
        } else {
            $l[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $s->getTimestamp());
            $l[] = 'DTEND:' . gmdate('Ymd\THis\Z', $e->getTimestamp());
        }
        if ($ev['recurrence'] !== 'none') {
            $r = 'RRULE:FREQ=' . strtoupper($ev['recurrence']) . ($ev['recur_interval'] > 1 ? ';INTERVAL=' . $ev['recur_interval'] : '');
            if ($ev['recur_until']) $r .= ';UNTIL=' . str_replace('-', '', $ev['recur_until']) . ($ev['all_day'] ? '' : 'T235959Z');
            $l[] = $r;
            foreach ($exc[(int)$ev['id']] ?? [] as $day) {
                $l[] = $ev['all_day'] ? 'EXDATE;VALUE=DATE:' . str_replace('-', '', $day) : 'EXDATE:' . gmdate('Ymd\THis\Z', cal_dt($day . ' ' . $s->format('H:i'))->getTimestamp());
            }
        }
        if ($ev['location'] !== '') $l[] = 'LOCATION:' . ics_escape($ev['location']);
        if ($ev['description'] !== '') $l[] = 'DESCRIPTION:' . ics_escape($ev['description']);
        if ($ev['url'] !== '') $l[] = 'URL:' . $ev['url'];
        if (isset($cats[(int)$ev['category_id']])) $l[] = 'CATEGORIES:' . ics_escape($cats[(int)$ev['category_id']]['name']);
        $l[] = 'CLASS:' . ($ev['private'] ? 'PRIVATE' : 'PUBLIC');
        $l[] = 'END:VEVENT';
        foreach ($l as $x) $lines[] = ics_fold($x);
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

/** Datum/Zeit aus einer ICS-Eigenschaft lesen -> [DateTimeImmutable (Ortszeit), ganztägig?] */
function ics_parse_date(string $params, string $value): array {
    $value = trim($value);
    if (stripos($params, 'VALUE=DATE') !== false || preg_match('/^\d{8}$/', $value)) {
        return [new DateTimeImmutable(substr($value, 0, 8)), true];
    }
    $tz = null;
    if (preg_match('/TZID=([^;:]+)/i', $params, $m)) {
        try { $tz = new DateTimeZone(trim($m[1], '"')); } catch (Exception $e) { $tz = null; }
    }
    if (substr($value, -1) === 'Z') {
        $d = new DateTimeImmutable(substr($value, 0, 15), new DateTimeZone('UTC'));
        return [$d->setTimezone(new DateTimeZone(date_default_timezone_get())), false];
    }
    $d = new DateTimeImmutable(substr($value, 0, 15), $tz ?: null);
    return [$d->setTimezone(new DateTimeZone(date_default_timezone_get())), false];
}

/** .ics-Datei importieren (z. B. aus Google/Apple/Outlook). Liefert [importiert, übersprungen]. */
function cal_import_ics(string $ics, ?int $category_id, bool $private): array {
    $ics = preg_replace("/\r\n[ \t]|\n[ \t]/", '', str_replace("\r\n", "\n", $ics));   // Zeilen entfalten
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks);
    $imported = 0; $skipped = 0;
    $exists = cal_db()->prepare('SELECT COUNT(*) FROM events WHERE title = ? AND start_at = ?');
    $unescape = function ($s) { return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s); };

    foreach ($blocks[1] as $block) {
        $props = [];
        $exdates = [];
        foreach (explode("\n", trim($block)) as $line) {
            if (!preg_match('/^([A-Z\-]+)((?:;[^:]*)?):(.*)$/', trim($line), $m)) continue;
            if ($m[1] === 'EXDATE') { foreach (explode(',', $m[3]) as $v) $exdates[] = [$m[2], $v]; continue; }
            if (!isset($props[$m[1]])) $props[$m[1]] = [$m[2], $m[3]];
        }
        if (empty($props['DTSTART']) || empty($props['SUMMARY'])) { $skipped++; continue; }
        try {
            [$s, $all] = ics_parse_date(...$props['DTSTART']);
            if (!empty($props['DTEND'])) {
                [$e] = ics_parse_date(...$props['DTEND']);
                if ($all) $e = $e->modify('-1 day')->setTime(23, 59);   // DTEND ist bei ganztägig exklusiv
            } else {
                $e = $all ? $s->setTime(23, 59) : $s->modify('+1 hour');
            }
        } catch (Exception $ex) { $skipped++; continue; }

        $rec = 'none'; $interval = 1; $until = null;
        if (!empty($props['RRULE']) && preg_match('/FREQ=(DAILY|WEEKLY|MONTHLY|YEARLY)/i', $props['RRULE'][1], $m)) {
            $rec = strtolower($m[1]);
            if (preg_match('/INTERVAL=(\d+)/', $props['RRULE'][1], $m)) $interval = (int)$m[1];
            if (preg_match('/UNTIL=(\d{8})/', $props['RRULE'][1], $m)) $until = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        }
        $title = mb_substr(trim($unescape($props['SUMMARY'][1])), 0, 150);
        $start = $s->format('Y-m-d') . ' ' . ($all ? '00:00' : $s->format('H:i'));
        $exists->execute([$title, $start]);
        if ($exists->fetchColumn()) { $skipped++; continue; }

        $url = trim($props['URL'][1] ?? '');
        cal_insert([
            'title' => $title,
            'description' => mb_substr($unescape($props['DESCRIPTION'][1] ?? ''), 0, 4000),
            'location' => mb_substr($unescape($props['LOCATION'][1] ?? ''), 0, 200),
            'url' => safe_url($url) === '#' ? '' : $url,
            'start_at' => $start,
            'end_at' => max($start, $e->format('Y-m-d') . ' ' . ($all ? '23:59' : $e->format('H:i'))),
            'all_day' => $all ? 1 : 0,
            'category_id' => $category_id,
            'recurrence' => $rec,
            'recur_interval' => max(1, min(99, $interval)),
            'recur_until' => $until,
            'private' => $private ? 1 : 0,
        ]);
        // Ausgelassene Termine einer Serie übernehmen
        $new_id = (int)cal_db()->lastInsertId();
        foreach ($exdates as [$p, $v]) {
            try {
                [$xd] = ics_parse_date($p, $v);
                cal_db()->prepare('INSERT OR IGNORE INTO event_exceptions (event_id, day) VALUES (?, ?)')->execute([$new_id, $xd->format('Y-m-d')]);
            } catch (Exception $ex) { /* ungültiges Datum ignorieren */ }
        }
        $imported++;
    }
    return [$imported, $skipped];
}

/** Kurzinfo für die Startseite */
function cal_preview(bool $is_admin): string {
    try {
        $today = new DateTimeImmutable('today');
        $items = array_values(array_filter(cal_items($today, $today->modify('+60 days'), $is_admin, ['events', 'holidays']),
            function ($it) { return $it['end'] >= new DateTimeImmutable(); }));
        $count_today = count(cal_items_on($items, $today->format('Y-m-d')));
        $next = $items[0] ?? null;
        $txt = 'HEUTE: ' . $count_today . ' ' . ($count_today === 1 ? 'TERMIN' : 'TERMINE');
        if ($next) $txt .= ' // NÄCHSTER: ' . mb_strtoupper($next['title'], 'UTF-8') . ' (' . $next['start']->format('d.m.') . ($next['all_day'] ? '' : ' ' . $next['start']->format('H:i')) . ')';
        return $txt;
    } catch (Throwable $e) {
        return 'Kalender noch nicht eingerichtet';
    }
}
