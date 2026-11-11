<?php
/**
 * Kalender: iCalendar (.ics) lesen/schreiben, Abos über URLs, eigener Abo-Link, Schnell-Eingabe.
 *
 * Abos: Termine fremder Kalender (z. B. WoltLab-Forum, Google, Outlook, Nextcloud) werden in die eigene
 * Datenbank übernommen und bei jedem Abgleich aktualisiert (neue/geänderte/gelöschte Termine).
 * Sie sind schreibgeschützt – Änderungen gehören in den Originalkalender.
 */
require_once __DIR__ . '/calendar.php';

const CAL_FEED_MAX_BYTES = 15 * 1024 * 1024;

// Häufige Windows-Zeitzonennamen (Outlook/Exchange) -> IANA
const CAL_WINDOWS_TZ = [
    'W. Europe Standard Time' => 'Europe/Berlin', 'Central Europe Standard Time' => 'Europe/Budapest',
    'Romance Standard Time' => 'Europe/Paris', 'Central European Standard Time' => 'Europe/Warsaw',
    'GMT Standard Time' => 'Europe/London', 'UTC' => 'UTC', 'Eastern Standard Time' => 'America/New_York',
    'Pacific Standard Time' => 'America/Los_Angeles', 'Central Standard Time' => 'America/Chicago',
    'FLE Standard Time' => 'Europe/Helsinki', 'E. Europe Standard Time' => 'Europe/Chisinau',
];

// ---------- Schreiben ----------

function ics_escape(string $s): string {
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
}

/** Lange Zeilen nach RFC 5545 umbrechen (ohne UTF-8-Zeichen zu zerteilen) */
function ics_fold(string $line): string {
    $out = '';
    while (strlen($line) > 74) {
        $cut = 74;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

/** Einen Termin als VEVENT-Zeilen */
function ics_vevent(array $ev, array $cats, array $exceptions): array {
    $s = cal_dt($ev['start_at']);
    $e = cal_dt($ev['end_at']);
    $l = ['BEGIN:VEVENT', 'UID:' . ($ev['source_uid'] ?: 'event-' . $ev['id'] . '@ghost-node'), 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
          'SUMMARY:' . ics_escape($ev['title'])];
    if ($ev['all_day']) {
        $l[] = 'DTSTART;VALUE=DATE:' . $s->format('Ymd');
        $l[] = 'DTEND;VALUE=DATE:' . $e->modify('+1 day')->format('Ymd');
    } else {
        $l[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $s->getTimestamp());
        $l[] = 'DTEND:' . gmdate('Ymd\THis\Z', $e->getTimestamp());
    }
    if ($ev['recurrence'] !== 'none') {
        $r = 'RRULE:FREQ=' . strtoupper($ev['recurrence']) . ($ev['recur_interval'] > 1 ? ';INTERVAL=' . $ev['recur_interval'] : '');
        if (!empty($ev['recur_byday'])) $r .= ';BYDAY=' . $ev['recur_byday'];
        if (!empty($ev['recur_count'])) $r .= ';COUNT=' . (int)$ev['recur_count'];
        elseif (!empty($ev['recur_until'])) $r .= ';UNTIL=' . str_replace('-', '', $ev['recur_until']) . ($ev['all_day'] ? '' : 'T235959Z');
        $l[] = $r;
        foreach ($exceptions as $day) {
            $l[] = $ev['all_day'] ? 'EXDATE;VALUE=DATE:' . str_replace('-', '', $day)
                                  : 'EXDATE:' . gmdate('Ymd\THis\Z', cal_dt($day . ' ' . $s->format('H:i'))->getTimestamp());
        }
    }
    if ($ev['location'] !== '') $l[] = 'LOCATION:' . ics_escape($ev['location']);
    if ($ev['geo'] !== '') $l[] = 'GEO:' . $ev['geo'];
    if ($ev['description'] !== '') $l[] = 'DESCRIPTION:' . ics_escape($ev['description']);
    if ($ev['url'] !== '') $l[] = 'URL:' . $ev['url'];
    $cat = $cats[(int)$ev['category_id']]['name'] ?? $ev['ext_category'];
    if ($cat !== '') $l[] = 'CATEGORIES:' . ics_escape($cat);
    if ($ev['cancelled']) $l[] = 'STATUS:CANCELLED';
    if ($ev['reminder'] > 0) array_push($l, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_escape($ev['title']), 'TRIGGER:-PT' . (int)$ev['reminder'] . 'M', 'END:VALARM');
    $l[] = 'CLASS:' . ($ev['private'] ? 'PRIVATE' : 'PUBLIC');
    $l[] = 'END:VEVENT';
    return $l;
}

/**
 * Kalender als .ics
 * $include_private: private Termine mitliefern; $include_subs: Termine aus Abos mitliefern; $only_id: nur ein Termin
 */
function cal_export_ics(bool $include_private, bool $include_subs = false, ?int $only_id = null): string {
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GHOST NODE//Kalender//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
              'X-WR-CALNAME:GHOST NODE Kalender', 'X-WR-TIMEZONE:' . date_default_timezone_get(), 'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
              'X-PUBLISHED-TTL:PT1H'];
    $cats = cal_categories();
    $exc = [];
    foreach (cal_db()->query('SELECT event_id, day FROM event_exceptions') as $x) $exc[(int)$x['event_id']][] = $x['day'];
    $where = [];
    $args = [];
    if (!$include_private) $where[] = 'private = 0';
    if (!$include_subs) $where[] = 'source_id IS NULL';
    if ($only_id) { $where[] = 'id = ?'; $args[] = $only_id; }
    $st = cal_db()->prepare('SELECT * FROM events' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY start_at');
    $st->execute($args);
    foreach ($st as $ev) {
        foreach (ics_vevent($ev, $cats, $exc[(int)$ev['id']] ?? []) as $x) $lines[] = ics_fold($x);
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

// ---------- Lesen ----------

function ics_unescape(string $s): string {
    return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
}

/** Beschreibung säubern (manche Kalender liefern HTML) */
function ics_clean_text(string $s, int $max): string {
    $s = ics_unescape($s);
    if ($s !== strip_tags($s)) {
        $s = preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', "\n", $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $s = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $s)));
    return mb_substr($s, 0, $max);
}

/** Datum/Zeit einer ICS-Eigenschaft -> [DateTimeImmutable (Ortszeit), ganztägig?] */
function ics_parse_date(string $params, string $value): array {
    $value = trim($value);
    if (stripos($params, 'VALUE=DATE') !== false && stripos($params, 'VALUE=DATE-TIME') === false || preg_match('/^\d{8}$/', $value)) {
        return [new DateTimeImmutable(substr($value, 0, 8)), true];
    }
    $local = new DateTimeZone(date_default_timezone_get());
    if (substr($value, -1) === 'Z') {
        return [(new DateTimeImmutable(substr($value, 0, 15), new DateTimeZone('UTC')))->setTimezone($local), false];
    }
    $tz = $local;
    if (preg_match('/TZID=("?)([^";:]+)\1/i', $params, $m)) {
        $name = CAL_WINDOWS_TZ[$m[2]] ?? $m[2];
        $name = preg_replace('#^/[^/]+/[^/]+/#', '', $name);   // z. B. "/mozilla.org/20050126_1/Europe/Berlin"
        try { $tz = new DateTimeZone($name); } catch (Exception $e) { $tz = $local; }
    }
    return [(new DateTimeImmutable(substr($value, 0, 15), $tz))->setTimezone($local), false];
}

/**
 * Alle VEVENTs einer .ics-Datei in ein einheitliches Format bringen.
 * Rückgabe: ['calname' => …, 'events' => [ … ]] – Ausnahmen (RECURRENCE-ID) sind eigene Einträge mit 'recurrence_id'.
 */
function ics_parse(string $ics): array {
    $ics = preg_replace('/^\xEF\xBB\xBF/', '', $ics);
    $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
    $calname = preg_match('/^X-WR-CALNAME[^:]*:(.*)$/m', $ics, $m) ? trim(ics_unescape($m[1])) : '';
    preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $ics, $blocks);
    $events = [];

    foreach ($blocks[1] as $block) {
        $block = preg_replace('/BEGIN:VALARM.*?END:VALARM\n?/s', '', $block);   // Erinnerungen der Quelle ignorieren
        $props = [];
        $exdates = [];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match('/^([A-Z][A-Z0-9\-]*)((?:;[^:]*)?):(.*)$/', rtrim($line), $m)) continue;
            if ($m[1] === 'EXDATE') { foreach (explode(',', $m[3]) as $v) $exdates[] = [$m[2], $v]; continue; }
            if (!isset($props[$m[1]])) $props[$m[1]] = [$m[2], $m[3]];
        }
        if (empty($props['DTSTART'])) continue;

        try {
            [$s, $all] = ics_parse_date(...$props['DTSTART']);
            if (!empty($props['DTEND'])) {
                [$e] = ics_parse_date(...$props['DTEND']);
                if ($all) $e = $e->modify('-1 day')->setTime(23, 59);   // DTEND ist bei ganztägig exklusiv
            } elseif (!empty($props['DURATION']) && preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?/', $props['DURATION'][1], $d)) {
                $secs = ((int)($d[1] ?? 0) * 7 + (int)($d[2] ?? 0)) * 86400 + (int)($d[3] ?? 0) * 3600 + (int)($d[4] ?? 0) * 60;
                $e = $s->modify('+' . $secs . ' seconds');
                if ($all) $e = $e->modify('-1 day')->setTime(23, 59);
            } else {
                $e = $all ? $s->setTime(23, 59) : $s;
            }
            if ($e < $s) $e = $all ? $s->setTime(23, 59) : $s;
        } catch (Exception $ex) {
            continue;
        }

        $rec = 'none'; $interval = 1; $until = null; $count = null; $byday = null;
        if (!empty($props['RRULE'])) {
            $rr = [];
            foreach (explode(';', strtoupper($props['RRULE'][1])) as $part) {
                [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
                $rr[$k] = $v;
            }
            if (in_array($rr['FREQ'] ?? '', ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
                $rec = strtolower($rr['FREQ']);
                $interval = max(1, min(99, (int)($rr['INTERVAL'] ?? 1)));
                if (!empty($rr['UNTIL'])) {
                    try {
                        [$u] = ics_parse_date('', $rr['UNTIL']);
                        $until = $u->format('Y-m-d');
                    } catch (Exception $ex) { /* ignorieren */ }
                }
                if (!empty($rr['COUNT'])) $count = max(1, (int)$rr['COUNT']);
                if (!empty($rr['BYDAY'])) {
                    $codes = explode(',', $rr['BYDAY']);
                    if ($rec === 'weekly') $byday = implode(',', array_filter($codes, function ($c) { return isset(CAL_WEEKDAY_CODES[$c]); })) ?: null;
                    if ($rec === 'monthly' && count($codes) === 1 && preg_match('/^([+-]?[1-5])(MO|TU|WE|TH|FR|SA|SU)$/', $codes[0])) $byday = $codes[0];
                    if ($rec === 'monthly' && !empty($rr['BYSETPOS']) && count($codes) === 1 && isset(CAL_WEEKDAY_CODES[$codes[0]])) $byday = (int)$rr['BYSETPOS'] . $codes[0];
                }
            }
        }

        $geo = '';
        if (!empty($props['GEO']) && preg_match('/^(-?\d+(?:\.\d+)?)[;,](-?\d+(?:\.\d+)?)$/', trim($props['GEO'][1]), $g)) $geo = $g[1] . ';' . $g[2];
        $url = trim($props['URL'][1] ?? '');
        $recid = null;
        if (!empty($props['RECURRENCE-ID'])) {
            try { [$r] = ics_parse_date(...$props['RECURRENCE-ID']); $recid = $r->format('Y-m-d'); } catch (Exception $ex) { /* ignorieren */ }
        }
        $ex_days = [];
        foreach ($exdates as [$p, $v]) {
            try { [$xd] = ics_parse_date($p, $v); $ex_days[] = $xd->format('Y-m-d'); } catch (Exception $ex) { /* ignorieren */ }
        }

        $events[] = [
            'uid' => mb_substr(trim($props['UID'][1] ?? ''), 0, 255),
            'recurrence_id' => $recid,
            'title' => ics_clean_text($props['SUMMARY'][1] ?? '(ohne Titel)', 150) ?: '(ohne Titel)',
            'description' => ics_clean_text($props['DESCRIPTION'][1] ?? '', 4000),
            'location' => ics_clean_text($props['LOCATION'][1] ?? '', 200),
            'url' => safe_url($url) === '#' ? '' : mb_substr($url, 0, 500),
            'geo' => $geo,
            'ext_category' => mb_substr(ics_clean_text(explode(',', $props['CATEGORIES'][1] ?? '')[0], 60), 0, 60),
            'start_at' => $s->format('Y-m-d') . ' ' . ($all ? '00:00' : $s->format('H:i')),
            'end_at' => $e->format('Y-m-d') . ' ' . ($all ? '23:59' : $e->format('H:i')),
            'all_day' => $all ? 1 : 0,
            'recurrence' => $rec,
            'recur_interval' => $interval,
            'recur_until' => $until,
            'recur_count' => $count,
            'recur_byday' => $byday,
            'cancelled' => strtoupper(trim($props['STATUS'][1] ?? '')) === 'CANCELLED' ? 1 : 0,
            'exdates' => array_values(array_unique($ex_days)),
        ];
    }
    return ['calname' => $calname, 'events' => $events];
}

/** Ausnahmen (ausgelassene Termine) eines Termins setzen */
function cal_set_exceptions(int $event_id, array $days): void {
    cal_db()->prepare('DELETE FROM event_exceptions WHERE event_id = ?')->execute([$event_id]);
    $ins = cal_db()->prepare('INSERT OR IGNORE INTO event_exceptions (event_id, day) VALUES (?, ?)');
    foreach ($days as $d) $ins->execute([$event_id, $d]);
}

/** Datensatz für die Tabelle events aus einem geparsten Termin */
function cal_row_from_ics(array $p): array {
    return array_intersect_key($p, array_flip(['title', 'description', 'location', 'url', 'geo', 'ext_category', 'start_at', 'end_at', 'all_day',
        'recurrence', 'recur_interval', 'recur_until', 'recur_count', 'recur_byday', 'cancelled']));
}

/** .ics-Datei einmalig importieren. Liefert [importiert, übersprungen]. */
function cal_import_ics(string $ics, ?int $category_id, bool $private): array {
    $parsed = ics_parse($ics);
    $imported = 0; $skipped = 0;
    $exists = cal_db()->prepare('SELECT COUNT(*) FROM events WHERE title = ? AND start_at = ? AND source_id IS NULL');
    foreach ($parsed['events'] as $p) {
        if ($p['recurrence_id']) { $skipped++; continue; }   // Einzeländerungen von Serien beim Datei-Import auslassen
        $exists->execute([$p['title'], $p['start_at']]);
        if ($exists->fetchColumn()) { $skipped++; continue; }
        $id = cal_insert(cal_row_from_ics($p) + ['category_id' => $category_id, 'private' => $private ? 1 : 0]);
        if ($p['exdates']) cal_set_exceptions($id, $p['exdates']);
        $imported++;
    }
    return [$imported, $skipped];
}

// ---------- Abos (URL-Sync) ----------

/** webcal:// -> https://, Leerzeichen entfernen */
function cal_normalize_feed_url(string $url): string {
    $url = trim($url);
    if (preg_match('#^webcals?://#i', $url)) $url = 'https://' . preg_replace('#^webcals?://#i', '', $url);
    return $url;
}

/** Nur öffentliche Internet-Adressen abrufen (keine internen Server-Adressen) */
function cal_url_allowed(string $url): bool {
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    if (getenv('CAL_ALLOW_LOCAL_FEEDS') === '1') return true;   // nur für Tests
    $host = parse_url($url, PHP_URL_HOST);
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel((string)$host) ?: []);
    if (!$ips) return true;   // DNS-Fehler meldet später cURL
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

/** Feed herunterladen (mit ETag/Last-Modified). Liefert ['code', 'body', 'etag', 'last_modified']. */
function cal_fetch_feed(string $url, string $etag = '', string $last_modified = ''): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Die PHP-Erweiterung cURL fehlt.');
    if (!cal_url_allowed($url)) throw new RuntimeException('Diese Adresse ist nicht erlaubt (nur öffentliche http(s)-Adressen).');
    $headers = ['Accept: text/calendar, text/x-vcalendar, application/ics, */*;q=0.5'];
    if ($etag !== '') $headers[] = 'If-None-Match: ' . $etag;
    if ($last_modified !== '') $headers[] = 'If-Modified-Since: ' . $last_modified;
    $resp_headers = [];
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GhostNode-Calendar/1.0)',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$resp_headers) {
            if (strpos($h, ':') !== false) { [$k, $v] = explode(':', $h, 2); $resp_headers[strtolower(trim($k))] = trim($v); }
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
            $body .= $chunk;
            return strlen($body) > CAL_FEED_MAX_BYTES ? 0 : strlen($chunk);   // zu groß -> abbrechen
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (strlen($body) > CAL_FEED_MAX_BYTES) throw new RuntimeException('Der Kalender ist zu groß (über 15 MB).');
    if ($ok === false && $code === 0) throw new RuntimeException('Nicht erreichbar: ' . $err);
    return ['code' => $code, 'body' => $body, 'etag' => $resp_headers['etag'] ?? '', 'last_modified' => $resp_headers['last-modified'] ?? ''];
}

/**
 * Ein Abo abgleichen. Neue Termine anlegen, geänderte aktualisieren, verschwundene löschen.
 * Liefert ['status' => 'ok'|'unchanged', 'added', 'updated', 'removed', 'total'].
 */
function cal_sync_subscription(int $sub_id, bool $force = false): array {
    $db = cal_db();
    $st = $db->prepare('SELECT * FROM subscriptions WHERE id = ?');
    $st->execute([$sub_id]);
    $sub = $st->fetch();
    if (!$sub) throw new RuntimeException('Abo nicht gefunden.');

    $mark = function (string $status, string $error, array $extra = []) use ($db, $sub_id) {
        $sets = ["last_sync = datetime('now','localtime')", 'last_status = :status', 'last_error = :error'];
        $args = [':status' => $status, ':error' => mb_substr($error, 0, 300), ':id' => $sub_id];
        foreach ($extra as $k => $v) { $sets[] = "$k = :$k"; $args[":$k"] = $v; }
        $db->prepare('UPDATE subscriptions SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
    };

    try {
        if (preg_match('/^nexus:(\d+)$/', $sub['url'], $nm)) {   // VRChat-Gruppen-Events aus VRC Nexus
            require_once __DIR__ . '/vrchat.php';
            $res = ['code' => 200, 'body' => vrc_calendar_ics((int)$nm[1]), 'etag' => '', 'last_modified' => ''];
        } else {
            $res = cal_fetch_feed($sub['url'], $force ? '' : $sub['etag'], $force ? '' : $sub['last_modified']);
        }
        if ($res['code'] === 304) {
            $mark('unverändert', '');
            return ['status' => 'unchanged', 'added' => 0, 'updated' => 0, 'removed' => 0, 'total' => (int)$sub['event_count']];
        }
        if ($res['code'] === 401 || $res['code'] === 403) throw new RuntimeException('Zugriff verweigert (HTTP ' . $res['code'] . ') – ist der Link noch gültig?');
        if ($res['code'] === 404) throw new RuntimeException('Kalender nicht gefunden (HTTP 404) – Link prüfen.');
        if ($res['code'] < 200 || $res['code'] >= 300) throw new RuntimeException('Server antwortet mit HTTP ' . $res['code'] . '.');
        if (stripos($res['body'], 'BEGIN:VCALENDAR') === false) throw new RuntimeException('Unter der Adresse liegt kein iCalendar (.ics).');

        $parsed = ics_parse($res['body']);
        $cutoff = (int)$sub['skip_past_days'] > 0 ? date('Y-m-d', strtotime('-' . (int)$sub['skip_past_days'] . ' days')) : null;

        $db->beginTransaction();
        $existing = [];
        $q = $db->prepare('SELECT id, source_uid FROM events WHERE source_id = ?');
        $q->execute([$sub_id]);
        foreach ($q as $row) $existing[$row['source_uid']] = (int)$row['id'];

        $seen = [];
        $added = 0; $updated = 0;
        $masters = [];
        $overrides = [];
        foreach ($parsed['events'] as $p) {
            if ($p['recurrence_id']) $overrides[] = $p; else $masters[] = $p;
        }

        $store = function (array $p, string $key) use (&$existing, &$seen, &$added, &$updated, $sub, $sub_id): int {
            $row = cal_row_from_ics($p) + ['private' => (int)$sub['private']];
            $seen[$key] = true;
            if (isset($existing[$key])) {
                cal_update($existing[$key], $row);
                $updated++;
                return $existing[$key];
            }
            $id = cal_insert($row + ['source_id' => $sub_id, 'source_uid' => $key, 'category_id' => null]);
            $existing[$key] = $id;
            $added++;
            return $id;
        };

        $uid_to_id = [];
        foreach ($masters as $i => $p) {
            // Vergangene Einzeltermine optional auslassen (Serien bleiben, solange sie noch laufen könnten)
            if ($cutoff && $p['recurrence'] === 'none' && substr($p['end_at'], 0, 10) < $cutoff) continue;
            if ($cutoff && $p['recurrence'] !== 'none' && $p['recur_until'] && $p['recur_until'] < $cutoff) continue;
            $key = $p['uid'] !== '' ? $p['uid'] : 'nouid-' . md5($p['title'] . $p['start_at']);
            if (isset($seen[$key])) $key .= '#' . $i;   // doppelte UIDs im Feed
            $id = $store($p, $key);
            $uid_to_id[$p['uid']] = $id;
            cal_set_exceptions($id, $p['exdates']);
        }
        // Einzeln geänderte Termine einer Serie: als eigener Termin + Ausnahme in der Serie
        foreach ($overrides as $p) {
            if (!isset($uid_to_id[$p['uid']])) continue;
            $db->prepare('INSERT OR IGNORE INTO event_exceptions (event_id, day) VALUES (?, ?)')->execute([$uid_to_id[$p['uid']], $p['recurrence_id']]);
            $p['recurrence'] = 'none'; $p['recur_count'] = null; $p['recur_until'] = null; $p['recur_byday'] = null;
            $store($p, $p['uid'] . '#' . $p['recurrence_id']);
        }

        $removed = 0;
        $del = $db->prepare('DELETE FROM events WHERE id = ?');
        foreach ($existing as $key => $id) {
            if (!isset($seen[$key])) { $del->execute([$id]); $removed++; }
        }

        $total = count($seen);
        $extra = ['etag' => $res['etag'], 'last_modified' => $res['last_modified'], 'event_count' => $total];
        if (trim($sub['name']) === '' && $parsed['calname'] !== '') $extra['name'] = mb_substr($parsed['calname'], 0, 80);
        $mark('ok', '', $extra);
        $db->commit();
        return ['status' => 'ok', 'added' => $added, 'updated' => $updated, 'removed' => $removed, 'total' => $total];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $mark('fehler', $e->getMessage());
        throw $e;
    }
}

/** Fällige Abos automatisch abgleichen (höchstens $max pro Seitenaufruf). Fehler werden am Abo vermerkt. */
function cal_sync_due(int $max = 3): int {
    $due = cal_db()->query("SELECT id FROM subscriptions WHERE enabled = 1 AND (last_sync IS NULL OR
        (strftime('%s','now','localtime') - strftime('%s', last_sync)) >= refresh_minutes * 60) ORDER BY last_sync LIMIT " . (int)$max)->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($due as $id) {
        try { cal_sync_subscription((int)$id); $n++; } catch (Throwable $e) { /* steht am Abo */ }
    }
    return $n;
}

// ---------- Eigener Abo-Link (für Handy-Kalender) ----------

/** Geheimer Token für den eigenen Kalender-Feed ('public' = nur öffentliche Termine, 'private' = alle) */
function cal_feed_token(string $kind, bool $regenerate = false): string {
    $key = 'feed_token_' . ($kind === 'private' ? 'private' : 'public');
    $token = cal_setting($key, '');
    if ($token === '' || $regenerate) {
        $token = bin2hex(random_bytes(20));
        cal_set_setting($key, $token);
    }
    return $token;
}

function cal_feed_kind_for_token(string $token): ?string {
    if ($token === '' || strlen($token) < 32) return null;
    foreach (['private', 'public'] as $kind) {
        $t = cal_setting('feed_token_' . $kind, '');
        if ($t !== '' && hash_equals($t, $token)) return $kind;
    }
    return null;
}

// ---------- Schnell-Eingabe ----------

/**
 * Deutschen Freitext in einen Termin übersetzen, z. B.
 *   "Morgen 19 Uhr Stammtisch @Stuttgart #Con"
 *   "Fr 12.12. 18:30-22 Uhr Weihnachtsfeier"
 *   "jeden 2. Samstag 15 Uhr Fursuit-Walk @Karlsruhe"
 *   "24.12. Heiligabend ganztägig !öffentlich"
 * Liefert Formularfelder (wie cal_event_from_post sie erwartet) + 'understood' (Erklärung).
 */
function cal_quick_parse(string $text, ?DateTimeImmutable $now = null): array {
    $now = $now ?? new DateTimeImmutable();
    $t = ' ' . trim(preg_replace('/\s+/u', ' ', $text)) . ' ';
    $out = ['recurrence' => 'none', 'private' => 1];
    $understood = [];
    $days = ['montag' => 1, 'mo' => 1, 'dienstag' => 2, 'di' => 2, 'mittwoch' => 3, 'mi' => 3, 'donnerstag' => 4, 'do' => 4,
             'freitag' => 5, 'fr' => 5, 'samstag' => 6, 'sa' => 6, 'sonnabend' => 6, 'sonntag' => 7, 'so' => 7];
    $codes = [1 => 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    $dayrx = implode('|', array_keys($days));
    $take = function (string $rx) use (&$t) {
        if (preg_match($rx, $t, $m)) { $t = str_replace($m[0], ' ', $t); return $m; }
        return null;
    };

    // Ort (@…) und Kategorie (#…)
    if ($m = $take('/\s@(.+?)(?=\s#|\s!|$)/u')) { $out['location'] = trim($m[1]); $understood[] = 'Ort: ' . trim($m[1]); }
    if ($m = $take('/\s#([\p{L}\p{N}&\-]+)/u')) {
        foreach (cal_categories() as $c) {
            if (mb_stripos($c['name'], $m[1]) === 0 || mb_stripos($c['name'], $m[1]) !== false) { $out['category_id'] = $c['id']; $understood[] = 'Kategorie: ' . $c['name']; break; }
        }
    }
    if ($take('/\s!(öffentlich|oeffentlich|public)\b/iu')) { $out['private'] = 0; $understood[] = 'öffentlich'; }
    $take('/\s!privat\b/iu');
    if ($take('/\s(ganztägig|ganztags|den ganzen tag)\b/iu')) $out['all_day'] = 1;

    // Wiederholungen
    if ($m = $take('/\s(?:jeden|jeder|jede|am)\s+(letzten|[1-5])\.?\s+(' . $dayrx . ')\b\.?(?:\s+im\s+monat)?/iu')) {
        $nth = mb_strtolower($m[1]) === 'letzten' ? -1 : (int)$m[1];
        $wd = $days[mb_strtolower($m[2])];
        $out['recurrence'] = 'monthly';
        $out['monthly_mode'] = $nth . $codes[$wd];
        $first = cal_nth_weekday($now->modify('first day of this month'), $nth, $wd);
        if (!$first || $first->format('Y-m-d') < $now->format('Y-m-d')) $first = cal_nth_weekday($now->modify('first day of next month'), $nth, $wd);
        $out['start_date'] = $first->format('Y-m-d');
        $understood[] = 'monatlich am ' . ($nth === -1 ? 'letzten' : $nth . '.') . ' ' . CAL_WEEKDAY_NAMES[$wd];
    } elseif ($m = $take('/\s(?:jeden|jede|immer)\s+(' . $dayrx . ')\b\.?/iu')) {
        $wd = $days[mb_strtolower($m[1])];
        $out['recurrence'] = 'weekly';
        $out['byday'] = [$codes[$wd]];
        $d = $now->setTime(0, 0);
        while ((int)$d->format('N') !== $wd) $d = $d->modify('+1 day');
        $out['start_date'] = $d->format('Y-m-d');
        $understood[] = 'wöchentlich am ' . CAL_WEEKDAY_NAMES[$wd];
    } elseif ($m = $take('/\s(täglich|jeden tag|wöchentlich|jede woche|monatlich|jeden monat|jährlich|jedes jahr)\b/iu')) {
        $map = ['täglich' => 'daily', 'jeden tag' => 'daily', 'wöchentlich' => 'weekly', 'jede woche' => 'weekly', 'monatlich' => 'monthly',
                'jeden monat' => 'monthly', 'jährlich' => 'yearly', 'jedes jahr' => 'yearly'];
        $out['recurrence'] = $map[mb_strtolower($m[1])];
        $understood[] = CAL_RECURRENCE[$out['recurrence']];
    }

    // Uhrzeit: "19 Uhr", "19:30", "19.30 Uhr", "19-23 Uhr", "von 19 bis 23 Uhr", "18:30–22:00"
    $time = '(\d{1,2})(?:[:.](\d{2}))?';
    if ($m = $take('/\s(?:von\s+|um\s+|ab\s+)?' . $time . '\s*(?:uhr\s*)?(?:-|–|bis)\s*' . $time . '\s*(?:uhr)?(?=\s)/iu')) {
        if ((int)$m[1] < 24 && (int)$m[3] < 24) {
            $out['start_time'] = sprintf('%02d:%02d', $m[1], ($m[2] ?? '') !== '' ? $m[2] : 0);
            $out['end_time'] = sprintf('%02d:%02d', $m[3], ($m[4] ?? '') !== '' ? $m[4] : 0);
        }
    } elseif ($m = $take('/\s(?:um\s+|ab\s+)?(\d{1,2})(?::(\d{2})|\.(\d{2})\s*uhr|\s*uhr)(?=\s)/iu')) {
        if ((int)$m[1] < 24) {
            $out['start_time'] = sprintf('%02d:%02d', $m[1], ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : 0));
            $out['end_time'] = date('H:i', strtotime($out['start_time'] . ' +2 hours'));
            if ($out['end_time'] < $out['start_time']) $out['end_time'] = '23:59';
        }
    }

    // Datum
    if (empty($out['start_date'])) {
        if ($m = $take('/\s(\d{4})-(\d{2})-(\d{2})\b/u')) {
            $out['start_date'] = "$m[1]-$m[2]-$m[3]";
        } elseif ($m = $take('/\s(?:(?:' . $dayrx . ')\.?,?\s+)?(\d{1,2})\.(\d{1,2})\.(\d{2,4})?/iu')) {
            $y = ($m[3] ?? '') !== '' ? (strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3]) : (int)$now->format('Y');
            if (checkdate((int)$m[2], (int)$m[1], $y)) {
                $d = sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
                if (($m[3] ?? '') === '' && $d < $now->format('Y-m-d')) $d = sprintf('%04d-%02d-%02d', $y + 1, $m[2], $m[1]);   // schon vorbei -> nächstes Jahr
                $out['start_date'] = $d;
            }
        } elseif ($m = $take('/\s(heute|morgen|übermorgen|uebermorgen)\b/iu')) {
            $off = ['heute' => 0, 'morgen' => 1, 'übermorgen' => 2, 'uebermorgen' => 2][mb_strtolower($m[1])];
            $out['start_date'] = $now->modify("+$off days")->format('Y-m-d');
        } elseif ($m = $take('/\s(?:nächsten?|naechsten?|kommenden?|am)?\s*(' . $dayrx . ')\b\.?/iu')) {
            $wd = $days[mb_strtolower($m[1])];
            $d = $now->setTime(0, 0)->modify('+1 day');
            while ((int)$d->format('N') !== $wd) $d = $d->modify('+1 day');
            $out['start_date'] = $d->format('Y-m-d');
        } elseif ($take('/\snächste woche\b/iu')) {
            $out['start_date'] = $now->modify('monday next week')->format('Y-m-d');
        }
    }
    if (empty($out['start_date'])) $out['start_date'] = $now->format('Y-m-d');
    $out['end_date'] = $out['start_date'];
    if (empty($out['start_time']) || !empty($out['all_day'])) { $out['all_day'] = 1; unset($out['start_time'], $out['end_time']); }

    $title = trim(preg_replace('/\s+/u', ' ', preg_replace('/\s(?:um|am|ab|von)\s*$/iu', '', $t)));
    $out['title'] = $title !== '' ? mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1) : 'Termin';
    array_unshift($understood, german_datetime(strtotime($out['start_date']), false)
        . (!empty($out['all_day']) ? ' (ganztägig)' : ', ' . $out['start_time'] . '–' . $out['end_time'] . ' Uhr'));
    $out['understood'] = $understood;
    return $out;
}
