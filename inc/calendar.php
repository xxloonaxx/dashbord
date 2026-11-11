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
const CAL_SCHEMA_VERSION = 2;

const CAL_STATES = [
    '' => 'Nur bundesweite Feiertage', 'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg',
    'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen',
    'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz', 'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt',
    'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
];

const CAL_RECURRENCE = ['none' => 'Einmalig', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich', 'yearly' => 'Jährlich'];
const CAL_WEEKDAY_CODES = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
const CAL_WEEKDAY_NAMES = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
const CAL_REMINDERS = [0 => 'keine', 5 => '5 Min. vorher', 15 => '15 Min. vorher', 30 => '30 Min. vorher', 60 => '1 Std. vorher',
                       120 => '2 Std. vorher', 1440 => '1 Tag vorher', 2880 => '2 Tage vorher', 10080 => '1 Woche vorher'];

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
    if ($version < 2) {
        // Abos (ICS-URLs), erweiterte Wiederholungen, Erinnerungen, Jahrestage, Absagen
        $db->exec("
            CREATE TABLE IF NOT EXISTS subscriptions (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                name            TEXT NOT NULL,
                url             TEXT NOT NULL,
                color           TEXT NOT NULL DEFAULT '#bd93f9',
                enabled         INTEGER NOT NULL DEFAULT 1,
                private         INTEGER NOT NULL DEFAULT 1,
                refresh_minutes INTEGER NOT NULL DEFAULT 60,
                skip_past_days  INTEGER NOT NULL DEFAULT 0,   -- 0 = alle Termine übernehmen
                last_sync       TEXT,
                last_status     TEXT NOT NULL DEFAULT '',
                last_error      TEXT NOT NULL DEFAULT '',
                etag            TEXT NOT NULL DEFAULT '',
                last_modified   TEXT NOT NULL DEFAULT '',
                event_count     INTEGER NOT NULL DEFAULT 0,
                created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
            ALTER TABLE events ADD COLUMN recur_byday  TEXT;                -- 'MO,WE' (wöchentlich) oder '2SA' / '-1FR' (monatlich)
            ALTER TABLE events ADD COLUMN recur_count  INTEGER;             -- Anzahl der Termine (statt Enddatum)
            ALTER TABLE events ADD COLUMN reminder     INTEGER NOT NULL DEFAULT 0;   -- Minuten vorher
            ALTER TABLE events ADD COLUMN show_age     INTEGER NOT NULL DEFAULT 0;   -- Jahrestag/Alter anzeigen
            ALTER TABLE events ADD COLUMN geo          TEXT NOT NULL DEFAULT '';     -- 'lat;lon'
            ALTER TABLE events ADD COLUMN cancelled    INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE events ADD COLUMN ext_category TEXT NOT NULL DEFAULT '';     -- Kategorie aus fremdem Kalender
            ALTER TABLE events ADD COLUMN source_id    INTEGER REFERENCES subscriptions(id) ON DELETE CASCADE;
            ALTER TABLE events ADD COLUMN source_uid   TEXT;
            CREATE INDEX IF NOT EXISTS idx_events_source ON events(source_id, source_uid);
        ");
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
        $subs = cal_subscriptions();
        $sql = 'SELECT * FROM events WHERE start_at <= :to AND (recurrence <> \'none\' OR end_at >= :from)'
             . ' AND (source_id IS NULL OR source_id IN (SELECT id FROM subscriptions WHERE enabled = 1))'
             . ($is_admin ? '' : ' AND private = 0');
        $st = cal_db()->prepare($sql);
        $st->execute([':to' => $to->format('Y-m-d H:i'), ':from' => $from->format('Y-m-d H:i')]);
        $events = $st->fetchAll();

        $exc = [];
        if ($events) {
            foreach (cal_db()->query('SELECT event_id, day FROM event_exceptions') as $x) $exc[(int)$x['event_id']][$x['day']] = true;
        }

        foreach ($events as $ev) {
            $cat = $cats[(int)$ev['category_id']] ?? null;
            $sub = $ev['source_id'] ? ($subs[(int)$ev['source_id']] ?? null) : null;
            $first_year = (int)substr($ev['start_at'], 0, 4);
            foreach (cal_expand($ev, $from, $to) as [$s, $e]) {
                if (isset($exc[(int)$ev['id']][$s->format('Y-m-d')])) continue;
                $age = ($ev['show_age'] && $ev['recurrence'] === 'yearly') ? (int)$s->format('Y') - $first_year : null;
                $items[] = [
                    'type' => 'event', 'key' => 'e' . $ev['id'] . '-' . $s->format('YmdHi'), 'id' => (int)$ev['id'],
                    'title' => $ev['title'] . ($age ? ' (' . $age . ')' : ''), 'base_title' => $ev['title'], 'age' => $age,
                    'start' => $s, 'end' => $e, 'all_day' => (bool)$ev['all_day'],
                    'color' => $cat['color'] ?? ($sub['color'] ?? '#8b949e'), 'category' => $cat['name'] ?? ($ev['ext_category'] ?: ($sub['name'] ?? '')),
                    'category_id' => (int)$ev['category_id'], 'filter' => $sub ? 's' . $sub['id'] : (string)(int)$ev['category_id'],
                    'location' => $ev['location'], 'description' => $ev['description'], 'url' => $ev['url'], 'geo' => $ev['geo'],
                    'recurrence' => $ev['recurrence'], 'recur_interval' => (int)$ev['recur_interval'], 'recur_until' => $ev['recur_until'],
                    'recur_byday' => (string)$ev['recur_byday'], 'recur_count' => (int)$ev['recur_count'],
                    'reminder' => (int)$ev['reminder'], 'show_age' => (bool)$ev['show_age'], 'cancelled' => (bool)$ev['cancelled'],
                    'source' => $sub ? ['id' => (int)$sub['id'], 'name' => $sub['name']] : null, 'ext_category' => $ev['ext_category'],
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

/** n-ter Wochentag eines Monats (n = 1..5 oder -1 = letzter), sonst null */
function cal_nth_weekday(DateTimeImmutable $month_first, int $n, int $weekday): ?DateTimeImmutable {
    $days = (int)$month_first->format('t');
    if ($n > 0) {
        $offset = ($weekday - (int)$month_first->format('N') + 7) % 7;
        $day = 1 + $offset + ($n - 1) * 7;
    } else {
        $last = $month_first->setDate((int)$month_first->format('Y'), (int)$month_first->format('n'), $days);
        $offset = ((int)$last->format('N') - $weekday + 7) % 7;
        $day = $days - $offset - (abs($n) - 1) * 7;
    }
    if ($day < 1 || $day > $days) return null;
    return $month_first->setDate((int)$month_first->format('Y'), (int)$month_first->format('n'), $day);
}

/**
 * Wiederholungen eines Termins im Zeitraum berechnen: Liste von [start, end].
 * Unterstützt (wie iCalendar): Intervall, Enddatum ODER Anzahl, wöchentlich an mehreren Tagen,
 * monatlich am n-ten / letzten Wochentag, 31. und 29.02. werden in "fehlenden" Monaten/Jahren übersprungen.
 */
function cal_expand(array $ev, DateTimeImmutable $from, DateTimeImmutable $to): array {
    $start = cal_dt($ev['start_at']);
    $end = cal_dt($ev['end_at']);
    $duration = $end->getTimestamp() - $start->getTimestamp();
    $rec = $ev['recurrence'];
    if ($rec === 'none') return [[$start, $end]];

    $n = max(1, (int)$ev['recur_interval']);
    $until = !empty($ev['recur_until']) ? cal_dt($ev['recur_until'] . ' 23:59:59') : null;
    $count = (int)($ev['recur_count'] ?? 0);
    $byday = strtoupper(trim((string)($ev['recur_byday'] ?? '')));
    [$h, $i] = [(int)$start->format('G'), (int)$start->format('i')];
    $out = [];
    $made = 0;

    // Liefert false, sobald die Serie zu Ende ist
    $emit = function (DateTimeImmutable $s) use (&$out, &$made, $count, $until, $to, $from, $duration, $start): bool {
        if ($s < $start) return true;
        if (($until && $s > $until) || ($count && $made >= $count) || $s > $to) return false;
        $made++;
        $e = $s->setTimestamp($s->getTimestamp() + $duration);
        if ($e >= $from) $out[] = [$s, $e];
        return true;
    };

    $weekdays = [];
    foreach (explode(',', $byday) as $code) if (isset(CAL_WEEKDAY_CODES[$code])) $weekdays[] = CAL_WEEKDAY_CODES[$code];
    sort($weekdays);
    $nth = preg_match('/^([+-]?[1-5])(MO|TU|WE|TH|FR|SA|SU)$/', $byday, $m) ? [(int)$m[1], CAL_WEEKDAY_CODES[$m[2]]] : null;

    $day = (int)$start->format('j');
    $month = (int)$start->format('n');
    for ($k = 0; $k < 10000; $k++) {
        switch ($rec) {
            case 'daily':
                if (!$emit($start->modify('+' . ($k * $n) . ' days'))) break 2;
                break;
            case 'weekly':
                if ($weekdays) {
                    $week = $start->modify('monday this week')->modify('+' . ($k * $n) . ' weeks')->setTime($h, $i);
                    foreach ($weekdays as $wd) if (!$emit($week->modify('+' . ($wd - 1) . ' days'))) break 3;
                } elseif (!$emit($start->modify('+' . ($k * $n) . ' weeks'))) break 2;
                break;
            case 'monthly':
                $base = $start->modify('first day of this month')->modify('+' . ($k * $n) . ' months')->setTime($h, $i);
                if ($base > $to || ($until && $base > $until)) break 2;
                $s = $nth ? cal_nth_weekday($base, $nth[0], $nth[1])
                          : ($day <= (int)$base->format('t') ? $base->setDate((int)$base->format('Y'), (int)$base->format('n'), $day) : null);
                if ($s && !$emit($s)) break 2;
                break;
            case 'yearly':
                $y = (int)$start->format('Y') + $k * $n;
                if ($y > (int)$to->format('Y') + 1) break 2;
                if (checkdate($month, $day, $y) && !$emit($start->setDate($y, $month, $day))) break 2;
                break;
            default:
                break 2;
        }
    }
    return $out;
}

/** Wiederholung in Worten, z. B. "Monatlich am 2. Samstag, 12 Termine" */
function cal_recurrence_text(array $ev): string {
    $rec = $ev['recurrence'] ?? 'none';
    if ($rec === 'none') return 'Einmalig';
    $n = max(1, (int)($ev['recur_interval'] ?? 1));
    $units = ['daily' => ['Täglich', 'Tage'], 'weekly' => ['Wöchentlich', 'Wochen'], 'monthly' => ['Monatlich', 'Monate'], 'yearly' => ['Jährlich', 'Jahre']];
    $txt = $n > 1 ? 'Alle ' . $n . ' ' . $units[$rec][1] : $units[$rec][0];
    $byday = strtoupper((string)($ev['recur_byday'] ?? ''));
    if ($rec === 'weekly' && $byday !== '') {
        $names = [];
        foreach (explode(',', $byday) as $c) if (isset(CAL_WEEKDAY_CODES[$c])) $names[] = mb_substr(CAL_WEEKDAY_NAMES[CAL_WEEKDAY_CODES[$c]], 0, 2);
        if ($names) $txt .= ' am ' . implode(', ', $names);
    }
    if ($rec === 'monthly' && preg_match('/^([+-]?[1-5])(MO|TU|WE|TH|FR|SA|SU)$/', $byday, $m)) {
        $txt .= ' am ' . ((int)$m[1] === -1 ? 'letzten' : (int)$m[1] . '.') . ' ' . CAL_WEEKDAY_NAMES[CAL_WEEKDAY_CODES[$m[2]]];
    }
    if (!empty($ev['recur_count'])) $txt .= ', ' . (int)$ev['recur_count'] . ' Termine';
    if (!empty($ev['recur_until'])) $txt .= ' bis ' . date('d.m.Y', strtotime($ev['recur_until']));
    return $txt;
}

function cal_subscriptions(): array {
    static $subs = null;
    if ($subs === null) {
        $subs = [];
        foreach (cal_db()->query('SELECT * FROM subscriptions ORDER BY name') as $sub) $subs[(int)$sub['id']] = $sub;
    }
    return $subs;
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
    $end_mode = $p['recur_end'] ?? (!empty($p['recur_until']) ? 'until' : (!empty($p['recur_count']) ? 'count' : 'never'));
    $until = ($rec !== 'none' && $end_mode === 'until' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['recur_until'] ?? '')) ? $p['recur_until'] : null;
    if ($until && $until < $sd) throw new InvalidArgumentException('„Wiederholen bis“ liegt vor dem ersten Termin.');
    $count = ($rec !== 'none' && $end_mode === 'count') ? max(1, min(999, (int)($p['recur_count'] ?? 0))) : null;

    // Wochentage (wöchentlich) bzw. "n-ter Wochentag" (monatlich)
    $byday = null;
    if ($rec === 'weekly' && !empty($p['byday']) && is_array($p['byday'])) {
        $days = array_values(array_intersect(array_keys(CAL_WEEKDAY_CODES), array_map('strtoupper', $p['byday'])));
        $byday = $days ? implode(',', $days) : null;
    }
    if ($rec === 'monthly' && preg_match('/^([+-]?[1-5])(MO|TU|WE|TH|FR|SA|SU)$/', strtoupper($p['monthly_mode'] ?? ''))) {
        $byday = strtoupper($p['monthly_mode']);
    }
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
        'recur_count' => $count,
        'recur_byday' => $byday,
        'reminder' => array_key_exists((int)($p['reminder'] ?? 0), CAL_REMINDERS) ? (int)($p['reminder'] ?? 0) : 0,
        'show_age' => ($rec === 'yearly' && !empty($p['show_age'])) ? 1 : 0,
        'private' => !empty($p['private']) ? 1 : 0,
    ];
}

/** Überschneidungen eines Termins mit anderen (für eine Warnung nach dem Speichern) */
function cal_conflicts(int $id, string $start_at, string $end_at, bool $all_day): array {
    if ($all_day) return [];
    $s = cal_dt($start_at);
    $e = cal_dt($end_at);
    $hits = [];
    foreach (cal_items($s, $e, true, ['events']) as $it) {
        if ($it['id'] === $id || $it['all_day'] || $it['cancelled']) continue;
        if ($it['start'] < $e && $it['end'] > $s) $hits[] = $it['title'] . ' (' . $it['start']->format('H:i') . '–' . $it['end']->format('H:i') . ')';
    }
    return array_unique($hits);
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

// iCalendar-Import/-Export, Abos und Schnell-Eingabe: siehe inc/calendar_ics.php

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
